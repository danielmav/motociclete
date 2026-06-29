<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BikerShop\Client as BikerShop;
use App\Catalog\Repository as Catalog;
use App\Client\Repository as ClientRepo;
use App\Support\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * "My Garage" — private area for Dual Motors customers. Passwordless login via a
 * one-time code emailed to the address on file (the `clienti` table). Shows the
 * customer's motorcycles, their OEM/aftermarket parts, service history and lets
 * them request a service appointment. All bike data is maintained by the dealer.
 */
final class ClientController
{
    private const OTP_TTL = 10;          // minutes
    private const OTP_RATE_WINDOW = 15;  // minutes
    private const OTP_RATE_MAX = 5;      // codes per window per email

    private ClientRepo $repo;
    private Catalog $catalog;
    private BikerShop $bikershop;
    private Mailer $mailer;
    private string $base;
    private bool $isDev;
    /** @var array<string,mixed> */
    private array $mail;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->repo      = $container['client'];
        $this->catalog   = $container['catalog'];
        $this->bikershop = $container['bikershop'];
        $this->mailer    = $container['mailer'];
        $this->base      = (string) ($container['settings']['app']['base_path'] ?? '');
        $this->isDev     = ($container['settings']['app']['env'] ?? 'prod') === 'dev';
        $this->mail      = $container['settings']['mail'];
    }

    // -- Auth -----------------------------------------------------------------

    public function loginForm(Request $request, Response $response): Response
    {
        if ($this->currentEmail()) {
            return $this->redirect($response, '/garage');
        }
        return $this->twig->render($response, 'client/login.twig', [
            'error' => $request->getQueryParams()['e'] ?? null,
        ]);
    }

    public function sendCode(Request $request, Response $response): Response
    {
        $identifier = trim((string) (($request->getParsedBody() ?? [])['identifier'] ?? ''));
        if ($identifier === '') {
            return $this->redirect($response, '/garage/login?e=empty');
        }

        $account = $this->repo->findByLogin($identifier);
        $email = $account['has_email'] ?? false ? $account['email_norm'] : normalize_email($identifier);

        // Send a code only when we have a real account email and aren't rate-limited.
        if ($account && ($account['has_email'] ?? false)) {
            if ($this->repo->recentOtpCount($email, self::OTP_RATE_WINDOW) < self::OTP_RATE_MAX) {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $this->repo->createOtp($email, $code, self::OTP_TTL, $this->clientIp($request));
                // Dev convenience: surface the code on the verify page (no SMTP in dev).
                if ($this->isDev) {
                    $_SESSION['garage_dev_code'] = $code;
                }
                $this->mailer->send(
                    $email,
                    'Codul tău Dual Motors Garage: ' . $code,
                    "Salut,\n\nCodul tău de autentificare în Garajul Dual Motors este: {$code}\n\n"
                    . "Expiră în " . self::OTP_TTL . " minute. Dacă nu ai cerut acest cod, ignoră acest mesaj.\n\n— Dual Motors"
                );
            }
        }

        // Generic outcome (no account enumeration): always go to verify.
        $_SESSION['garage_pending'] = $email ?: $identifier;
        return $this->redirect($response, '/garage/verify');
    }

    public function verifyForm(Request $request, Response $response): Response
    {
        if ($this->currentEmail()) {
            return $this->redirect($response, '/garage');
        }
        $pending = $_SESSION['garage_pending'] ?? null;
        if (!$pending) {
            return $this->redirect($response, '/garage/login');
        }
        return $this->twig->render($response, 'client/verify.twig', [
            'masked'   => $this->maskEmail((string) $pending),
            'error'    => $request->getQueryParams()['e'] ?? null,
            'dev_code' => $this->isDev ? ($_SESSION['garage_dev_code'] ?? null) : null,
        ]);
    }

    public function verify(Request $request, Response $response): Response
    {
        $pending = $_SESSION['garage_pending'] ?? null;
        if (!$pending) {
            return $this->redirect($response, '/garage/login');
        }
        $code = preg_replace('/\D+/', '', (string) (($request->getParsedBody() ?? [])['code'] ?? '')) ?? '';
        if ($code !== '' && $this->repo->verifyOtp((string) $pending, $code)) {
            session_regenerate_id(true); // anti session-fixation
            $_SESSION['garage_email'] = (string) $pending;
            unset($_SESSION['garage_pending'], $_SESSION['garage_dev_code']);
            return $this->redirect($response, '/garage');
        }
        return $this->redirect($response, '/garage/verify?e=code');
    }

    public function logout(Request $request, Response $response): Response
    {
        $_SESSION = [];
        session_regenerate_id(true);
        return $this->redirect($response, '/garage/login');
    }

    // -- Garage ---------------------------------------------------------------

    public function dashboard(Request $request, Response $response): Response
    {
        if (!($email = $this->currentEmail())) {
            return $this->redirect($response, '/garage/login');
        }
        return $this->twig->render($response, 'client/garage.twig', [
            'bikes' => $this->repo->bikesForEmail($email),
        ]);
    }

    public function bike(Request $request, Response $response, array $args): Response
    {
        if (!($email = $this->currentEmail())) {
            return $this->redirect($response, '/garage/login');
        }
        $bike = $this->repo->bikeForEmail($email, (int) ($args['id'] ?? 0));
        if (!$bike) {
            throw new HttpNotFoundException($request);
        }

        $rel = $this->bikershop->relatedForBike((int) ($bike['bs_product_id'] ?? 0), (string) $bike['brand'], 10);

        return $this->twig->render($response, 'client/moto.twig', [
            'bike'        => $bike,
            'service'     => $this->repo->serviceRecords($bike['id']),
            'incidents'   => $this->repo->incidents($bike['id']),
            'oemParts'    => $rel['oem'],
            'accessories' => $rel['aftermarket'],
        ]);
    }

    public function serviceForm(Request $request, Response $response): Response
    {
        if (!($email = $this->currentEmail())) {
            return $this->redirect($response, '/garage/login');
        }
        return $this->twig->render($response, 'client/service.twig', [
            'bikes' => $this->repo->bikesForEmail($email),
            'sent'  => isset($request->getQueryParams()['sent']),
        ]);
    }

    public function serviceSubmit(Request $request, Response $response): Response
    {
        if (!($email = $this->currentEmail())) {
            return $this->redirect($response, '/garage/login');
        }
        $body = $request->getParsedBody() ?? [];
        $bikeId = (int) ($body['bike_id'] ?? 0);
        $date = trim((string) ($body['preferred_date'] ?? ''));
        $problem = trim((string) ($body['problem'] ?? ''));

        // Authorize: the bike must belong to this customer.
        $bike = $bikeId ? $this->repo->bikeForEmail($email, $bikeId) : null;
        if ($bike && $problem !== '') {
            $this->repo->createServiceRequest($bike['clienti_id'], $bike['id'], $date ?: null, $problem);
            $this->mailer->send(
                (string) ($this->mail['service'] ?? $this->mail['dealer']),
                'Cerere programare service — ' . $bike['model'],
                "Client: {$email}\nModel: {$bike['model']}" . ($bike['plate'] ? " ({$bike['plate']})" : '')
                . "\nData preferată: " . ($date ?: '—') . "\n\nProblemă:\n{$problem}"
            );
            return $this->redirect($response, '/garage/service?sent=1');
        }
        return $this->redirect($response, '/garage/service');
    }

    // -- Helpers --------------------------------------------------------------

    private function currentEmail(): ?string
    {
        $e = $_SESSION['garage_email'] ?? null;
        return is_string($e) && $e !== '' ? $e : null;
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', $this->base . $path)->withStatus(302);
    }

    private function clientIp(Request $request): ?string
    {
        $p = $request->getServerParams();
        return $p['REMOTE_ADDR'] ?? null;
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return 'adresa ta de email';
        }
        [$user, $domain] = explode('@', $email, 2);
        $shown = mb_substr($user, 0, 1) . str_repeat('•', max(1, mb_strlen($user) - 1));
        return $shown . '@' . $domain;
    }
}
