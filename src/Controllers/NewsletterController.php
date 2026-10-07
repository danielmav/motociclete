<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Newsletter\Address;
use App\Newsletter\Repository;
use App\Support\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;
use Throwable;

/**
 * Partea publică a newsletterului: abonare cu confirmare prin email, pagina de
 * preferințe/dezabonare și dezabonarea cu un clic cerută de Gmail/Yahoo.
 *
 * Linkurile publice poartă tokenul abonatului (secret, trimis doar pe email).
 * GET nu schimbă niciodată starea: filtrele de email deschid automat linkurile, așa
 * că și confirmarea, și dezabonarea se fac prin POST (buton pe pagină).
 * Confirmarea e valabilă CONFIRM_VALID_DAYS zile de la cerere și o singură dată.
 */
final class NewsletterController
{
    private const SIGNUPS_PER_IP_PER_HOUR = 5;
    private const CONFIRM_RESEND_MINUTES  = 15;
    private const CONFIRM_VALID_DAYS      = 7;
    /** Plafon pe tot situl: plasă de siguranță dacă limita pe IP e ocolită. */
    private const MAX_CONFIRMS_PER_HOUR   = 60;

    private Repository $repo;
    private Mailer $mailer;
    private \App\Newsletter\Campaigns $campaigns;
    private string $base;
    private string $siteUrl;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->repo    = $container['newsletter'];
        $this->mailer  = $container['mailer'];
        $this->campaigns = $container['newsletter_campaigns'];
        $this->base    = (string) ($container['settings']['app']['base_path'] ?? '');
        $this->siteUrl = rtrim((string) ($container['settings']['app']['url'] ?? ''), '/') . $this->base;
    }

    /** POST /api/newsletter/abonare */
    public function subscribe(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();

        // Honeypot: succes aparent pentru boți.
        if (trim((string) ($d['website'] ?? '')) !== '') {
            return $this->ok($request, $response);
        }
        if (trim((string) ($d['consent'] ?? '')) !== '1') {
            return $this->err($request, $response, 'Bifează acordul pentru a primi newsletterul.');
        }
        $lists = $this->validLists((array) ($d['lists'] ?? []));
        if (!$lists) {
            return $this->err($request, $response, 'Alege cel puțin o listă.');
        }
        $email = Address::clean(isset($d['email']) ? (string) $d['email'] : null);
        if ($email === null) {
            return $this->err($request, $response, 'Introdu o adresă de email validă.');
        }

        $ip = $this->clientIp($request);
        try {
            $sub = $this->repo->findByEmail($email);
            if ($sub === null) {
                if ($this->repo->recentSignupsFromIp($ip, 60) >= self::SIGNUPS_PER_IP_PER_HOUR) {
                    return $this->err($request, $response, 'Prea multe cereri. Încearcă din nou mai târziu.', 429);
                }
                $sub = $this->repo->ensureSubscriber($email, null, 'pending', $ip);
            }
            // Același răspuns indiferent dacă adresa exista: nu dezvăluim cine e abonat.
            // Abonamentele se schimbă abia la confirmare, din linkul primit pe email.
            // Nu scriem adreselor cu reclamație de spam; rezervarea trimiterii e atomică.
            $id = (int) $sub['id'];
            if ($sub['status'] !== 'complained'
                && $this->repo->confirmsSentSince(60) < self::MAX_CONFIRMS_PER_HOUR
                && $this->repo->claimConfirmSend($id, self::CONFIRM_RESEND_MINUTES)) {
                $this->mailer->send(
                    $email,
                    'Confirmă abonarea la newsletterul Dual Motors',
                    $this->confirmBody((string) $sub['token'], $lists),
                    'newsletter-confirm'
                );
            }
        } catch (Throwable) {
            return $this->err($request, $response, 'A apărut o eroare. Încearcă din nou.', 500);
        }

        return $this->ok($request, $response);
    }

    /** GET /newsletter/abonare — pagina de stare după un POST fără JavaScript. */
    public function signupStatus(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $error = trim((string) ($q['eroare'] ?? ''));
        return $this->status(
            $response,
            $error !== '' ? 'Abonarea nu a reușit' : 'Verifică-ți emailul',
            $error !== '' ? $error : 'Ți-am trimis un email cu un link de confirmare. Abonarea devine activă după ce apeși pe link.',
            '/newsletter/abonare'
        );
    }

    /** GET /newsletter/confirmare/{token}?l=oferte,stiri — doar afișează butonul. */
    public function confirmForm(Request $request, Response $response, array $args): Response
    {
        $sub   = $this->subscriberOr404($request, $args);
        $lists = $this->validLists(explode(',', (string) ($request->getQueryParams()['l'] ?? '')));
        if ($expired = $this->confirmRefusal($response, $sub)) {
            return $expired;
        }
        return $this->twig->render($response, 'newsletter/confirm.twig', [
            'email'          => (string) $sub['email'],
            'action'         => $this->base . '/newsletter/confirmare/' . $sub['token'] . '?l=' . implode(',', $lists),
            'names'          => array_map(static fn (string $l): string => Repository::LISTS[$l], $lists),
            'canonical_path' => '/newsletter/confirmare',
        ]);
    }

    /** POST /newsletter/confirmare/{token}?l=oferte,stiri */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        $sub   = $this->subscriberOr404($request, $args);
        $id    = (int) $sub['id'];
        $lists = $this->validLists(explode(',', (string) ($request->getQueryParams()['l'] ?? '')));
        if ($expired = $this->confirmRefusal($response, $sub)) {
            return $expired;
        }

        $this->repo->activate($id);
        foreach ($lists as $list) {
            $this->repo->setSubscription($id, $list, 'portal');
        }
        $this->repo->clearConfirm($id);

        $names = array_map(static fn (string $l): string => Repository::LISTS[$l], $lists);
        return $this->status(
            $response,
            'Abonare confirmată',
            $names
                ? 'Mulțumim! De acum primești: ' . implode(' și ', $names) . '.'
                : 'Adresa ta este confirmată.',
            '/newsletter/confirmare',
            $this->base . '/newsletter/dezabonare/' . $sub['token']
        );
    }

    /** GET /newsletter/dezabonare/{token}[?l=lista] — doar afișează. */
    public function prefs(Request $request, Response $response, array $args): Response
    {
        $sub   = $this->subscriberOr404($request, $args);
        $q     = $request->getQueryParams();
        $focus = $this->validLists([(string) ($q['l'] ?? '')])[0] ?? null;

        return $this->twig->render($response, 'newsletter/prefs.twig', [
            'email'          => (string) $sub['email'],
            'token'          => (string) $sub['token'],
            'lists'          => Repository::LISTS,
            'subs'           => $this->repo->subscriptions((int) $sub['id']),
            'focus'          => $focus,
            'saved'          => isset($q['salvat']),
            'excluded'       => in_array($sub['status'], ['bounced', 'complained'], true),
            'canonical_path' => '/newsletter/dezabonare',
        ]);
    }

    /** POST /newsletter/dezabonare/{token}[?l=lista] */
    public function prefsSave(Request $request, Response $response, array $args): Response
    {
        $sub   = $this->subscriberOr404($request, $args);
        $id    = (int) $sub['id'];
        $d     = (array) $request->getParsedBody();
        $all   = array_keys(Repository::LISTS);
        $focus = $this->validLists([(string) ($request->getQueryParams()['l'] ?? '')]);

        // Dezabonare cu un clic (RFC 8058): clientul de email trimite acest POST singur.
        if ((string) ($d['List-Unsubscribe'] ?? '') === 'One-Click') {
            foreach ($focus ?: $all as $list) {
                $this->repo->unsubscribe($id, $list);
            }
            $response->getBody()->write('OK');
            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $keep = isset($d['unsub_all']) ? [] : $this->validLists((array) ($d['keep'] ?? []));
        foreach ($all as $list) {
            if (in_array($list, $keep, true)) {
                $this->repo->setSubscription($id, $list, 'portal');
            } else {
                $this->repo->unsubscribe($id, $list);
            }
        }
        // Cine ține tokenul a primit emailul, deci adresa e a lui: o putem activa.
        if ($keep && $sub['status'] === 'pending') {
            $this->repo->activate($id);
        }

        return $response
            ->withHeader('Location', $this->base . '/newsletter/dezabonare/' . $sub['token'] . '?salvat=1')
            ->withStatus(303);
    }

    /** GET /newsletter/c/{id}-{key} — „vezi în browser" (fără blocul personal). */
    public function view(Request $request, Response $response, array $args): Response
    {
        $row = $this->campaigns->findPublic((int) ($args['id'] ?? 0), (string) ($args['key'] ?? ''));
        if ($row === null || trim((string) ($row['html'] ?? '')) === '') {
            throw new HttpNotFoundException($request);
        }
        $html = \App\Newsletter\Renderer::personalize(
            \App\Newsletter\Renderer::stripPersonal((string) $row['html']),
            [
                'VIEW_URL'  => $this->siteUrl . '/newsletter/c/' . $row['id'] . '-' . $row['view_key'],
                'UNSUB_URL' => $this->siteUrl . '/',
                'PREFS_URL' => $this->siteUrl . '/',
                'EMAIL'     => '',
            ]
        );
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    // ------------------------------------------------------------------

    /**
     * Pagina de refuz (410) când confirmarea nu mai e posibilă: cererea a expirat, linkul
     * a fost deja folosit sau adresa are o reclamație de spam. Null = se poate confirma.
     * @param array<string,mixed> $sub
     */
    private function confirmRefusal(Response $response, array $sub): ?Response
    {
        if ($sub['status'] !== 'complained' && $this->repo->confirmPending((int) $sub['id'], self::CONFIRM_VALID_DAYS)) {
            return null;
        }
        return $this->status(
            $response->withStatus(410),
            'Link expirat',
            'Acest link de confirmare a fost deja folosit sau nu mai este valabil. Dacă vrei să primești newsletterul, abonează-te din nou din subsolul paginii.',
            '/newsletter/confirmare'
        );
    }

    /** @return array<string,mixed> */
    private function subscriberOr404(Request $request, array $args): array
    {
        $sub = $this->repo->findByToken((string) ($args['token'] ?? ''));
        if ($sub === null) {
            throw new HttpNotFoundException($request);
        }
        return $sub;
    }

    /** @param array<int|string,mixed> $raw @return array<int,string> cheile de listă valide, în ordinea oficială */
    private function validLists(array $raw): array
    {
        $raw = array_map(static fn ($v): string => trim((string) $v), $raw);
        return array_values(array_filter(
            array_keys(Repository::LISTS),
            static fn (string $key): bool => in_array($key, $raw, true)
        ));
    }

    /** @param array<int,string> $lists */
    private function confirmBody(string $token, array $lists): string
    {
        $names = array_map(static fn (string $l): string => Repository::LISTS[$l], $lists);
        return implode("\n", [
            'Salut,',
            '',
            'Ai cerut să primești pe email: ' . implode(' și ', $names) . '.',
            'Confirmă abonarea apăsând pe butonul de mai jos:',
            '',
            $this->siteUrl . '/newsletter/confirmare/' . $token . '?l=' . implode(',', $lists),
            '',
            'Dacă nu ai cerut tu această abonare, ignoră mesajul: nu vei primi nimic.',
        ]);
    }

    private function status(Response $response, string $title, string $message, string $canonical, ?string $prefsUrl = null): Response
    {
        return $this->twig->render($response, 'newsletter/status.twig', [
            'title'          => $title,
            'message'        => $message,
            'prefs_url'      => $prefsUrl,
            'canonical_path' => $canonical,
        ]);
    }

    private function isAjax(Request $request): bool
    {
        return strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    private function ok(Request $request, Response $response): Response
    {
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        return $response->withHeader('Location', $this->base . '/newsletter/abonare?ok=1')->withStatus(303);
    }

    private function err(Request $request, Response $response, string $msg, int $status = 422): Response
    {
        if ($this->isAjax($request)) {
            return $this->json($response->withStatus($status), ['ok' => false, 'error' => $msg]);
        }
        return $response
            ->withHeader('Location', $this->base . '/newsletter/abonare?eroare=' . rawurlencode($msg))
            ->withStatus(303);
    }

    /**
     * IP-ul pentru limita de abonări. NU folosim X-Forwarded-For: primul element e
     * scris de client și ar ocoli limita. Situl e în spatele Cloudflare, care pune
     * IP-ul real în CF-Connecting-IP; altfel rămâne adresa conexiunii.
     */
    private function clientIp(Request $request): string
    {
        $cf = trim($request->getHeaderLine('CF-Connecting-IP'));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /** @param array<string,mixed> $payload */
    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
