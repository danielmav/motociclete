<?php

declare(strict_types=1);

namespace App\Accessories;

use App\Database;
use PDO;
use Throwable;

/**
 * Auditul referințelor din categoria 473 de pe BikerShop (accesorii/piese Yamaha).
 *
 * Problema: multe produse au `reference` TRUNCHIAT la 10 caractere în loc de codul
 * complet Yamaha de 12 (cauza: App\Accessories\Importer tăia necondiționat ultimele
 * 2 caractere, presupunând că sunt cod de mărime). Sufixul lipsă NU e mereu „00" —
 * ex. BR8-HIPER-KT-10 — iar `BR8HIPERKT00` e un produs cu totul diferit.
 *
 * Sursa de adevăr = tabela locală `yamaha_catalog` (snapshot al catalogului public
 * Yamaha), nu ghicirea sufixului.
 *
 * Clasa doar CITEȘTE. Scrierea trăiește în database/apply_dup_ref_473.php.
 */
final class RefAudit
{
    /** Categoria BikerShop cu accesoriile/piesele Yamaha curatate (imagini + descrieri). */
    public const CAT_MAIN = 473;

    /** Categoria în care un script separat a adăugat produse „goale" cu cod + preț corecte. */
    public const CAT_EMPTY = 2545;

    /** Cota de TVA folosită de BikerShop pentru aceste produse (verificată: tax_rules_group 1). */
    public const VAT = 1.21;

    /**
     * Cursul EUR→RON aplicat prețurilor corectate.
     *
     * Atenție: în BikerShop coexistă DOUĂ populații de prețuri — produsele curatate
     * din categoria 473 sunt calculate la ~5,20 (369 produse), iar cele adăugate de
     * scriptul care a populat categoria 2545 la ~5,30 (276 produse). S-a ales 5,30
     * ca referință, deci prețurile vechi din 473 cresc cu ~1,9%.
     * Mediana reală se calculează oricum și se afișează în raport, ca abaterile să fie vizibile.
     */
    public const RATE = 5.30;

    private ?PDO $bs;
    private ?PDO $local;
    private string $baseUrl;

    public function __construct(Database $db, array $bsCfg)
    {
        try {
            $this->bs = $db->bikershop();
        } catch (Throwable) {
            $this->bs = null;
        }
        try {
            $this->local = $db->local();
        } catch (Throwable) {
            $this->local = null;
        }
        $this->baseUrl = rtrim((string) ($bsCfg['base_url'] ?? 'https://bikershop.ro'), '/');
    }

    public function isAvailable(): bool
    {
        return $this->bs instanceof PDO && $this->local instanceof PDO;
    }

