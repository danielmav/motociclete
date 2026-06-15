<?php

declare(strict_types=1);

namespace App\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Admin login / logout. Passwords are verified against `admin_users`
 * (password_hash). Session key `admin_uid` marks an authenticated admin.
 */
final class AuthController extends BaseController
{
    /** GET {base}/login */
    public function loginForm(Request $request, Response $response): Response
    {
        if ((int) ($_SESSION['admin_uid'] ?? 0) > 0 && $this->currentUser() !== null) {
            return $this->to($response, '');
        }
        return $this->render($response, 'admin/login.twig', [
            'error' => isset($request->getQueryParams()['err']),
        ]);
    }

    /** POST {base}/login */
    public function login(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/login?err=1');
        }
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        try {
            $stmt = $this->pdo->prepare('SELECT id, password_hash FROM admin_users WHERE username = :u AND is_active = 1');
            $stmt->execute([':u' => $username]);
            $row = $stmt->fetch();
        } catch (Throwable) {
            $row = null;
        }

        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            return $this->to($response, '/login?err=1');
        }

        session_regenerate_id(true);
        $_SESSION['admin_uid'] = (int) $row['id'];
        try {
            $this->pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id')
                ->execute([':id' => (int) $row['id']]);
        } catch (Throwable) {
            // non-fatal
        }
        return $this->to($response, '');
    }

    /** GET {base}/logout */
    public function logout(Request $request, Response $response): Response
    {
        unset($_SESSION['admin_uid']);
        return $this->to($response, '/login');
    }
}
