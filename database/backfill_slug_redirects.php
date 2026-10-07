<?php
declare(strict_types=1);
// Backfill pentru product_slug_redirects: slug-uri vechi (din URL-urile raportate 404 /
// „redirect la categorie" în Search Console) → produsul curent. Dry-run implicit; scrie cu --apply.
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/backfill_slug_redirects.php [--apply]
//
// De ce: în iunie 2026 gama Yamaha a fost reimportată ca produse noi (slug fără an, fără
// legacy_url) → URL-urile vechi nu mai găseau produsul. Sufixul de an (`mt-09-2026` → `mt-09`)
// îl rezolvă runtime-ul (Repository::canonicalForRetiredSlug); aici stau doar aliasurile care
// NU se pot deduce: variante Y-AMT / Anniversary comasate, greșeli de slug vechi etc.
// Nedistructiv: nu atinge rândurile existente; sare aliasul dacă ținta lipsește sau dacă
// slug-ul vechi e chiar un produs existent.

require __DIR__ . '/../vendor/autoload.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$pdo = (new App\Database($settings['db']))->local();
$apply = in_array('--apply', $argv, true);

// brand => [slug vechi (fără an) => slug-ul produsului curent]
$aliases = [
    'yamaha' => [
        'mt-07-y-amt'            => 'mt-07',
        'mt-09-y-amt'            => 'mt-09',
        'tracer-7-y-amt'         => 'tracer-7',
        'tracer-7-gt-y-amt'      => 'tracer-7-gt',
        'tracer-9-y-amt'         => 'tracer-9',
        'tracer-9-gt-y-amt'      => 'tracer-9-gt',
        'tracer-9-gt-plus'       => 'tracer-9-gt-plus-y-amt',
        'r9-35-kw'               => 'r9-35kw',
        'r7-anniversary'         => 'r7',
        'r7-annyversary'         => 'r7',
        'r125-anniversary'       => 'r125',
        'r125-annyversary'       => 'r125',
        'r3-anniversary'         => 'r3',
        'r3-annyversary'         => 'r3',
        'nmax-125-techmax'       => 'nmax-125-tech-max',
        'xmax-125'               => 'xmax-125-2026',
        'tricity-300'            => 'tricity-300-airbag-2026',
        // slug-uri generate greșit din „Ténéré" pe un build PCRE fără \pL
        't-n-r-700'              => 'tenere-700',
        't-n-r-700-rally'        => 'tenere-700-rally',
        't-n-r-700-world-raid'   => 'tenere-700-world-raid',
    ],
    'cfmoto' => [
        'cfmoto-675nk-abs-25'    => 'cfmoto-675nk-abs-2026',
        'cfmoto-300sr-abs-25'    => 'cflite-300sr-abs-2026',
        'cfmoto-450sr-abs-25'    => 'cfmoto-450sr-abs-0',
        'cfmoto-450sr-abs-26'    => 'cfmoto-450sr-abs-0',
        'cfmoto-450cl-c-25'      => 'cfmoto-450cl-c-2026',
        'cfmoto-675sr-r-25'      => 'cfmoto-675sr-r-26-2026',
    ],
];

$find = $pdo->prepare('SELECT id, is_active FROM products WHERE brand = :b AND slug = :s');
$has  = $pdo->prepare('SELECT product_id FROM product_slug_redirects WHERE brand = :b AND old_slug = :s');
$ins  = $pdo->prepare('INSERT INTO product_slug_redirects (brand, old_slug, product_id) VALUES (:b, :s, :p)');

$n = ['adaugat' => 0, 'exista' => 0, 'sarit' => 0];
foreach ($aliases as $brand => $map) {
    foreach ($map as $old => $target) {
        $find->execute([':b' => $brand, ':s' => $old]);
        if ($find->fetch()) {
            printf("  SARIT   %-7s %-28s e un produs existent\n", $brand, $old);
            $n['sarit']++;
            continue;
        }
        $find->execute([':b' => $brand, ':s' => $target]);
        $t = $find->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            printf("  SARIT   %-7s %-28s tinta lipseste: %s\n", $brand, $old, $target);
            $n['sarit']++;
            continue;
        }
        $has->execute([':b' => $brand, ':s' => $old]);
        if ($has->fetchColumn() !== false) {
            printf("  EXISTA  %-7s %-28s\n", $brand, $old);
            $n['exista']++;
            continue;
        }
        printf("  %s %-7s %-28s -> #%d %s%s\n", $apply ? 'ADAUGAT' : 'DE ADAUGAT', $brand, $old, $t['id'], $target, (int) $t['is_active'] ? '' : ' (inactiv)');
        if ($apply) {
            $ins->execute([':b' => $brand, ':s' => $old, ':p' => (int) $t['id']]);
        }
        $n['adaugat']++;
    }
}
printf("%s: %d de adaugat, %d existente, %d sarite.\n", $apply ? 'APLICAT' : 'DRY-RUN', $n['adaugat'], $n['exista'], $n['sarit']);
