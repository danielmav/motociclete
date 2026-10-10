<?php

declare(strict_types=1);

namespace App\Admin;

use App\Newsletter\Campaigns;
use App\Newsletter\Composer;
use App\Newsletter\Content;
use App\Newsletter\Images;
use App\Newsletter\Renderer;
use App\Newsletter\Repository;
use App\Newsletter\Sends;
use App\Newsletter\Transport;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Admin Newsletter → Campanii: compunerea unui mesaj (știri sau oferte), salvat ca
 * ciornă cu HTML-ul generat, previzualizare și trimitere de test, plus mersul și
 * rezultatele unei campanii puse la trimis (acțiunile sunt în CampaignSendController).
 */
final class CampaignController extends BaseController
{
    private const PATH = '/newsletter/campanii';
    private const DRAFT_KEY = 'nl_campaign_draft';
    private const WARN_KEY  = 'nl_campaign_warnings';

    /** GET {base}/newsletter/campanii */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $q = $request->getQueryParams();
        $sent24 = 0;
        try {
            $rows = $this->campaigns()->all();
            foreach ($rows as &$row) {
                $row['stats'] = $row['status'] !== 'draft' ? $this->sends()->stats((int) $row['id']) : null;
            }
            unset($row);
            $sent24 = $this->sends()->sentLast24h();
            $err  = (string) ($q['err'] ?? '');
        } catch (Throwable) {
            $rows = [];
            $err  = 'Tabelele de campanii lipsesc sau baza de date nu răspunde. Rulează database/migrate_admin.php.';
        }
        $s = $this->container['app_settings'];
        return $this->render($response, 'admin/newsletter/campaigns.twig', [
            'active'    => 'newsletter',
            'campaigns' => $rows,
            'lists'     => Repository::LISTS,
            'send_mode' => $this->sendMode(),
            'sent_24h'  => $sent24,
            'limits'    => [
                'batch' => $s->int('nl_batch_size', CampaignSendController::DEFAULT_BATCH),
                'daily' => $s->int('nl_daily_limit', CampaignSendController::DEFAULT_DAILY),
            ],
            'site_url'  => $this->siteUrl(),
            'msg'       => (string) ($q['msg'] ?? ''),
            'err'       => $err,
        ]);
    }

    /** GET {base}/newsletter/campanii/{id} */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id  = (int) ($args['id'] ?? 0);
        $row = $id > 0 ? $this->campaigns()->find($id) : null;
        if ($id > 0 && $row === null) {
            return $this->to($response, self::PATH . '?err=' . rawurlencode('Campania nu există.'));
        }
        // Formularul nesalvat din cauza unei erori are prioritate față de ce e în DB.
        $draft = $_SESSION[self::DRAFT_KEY][$id] ?? null;
        unset($_SESSION[self::DRAFT_KEY][$id]);
        $form = $draft ?? ($row !== null ? $this->formFromRow($row) : $this->defaults());
        $warnings = $_SESSION[self::WARN_KEY][$id] ?? [];
        unset($_SESSION[self::WARN_KEY][$id]);
        $q = $request->getQueryParams();

        return $this->view($response, $id, $row, $form, [
            'saved'    => isset($q['salvat']),
            'msg'      => (string) ($q['msg'] ?? ''),
            'error'    => (string) ($q['err'] ?? ''),
            'warnings' => $warnings,
        ]);
    }

    /** POST {base}/newsletter/campanii/{id} */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id   = (int) ($args['id'] ?? 0);
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, self::PATH . '/' . $id . '?err=' . rawurlencode('Sesiune expirată. Reîncarcă pagina.'));
        }
        $form = $this->formFrom($body);
        $campaigns = $this->campaigns();
        try {
            $row = $id > 0 ? $campaigns->find($id) : null;
            if ($id > 0 && $row === null) {
                return $this->to($response, self::PATH . '?err=' . rawurlencode('Campania nu există.'));
            }
            if ($row !== null && $row['status'] !== 'draft') {
                return $this->to($response, self::PATH . '/' . $id . '?err=' . rawurlencode('Campania a fost pusă la trimis și nu mai poate fi modificată.'));
            }
            // Întâi compunem: dacă datele sunt greșite, nu rămâne o ciornă goală în listă.
            $input = $this->inputFrom($form);
            $this->composer()->compose($form['tip'], $input, 'nl-0', $this->brand());
            if ($id === 0) {
                $id = $campaigns->create($form['lista'], $form['tip'], $form['subiect']);
            }
            // A doua compunere pune în UTM numele campaniei, care are nevoie de id.
            $name = 'nl-' . $id . '-' . substr(slugify($form['subiect']), 0, 40);
            $out  = $this->composer()->compose($form['tip'], $input, $name, $this->brand());
            $campaigns->update($id, [
                'list_key'   => $form['lista'],
                'type'       => $form['tip'],
                'subject'    => $form['subiect'],
                'preheader'  => $form['preheader'] !== '' ? $form['preheader'] : null,
                'input_json' => json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'html'       => $out['html'],
                'body_text'  => $out['text'],
            ]);
            $_SESSION[self::WARN_KEY][$id] = $out['warnings'];
            return $this->to($response, self::PATH . '/' . $id . '?salvat=1');
        } catch (Throwable $e) {
            // Păstrăm formularul completat: operatorul corectează și salvează din nou.
            $_SESSION[self::DRAFT_KEY][$id] = $form;
            return $this->to($response, self::PATH . '/' . $id . '?err=' . rawurlencode($e->getMessage()));
        }
    }

    /** GET {base}/newsletter/campanii/{id}/preview */
    public function preview(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $row = $this->campaigns()->find((int) ($args['id'] ?? 0));
        $html = $row !== null && trim((string) ($row['html'] ?? '')) !== ''
            ? Renderer::personalize((string) $row['html'], $this->vars($row, 'adresa@exemplu.ro', null))
            : '<p style="font-family:sans-serif;padding:24px">Campania nu are încă un mesaj generat. Salvează formularul.</p>';
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** POST {base}/newsletter/campanii/{id}/test */
    public function test(Request $request, Response $response, array $args): Response
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
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->to($response, $back . '?err=' . rawurlencode('Introdu o adresă de email validă pentru test.'));
        }
        $row = $this->campaigns()->find($id);
        if ($row === null || trim((string) ($row['html'] ?? '')) === '') {
            return $this->to($response, $back . '?err=' . rawurlencode('Salvează întâi campania.'));
        }
        try {
            $sub = $this->container['newsletter']->findByEmail($email);
        } catch (Throwable) {
            $sub = null;
        }
        $vars = $this->vars($row, $email, $sub);
        $transport = new Transport(
            $this->settings['newsletter'],
            dirname(__DIR__, 2) . '/storage/logs',
            ($this->settings['app']['env'] ?? 'prod') === 'dev'
        );
        $ok = $transport->send(
            $email,
            '[TEST] ' . $row['subject'],
            Renderer::personalize((string) $row['html'], $vars),
            Renderer::personalize((string) ($row['body_text'] ?? ''), $vars, false)
        );
        return $this->to($response, $back . ($ok
            ? '?msg=' . rawurlencode("Test trimis la {$email}.")
            : '?err=' . rawurlencode('Testul nu a putut fi trimis: ' . $transport->lastError())));
    }

    /** POST {base}/newsletter/campanii/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if (!$this->csrfOk($this->body($request))) {
            return $this->to($response, self::PATH . '?err=' . rawurlencode('Sesiune expirată. Reîncarcă pagina.'));
        }
        $ok = $this->campaigns()->delete((int) ($args['id'] ?? 0));
        return $this->to($response, self::PATH . ($ok
            ? '?msg=' . rawurlencode('Ciorna a fost ștearsă.')
            : '?err=' . rawurlencode('Doar ciornele pot fi șterse.')));
    }

    // ------------------------------------------------------------------

    /** @param array<string,mixed>|null $row @param array<string,mixed> $form @param array<string,mixed> $extra */
    private function view(Response $response, int $id, ?array $row, array $form, array $extra): Response
    {
        // Trimitere: destinatarii listei (ciornă) sau mersul și rezultatele campaniei.
        $sending = ['recipients' => null, 'stats' => null, 'clicks' => 0, 'top_links' => []];
        if ($row !== null) {
            try {
                if ($row['status'] === 'draft') {
                    $sending['recipients'] = $this->sends()->recipientCount((string) $row['list_key']);
                } else {
                    $sending['stats']     = $this->sends()->stats($id);
                    $sending['clicks']    = $this->container['newsletter_tracking']->uniqueClicks($id);
                    $sending['top_links'] = $this->container['newsletter_tracking']->topLinks($id);
                }
            } catch (Throwable) {
                // Pagina de compunere rămâne utilizabilă și fără tabelele de trimitere.
            }
        }
        return $this->render($response, 'admin/newsletter/campaign_form.twig', $extra + $sending + [
            'send_mode'  => $this->sendMode(),
            'active'     => 'newsletter',
            'id'         => $id,
            'campaign'   => $row,
            'form'       => $form,
            'lists'      => Repository::LISTS,
            'types'      => Content::TYPES,
            'editable'   => $row === null || $row['status'] === 'draft',
            'has_html'   => $row !== null && trim((string) ($row['html'] ?? '')) !== '',
            'public_url' => $row !== null ? $this->siteUrl() . '/newsletter/c/' . $row['id'] . '-' . $row['view_key'] : '',
            'site'       => Content::SITE,
        ]);
    }

    private function campaigns(): Campaigns
    {
        return $this->container['newsletter_campaigns'];
    }

    private function sends(): Sends
    {
        return $this->container['newsletter_sends'];
    }

    /** `live` = pleacă prin releu, `log` = doar în jurnal (dezvoltare), `off` = neactivată. */
    private function sendMode(): string
    {
        return Transport::mode($this->settings['newsletter'], ($this->settings['app']['env'] ?? 'prod') === 'dev');
    }

    private function composer(): Composer
    {
        $root = dirname(__DIR__, 2);
        return new Composer(
            Content::fromServices($this->container['catalog'], $this->container['bikershop'], $this->container['db']),
            new Renderer($root . '/templates/email/newsletter', $this->siteUrl()),
            new Images($root . '/media', $this->siteUrl())
        );
    }

    private function siteUrl(): string
    {
        return rtrim((string) ($this->settings['app']['url'] ?? ''), '/') . $this->base;
    }

    /** Datele de contact din footerul emailului (aceleași ca în footerul sitului). @return array<string,mixed> */
    private function brand(): array
    {
        $s = $this->container['app_settings'];
        return [
            'address'     => (string) $s->get('address', ''),
            'schedule'    => str_replace('|', ' · ', (string) $s->get('schedule', '')),
            'departments' => array_map(
                static fn (array $d): array => ['label' => (string) ($d['label'] ?? ''), 'phone' => (string) ($d['phone'] ?? '')],
                $this->container['content']->departments()
            ),
        ];
    }

    /**
     * Valorile marcajelor pentru previzualizare și test.
     * @param array<string,mixed> $row @param array<string,mixed>|null $subscriber
     * @return array<string,string>
     */
    private function vars(array $row, string $email, ?array $subscriber): array
    {
        $site  = $this->siteUrl();
        $prefs = $subscriber !== null ? $site . '/newsletter/dezabonare/' . $subscriber['token'] : $site . '/';
        return [
            'VIEW_URL'  => $site . '/newsletter/c/' . $row['id'] . '-' . $row['view_key'],
            'UNSUB_URL' => $subscriber !== null ? $prefs . '?l=' . $row['list_key'] : $prefs,
            'PREFS_URL' => $prefs,
            'EMAIL'     => $email,
        ];
    }

    /** @return array<string,mixed> */
    private function defaults(): array
    {
        return [
            'tip' => 'stiri', 'lista' => 'stiri', 'subiect' => '', 'preheader' => '',
            'titlu' => '', 'imagine' => '', 'imagine_url' => '', 'link' => '', 'buton' => 'Detalii', 'paragrafe' => '',
            'modele' => ['', ''], 'produse' => ['', '', '', '', '', ''], 'produse_oferte' => '',
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function formFromRow(array $row): array
    {
        $saved = json_decode((string) ($row['input_json'] ?? ''), true);
        return (is_array($saved) ? $saved : []) + ['tip' => $row['type'], 'lista' => $row['list_key'], 'subiect' => $row['subject']] + $this->defaults();
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function formFrom(array $body): array
    {
        $s = static fn (string $k): string => trim((string) ($body[$k] ?? ''));
        $list = static fn (string $k, int $n): array => array_map(
            static fn (int $i): string => trim((string) (((array) ($body[$k] ?? []))[$i] ?? '')),
            range(0, $n - 1)
        );
        $tip   = isset(Content::TYPES[$s('tip')]) ? $s('tip') : 'stiri';
        $lista = isset(Repository::LISTS[$s('lista')]) ? $s('lista') : 'stiri';
        return [
            'tip' => $tip, 'lista' => $lista,
            'subiect' => $s('subiect'), 'preheader' => $s('preheader'),
            'titlu' => $s('titlu'), 'imagine' => $s('imagine'), 'imagine_url' => $s('imagine_url'),
            'link' => $s('link'), 'buton' => $s('buton'),
            'paragrafe' => trim((string) ($body['paragrafe'] ?? '')),
            'modele' => $list('modele', 2), 'produse' => $list('produse', 6),
            'produse_oferte' => trim((string) ($body['produse_oferte'] ?? '')),
        ];
    }

    /** Formular → intrarea lui Content. @param array<string,mixed> $f @return array<string,mixed> */
    private function inputFrom(array $f): array
    {
        $filled = static fn (array $xs): array => array_values(array_filter($xs, static fn ($x): bool => trim((string) $x) !== ''));
        return [
            'subiect'   => $f['subiect'],
            'preheader' => $f['preheader'],
            'stire'     => [
                'titlu_html' => $f['titlu'],
                'imagine'    => $f['imagine'] !== '' ? $f['imagine'] : $f['imagine_url'],
                'link'       => $f['link'],
                'buton'      => $f['buton'],
                'paragrafe'  => Content::splitParagraphs($f['paragrafe']),
            ],
            'modele'  => $f['tip'] === 'stiri' ? $filled($f['modele']) : [],
            'produse' => $f['tip'] === 'stiri'
                ? $filled($f['produse'])
                : $filled(preg_split('/\R/', $f['produse_oferte']) ?: []),
        ];
    }
}