    /**
     * Construiește auditul complet.
     *
     * @param float|null $rateOverride curs EUR→RON de aplicat (implicit self::RATE)
     * @return array{
     *   rate:float, rate_derived:float, rate_samples:int,
     *   rows:array<int,array<string,mixed>>,
     *   counts:array<string,int>
     * }
     */
    public function build(?float $rateOverride = null): array
    {
        $rate = $rateOverride !== null && $rateOverride > 0 ? $rateOverride : self::RATE;

        if (!$this->isAvailable()) {
            return ['rate' => $rate, 'rate_derived' => $rate, 'rate_samples' => 0, 'rows' => [], 'counts' => []];
        }

        $catalog  = $this->yamahaCatalog();
        $products = $this->productsInCategory(self::CAT_MAIN);

        // Toate SKU-urile candidate → o singură interogare pentru gemeni.
        $wanted = [];
        foreach ($products as $p) {
            foreach ($this->candidatesFor($p['reference'], $catalog) as $sku) {
                $wanted[$sku] = true;
            }
        }
        $twins = $this->productsByReferences(array_keys($wanted));

        // Mediana reală, doar ca diagnostic: arată dacă prețurile au derivat față de cursul ales.
        $derived = $this->deriveRate($products, $catalog);

        $rows   = [];
        $counts = ['A1' => 0, 'A2' => 0, 'A3' => 0, 'B' => 0, 'C' => 0, 'OK' => 0];

        foreach ($products as $p) {
            $ref   = strtoupper(trim((string) $p['reference']));
            $cands = $this->candidatesFor($ref, $catalog);

            // Produsele cu cod deja complet (12) intră doar în auditul de preț.
            if (strlen($ref) === 12) {
                $y = $catalog['by_sku'][$ref] ?? null;
                $rows[] = $this->shapeRow('OK', $p, $y, [], $twins, $rate, $catalog);
                $counts['OK']++;
                continue;
            }

            if (!$cands) {
                $rows[] = $this->shapeRow('C', $p, null, [], $twins, $rate, $catalog);
                $counts['C']++;
                continue;
            }

            if (count($cands) > 1) {
                // Ambiguu: ordonează candidații după cât de aproape e prețul lor de cel actual.
                usort($cands, function (string $a, string $b) use ($catalog, $p, $rate): int {
                    return $this->priceGap($catalog['by_sku'][$a] ?? null, $p, $rate)
                       <=> $this->priceGap($catalog['by_sku'][$b] ?? null, $p, $rate);
                });
                $rows[] = $this->shapeRow('B', $p, $catalog['by_sku'][$cands[0]] ?? null, $cands, $twins, $rate, $catalog);
                $counts['B']++;
                continue;
            }

            $sku  = $cands[0];
            $y    = $catalog['by_sku'][$sku] ?? null;
            $twin = $twins[$sku] ?? null;

            if ($twin === null) {
                $class = 'A3';                       // fără geamăn: doar corectăm codul + prețul
            } elseif ((int) $twin['imgs'] === 0) {
                $class = 'A1';                       // geamăn gol → de retras
            } else {
                $class = 'A2';                       // geamăn cu imagini → verificare manuală
            }
            $rows[] = $this->shapeRow($class, $p, $y, $cands, $twins, $rate, $catalog);
            $counts[$class]++;
        }

        return [
            'rate'         => $rate,
            'rate_derived' => $derived,
            'rate_samples' => $this->rateSamples,
            'rows'         => $rows,
            'counts'       => $counts,
        ];
    }

    private int $rateSamples = 0;

    /**
     * Duplicate în categoria 473 + produse candidate la dezactivare.
     *
     * „Duplicat" = același produs Yamaha, adică aceeași referință NORMALIZATĂ
     * (majuscule, fără cratime/spații) — așa se prind și perechile care diferă doar
     * prin scriere, ex. `2sapgf473140` vs `2SAPGF473140`.
     *
     * Se păstrează un singur produs pe grup: cel cu imagini + descriere care există
     * și la Yamaha. Restul se propun spre dezactivare. NU se propune niciodată
     * dezactivarea întregului grup — dacă niciun membru nu califică, grupul e marcat
     * pentru verificare manuală.
     *
     * @return array{groups:array<int,array<string,mixed>>,orphans:array<int,array<string,mixed>>}
     */
    public function dedupe(): array
    {
        if (!$this->isAvailable()) {
            return ['groups' => [], 'orphans' => []];
        }

        $catalog  = $this->yamahaCatalog();
        $products = $this->productsInCategory(self::CAT_MAIN);

        // Grupare pe referința normalizată; doar produsele ACTIVE contează ca duplicate.
        $groups = [];
        foreach ($products as $p) {
            if ((int) $p['active'] !== 1) {
                continue;
            }
            $key = strtoupper(trim((string) $p['reference']));
            if ($key === '') {
                continue;
            }
            $groups[$key][] = $p;
        }

        $out     = [];
        $orphans = [];

        foreach ($groups as $key => $members) {
            $onYamaha = isset($catalog['by_sku'][$key]);

            if (count($members) === 1) {
                // Grup de unul: singurul motiv de dezactivare e „fără stoc și inexistent la Yamaha".
                $m = $members[0];
                if (!$onYamaha && (int) $m['stock'] <= 0) {
                    $orphans[] = $this->shapeMember($m, $onYamaha, false, ['fără stoc și inexistent la Yamaha']);
                }
                continue;
            }

            // Cel mai bun candidat de păstrat: la Yamaha > cu imagini > cu descriere > cu stoc.
            $scored = $members;
            usort($scored, static function (array $a, array $b): int {
                $score = static fn (array $p): array => [
                    (int) $p['imgs'] > 0 ? 1 : 0,
                    (int) $p['dlen'] > 0 ? 1 : 0,
                    (int) $p['stock'] > 0 ? 1 : 0,
                    (int) $p['imgs'],
                    -(int) $p['id_product'],
                ];
                return $score($b) <=> $score($a);
            });

            $keeper    = $scored[0];
            $keeperOk  = $onYamaha && (int) $keeper['imgs'] > 0 && (int) $keeper['dlen'] > 0;
            $shaped    = [];

            foreach ($scored as $i => $m) {
                $reasons = [];
                if ($i > 0) {
                    if ((int) $m['imgs'] === 0 && (int) $m['dlen'] === 0) {
                        $reasons[] = 'duplicat fără imagini și descriere';
                    }
                    if (!$onYamaha && (int) $m['stock'] <= 0) {
                        $reasons[] = 'fără stoc și inexistent la Yamaha';
                    }
                    if (!$reasons) {
                        $reasons[] = 'duplicat (păstrăm varianta completă)';
                    }
                }
                $shaped[] = $this->shapeMember($m, $onYamaha, $i === 0, $reasons);
            }

            $out[] = [
                'key'        => $key,
                'on_yamaha'  => $onYamaha,
                'yamaha'     => $catalog['by_sku'][$key] ?? null,
                'keeper_ok'  => $keeperOk,
                'members'    => $shaped,
            ];
        }

        return ['groups' => $out, 'orphans' => $orphans];
    }

