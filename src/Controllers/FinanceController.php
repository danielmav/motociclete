<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Finance\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/** Financing-conditions page (/finantare), backed by the `finance` config row. */
final class FinanceController
{
    private Repository $finance;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->finance = $container['finance'];
    }

    public function page(Request $request, Response $response): Response
    {
        $cfg = $this->finance->config();
        return $this->twig->render($response, 'finance.twig', [
            'finance' => $cfg,
        ]);
    }
}
