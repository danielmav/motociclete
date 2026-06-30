<?php

declare(strict_types=1);

namespace App\Support;

use App\Catalog\Repository as CatalogRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

/**
 * Custom 404 handler: instead of Slim's bare error page, render a full portal
 * page (header + footer + menu) with model suggestions derived from the URL the
 * visitor tried to reach. Catches both routing 404s and HttpNotFoundException
 * thrown by controllers. Registered on the error middleware in Bootstrap.
 */
final class NotFoundHandler
{
    public function __construct(
        private Twig $twig,
        private CatalogRepository $catalog,
        private ResponseFactoryInterface $responseFactory
    ) {
    }

    /** Slim error-handler signature. */
    public function __invoke(
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): Response {
        $path   = $request->getUri()->getPath();
        $tokens = $this->tokensFromPath($path);
        $brand  = $this->detectBrand($path);

        // AND-search is strict, so try the full phrase first then progressively
        // fewer tokens until something matches (the broadest helpful set wins).
        $suggestions = [];
        $query = '';
        for ($n = count($tokens); $n >= 1 && !$suggestions; $n--) {
            $query = implode(' ', array_slice($tokens, 0, $n));
            $suggestions = $this->catalog->search($query, 8);
        }
        if (!$suggestions) {
            $query = '';
        }

        // Top up to a full row of cards with the newest models (deduped by URL).
        if (count($suggestions) < 4) {
            $byUrl = [];
            foreach ($suggestions as $card) {
                $byUrl[$card['url']] = $card;
            }
            foreach ($this->catalog->latestProducts(8) as $card) {
                if (count($byUrl) >= 8) {
                    break;
                }
                $byUrl[$card['url']] ??= $card;
            }
            $suggestions = array_values($byUrl);
        }

        $response = $this->responseFactory->createResponse(404);
        return $this->twig->render($response, 'errors/404.twig', [
            'suggestions' => $suggestions,
            'query'       => $query,
            'brand'       => $brand,
        ]);
    }

    /**
     * Meaningful search tokens from the last path segment: drop the `.html`
     * suffix, split on `-`, keep tokens with a letter (skip pure numbers and a
     * trailing year). E.g. `/yamaha/scutere/sport/nmax-125-techmax-2026`
     * → ['nmax', 'techmax']. @return array<int,string>
     */
    private function tokensFromPath(string $path): array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $last = $segments ? end($segments) : '';
        $last = preg_replace('/\.html$/i', '', $last) ?? $last;
        $parts = preg_split('/[-_]+/', $last) ?: [];

        $tokens = [];
        foreach ($parts as $p) {
            $p = trim($p);
            // Keep only tokens that contain a letter and are at least 2 chars.
            if (mb_strlen($p) >= 2 && preg_match('/\p{L}/u', $p)) {
                $tokens[] = $p;
            }
        }
        return $tokens;
    }

    /** Brand hint from the first path segment, for quick links on the 404 page. */
    private function detectBrand(string $path): ?string
    {
        $first = explode('/', trim($path, '/'))[0] ?? '';
        return in_array($first, ['yamaha', 'cfmoto'], true) ? $first : null;
    }
}
