<?php

declare(strict_types=1);

namespace App\Admin;

use App\Newsletter\Generator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Admin Newsletter: formular (știre + 2 modele + 6 produse BikerShop) → YAML pentru
 * Brevo Developer mode (App\Newsletter\Generator). Nu scrie în DB; ultimul formular
 * completat rămâne în sesiune ca să poată fi regenerat rapid.
 */
final class NewsletterController extends BaseController
{
    private const SESSION_KEY = 'newsletter_form';

    /** GET {base}/newsletter */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->view($response, $_SESSION[self::SESSION_KEY] ?? $this->defaults());
    }

    /** POST {base}/newsletter */
    public function generate(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/newsletter');
        }
        $form = $this->formFrom($body);
        $_SESSION[self::SESSION_KEY] = $form;

        $gen = $this->generator();
        try {
            $yaml = $gen->generate($this->inputFrom($form));
            return $this->view($response, $form, [
                'yaml'     => $yaml,
                'summary'  => $gen->summary(),
                'warnings' => $gen->warnings(),
            ]);
        } catch (Throwable $e) {
            return $this->view($response, $form, ['error' => $e->getMessage(), 'warnings' => $gen->warnings()]);
        }
    }

    // ------------------------------------------------------------------

    private function view(Response $response, array $form, array $extra = []): Response
    {
        return $this->render($response, 'admin/newsletter/index.twig', $extra + [
            'active'          => 'newsletter',
            'form'            => $form,
            'parts_available' => $this->generator()->partsAvailable(),
            'site'            => Generator::SITE,
        ]);
    }

    private function generator(): Generator
    {
        return new Generator(
            $this->container['catalog'],
            $this->container['bikershop'],
            $this->container['db'],
            dirname(__DIR__, 2) . '/storage/newsletter/parts',
        );
    }

    private function defaults(): array
    {
        return [
            'subiect' => '', 'titlu' => '', 'imagine' => '', 'imagine_url' => '', 'link' => '',
            'buton' => 'Detalii', 'paragrafe' => '',
            'modele' => ['', ''], 'produse' => ['', '', '', '', '', ''],
        ];
    }

    private function formFrom(array $body): array
    {
        $s = fn (string $k) => trim((string) ($body[$k] ?? ''));
        $list = fn (string $k, int $n) => array_map(
            fn ($i) => trim((string) (($body[$k] ?? [])[$i] ?? '')),
            range(0, $n - 1)
        );
        return [
            'subiect'     => $s('subiect'),
            'titlu'       => $s('titlu'),
            'imagine'     => $s('imagine'),      // upload în /media/newsletter (imgmgr)
            'imagine_url' => $s('imagine_url'),  // alternativ: URL extern
            'link'        => $s('link'),
            'buton'       => $s('buton'),
            'paragrafe'   => trim((string) ($body['paragrafe'] ?? '')),
            'modele'      => $list('modele', 2),
            'produse'     => $list('produse', 6),
        ];
    }

    private function inputFrom(array $f): array
    {
        return [
            'subiect' => $f['subiect'],
            'stire'   => [
                'titlu_html' => $f['titlu'],
                'imagine'    => $f['imagine'] !== '' ? $f['imagine'] : $f['imagine_url'],
                'link'       => $f['link'],
                'buton'      => $f['buton'],
                'paragrafe'  => Generator::splitParagraphs($f['paragrafe']),
            ],
            'modele'  => array_values(array_filter($f['modele'], fn ($x) => $x !== '')),
            'produse' => array_values(array_filter($f['produse'], fn ($x) => $x !== '')),
        ];
    }
}
