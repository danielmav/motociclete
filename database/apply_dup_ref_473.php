<?php

declare(strict_types=1);

/**
 * Aplică în BikerShop corecțiile alese în tests/dup_ref_473.php.
 *
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/apply_dup_ref_473.php
 *   ... --apply            scrie efectiv (implicit e dry-run)
 *   ... --only=358400      doar un produs
 *
 * Pentru fiecare produs selectat:
 *   1. re-verifică starea față de selecția salvată (protecție la raport învechit)
 *   2. pune codul complet Yamaha pe `ps_product.reference`
 *   3. pune prețul = EUR × curs / TVA în `ps_product.price` ȘI `ps_product_shop.price`
 *   4. retrage geamănul duplicat (active=0, visibility='none', scos din categoria 2545)
 *
 * Retragerea e REVERSIBILĂ: rândul rămâne în DB, iar fiecare modificare are UPDATE-ul
 * invers scris în storage/dup473_rollback.sql.
 *
 * ATENȚIE: acesta e SINGURUL loc care scrie în BikerShop. src/BikerShop/Client.php
 * rămâne strict read-only.
 */

use App\Accessories\RefAudit;
use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$settings = require $root . '/config/settings.php';
$argvList = $argv ?? [];
$apply    = in_array('--apply', $argvList, true);

$only = null;
foreach ($argvList as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = (int) substr($a, 7);
    }
}

$selectionFile = $root . '/storage/dup473_selection.json';
if (!is_file($selectionFile)) {
    fwrite(STDERR, "Lipsește {$selectionFile}.\nDeschide întâi http://motociclete.test/tests/dup_ref_473.php și salvează o selecție.\n");
    exit(1);
}
$sel = json_decode((string) file_get_contents($selectionFile), true);
if (!is_array($sel) || (!($sel['picks'] ?? []) && !($sel['deactivate'] ?? []))) {
    fwrite(STDERR, "Selecție goală sau invalidă.\n");
    exit(1);
}

$rate = (float) ($sel['rate'] ?? RefAudit::RATE);
if ($rate <= 0) {
    $rate = RefAudit::RATE;
}

$db = new Database($settings['db']);
$bs = $db->bikershop();
$local = $db->local();
if (!$bs instanceof PDO) {
    fwrite(STDERR, "BikerShop indisponibil.\n");
    exit(1);
}

// Catalogul Yamaha = sursa prețului și a codului corect.
$catalog = [];
foreach ($local->query("SELECT sku, name, price_eur FROM yamaha_catalog")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $catalog[$r['sku']] = $r;
}

$picks = $sel['picks'];
if ($only !== null) {
    $picks = array_values(array_filter($picks, static fn ($p) => (int) $p['id_product'] === $only));
}

printf("apply_dup_ref_473%s — %d produse, curs %.2f, TVA %.0f%%\n",
    $apply ? '' : ' (DRY-RUN)', count($picks), $rate, (RefAudit::VAT - 1) * 100);
echo str_repeat('─', 100) . "\n";

// ---- Interogări pregătite ----------------------------------------------
$qProduct = $bs->prepare(
    "SELECT pr.id_product, pr.reference, pr.price AS p_price, ps.price AS s_price, ps.active, ps.visibility
     FROM ps_product pr
     JOIN ps_product_shop ps ON ps.id_product = pr.id_product AND ps.id_shop = 1
     WHERE pr.id_product = :id"
);
$qByRef = $bs->prepare(
    "SELECT pr.id_product, pr.reference, ps.active, ps.visibility,
            (SELECT COUNT(*) FROM ps_image i WHERE i.id_product = pr.id_product) AS imgs,
            (SELECT COUNT(*) FROM ps_category_product c WHERE c.id_product = pr.id_product AND c.id_category = :cat) AS in_empty
     FROM ps_product pr
     JOIN ps_product_shop ps ON ps.id_product = pr.id_product AND ps.id_shop = 1
     WHERE pr.reference = :ref AND pr.id_product <> :self
     ORDER BY ps.active DESC, pr.id_product"
);

// Sursa REALĂ a prețului: modulul supplierpricing ține prețul brut în
// `ps_product_supplier.product_supplier_reference` și recalculează singur
// `ps_product.price` = brut / 1.21. Scrierea directă în ps_product.price NU rezistă.
$qSuppliers = $bs->prepare(
    "SELECT id_product_supplier, id_product_attribute, id_supplier, product_supplier_reference,
            product_supplier_price_te, id_currency
     FROM ps_product_supplier WHERE id_product = :id ORDER BY id_supplier"
);
$uSupplierRef = $bs->prepare(
    "UPDATE ps_product_supplier SET product_supplier_reference = :ref WHERE id_product_supplier = :sid"
);
$dSupplier = $bs->prepare("DELETE FROM ps_product_supplier WHERE id_product = :id");
$qEnqueue  = $bs->prepare(
    "INSERT INTO ps_supplierpricing_queue (id_product, last_update, in_progress, force_update)
     VALUES (:id, NOW(), 0, 1) ON DUPLICATE KEY UPDATE force_update = 1, last_update = NOW()"
);