    /** @return array<string,mixed> */
    private function shapeMember(array $p, bool $onYamaha, bool $isKeeper, array $reasons): array
    {
        return [
            'id'        => (int) $p['id_product'],
            'ref'       => (string) $p['reference'],
            'name'      => (string) ($p['name'] ?? ''),
            'url'       => sprintf('%s/%d-%s.html', $this->baseUrl, (int) $p['id_product'], (string) ($p['link_rewrite'] ?? '')),
            'image'     => $p['id_image']
                ? sprintf('%s/%d-home_default/%s.jpg', $this->baseUrl, (int) $p['id_image'], (string) ($p['link_rewrite'] ?? ''))
                : null,
            'price'     => (float) $p['price'],
            'imgs'      => (int) $p['imgs'],
            'dlen'      => (int) $p['dlen'],
            'stock'     => (int) $p['stock'],
            'on_yamaha' => $onYamaha,
            'keeper'    => $isKeeper,
            'reasons'   => $reasons,
        ];
    }

    /** SKU-urile Yamaha care pot corespunde unei referințe BikerShop. @return array<int,string> */
    private function candidatesFor(string $ref, array $catalog): array
    {
        // Catalogul Yamaha e normalizat cu majuscule; BikerShop are și referințe
        // scrise cu litere mici → comparăm mereu pe forma normalizată.
        $ref = strtoupper(trim($ref));
        if ($ref === '') {
            return [];
        }
        if (strlen($ref) === 12) {
            return isset($catalog['by_sku'][$ref]) ? [$ref] : [];
        }
        return $catalog['by_base'][substr($ref, 0, 10)] ?? [];
    }

    /**
     * Prețul BikerShop fără TVA care ar rezulta din prețul Yamaha.
     *
     * Se derivă din prețul brut DEJA ROTUNJIT, fiindcă exact așa procedează modulul:
     * citește valoarea cu 2 zecimale din `product_supplier_reference` și o împarte la
     * TVA. Dacă am împărți brutul nerotunjit, am obține diferențe de un ban față de ce
     * scrie cronul, iar raportul ar semnala greșit produse deja corecte.
     */
    public static function expectedPrice(float $eur, float $rate): float
    {
        return round(self::grossPrice($eur, $rate) / self::VAT, 2);
    }

