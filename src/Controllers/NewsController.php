<?php

declare(strict_types=1);

namespace App\Controllers;

use App\News\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Blog ("Pe Două Roți") backed by the legacy `noutati` table via News\Repository.
 */
final class NewsController
{
    private Repository $news;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->news = $container['news'];
    }

    /** /blog — listing, paginat. */
    public function index(Request $request, Response $response): Response
    {
        $perPage = 12;
        $total   = $this->news->count();
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $page    = min($page, $pages);

        return $this->twig->render($response, 'blog/index.twig', [
            'articles' => $this->news->page($page, $perPage),
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
        ]);
    }

    /** /blog/{id}-{slug} — single article (resolved by leading id). */
    public function article(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['slug']; // leading integer of "{id}-{slug}"
        $article = $id ? $this->news->find($id) : null;
        if (!$article) {
            throw new HttpNotFoundException($request);
        }
        return $this->twig->render($response, 'blog/article.twig', [
            'a'        => $article,
            'og_image' => $article['image'],
            'more'     => $this->news->latest(6),
        ]);
    }
}
