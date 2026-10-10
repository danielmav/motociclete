<?php

declare(strict_types=1);

namespace App\Admin;

use App\Newsletter\Sends;
use App\Newsletter\Transport;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Admin Newsletter → Campanii: punerea la trimis către listă, pauza, reluarea,
 * oprirea definitivă și limitele de trimitere. Trimiterea propriu-zisă o face
 * cronul database/newsletter_send.php.
 */
final class CampaignSendController extends BaseController
{
    private const PATH = '/newsletter/campanii';
    public const DEFAULT_BATCH = 50;
    public const DEFAULT_DAILY = 200;

    /** POST {base}/newsletter/campanii/{id}/trimite */
    public function send(Request $request, Response $response, array $args): Response
    {
        return $this->action($request, $response, $args, function (int $id, array $body): array {
            if (Transport::mode($this->settings['newsletter'], $this->isDev()) === 'off') {
                return ['err', 'Trimiterea către liste nu este activată pe acest server.'];
            }
            if (($body['confirm'] ?? '') !== '1') {
                return ['err', 'Bifează confirmarea înainte de a pune campania la trimis.'];
            }
            $n = $this->sends()->enqueue($id);
            return ['msg', "Campania a fost pusă la trimis către {$n} destinatari. Mesajele pleacă în tranșe, la câteva minute."];
        });
    }

    /** POST {base}/newsletter/campanii/{id}/pauza */
    public function pause(Request $request, Response $response, array $args): Response
    {
        return $this->action($request, $response, $args, fn (int $id): array => $this->sends()->pause($id, 'Pusă în pauză manual.')
            ? ['msg', 'Campania este în pauză. Mesajele deja plecate nu pot fi oprite.']
            : ['err', 'Campania nu este în curs de trimitere.']);
    }

    /** POST {base}/newsletter/campanii/{id}/reia */
    public function resume(Request $request, Response $response, array $args): Response
    {
        return $this->action($request, $response, $args, fn (int $id): array => $this->sends()->resume($id)
            ? ['msg', 'Trimiterea a fost reluată.']
            : ['err', 'Campania nu este în pauză.']);
    }

    /** POST {base}/newsletter/campanii/{id}/opreste */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        return $this->action($request, $response, $args, function (int $id): array {
            $n = $this->sends()->cancel($id);
            return ['msg', "Campania a fost oprită definitiv. {$n} mesaje nu au mai plecat."];
        });
    }

    /** POST {base}/newsletter/campanii/limite */
    public function limits(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, self::PATH . '?err=' . rawurlencode('Sesiune expirată. Reîncarcă pagina.'));
        }
        $batch = (int) ($body['nl_batch_size'] ?? 0);
        $daily = (int) ($body['nl_daily_limit'] ?? 0);
        if ($batch < 1 || $batch > 500 || $daily < 1 || $daily > 100000) {
            return $this->to($response, self::PATH . '?err=' . rawurlencode('Limite invalide: 1–500 pe rulare, 1–100.000 pe 24 de ore.'));
        }
        $s = $this->container['app_settings'];
        $ok = $s->set('nl_batch_size', (string) $batch) && $s->set('nl_daily_limit', (string) $daily);
        return $this->to($response, self::PATH . ($ok
            ? '?msg=' . rawurlencode('Limitele au fost salvate.')
            : '?err=' . rawurlencode('Limitele nu au putut fi salvate.')));
    }

    // ------------------------------------------------------------------

    /**
     * Tiparul comun al acțiunilor pe o campanie: autentificare, CSRF, apoi mesajul
     * întors de $do (['msg'|'err', text]) ajunge pe pagina campaniei.
     * @param callable(int, array<string,mixed>): array{0:string,1:string} $do
     */
    private function action(Request $request, Response $response, array $args, callable $do): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id   = (int) ($args['id'] ?? 0);
        $back = self::PATH . '/' . $id;
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, $back . '?err=' . rawurlencode('Sesiune expirată. Reîncarcă pagina.'));
        }
        try {
            [$kind, $text] = $do($id, $body);
        } catch (Throwable $e) {
            [$kind, $text] = ['err', $e->getMessage()];
        }
        return $this->to($response, $back . '?' . $kind . '=' . rawurlencode($text));
    }

    private function sends(): Sends
    {
        return $this->container['newsletter_sends'];
    }

    private function isDev(): bool
    {
        return ($this->settings['app']['env'] ?? 'prod') === 'dev';
    }
}
