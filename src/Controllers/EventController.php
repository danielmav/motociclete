<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Event\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Public events section: list (/evenimente) + single (/evenimente/{slug}).
 */
final class EventController
{
    private Repository $repo;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->repo = $container['events'];
    }

    /** GET /evenimente */
    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'events/index.twig', [
            'events'         => $this->repo->published(),
            'canonical_path' => '/evenimente',
        ]);
    }

    /** GET /evenimente/{slug} */
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        // tolerate "{id}-{slug}" or plain slug
        if (preg_match('/^\d+-(.+)$/', $slug, $m)) {
            $slug = $m[1];
        }
        $event = $this->repo->bySlug($slug);
        if (!$event) {
            $response->getBody()->write('Eveniment inexistent');
            return $response->withStatus(404);
        }
        return $this->twig->render($response, 'events/show.twig', [
            'event'          => $event,
            'canonical_path' => $event['url'],
        ]);
    }
}
