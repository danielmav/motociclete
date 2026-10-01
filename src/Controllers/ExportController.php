<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\Settings;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * GET /api/export/cfmoto?token=… — motocicletele CFMOTO ale portalului (active + inactive,
 * doar cele cu cod de produs) pentru sincronizarea BikerShop
 * (database/bikershop/sync_cfmoto_bikershop.php, rulează pe serverul BikerShop).
 *
 * Portalul = sursa de adevăr pentru BikerShop: produs activ aici = activ acolo. Prețurile în
 * lei = EUR cu TVA × cursul CFMOTO (BRD), exact ca pe portal (price_dual). Protejat cu
 * EXPORT_TOKEN (.env); fără token configurat endpoint-ul răspunde 404.
 */
final class ExportController
{
    /** @param array<string,mixed> $container */
    public function __construct(private array $container) {}

    public function cfmoto(Request $request, Response $response): Response
    {
        $token = (string) ($_ENV['EXPORT_TOKEN'] ?? '');
        $given = (string) ($request->getQueryParams()['token'] ?? $request->getHeaderLine('X-Export-Token'));
        if ($token === '' || !hash_equals($token, $given)) {
            return $response->withStatus(404);
        }

        /** @var Database $db */
        $db = $this->container['db'];
        /** @var Settings $store */
        $store = $this->container['app_settings'];
        $cur = $store->currency();
        $rate = Settings::rateForBrand($cur, 'cfmoto');
        $site = $this->container['settings']['app']['url'] . $this->container['settings']['app']['base_path'];

        try {
            $pdo = $db->local();
            $rows = $pdo->query(
                "SELECT p.id, p.name, p.slug, p.year, p.price, p.discount_pct, p.licence, p.is_active, p.cover_image,
                        p.excerpt, p.description, p.specs_engine, p.specs_chassis, p.specs_dimensions,
                        p.variants_json, p.sku, p.supplier_ref, p.updated_at, c.slug AS cat_slug
                 FROM products p JOIN categories c ON c.id = p.category_id
                 WHERE p.brand = 'cfmoto' AND p.sku IS NOT NULL AND p.sku <> ''
                 ORDER BY p.id"
            )->fetchAll(PDO::FETCH_ASSOC);
            $imgSt = $pdo->prepare("SELECT type, filename, caption FROM product_images WHERE product_id = :p ORDER BY type, position, id");
        } catch (Throwable) {
            return $this->json($response->withStatus(503), ['error' => 'db']);
        }

        $media = static fn (string $folder, string $f): string => $site . '/media/cfmoto/' . $folder . '/' . rawurlencode($f);
        $out = [];
        foreach ($rows as $r) {
            $price = (float) $r['price'];
            $pct = (float) $r['discount_pct'];
            $old = $pct > 0 ? round($price / (1 - $pct / 100)) : $price;
            $sale = price_dual($price, $cur, 'cfmoto');
            $list = price_dual($old, $cur, 'cfmoto');

            $imgSt->execute([':p' => $r['id']]);
            $colors = $gallery = [];
            foreach ($imgSt->fetchAll(PDO::FETCH_ASSOC) as $i) {
                if ($i['type'] === 'color') {
                    $colors[] = ['caption' => (string) $i['caption'], 'url' => $media('culori', $i['filename'])];
                } elseif ($i['type'] === 'gallery') {
                    $gallery[] = $media('motociclete', $i['filename']);
                }
            }

            $out[] = [
                'id'          => (int) $r['id'],
                'sku'         => (string) $r['sku'],
                'supplier_ref' => (string) $r['supplier_ref'],
                'name'        => (string) $r['name'],
                'year'        => $r['year'] ? (int) $r['year'] : null,
                'active'      => (int) $r['is_active'] === 1,
                'category'    => (string) $r['cat_slug'],
                'licence'     => $r['licence'],
                'price_eur'   => $sale['eur_raw'],
                'list_eur'    => $list['eur_raw'],
                'special_ron' => $sale['ron_raw'],
                'rrp_ron'     => $list['ron_raw'],
                'excerpt'     => (string) $r['excerpt'],
                'description' => (string) $r['description'],
                'specs'       => $this->specRows(($r['specs_engine'] ?? '') . ($r['specs_chassis'] ?? '') . ($r['specs_dimensions'] ?? '')),
                'variants'    => json_decode((string) $r['variants_json'], true) ?: [],
                'cover'       => $r['cover_image'] ? $media('cover', $r['cover_image']) : null,
                'colors'      => $colors,
                'gallery'     => $gallery,
                'url'         => $site . '/cfmoto/' . $r['cat_slug'] . '/' . $r['slug'],
                'updated_at'  => (string) $r['updated_at'],
            ];
        }
        return $this->json($response, ['generated_at' => date('c'), 'rate' => $rate, 'products' => $out]);
    }

    /** <table><tr><th>X</th><td>Y</td></tr>… → [{label, value}]. */
    private function specRows(string $html): array
    {
        preg_match_all('#<tr[^>]*>\s*<t[hd][^>]*>(.*?)</t[hd]>\s*<td[^>]*>(.*?)</td>#is', $html, $m, PREG_SET_ORDER);
        $rows = [];
        foreach ($m as $x) {
            $label = trim(html_entity_decode(strip_tags($x[1]), ENT_QUOTES, 'UTF-8'));
            $value = trim(html_entity_decode(strip_tags($x[2]), ENT_QUOTES, 'UTF-8'));
            if ($label !== '' && $value !== '') {
                $rows[] = ['label' => $label, 'value' => $value];
            }
        }
        return $rows;
    }

    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withHeader('Cache-Control', 'no-store');
    }
}
