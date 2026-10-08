<?php
/**
 * Abonări false la newsletterul BikerShop (formularul din footer, atacat de boți în 7–8 oct. 2026).
 *
 * Rulează PE SERVERUL BIKERSHOP, din ~/public_html/tool/ (bootstrap PrestaShop).
 *
 *   ea-php84 curatare-abonari-boti.php                  # dry-run: arată ce ar șterge / schimba
 *   ea-php84 curatare-abonari-boti.php --apply          # șterge rândurile boților (backup SQL în logs/)
 *   ea-php84 curatare-abonari-boti.php --no-voucher --apply   # + golește NW_VOUCHER_CODE
 *
 * Rândurile boților = ps_emailsubscription cu IP-ul de înregistrare pe unul dintre prefixele din
 * --prefixes (implicit cele din atac) și data de la --from încoace. PrestaShop păstrează doar
 * primele 15 caractere din IP, deci prefixele se compară pe începutul șirului.
 *
 * --no-voucher: cât timp NW_VOUCHER_CODE e completat și NW_VERIFICATION_EMAIL = 0, orice abonare
 * trimite pe loc emailul „Cupon din buletinul informativ" către o adresă neverificată.
 *
 * Opțiuni: --from="2026-10-07 22:00:00", --prefixes=a,b,c, --ps-root=<dir> (implicit ~/public_html).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Doar din linia de comandă.\n");
}

const ID_SHOP = 1;

$opt = [
    'apply'      => false,
    'no_voucher' => false,
    'from'       => '2026-10-07 22:00:00',
    'prefixes'   => ['2001:19f0:', '2401:c080:', '2a05:f480:', '2a03:f80:'],
    'ps_root'    => getenv('HOME') . '/public_html',
];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') {
        $opt['apply'] = true;
    } elseif ($a === '--no-voucher') {
        $opt['no_voucher'] = true;
    } elseif (str_starts_with($a, '--from=')) {
        $opt['from'] = substr($a, 7);
    } elseif (str_starts_with($a, '--prefixes=')) {
        $opt['prefixes'] = array_values(array_filter(array_map('trim', explode(',', substr($a, 11)))));
    } elseif (str_starts_with($a, '--ps-root=')) {
        $opt['ps_root'] = rtrim(substr($a, 10), '/');
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(1);
    }
}
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $opt['from'])) {
    fwrite(STDERR, "--from trebuie să fie de forma \"AAAA-LL-ZZ HH:MM:SS\".\n");
    exit(1);
}
foreach ($opt['prefixes'] as $p) {
    // Un prefix prea scurt ar prinde și abonați reali.
    if (!preg_match('/^[0-9a-f:.]{6,}$/i', $p)) {
        fwrite(STDERR, "Prefix IP invalid sau prea scurt: {$p}\n");
        exit(1);
    }
}
if (!$opt['prefixes']) {
    fwrite(STDERR, "Niciun prefix IP.\n");
    exit(1);
}

$logDir = __DIR__ . '/logs'; // comun cu celelalte scripturi din tool/ → fișierele au prefix abonari-boti-
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// ---------------------------------------------------------------------------
// Bootstrap PrestaShop (același tipar ca home_products.php)
// ---------------------------------------------------------------------------
$root = $opt['ps_root'];
if (!is_file($root . '/config/config.inc.php')) {
    fwrite(STDERR, "PrestaShop nu e la {$root}.\n");
    exit(1);
}
if (!defined('_PS_ADMIN_DIR_')) {
    $adminDir = $root . '/admin';
    foreach (scandir($root) as $entry) {
        if (str_starts_with($entry, '__admin') && is_dir($root . '/' . $entry)) {
            $adminDir = $root . '/' . $entry;
            break;
        }
    }
    define('_PS_ADMIN_DIR_', $adminDir);
}
require $root . '/config/config.inc.php';

$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$ts     = date('Ymd-His');
$mode   = $opt['apply'] ? 'APPLY' : 'DRY-RUN';
echo "[{$mode}] de la {$opt['from']}, prefixe: " . implode(', ', $opt['prefixes']) . "\n";

// ---------------------------------------------------------------------------
// 1. Rândurile boților
// ---------------------------------------------------------------------------
$likes = [];
foreach ($opt['prefixes'] as $p) {
    $likes[] = "ip_registration_newsletter LIKE '" . pSQL($p) . "%'";
}
$where = "newsletter_date_add >= '" . pSQL($opt['from']) . "' AND (" . implode(' OR ', $likes) . ')';

$rows  = $db->executeS("SELECT * FROM `{$prefix}emailsubscription` WHERE {$where} ORDER BY id") ?: [];
$total = (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}emailsubscription`");
$mails = array_unique(array_map('strtolower', array_column($rows, 'email')));
echo 'Rânduri de șters: ' . count($rows) . ' (' . count($mails) . " adrese distincte) din {$total} în tabel.\n";
if ($rows) {
    echo '  interval: ' . $rows[0]['newsletter_date_add'] . ' → ' . end($rows)['newsletter_date_add'] . "\n";
    $byPrefix = [];
    foreach ($rows as $r) {
        foreach ($opt['prefixes'] as $p) {
            if (stripos((string) $r['ip_registration_newsletter'], $p) === 0) {
                $byPrefix[$p] = ($byPrefix[$p] ?? 0) + 1;
                break;
            }
        }
    }
    foreach ($byPrefix as $p => $n) {
        echo "  {$p}* = {$n}\n";
    }
}

if ($rows && $opt['apply']) {
    $backup = "{$logDir}/abonari-boti-rollback-{$ts}.sql";
    $fh     = fopen($backup, 'w');
    $cols   = '`' . implode('`, `', array_keys($rows[0])) . '`';
    foreach ($rows as $r) {
        $vals = array_map(static fn ($v) => $v === null ? 'NULL' : "'" . pSQL((string) $v, true) . "'", $r);
        fwrite($fh, "INSERT INTO `{$prefix}emailsubscription` ({$cols}) VALUES (" . implode(', ', $vals) . ");\n");
    }
    fclose($fh);
    chmod($backup, 0600); // conține adrese de email
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    $db->execute("DELETE FROM `{$prefix}emailsubscription` WHERE id IN ({$ids})");
    echo 'Șterse: ' . $db->Affected_Rows() . ". Backup: {$backup}\n";
}

// ---------------------------------------------------------------------------
// 2. Cuponul trimis automat la abonare
// ---------------------------------------------------------------------------
$cfgSql = "SELECT id_configuration, id_shop_group, id_shop, value FROM `{$prefix}configuration` WHERE name = 'NW_VOUCHER_CODE'";
echo "NW_VOUCHER_CODE acum:\n";
foreach ($db->executeS($cfgSql) ?: [] as $c) {
    echo "  #{$c['id_configuration']} grup=" . ($c['id_shop_group'] ?? '-') . ' shop=' . ($c['id_shop'] ?? '-') . " → '{$c['value']}'\n";
}
if ($opt['no_voucher']) {
    if ($opt['apply']) {
        $old = [];
        foreach ($db->executeS($cfgSql) ?: [] as $c) {
            if ((string) $c['value'] !== '') {
                $old[] = "UPDATE `{$prefix}configuration` SET value = '" . pSQL((string) $c['value']) . "' WHERE id_configuration = " . (int) $c['id_configuration'] . ';';
            }
        }
        if ($old) {
            file_put_contents("{$logDir}/abonari-boti-voucher-{$ts}.sql", implode("\n", $old) . "\n");
        }
        // Prin Configuration (nu SQL direct), ca să se invalideze și cache-ul.
        Shop::setContext(Shop::CONTEXT_SHOP, ID_SHOP);
        Configuration::updateValue('NW_VOUCHER_CODE', '');
        Shop::setContext(Shop::CONTEXT_ALL);
        Configuration::updateValue('NW_VOUCHER_CODE', '');
        $left = (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}configuration` WHERE name = 'NW_VOUCHER_CODE' AND value <> ''");
        echo $left === 0 ? "NW_VOUCHER_CODE golit.\n" : "ATENȚIE: au rămas {$left} rânduri completate.\n";
    } else {
        echo "(dry-run) NW_VOUCHER_CODE ar fi golit.\n";
    }
}