    /**
     * Prețul BRUT (cu TVA) — valoarea pe care modulul supplierpricing o ține în
     * `ps_product_supplier.product_supplier_reference` și din care derivă singur
     * `ps_product.price` împărțind la SUPPLIERPRICING_VAT_RATE (= self::VAT).
     * Ăsta e singurul loc în care o schimbare de preț rezistă la următorul cron.
     */
    public static function grossPrice(float $eur, float $rate): float
    {
        return round($eur * $rate, 2);
    }

    /**
     * Rescrie un `product_supplier_reference` păstrând tot ce nu ține de preț.
     *
     * Format (ParseSupplierReference::parse): `cantitate|special_price|rrp_price`,
     * sau 5 câmpuri cu perioadă de promoție: `...|d.m.Y|d.m.Y`.
     * ⚠️ Primul câmp e STOCUL, nu un discount — cronul îl scrie în StockAvailable,
     * deci se păstrează neatins. `special` se ține egal cu `rrp`: dacă special > rrp,
     * modulul invalidează produsul (stoc 0, prețuri -1).
     *
     * @return string|null null dacă formatul nu e recunoscut (nu atingem ce nu înțelegem)
     */
    public static function rewriteSupplierReference(string $current, float $gross): ?string
    {
        $parts = explode('|', $current);
        if (count($parts) !== 3 && count($parts) !== 5) {
            return null;
        }
        $price = rtrim(rtrim(number_format($gross, 2, '.', ''), '0'), '.');
        if ($price === '' || $price === '-0') {
            return null;
        }
        $parts[1] = $price;
        $parts[2] = $price;
        return implode('|', $parts);
    }

    /** Distanța relativă între prețul actual și cel derivat din Yamaha (pentru ordonare). */
    private function priceGap(?array $y, array $p, float $rate): float
    {
        if (!$y || (float) $y['price_eur'] <= 0 || (float) $p['price'] <= 0) {
            return PHP_FLOAT_MAX;
        }
        $exp = self::expectedPrice((float) $y['price_eur'], $rate);
        return abs($exp - (float) $p['price']) / max($exp, 0.01);
    }

    /**
     * Cursul implicit median, calculat din produsele cu potrivire unică și preț valid.
     * Astfel se vede imediat dacă Yamaha sau BikerShop și-au schimbat prețurile.
     */
    private function deriveRate(array $products, array $catalog): float
    {
        $implied = [];
        foreach ($products as $p) {
            $c = $this->candidatesFor($p['reference'], $catalog);
            if (count($c) !== 1) {
                continue;
            }
            $y = $catalog['by_sku'][$c[0]] ?? null;
            if (!$y || (float) $y['price_eur'] <= 0 || (float) $p['price'] <= 0) {
                continue;
            }
            $implied[] = (float) $p['price'] * self::VAT / (float) $y['price_eur'];
        }
        $this->rateSamples = count($implied);
        if (!$implied) {
            return self::RATE;
        }
        sort($implied);
        $mid = intdiv(count($implied), 2);
        $med = count($implied) % 2 ? $implied[$mid] : ($implied[$mid - 1] + $implied[$mid]) / 2;
        return round($med, 4);
    }

    /** @return array{by_sku:array<string,array>,by_base:array<string,array<int,string>>} */
    private function yamahaCatalog(): array
    {
        $bySku = [];
        $byBase = [];
        $sql = "SELECT sku, sku_raw, sku_base, name, price_eur, image_url FROM yamaha_catalog";
        foreach ($this->local->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bySku[$r['sku']] = $r;
            $byBase[$r['sku_base']][] = $r['sku'];
        }
        return ['by_sku' => $bySku, 'by_base' => $byBase];
    }