$uRefPrice = $bs->prepare("UPDATE ps_product SET reference = :ref, price = :price, date_upd = NOW() WHERE id_product = :id");
$uShopPrice = $bs->prepare("UPDATE ps_product_shop SET price = :price, date_upd = NOW() WHERE id_product = :id AND id_shop = 1");
$uRetireP  = $bs->prepare("UPDATE ps_product SET active = 0, date_upd = NOW() WHERE id_product = :id");
$uRetireS  = $bs->prepare("UPDATE ps_product_shop SET active = 0, visibility = 'none', date_upd = NOW() WHERE id_product = :id AND id_shop = 1");
$dCat      = $bs->prepare("DELETE FROM ps_category_product WHERE id_product = :id AND id_category = :cat");
// NU nota produsul în `ps_supplierpricing_queue`: modulul de supplier pricing
// recalculează prețul din datele lui (`ps_supplierpricing_prices_cached`, supplier 7)
// și SUPRASCRIE valoarea pe care tocmai am scris-o. Prima rulare a pierdut astfel
// toate prețurile, deși referințele au rămas corecte.

$logLines = [];
$rollback = [];
$done = 0;
$skipped = 0;

foreach ($picks as $pick) {
    $id  = (int) $pick['id_product'];
    $sku = strtoupper(trim((string) $pick['sku']));

    $y = $catalog[$sku] ?? null;
    if (!$y) {
        printf("  ! %-8d SKU %s lipsește din yamaha_catalog — sărit\n", $id, $sku);
        $skipped++;
        continue;
    }
    $eur = (float) $y['price_eur'];
    if ($eur <= 0) {
        printf("  ! %-8d %s nu are preț la Yamaha — sărit\n", $id, $sku);
        $skipped++;
        continue;
    }
    $newPrice = RefAudit::expectedPrice($eur, $rate);
    $gross    = RefAudit::grossPrice($eur, $rate);
    if ($newPrice <= 0) {
        printf("  ! %-8d preț calculat <= 0 — sărit\n", $id);
        $skipped++;
        continue;
    }

    // Prețul se poate schimba durabil doar dacă produsul are exact un furnizor:
    // la mai mulți, prioritatea (SPECIALPRICE_SUPPLIER=4, apoi OVERRIDE=1, apoi
    // oricare cu stoc > 0) decide care câștigă, deci nu ghicim.
    $qSuppliers->execute([':id' => $id]);
    $suppliers = $qSuppliers->fetchAll(PDO::FETCH_ASSOC);
    $newSupRef = null;
    $supRow    = null;
    if (count($suppliers) === 1) {
        $supRow    = $suppliers[0];
        $newSupRef = RefAudit::rewriteSupplierReference((string) $supRow['product_supplier_reference'], $gross);
        if ($newSupRef === null) {
            printf("  ! %-8d referință furnizor în format necunoscut (%s) — corectez doar codul\n",
                $id, (string) $supRow['product_supplier_reference']);
        }
    } elseif (count($suppliers) === 0) {
        printf("  ! %-8d fără furnizor — corectez doar codul\n", $id);
    } else {
        printf("  ! %-8d are %d furnizori — corectez doar codul (prețul se rezolvă manual)\n", $id, count($suppliers));
    }

    $qProduct->execute([':id' => $id]);
    $p = $qProduct->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        printf("  ! %-8d nu mai există în BikerShop — sărit\n", $id);
        $skipped++;
        continue;
    }
    $oldRef   = (string) $p['reference'];
    $oldPrice = (float) ($p['s_price'] ?? $p['p_price']);

    // Geamănul care poartă deja codul complet.
    $qByRef->execute([':ref' => $sku, ':cat' => RefAudit::CAT_EMPTY, ':self' => $id]);
    $twin = $qByRef->fetch(PDO::FETCH_ASSOC);
    if ($twin && (int) $twin['id_product'] === $id) {
        $twin = null; // e chiar produsul nostru (cod deja corect)
    }
    if ($twin && (int) $twin['imgs'] > 0) {
        printf("  ! %-8d geamănul %d are %d imagini — sărit (verifică manual)\n",
            $id, (int) $twin['id_product'], (int) $twin['imgs']);
        $skipped++;
        continue;
    }

    printf("  %s %-8d %-12s → %-12s   %10.2f → %10.2f lei  [%s]%s\n",
        $apply ? '✓' : '·', $id, $oldRef, $sku, $oldPrice, $newPrice,
        $newSupRef !== null ? 'furnizor: ' . $newSupRef : 'doar cod',
        $twin ? sprintf('   retrage %d', (int) $twin['id_product']) : '');

    if (!$apply) {
        $done++;
        continue;
    }

    try {
        $bs->beginTransaction();

        // Geamănul se retrage ÎNAINTE, ca să nu existe simultan două produse vizibile cu același cod.
        if ($twin) {
            $tid = (int) $twin['id_product'];

            // Îi ștergem și rândurile de furnizor: altfel, dacă geamănul ajunge vreodată
            // în coadă (salvare din BO, „queue all"), cronul l-ar re-activa ca produs
            // inactiv cu stoc 0 (cron.php, „Caz 2"). Fără furnizor, regula
            // DISABLE_NO_SUPPLIER_PRODUCTS îl ține dezactivat definitiv.
            $qSuppliers->execute([':id' => $tid]);
            foreach ($qSuppliers->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                $rollback[] = sprintf(
                    "INSERT INTO ps_product_supplier (id_product_supplier,id_product,id_product_attribute,id_supplier,product_supplier_reference,product_supplier_price_te,id_currency)"
                    . " VALUES (%d,%d,%d,%d,'%s',%s,%d);",
                    (int) $sr['id_product_supplier'], $tid, (int) $sr['id_product_attribute'], (int) $sr['id_supplier'],
                    str_replace("'", "''", (string) $sr['product_supplier_reference']),
                    (string) $sr['product_supplier_price_te'], (int) $sr['id_currency']
                );
            }
            $dSupplier->execute([':id' => $tid]);

            $uRetireP->execute([':id' => $tid]);
            $uRetireS->execute([':id' => $tid]);
            $dCat->execute([':id' => $tid, ':cat' => RefAudit::CAT_EMPTY]);
            $rollback[] = sprintf(
                "UPDATE ps_product SET active=1 WHERE id_product=%d;\n"
                . "UPDATE ps_product_shop SET active=%d, visibility='%s' WHERE id_product=%d AND id_shop=1;\n"
                . "INSERT IGNORE INTO ps_category_product (id_category,id_product) VALUES (%d,%d);",
                $tid, (int) $twin['active'], (string) $twin['visibility'], $tid, RefAudit::CAT_EMPTY, $tid
            );
        }

        $uRefPrice->execute([':ref' => $sku, ':price' => $newPrice, ':id' => $id]);
        $uShopPrice->execute([':price' => $newPrice, ':id' => $id]);

        $rollback[] = sprintf(
            "UPDATE ps_product SET reference='%s', price=%.6f WHERE id_product=%d;\n"
            . "UPDATE ps_product_shop SET price=%.6f WHERE id_product=%d AND id_shop=1;",
            str_replace("'", "''", $oldRef), (float) $p['p_price'], $id, $oldPrice, $id
        );

        // Sursa durabilă + repunere în coadă, ca modulul să recalculeze din valoarea nouă.
        if ($newSupRef !== null && $supRow !== null) {
            $uSupplierRef->execute([':ref' => $newSupRef, ':sid' => (int) $supRow['id_product_supplier']]);
            $rollback[] = sprintf(
                "UPDATE ps_product_supplier SET product_supplier_reference='%s' WHERE id_product_supplier=%d;",
                str_replace("'", "''", (string) $supRow['product_supplier_reference']),
                (int) $supRow['id_product_supplier']
            );
            $qEnqueue->execute([':id' => $id]);
        }

        $bs->commit();
        $logLines[] = sprintf('%s  id=%d  ref %s -> %s  pret %.2f -> %.2f  twin=%s',
            date('c'), $id, $oldRef, $sku, $oldPrice, $newPrice, $twin ? $twin['id_product'] : '-');
        $done++;
    } catch (Throwable $e) {
        if ($bs->inTransaction()) {
            $bs->rollBack();
        }
        printf("  ! %-8d EROARE: %s\n", $id, $e->getMessage());
        $skipped++;
    }
}

