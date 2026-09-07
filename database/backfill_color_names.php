<?php
declare(strict_types=1);
// Backfill best-effort pentru product_images.caption (numele culorii) derivat din
// numele fișierului, DOAR unde caption e gol. Dry-run implicit; scrie cu --apply.
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/backfill_color_names.php [--apply]
//
// Yamaha (stil dash):  2026-Yamaha-YZF900R9-EU-Icon_Blue-Studio-001-03.jpg → "Icon Blue"
// Yamaha (stil under): 2016_YAM_F2-5B_EU_NA_STU_002.jpg → "NA" = fără culoare → skip
// CFMOTO:              450CLC_BOBBER_IvoryWhite_Left 45.jpg → "Ivory White"
// Importurile noi Yamaha primesc numele oficial (colourName) din hyperdrive — ăsta e
// doar pentru datele istorice; corecturile fine se fac din admin (câmpul de pe tile).

require __DIR__ . '/../vendor/autoload.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$pdo = (new App\Database($settings['db']))->local();
$apply = in_array('--apply', $argv, true);

/** Numele culorii dedus din fișier, sau '' dacă nu se poate. */
function color_from_filename(string $brand, string $filename): string
{
    $base = preg_replace('/\.[a-z0-9]+$/i', '', $filename) ?? $filename;
    $base = preg_replace('/\s*-\s*Copy\s*(\(\d+\))?$/i', '', $base) ?? $base;

    if ($brand === 'yamaha') {
        // Stil dash: culoarea între -EU- (sau -EUR-) și -Studio/-Static/-Action.
        if (preg_match('/-EUR?-([A-Za-z0-9_]+?)-(?:Studio|Static|Action)/i', $base, $m)) {
            $tok = trim(str_replace('_', ' ', $m[1]));
            return strtoupper($tok) === 'NA' ? '' : ucwords(strtolower($tok));
        }
        // Stil underscore vechi: _EU_<culoare>_STU ("NA" = fără variantă de culoare).
        if (preg_match('/_EU_([A-Za-z0-9]+(?:_[A-Za-z0-9]+)*?)_STU/i', $base, $m)) {
            $tok = trim(str_replace('_', ' ', $m[1]));
            return strtoupper($tok) === 'NA' ? '' : ucwords(strtolower($tok));
        }
        return '';
    }

    // CFMOTO: MODEL[_VARIANTA]_Culoare[_config]_unghi — aruncăm segmentele de unghi/config
    // de la coadă și luăm ultimul rămas (dacă nu e chiar numele modelului).
    $parts = array_values(array_filter(array_map('trim', explode('_', $base)), 'strlen'));
    while (count($parts) > 1) {
        $last = $parts[count($parts) - 1];
        if (preg_match('/^(left|right|front|rear|back|side|top)\b/i', $last)
            || preg_match('/\b(left|right|front|rear)\s*-?\s*\d*$/i', $last)
            || preg_match('/^(low|high)\s*(seat|fender)$/i', $last)      // configurație, nu culoare
            || preg_match('/^(euro?\s*5|eu\s*5)/i', $last)) {            // norma de poluare
            array_pop($parts);
            continue;
        }
        break;
    }
    if (count($parts) < 2) {
        return '';
    }
    $cand = $parts[count($parts) - 1];
    if (!preg_match('/[a-z]/i', $cand) || preg_match('/^\d/', $cand)) {
        return '';
    }
    // CamelCase → spații (IvoryWhite → Ivory White), cratimele → spații, apoi Title Case.
    $cand = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $cand) ?? $cand;
    $cand = str_replace('-', ' ', $cand);
    return ucwords(strtolower(trim(preg_replace('/\s+/', ' ', $cand) ?? $cand)));
}

$rows = $pdo->query(
    "SELECT pi.id, pi.filename, p.brand, p.slug
     FROM product_images pi JOIN products p ON p.id = pi.product_id
     WHERE pi.type = 'color' AND (pi.caption IS NULL OR pi.caption = '')
     ORDER BY p.brand, p.slug, pi.position"
)->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare("UPDATE product_images SET caption = :c WHERE id = :id");
$set = 0;
$skip = 0;
foreach ($rows as $r) {
    $name = color_from_filename((string) $r['brand'], (string) $r['filename']);
    if ($name === '') {
        $skip++;
        continue;
    }
    printf("%s %-8s %-28s %-55s -> %s\n", $apply ? '*' : ' ', $r['brand'], $r['slug'], $r['filename'], $name);
    if ($apply) {
        $upd->execute([':c' => $name, ':id' => $r['id']]);
    }
    $set++;
}
printf("\n%s: %d nume derivate, %d fără nume (rămân goale). Total analizate: %d.\n",
    $apply ? 'APLICAT' : 'DRY-RUN (rulează cu --apply)', $set, $skip, count($rows));
