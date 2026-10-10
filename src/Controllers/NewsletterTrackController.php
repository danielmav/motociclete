<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Newsletter\Feedback;
use App\Newsletter\Repository;
use App\Newsletter\Tracking;
use App\Newsletter\Webhook\Provider;
use App\Newsletter\Webhook\Ses;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Cele două adrese publice ale newsletterului care nu afișează nimic: redirectul de
 * numărare a clicurilor și webhook-ul prin care releul anunță respingerile și
 * reclamațiile de spam.
 */
final class NewsletterTrackController
{
    private Repository $repo;
    private Tracking $tracking;
    private Feedback $feedback;
    private Provider $provider;
    private string $secret;
    /** @var callable(string): bool */
    private $fetch;

    /**
     * @param array<string,mixed> $container
     * @param callable(string): bool|null $fetch vizitează o adresă (confirmarea abonamentului la notificări)
     */
    public function __construct(array $container, ?Provider $provider = null, ?callable $fetch = null)
    {
        $this->repo     = $container['newsletter'];
        $this->tracking = $container['newsletter_tracking'];
        $this->feedback = new Feedback($container['db'], $this->repo, $container['newsletter_sends']);
        $this->provider = $provider ?? new Ses();
        $this->secret   = (string) ($container['settings']['newsletter']['webhook_secret'] ?? '');
        $this->fetch    = $fetch ?? static function (string $url): bool {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        };
    }

    /**
     * GET /nl/c/{link}/{token} — numără clicul și trimite cititorul la destinație.
     * Destinația vine doar din `nl_links`, deci adresa nu poate fi folosită ca
     * redirect către situri străine.
     */
    public function click(Request $request, Response $response, array $args): Response
    {
        $link = $this->tracking->link((int) ($args['link'] ?? 0));
        if ($link === null) {
            throw new HttpNotFoundException($request);
        }
        try {
            $sub = $this->repo->findByToken((string) ($args['token'] ?? ''));
            if ($sub !== null) {
                $this->tracking->click((int) $link['id'], (int) $sub['id']);
            }
        } catch (Throwable) {
            // Numărătoarea nu are voie să strice linkul.
        }
        return $response
            ->withHeader('Location', (string) $link['url'])
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus(302);
    }

    /** POST /api/newsletter/webhook/{secret} */
    public function webhook(Request $request, Response $response, array $args): Response
    {
        // Fără secret configurat, sau cu unul greșit, adresa nu există.
        if ($this->secret === '' || !hash_equals($this->secret, (string) ($args['secret'] ?? ''))) {
            throw new HttpNotFoundException($request);
        }
        $body   = (string) $request->getBody();
        $name   = $this->provider->name();
        $parsed = $this->provider->parse($body);
        try {
            if ($parsed['confirm_url'] !== null) {
                $ok = ($this->fetch)($parsed['confirm_url']);
                $this->feedback->log($name, $ok ? 'subscription' : 'subscription_failed', null, null, $body);
            } elseif (!$parsed['events']) {
                $this->feedback->log($name, 'unknown', null, null, $body);
            }
            foreach ($parsed['events'] as $event) {
                $result = $this->feedback->apply($event);
                // Confirmările de livrare sunt multe și nu spun nimic: fără corp în jurnal.
                $this->feedback->log(
                    $name,
                    $event['type'] . ($result === 'unknown' ? ':necunoscut' : ''),
                    $event['email'],
                    $event['message_id'],
                    $event['type'] === 'delivery' ? null : $body
                );
            }
        } catch (Throwable) {
            // Răspuns de eroare: releul reîncearcă notificarea mai târziu.
            $response->getBody()->write('error');
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
        $response->getBody()->write('OK');
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
