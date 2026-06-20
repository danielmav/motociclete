<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalog\Repository as Catalog;
use App\Event\Repository as Events;
use App\Content\Repository as Content;
use App\News\Repository as News;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * SEO plumbing: robots.txt + sitemap.xml (built live from the catalog, blog,
 * events and legal pages) and 301 redirects from the old `stiri.php?id=N` URLs.
 */
final class SeoController
{
    private Catalog $catalog;
    private News $news;
    private Events $events;
    private Content $content;
    private string $base;
    private string $appUrl;
    private string $adminPath;

    /** @param array<string,mixed> $container */
    public function __construct(array $container)
    {
        $this->catalog   = $container['catalog'];
        $this->news      = $container['news'];
        $this->events    = $container['events'];
        $this->content   = $container['content'];
        $this->base      = (string) ($container['settings']['app']['base_path'] ?? '');
        $this->appUrl    = rtrim((string) ($container['settings']['app']['url'] ?? ''), '/');
        $this->adminPath = (string) ($container['settings']['admin']['path'] ?? '/dm-control');
    }

    /** GET /robots.txt */
    public function robots(Request $request, Response $response): Response
    {
        $b = $this->base;
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: ' . $b . $this->adminPath,
            'Disallow: ' . $b . '/garage',
            'Disallow: ' . $b . '/api/',
            'Disallow: ' . $b . '/cauta',
            'Disallow: ' . $b . '/compara',
            '',
            'Sitemap: ' . $this->appUrl . $b . '/sitemap.xml',
            '',
        ];
        $response->getBody()->write(implode("\n", $lines));
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /** GET /sitemap.xml */
    public function sitemap(Request $request, Response $response): Response
    {
        $entries = [];
        // Static, high-value pages.
        foreach (['/', '/despre_dual_motors', '/service', '/accesorii', '/finantare', '/blog', '/evenimente'] as $p) {
            $entries[] = ['path' => $p, 'lastmod' => null];
        }
        // Catalog (categories then products), blog, events, legal pages.
        $entries = array_merge(
            $entries,
            $this->catalog->sitemapCategories(),
            $this->catalog->sitemapProducts(),
            $this->news->sitemapArticles(),
            array_map(static fn ($e) => ['path' => $e['url'], 'lastmod' => null], $this->events->published(200)),
            array_map(static fn ($p) => ['path' => '/' . $p['slug'], 'lastmod' => null], $this->content->activePages())
        );

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $seen = [];
        foreach ($entries as $e) {
            $loc = $this->appUrl . $this->base . $e['path'];
            if (isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $xml .= '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>';
            if (!empty($e['lastmod'])) {
                $ts = strtotime((string) $e['lastmod']);
                if ($ts !== false) {
                    $xml .= '<lastmod>' . date('Y-m-d', $ts) . '</lastmod>';
                }
            }
            $xml .= '</url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /** GET /stiri.php?id=N — 301 to the new blog URL (legacy Yamaha news). */
    public function legacyStiri(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $path = $id ? $this->news->pathForLegacyId($id) : null;
        return $response
            ->withHeader('Location', $this->base . ($path ?? '/blog'))
            ->withStatus(301);
    }
}
