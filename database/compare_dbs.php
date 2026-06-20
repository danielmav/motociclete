<?php
declare(strict_types=1);

/**
 * Compară baza locală (motociclete) cu cea de staging (dualmotors_motociclete2026):
 * listă de tabele, schemă la nivel de coloană, număr de rânduri și fingerprint de
 * conținut (SUM(CRC32(...)) per tabel). Local = sursă de adevăr.
 *
 * Rulează cu binarul Laragon PHP 8.1 (are pdo_mysql; php din PATH e 8.2 fără el):
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/compare_dbs.php
 *
 * Moduri:
 *   (fără argument)  raport lizibil la stdout. Exit 0 = sincron, 2 = diferențe, 1 = eroare.
 *   --hook           pentru hook-ul Claude Code: tace când e sincron; când există drift
 *                    emite JSON cu additionalContext + systemMessage. Nu blochează niciodată.
 *
 * Tabele efemere/specifice mediului (NU se compară — sync-ul lor n-are sens):
 *   client_otp (coduri OTP de unică folosință), email_log (audit per-mediu).
 */

require __DIR__ . '/../vendor/autoload.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';

const IGNORED_TABLES = ['client_otp', 'email_log'];

// Coloane excluse din fingerprint-ul de CONȚINUT (nu reprezintă drift real):
//  - `id` = cheia surogat auto-increment; se renumerotează natural la re-import
//    (numărul de rânduri prinde oricum adăugări/ștergeri).
//  - timestamp-uri volatile = diferă firesc între medii (import, login, editări).
const NONCONTENT_COLUMNS = ['id', 'created_at', 'updated_at', 'last_login_at'];

$hookMode = in_array('--hook', $argv, true);

/** Conexiune PDO simplă (aceleași opțiuni ca App\Database). */
function pdo_connect(string $host, string $port, string $name, string $user, string $pass): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

$dbL = $settings['db']['local'];
$dbM = $settings['db']['dm']; // host/user/pass cu acces full pe serverul remote
$stagingName = $_ENV['STAGING_DB_NAME'] ?? 'dualmotors_motociclete2026';

try {
    $local   = pdo_connect($dbL['host'], $dbL['port'], $dbL['name'], $dbL['user'], $dbL['pass']);
    $staging = pdo_connect($dbM['host'], $dbM['port'], $stagingName, $dbM['user'], $dbM['pass']);
} catch (Throwable $e) {
    // Conexiunea pică (staging offline / IP ne-whitelisted / Laragon oprit). NU bloca push-ul.
    $msg = 'Verificare DB local↔staging SĂRITĂ (conexiune eșuată): ' . $e->getMessage();
    if ($hookMode) {
        echo json_encode(['systemMessage' => '⚠ ' . $msg], JSON_UNESCAPED_UNICODE);
        exit(0); // în hook nu semnalăm niciodată eroare blocantă pe push
    }
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

/** Normalizează tipul coloanei ca să dispară diferențele cosmetice MySQL8↔MariaDB. */
function norm_type(string $t): string
{
    $t = strtolower($t);
    $t = preg_replace('/int\(\d+\)/', 'int', $t);   // int(10)->int, tinyint(1)->tinyint, bigint(20)->bigint
    $t = preg_replace('/year\(\d+\)/', 'year', $t); // year(4)->year
    return $t;
}

/** @return array<string,string> table.column => "type|null|key" */
function columns(PDO $pdo, string $schema): array
{
    $st = $pdo->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?
         ORDER BY TABLE_NAME, ORDINAL_POSITION'
    );
    $st->execute([$schema]);
    $out = [];
    foreach ($st as $r) {
        $key = $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'];
        $out[$key] = norm_type($r['COLUMN_TYPE']) . '|null=' . $r['IS_NULLABLE'] . '|key=' . $r['COLUMN_KEY'];
    }
    return $out;
}

