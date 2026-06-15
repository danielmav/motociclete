<?php

declare(strict_types=1);

namespace App\Controllers;

use App\About\Repository as About;
use App\History\Repository as History;
use App\Support\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Public "Despre noi" page (/despre): intro + showroom gallery, team cards,
 * and the company history timeline. All content is admin-managed.
 */
final class AboutController
{
    private About $about;
    private History $history;
    private Settings $settings;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->about    = $container['about'];
        $this->history  = $container['history'];
        $this->settings = $container['app_settings'];
    }

    /** GET /despre */
    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'about.twig', [
            'heading'        => $this->settings->get('about_heading', 'Despre noi'),
            'intro_html'     => $this->settings->get('about_intro_html', ''),
            'gallery'        => $this->about->galleryUrls(),
            'team'           => $this->about->activeTeam(),
            'timeline'       => $this->history->timeline(),
            'canonical_path' => '/despre_dual_motors',
        ]);
    }
}