// ---- Dezactivări (duplicate + produse fără stoc inexistente la Yamaha) --
$deact = array_map('intval', $sel['deactivate'] ?? []);
if ($only !== null) {
    $deact = array_values(array_filter($deact, static fn ($d) => $d === $only));
}
$deactDone = 0;

if ($deact) {
    echo str_repeat('─', 100) . "\n";
    printf("Dezactivări: %d\n", count($deact));

    $qState = $bs->prepare(
        "SELECT pr.reference, ps.active, ps.visibility, pl.name
         FROM ps_product pr
         JOIN ps_product_shop ps ON ps.id_product = pr.id_product AND ps.id_shop = 1
         LEFT JOIN ps_product_lang pl ON pl.id_product = pr.id_product AND pl.id_shop = 1 AND pl.id_lang = 1
         WHERE pr.id_product = :id"
    );

    foreach ($deact as $id) {
        $qState->execute([':id' => $id]);
        $s = $qState->fetch(PDO::FETCH_ASSOC);
        if (!$s) {
            printf("  ! %-8d nu există — sărit\n", $id);
            $skipped++;
            continue;
        }
        // „Deja inactiv" NU înseamnă în siguranță: cât timp are rânduri de furnizor,
        // cronul îl poate re-activa (produs inactiv cu stoc 0 → „Caz 2"). Sărim doar
        // dacă e inactiv ȘI fără furnizor, adică deja consolidat.
        $qSuppliers->execute([':id' => $id]);
        $supRows = $qSuppliers->fetchAll(PDO::FETCH_ASSOC);
        if ((int) $s['active'] === 0 && !$supRows) {
            printf("  · %-8d %-13s deja dezactivat definitiv\n", $id, (string) $s['reference']);
            continue;
        }

        printf("  %s %-8d %-13s %s%s\n", $apply ? '✓' : '·', $id, (string) $s['reference'],
            (int) $s['active'] === 0 ? '[consolidare] ' : '',
            mb_substr((string) ($s['name'] ?? ''), 0, 46));

        if (!$apply) {
            $deactDone++;
            continue;
        }
        try {
            $bs->beginTransaction();

            // Doar `active = 0` NU rezistă: cronul re-activează produsele inactive cu
            // stoc 0 și brand permis (cron.php, „Caz 2"). Ștergerea rândurilor de furnizor
            // face ca regula DISABLE_NO_SUPPLIER_PRODUCTS să-l țină dezactivat — ea are
            // prioritate în fața re-activării.
            foreach ($supRows as $sr) {
                $rollback[] = sprintf(
                    "INSERT INTO ps_product_supplier (id_product_supplier,id_product,id_product_attribute,id_supplier,product_supplier_reference,product_supplier_price_te,id_currency)"
                    . " VALUES (%d,%d,%d,%d,'%s',%s,%d);",
                    (int) $sr['id_product_supplier'], $id, (int) $sr['id_product_attribute'], (int) $sr['id_supplier'],
                    str_replace("'", "''", (string) $sr['product_supplier_reference']),
                    (string) $sr['product_supplier_price_te'], (int) $sr['id_currency']
                );
            }
            $dSupplier->execute([':id' => $id]);

            $uRetireP->execute([':id' => $id]);
            $uRetireS->execute([':id' => $id]);
            $rollback[] = sprintf(
                "UPDATE ps_product SET active=1 WHERE id_product=%d;\n"
                . "UPDATE ps_product_shop SET active=%d, visibility='%s' WHERE id_product=%d AND id_shop=1;",
                $id, (int) $s['active'], (string) $s['visibility'], $id
            );
            $qEnqueue->execute([':id' => $id]);
            $bs->commit();
            $logLines[] = sprintf('%s  DEZACTIVAT id=%d ref=%s', date('c'), $id, (string) $s['reference']);
            $deactDone++;
        } catch (Throwable $e) {
            if ($bs->inTransaction()) {
                $bs->rollBack();
            }
            printf("  ! %-8d EROARE: %s\n", $id, $e->getMessage());
            $skipped++;
        }
    }
}

echo str_repeat('─', 100) . "\n";
printf("  %s: %d corectate, %d dezactivate   sărite: %d\n",
    $apply ? 'aplicate' : 'de aplicat', $done, $deactDone, $skipped);

if ($apply && $logLines) {
    @mkdir($root . '/storage/logs', 0775, true);
    file_put_contents($root . '/storage/logs/dup473_apply.log', implode("\n", $logLines) . "\n", FILE_APPEND);

    // Fișier separat per rulare: unul cumulativ ar anula, la re-aplicare, și corecțiile vechi.
    $rbFile = sprintf('%s/storage/dup473_rollback_%s.sql', $root, date('Ymd_His'));
    file_put_contents($rbFile, "-- Rollback pentru rularea din " . date('c') . "\n" . implode("\n", $rollback) . "\n");
    echo "  log      : storage/logs/dup473_apply.log\n";
    echo "  rollback : " . basename($rbFile) . " (în storage/)\n";
    echo "\n  Nu uita: golește cache-ul PrestaShop (var/cache) și reindexează căutarea.\n";
} elseif (!$apply) {
    echo "\n  Dry-run. Rulează din nou cu --apply.\n";
}
