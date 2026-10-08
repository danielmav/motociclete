<?php
/**
 * Jetonul cerut de formularul de abonare chiar înainte de trimitere (views/js/front.js).
 * Doar POST, ca răspunsul să nu ajungă în cache-ul LiteSpeed.
 */
class DmNewsletterGuardTokenModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            exit('{}');
        }
        $email = trim((string) Tools::getValue('email'));
        if (!Validate::isEmail($email)) {
            http_response_code(422);
            exit('{}');
        }
        exit(json_encode([
            'token' => DmNewsletterGuard::issueToken($email),
            'wait' => DmNewsletterGuard::MIN_AGE,
        ]));
    }
}
