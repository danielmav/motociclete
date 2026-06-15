<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Content\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Public static pages (terms, privacy, about…), backed by the `pages` table and
 * served at /{slug}. Registered last so it never shadows real routes; 404s for
 * unknown slugs.
 */
final class PageController
{
    private Repository $content;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->content = $container['content'];
    }

    /** GET /{slug} */
    public function show(Request $request, Response $response, array $args): Response
    {
        $page = $this->content->pageBySlug((string) ($args['slug'] ?? ''));
        if (!$page) {
            $response->getBody()->write('Pagină inexistentă');
            return $response->withStatus(404);
        }
        return $this->twig->render($response, 'page.twig', [
            'page'           => $page,
            'canonical_path' => '/' . $page['slug'],
        ]);
    }
}
