<?php

declare(strict_types=1);

namespace App\Admin;

use App\Newsletter\Address;
use App\Newsletter\Repository;
use App\Newsletter\Sync;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Admin Newsletter → Abonați: totaluri pe liste și surse, căutare după email,
 * adăugare și dezabonare manuală, sincronizare la cerere din BikerShop + My Garage.
 */
final class SubscriberController extends BaseController
{
    private const PATH = '/newsletter/abonati';

    /** GET {base}/newsletter/abonati */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $q    = $request->getQueryParams();
        $term = trim((string) ($q['q'] ?? ''));
        $repo = $this->repo();
        try {
            $counts  = $repo->counts();
            $results = $term !== '' ? $repo->search($term) : [];
            $dbError = null;
        } catch (Throwable) {
            $counts  = null;
            $results = [];
            $dbError = 'Tabelele de newsletter lipsesc sau baza de date nu răspunde. Rulează database/migrate_admin.php.';
        }
        return $this->render($response, 'admin/newsletter/subscribers.twig', [
            'active'  => 'newsletter',
            'lists'   => Repository::LISTS,
            'counts'  => $counts,
            'q'       => $term,
            'results' => $results,
            'msg'     => (string) ($q['msg'] ?? ''),
            'err'     => $dbError ?? (string) ($q['err'] ?? ''),
        ]);
    }

    /** POST {base}/newsletter/abonati/adauga */
    public function add(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->back($response, 'err', 'Sesiune expirată. Reîncarcă pagina.');
        }
        $email = Address::clean(isset($body['email']) ? (string) $body['email'] : null);
        if ($email === null) {
            return $this->back($response, 'err', 'Adresă invalidă sau blocată (fictivă / de marketplace).');
        }
        $lists = array_values(array_intersect(array_keys(Repository::LISTS), (array) ($body['lists'] ?? [])));
        if (!$lists) {
            return $this->back($response, 'err', 'Alege cel puțin o listă.');
        }
        $name = trim((string) ($body['name'] ?? ''));
        try {
            $repo = $this->repo();
            $sub  = $repo->ensureSubscriber($email, $name !== '' ? $name : null, 'active');
            if (in_array($sub['status'], ['bounced', 'complained'], true)) {
                return $this->back($response, 'err', "Adresa {$email} este exclusă (mesaje respinse sau reclamație de spam) și nu poate fi adăugată.", $email);
            }
            $id = (int) $sub['id'];
            if ($sub['status'] === 'pending') {
                $repo->activate($id);
            }
            foreach ($lists as $list) {
                $repo->setSubscription($id, $list, 'manual');
            }
        } catch (Throwable) {
            return $this->back($response, 'err', 'Eroare la salvare.');
        }
        return $this->back($response, 'msg', "Adresa {$email} a fost abonată.", $email);
    }

    /** POST {base}/newsletter/abonati/{id}/dezabonare */
    public function unsubscribe(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->back($response, 'err', 'Sesiune expirată. Reîncarcă pagina.');
        }
        $list = (string) ($body['list'] ?? '');
        if (!isset(Repository::LISTS[$list])) {
            return $this->back($response, 'err', 'Listă necunoscută.');
        }
        try {
            $repo = $this->repo();
            $sub  = $repo->find((int) ($args['id'] ?? 0));
            if ($sub === null) {
                return $this->back($response, 'err', 'Abonatul nu există.');
            }
            $repo->unsubscribe((int) $sub['id'], $list);
        } catch (Throwable) {
            return $this->back($response, 'err', 'Eroare la salvare.');
        }
        return $this->back($response, 'msg', $sub['email'] . ' a fost dezabonat de la ' . Repository::LISTS[$list] . '.', (string) $sub['email']);
    }

    /** POST {base}/newsletter/abonati/sync */
    public function sync(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if (!$this->csrfOk($this->body($request))) {
            return $this->back($response, 'err', 'Sesiune expirată. Reîncarcă pagina.');
        }
        try {
            $sources = Sync::gather($this->container['bikershop'], $this->container['client']);
            $r = (new Sync($this->repo()))->run($sources, true);
        } catch (Throwable $e) {
            return $this->back($response, 'err', 'Sincronizarea a eșuat: ' . $e->getMessage());
        }
        if ($r['aborted'] !== null) {
            return $this->back($response, 'err', $r['aborted']);
        }
        return $this->back($response, 'msg', sprintf(
            'Listă actualizată: %d abonați noi, %d abonamente adăugate, %d dezabonați (bifă scoasă în BikerShop), %d adrese sărite.',
            $r['new_subscribers'],
            array_sum($r['added']),
            $r['unsubscribed'],
            $r['blocked'] + $r['invalid']
        ));
    }

    // ------------------------------------------------------------------

    private function repo(): Repository
    {
        return $this->container['newsletter'];
    }

    /** Redirect înapoi la pagină cu un mesaj (și, opțional, căutarea păstrată). */
    private function back(Response $response, string $kind, string $text, string $q = ''): Response
    {
        $query = http_build_query(array_filter([$kind => $text, 'q' => $q], static fn ($v) => $v !== ''));
        return $this->to($response, self::PATH . '?' . $query);
    }
}