    /**
     * Produsele dintr-o categorie BikerShop, cu tot ce trebuie pentru decizie.
     * Cover-ul vine din `ps_image_shop` (autoritar pe multistore), nu din `ps_image.cover`.
     * @return array<int,array<string,mixed>>
     */
    private function productsInCategory(int $categoryId): array
    {
        $sql = "
            SELECT pr.id_product, pr.reference, ps.active, ps.visibility,
                   COALESCE(ps.price, pr.price) AS price,
                   (SELECT t.rate FROM ps_tax_rule trl JOIN ps_tax t ON t.id_tax = trl.id_tax
                     WHERE trl.id_tax_rules_group = pr.id_tax_rules_group AND t.active = 1 LIMIT 1) AS tax_rate,
                   pl.name, pl.link_rewrite,
                   CHAR_LENGTH(COALESCE(pl.description, '')) AS dlen,
                   COALESCE(sa.quantity, 0) AS stock,
                   (SELECT COUNT(*) FROM ps_product_supplier s WHERE s.id_product = pr.id_product) AS sup_count,
                   (SELECT s.product_supplier_reference FROM ps_product_supplier s WHERE s.id_product = pr.id_product ORDER BY s.id_supplier LIMIT 1) AS sup_ref,
                   (SELECT COUNT(*) FROM ps_image i WHERE i.id_product = pr.id_product) AS imgs,
                   (SELECT ish.id_image FROM ps_image_shop ish
                     WHERE ish.id_product = pr.id_product AND ish.id_shop = 1 AND ish.cover = 1 LIMIT 1) AS id_image
            FROM ps_category_product cp
            JOIN ps_product      pr ON pr.id_product = cp.id_product
            JOIN ps_product_shop ps ON ps.id_product = pr.id_product AND ps.id_shop = 1
            LEFT JOIN ps_product_lang pl ON pl.id_product = pr.id_product AND pl.id_shop = 1 AND pl.id_lang = 1
            LEFT JOIN ps_stock_available sa ON sa.id_product = pr.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = 1
            WHERE cp.id_category = :cat AND pr.reference <> ''
            ORDER BY pr.reference";
        $st = $this->bs->prepare($sql);
        $st->execute([':cat' => $categoryId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Produsele BikerShop pentru o listă de referințe, indexate pe referință.
     * @param array<int,string> $refs
     * @return array<string,array<string,mixed>>
     */
    private function productsByReferences(array $refs): array
    {
        $refs = array_values(array_unique(array_filter($refs)));
        if (!$refs) {
            return [];
        }
        $out = [];
        foreach (array_chunk($refs, 500) as $chunk) {
            $ph  = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "
                SELECT pr.id_product, pr.reference, ps.active, ps.visibility,
                       COALESCE(ps.price, pr.price) AS price,
                       pl.name,
                       (SELECT COUNT(*) FROM ps_image i WHERE i.id_product = pr.id_product) AS imgs,
                       CHAR_LENGTH(COALESCE(pl.description, '')) AS dlen,
                       (SELECT COUNT(*) FROM ps_category_product c WHERE c.id_product = pr.id_product AND c.id_category = " . self::CAT_EMPTY . ") AS in_empty_cat,
                       (SELECT COUNT(*) FROM ps_category_product c WHERE c.id_product = pr.id_product AND c.id_category = " . self::CAT_MAIN . ") AS in_main_cat,
                       (SELECT GROUP_CONCAT(c.id_category ORDER BY c.id_category) FROM ps_category_product c WHERE c.id_product = pr.id_product) AS cats,
                       (SELECT COUNT(*) FROM ps_specific_price sp WHERE sp.id_product = pr.id_product) AS promos
                FROM ps_product      pr
                JOIN ps_product_shop ps ON ps.id_product = pr.id_product AND ps.id_shop = 1
                LEFT JOIN ps_product_lang pl ON pl.id_product = pr.id_product AND pl.id_shop = 1 AND pl.id_lang = 1
                WHERE pr.reference IN ($ph)";
            $st = $this->bs->prepare($sql);
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // Cheia se normalizează: MySQL potrivește case-insensitive, dar cheile de
                // array în PHP nu — iar BikerShop are referințe scrise cu litere mici
                // (ex. `34bf84a81000`), care altfel s-ar pierde tăcut.
                $k = strtoupper(trim((string) $r['reference']));
                if (!isset($out[$k]) || ((int) $r['active'] === 1 && (int) $out[$k]['active'] === 0)) {
                    $out[$k] = $r;
                }
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function shapeRow(string $class, array $p, ?array $y, array $cands, array $twins, float $rate, array $catalog): array
    {
        $curPrice = (float) $p['price'];
        $eur      = $y ? (float) $y['price_eur'] : 0.0;
        $expected = $eur > 0 ? self::expectedPrice($eur, $rate) : null;

        $implied = ($y && $eur > 0 && $curPrice > 0) ? round($curPrice * self::VAT / $eur, 4) : null;
        $drift   = $implied !== null ? abs($implied - $rate) / $rate : null;

        // Produsul care poartă deja codul complet e adesea chiar produsul curent
        // (clasa OK) — altfel s-ar afișa ca propriul său geamăn, arătând ca un duplicat.
        $twin = ($y && isset($twins[$y['sku']])) ? $twins[$y['sku']] : null;
        if ($twin !== null && (int) $twin['id_product'] === (int) $p['id_product']) {
            $twin = null;
        }

        $warnings = [];
        if ($y && $eur <= 0) {
            $warnings[] = 'Yamaha nu publică preț';
        }
        if ($drift !== null && $drift > 0.02) {
            $warnings[] = sprintf('curs implicit %.3f (≠ %.3f)', $implied, $rate);
        }
        if ($twin && (int) $twin['imgs'] > 0) {
            $warnings[] = 'geamănul are imagini';
        }
        if ($twin && (int) $twin['promos'] > 0) {
            $warnings[] = 'geamănul are promoții (specific_price)';
        }
        if ($twin && (int) $twin['in_main_cat'] > 0) {
            $warnings[] = 'geamănul e tot în 473';
        }
        // Prețul e durabil doar dacă îl putem scrie în sursa modulului supplierpricing.
        $supCount = (int) ($p['sup_count'] ?? 0);
        if ($supCount === 0) {
            $warnings[] = 'fără furnizor — prețul nu poate fi corectat durabil';
        } elseif ($supCount > 1) {
            $warnings[] = sprintf('%d furnizori — prețul se rezolvă manual', $supCount);
        }

        // Toți candidații, pentru clasa B (operatorul alege).
        $candRows = [];
        foreach ($cands as $sku) {
            $cy = $catalog['by_sku'][$sku] ?? null;
            if (!$cy) {
                continue;
            }
            $ceur = (float) $cy['price_eur'];
            $candRows[] = [
                'sku'       => $sku,
                'name'      => $cy['name'],
                'eur'       => $ceur,
                'expected'  => $ceur > 0 ? self::expectedPrice($ceur, $rate) : null,
                'twin'      => $twins[$sku] ?? null,
            ];
        }

        return [
            'class'    => $class,
            'id'       => (int) $p['id_product'],
            'ref'      => (string) $p['reference'],
            'name'     => (string) ($p['name'] ?? ''),
            'url'      => sprintf('%s/%d-%s.html', $this->baseUrl, (int) $p['id_product'], (string) ($p['link_rewrite'] ?? '')),
            'image'    => $p['id_image']
                ? sprintf('%s/%d-home_default/%s.jpg', $this->baseUrl, (int) $p['id_image'], (string) ($p['link_rewrite'] ?? ''))
                : null,
            'price'    => $curPrice,
            'price_vat' => round($curPrice * self::VAT, 2),
            'imgs'      => (int) $p['imgs'],
            'dlen'      => (int) $p['dlen'],
            'active'    => (int) $p['active'],
            'stock'     => (int) ($p['stock'] ?? 0),
            'sup_count' => $supCount,
            'sup_ref'   => (string) ($p['sup_ref'] ?? ''),
            'sup_new'   => ($y && $eur > 0)
                ? self::rewriteSupplierReference((string) ($p['sup_ref'] ?? ''), self::grossPrice($eur, $rate))
                : null,
            'yamaha'   => $y ? [
                'sku'      => $y['sku'],
                'sku_raw'  => $y['sku_raw'],
                'name'     => $y['name'],
                'eur'      => $eur,
                'expected' => $expected,
                'image'    => $y['image_url'] ?: null,
            ] : null,
            'twin'      => $twin,
            'candidates' => $candRows,
            'implied_rate' => $implied,
            'price_delta'  => $expected !== null ? round($expected - $curPrice, 2) : null,
            'warnings'  => $warnings,
        ];
    }
}
