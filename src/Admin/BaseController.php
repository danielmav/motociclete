<?php

declare(strict_types=1);

namespace App\Admin;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

/**
 * Shared base for every admin controller: session-based auth guard, CSRF check,
 * and a render/redirect helper that injects the admin chrome (hidden base path,
 * current user, CSRF token, flash). Auth = `admin_users` table, session key
 * `admin_uid`. Keep it small — modules subclass this.
 */
abstract class BaseController
{
    protected Twig $twig;
    protected PDO $pdo;
    /** @var array<string,mixed> */
    protected array $settings;
    protected string $base;       // app base_path (e.g. /2026), for URLs
    protected string $adminBase;  // hidden admin prefix (e.g. /dm-control)
    /** @var array<string,mixed> */
    protected array $container;

    /** @param array<string,mixed> $container */
    public function __construct(Twig $twig, array $container)
    {
        $this->twig      = $twig;
        $this->container = $container;
        $this->pdo       = $container['db']->local();
        $this->settings  = $container['settings'];
        $this->base      = (string) ($this->settings['app']['base_path'] ?? '');
        $this->adminBase = (string) ($this->settings['admin']['path'] ?? '/dm-control');
    }

    /** Redirect to login when not authenticated; null when allowed. */
    protected function requireAuth(Response $response): ?Response
    {
        $uid = (int) ($_SESSION['admin_uid'] ?? 0);
        if ($uid > 0 && $this->currentUser() !== null) {
            return null;
        }
        return $this->to($response, '/login');
    }

    /** The logged-in admin user row (active), or null. Memoized. */
    protected function currentUser(): ?array
    {
        static $user = false;
        if ($user !== false) {
            return $user ?: null;
        }
        $uid = (int) ($_SESSION['admin_uid'] ?? 0);
        if ($uid <= 0) {
            return $user = null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT id, username, name FROM admin_users WHERE id = :id AND is_active = 1');
            $stmt->execute([':id' => $uid]);
            $row = $stmt->fetch();
            return $user = ($row ?: null);
        } catch (Throwable) {
            return $user = null;
        }
    }

    /** Constant-time CSRF check against the session token. */
    protected function csrfOk(array $body): bool
    {
        $token = (string) ($_SESSION['csrf'] ?? '');
        return $token !== '' && hash_equals($token, (string) ($body['_csrf'] ?? ''));
    }

    /**
     * Render an admin template with the admin chrome injected.
     * @param array<string,mixed> $data
     */
    protected function render(Response $response, string $template, array $data = []): Response
    {
        $q = [];
        // flash flags come via query (?ok / ?err)
        return $this->twig->render($response, $template, $data + [
            'admin_base'   => $this->base . $this->adminBase,
            'current_user' => $this->currentUser(),
            'csrf'         => (string) ($_SESSION['csrf'] ?? ''),
        ]);
    }

    /** 303 redirect to an admin-relative path (prefixed with base + adminBase). */
    protected function to(Response $response, string $path): Response
    {
        return $response
            ->withHeader('Location', $this->base . $this->adminBase . $path)
            ->withStatus(303);
    }

    /** Parsed POST body as array. */
    protected function body(Request $request): array
    {
        return (array) $request->getParsedBody();
    }

    /** Invalidate the file-cached mega menu after a category/product change. */
    protected function bustMenuCache(): void
    {
        $file = dirname(__DIR__, 2) . '/storage/cache/navv2.cache';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