/** @return list<string> tabele BASE, ordonate */
function tables(PDO $pdo, string $schema): array
{
    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    );
    $st->execute([$schema]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Pentru fiecare tabel comun (ne-ignorat) construiește o expresie de fingerprint din
 * coloanele LOCALE și rulează un singur SELECT UNION pe fiecare bază.
 * @param array<string,list<string>> $perTableCols  tabel => coloane (fără cele non-conținut)
 * @return array<string,array{count:?int,fp:?string}>
 */
function fingerprints(PDO $pdo, array $perTableCols): array
{
    $parts = [];
    foreach ($perTableCols as $tbl => $cols) {
        if ($cols === []) {
            // Tabel doar cu coloane non-conținut (ex. join pur) -> doar count, fără fingerprint.
            $parts[] = "SELECT '$tbl' AS tbl, COUNT(*) AS c, NULL AS fp FROM `$tbl`";
            continue;
        }
        $concat = [];
        foreach ($cols as $c) {
            $concat[] = "IFNULL(`$c`,'\\0')";
        }
        $expr = 'CONCAT_WS(0x01,' . implode(',', $concat) . ')';
        $parts[] = "SELECT '$tbl' AS tbl, COUNT(*) AS c, "
            . "CAST(SUM(CRC32($expr)) AS UNSIGNED) AS fp FROM `$tbl`";
    }
    $sql = implode("\nUNION ALL\n", $parts);
    $out = [];
    foreach ($pdo->query($sql) as $r) {
        $out[$r['tbl']] = ['count' => (int) $r['c'], 'fp' => $r['fp'] === null ? null : (string) $r['fp']];
    }
    return $out;
}

// ---- Tabele ----
$tL = tables($local, $dbL['name']);
$tM = tables($staging, $stagingName);
$onlyLocal  = array_values(array_diff($tL, $tM));
$onlyRemote = array_values(array_diff($tM, $tL));
$common     = array_values(array_intersect($tL, $tM));

// ---- Schemă (coloane) ----
$cL = columns($local, $dbL['name']);
$cM = columns($staging, $stagingName);
$schemaDiff = []; // table.column => [local, staging]
foreach ($cL as $k => $v) {
    [$tbl] = explode('.', $k, 2);
    if (in_array($tbl, IGNORED_TABLES, true)) continue;
    if (!array_key_exists($k, $cM)) {
        $schemaDiff[$k] = [$v, '(lipsește pe staging)'];
    } elseif ($cM[$k] !== $v) {
        $schemaDiff[$k] = [$v, $cM[$k]];
    }
}
foreach ($cM as $k => $v) {
    [$tbl] = explode('.', $k, 2);
    if (in_array($tbl, IGNORED_TABLES, true)) continue;
    if (!array_key_exists($k, $cL)) {
        $schemaDiff[$k] = ['(lipsește pe local)', $v];
    }
}

// ---- Date (count + fingerprint) pe tabelele comune, ne-ignorate ----
$compareTables = array_values(array_filter($common, fn($t) => !in_array($t, IGNORED_TABLES, true)));
// coloane per tabel (din local), păstrând ordinea
$perTableCols = [];
foreach ($compareTables as $t) {
    $perTableCols[$t] = [];
}
$st = $local->prepare(
    'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION'
);
$st->execute([$dbL['name']]);
foreach ($st as $r) {
    if (isset($perTableCols[$r['TABLE_NAME']])
        && !in_array($r['COLUMN_NAME'], NONCONTENT_COLUMNS, true)) {
        $perTableCols[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
    }
}

$fpL = fingerprints($local, $perTableCols);
try {
    $fpM = fingerprints($staging, $perTableCols);
} catch (Throwable $e) {
    $fpM = []; // dacă o coloană lipsește pe staging, schemaDiff a prins-o deja
}

$dataDiff = []; // tbl => [cL, cM, fpMatch]
foreach ($compareTables as $t) {
    $lc = $fpL[$t]['count'] ?? null;
    $lf = $fpL[$t]['fp'] ?? null;
    $rc = $fpM[$t]['count'] ?? null;
    $rf = $fpM[$t]['fp'] ?? null;
    if ($lc !== $rc || $lf !== $rf) {
        $dataDiff[$t] = ['lc' => $lc, 'rc' => $rc, 'fpMatch' => ($lf === $rf)];
    }
}

$hasDrift = $onlyLocal || $onlyRemote || $schemaDiff || $dataDiff;

// ---- Raport ----
$lines = [];
$lines[] = sprintf('DB DRIFT — local(%s) ↔ staging(%s)  [%s]',
    $dbL['name'], $stagingName, date('Y-m-d H:i'));
if (!$hasDrift) {
    $lines[] = '✅ SINCRON — structură și date identice (excluse: ' . implode(', ', IGNORED_TABLES) . ').';
} else {
    if ($onlyLocal)  $lines[] = '• Tabele doar pe LOCAL: ' . implode(', ', $onlyLocal);
    if ($onlyRemote) $lines[] = '• Tabele doar pe STAGING: ' . implode(', ', $onlyRemote);
    if ($schemaDiff) {
        $lines[] = '• Diferențe de SCHEMĂ:';
        foreach ($schemaDiff as $k => [$a, $b]) {
            $lines[] = sprintf('    %-32s local: %s   staging: %s', $k, $a, $b);
        }
    }
    if ($dataDiff) {
        $lines[] = '• Diferențe de DATE (tabel | local | staging | conținut):';
        foreach ($dataDiff as $t => $d) {
            $note = $d['lc'] === $d['rc']
                ? ($d['fpMatch'] ? 'OK' : 'același nr., conținut DIFERIT')
                : 'nr. rânduri diferit';
            $lines[] = sprintf('    %-26s %6s %6s   %s',
                $t, $d['lc'] ?? 'NULL', $d['rc'] ?? 'NULL', $note);
        }
    }
    $lines[] = 'Local = sursă de adevăr. Întreabă utilizatorul ce sincronizăm.';
    $lines[] = '(necomparate pt. conținut: ' . implode(', ', NONCONTENT_COLUMNS)
        . '; tabele excluse: ' . implode(', ', IGNORED_TABLES) . ')';
}
$report = implode("\n", $lines);

if ($hookMode) {
    if ($hasDrift) {
        echo json_encode([
            'systemMessage' => '⚠ Diferențe între baza locală și staging — vezi detaliile în conversație.',
            'hookSpecificOutput' => [
                'hookEventName'     => 'PostToolUse',
                'additionalContext' => $report,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
    // sincron => tăcere totală
    exit(0);
}

echo $report . "\n";
exit($hasDrift ? 2 : 0);
