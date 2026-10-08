<?php

declare(strict_types=1);

namespace App\Admin;

use App\Used\Repository;
use App\Used\Thumb;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin pentru vehicule rulate (`used_*`): anunțuri + mărci + categorii.
 * Un anunț expiră singur după 30 de zile; „Reactivează" îi dă încă 30.
 */
final class UsedController extends BaseController
{
    private const FLASH = [
        'salvat'      => ['ok', 'Anunțul a fost salvat.'],
        'reactivat'   => ['ok', 'Anunțul e activ din nou, pentru 30 de zile.'],
        'dezactivat'  => ['ok', 'Anunțul a fost dezactivat.'],
        'sters'       => ['ok', 'Anunțul a fost șters.'],
        'tax-ok'      => ['ok', 'Lista a fost actualizată.'],
        'tax-refuzat' => ['err', 'Numele e gol, există deja sau nu poate fi folosit (o categorie nu se poate numi „Marca" și nu poate începe cu un număr urmat de cratimă).'],
        'tax-folosit' => ['err', 'Nu se poate șterge: există anunțuri care o folosesc.'],
    ];

    private function repo(): Repository
    {
        return $this->container['used'];
    }

    /** GET {base}/rulate[?stare=active|expired|inactive] */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $q = $request->getQueryParams();
        $state = in_array($q['stare'] ?? '', ['active', 'expired', 'inactive'], true) ? (string) $q['stare'] : '';
        return $this->render($response, 'admin/used/index.twig', [
            'active'     => 'used',
            'vehicles'   => $this->repo()->adminList($state ?: null),
            'state'      => $state,
            'brands'     => $this->repo()->brands(),
            'categories' => $this->repo()->categories(),
            'flash'      => self::FLASH[(string) ($q['msg'] ?? '')] ?? null,
        ]);
    }

    /** GET {base}/rulate/{id} — formular (id 0 = anunț nou). */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id = (int) ($args['id'] ?? 0);
        $vehicle = $id > 0 ? $this->repo()->find($id) : null;
        if ($id > 0 && $vehicle === null) {
            return $this->to($response, '/rulate');
        }
        return $this->renderForm($response, $id, $vehicle, [], isset($request->getQueryParams()['ok']));
    }

    /** POST {base}/rulate/{id} */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/rulate');
        }
        $id = (int) ($args['id'] ?? 0);
        $images = array_values(array_filter(array_map(
            static fn ($f): string => basename(trim((string) $f)),
            (array) ($body['images'] ?? [])
        )));

        $data = [
            'title'            => trim((string) ($body['title'] ?? '')),
            'brand_id'         => (int) ($body['brand_id'] ?? 0),
            'category_id'      => (int) ($body['category_id'] ?? 0),
            'price_eur'        => $this->number($body['price_eur'] ?? ''),
            'year'             => $this->number($body['year'] ?? ''),
            'km'               => $this->number($body['km'] ?? ''),
            'cc'               => $this->number($body['cc'] ?? ''),
            'description_html' => trim((string) ($body['description_html'] ?? '')),
            'video'            => trim((string) ($body['video'] ?? '')) ?: null,
        ];

        $errors = $this->validate($data);
        if ($errors !== []) {
            // Valorile invalide (-1) nu se reafișează; restul formularului rămâne completat.
            $draft = array_map(static fn ($x) => $x === -1.0 ? null : $x, $data) + [
                'images' => array_map(static fn (string $f): array => ['filename' => $f], $images),
            ];
            return $this->renderForm($response->withStatus(422), $id, $draft, $errors, false);
        }
        foreach (['year', 'km', 'cc'] as $k) {
            $data[$k] = $data[$k] !== null ? (int) $data[$k] : null;
        }

        $vid = $this->repo()->save($id > 0 ? $id : null, $data, $images);
        $media = dirname(__DIR__, 2) . '/media/rulate';
        foreach ($images as $f) {
            Thumb::make($media, $f);
        }
        return $this->to($response, '/rulate/' . $vid . '?ok=1');
    }

    /** POST {base}/rulate/{id}/dezactiveaza */
    public function deactivate(Request $request, Response $response, array $args): Response
    {
        return $this->act($request, $response, $args, 'deactivate', 'dezactivat');
    }

    /** POST {base}/rulate/{id}/reactiveaza — câmpul `back=dashboard` întoarce la dashboard. */
    public function reactivate(Request $request, Response $response, array $args): Response
    {
        return $this->act($request, $response, $args, 'reactivate', 'reactivat');
    }

    /** POST {base}/rulate/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        return $this->act($request, $response, $args, 'delete', 'sters');
    }

    /** POST {base}/rulate/marca */
    public function addBrand(Request $request, Response $response): Response
    {
        return $this->taxon($request, $response, fn (array $b): bool => $this->repo()->addBrand((string) ($b['name'] ?? '')) !== null, 'tax-refuzat');
    }

    /** POST {base}/rulate/marca/{id}/delete */
    public function deleteBrand(Request $request, Response $response, array $args): Response
    {
        return $this->taxon($request, $response, fn (): bool => $this->repo()->deleteBrand((int) ($args['id'] ?? 0)), 'tax-folosit');
    }

    /** POST {base}/rulate/categorie */
    public function addCategory(Request $request, Response $response): Response
    {
        return $this->taxon($request, $response, fn (array $b): bool => $this->repo()->addCategory((string) ($b['name'] ?? '')) !== null, 'tax-refuzat');
    }

    /** POST {base}/rulate/categorie/{id}/delete */
    public function deleteCategory(Request $request, Response $response, array $args): Response
    {
        return $this->taxon($request, $response, fn (): bool => $this->repo()->deleteCategory((int) ($args['id'] ?? 0)), 'tax-folosit');
    }

    private function act(Request $request, Response $response, array $args, string $method, string $msg): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if ($this->csrfOk($body)) {
            $this->repo()->{$method}((int) ($args['id'] ?? 0));
        }
        if (($body['back'] ?? '') === 'dashboard') {
            return $this->to($response, '');
        }
        return $this->to($response, '/rulate?msg=' . $msg);
    }

    private function taxon(Request $request, Response $response, callable $do, string $failMsg): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/rulate');
        }
        return $this->to($response, '/rulate?msg=' . ($do($body) ? 'tax-ok' : $failMsg));
    }

    /** @param array<string,mixed>|null $vehicle @param list<string> $errors */
    private function renderForm(Response $response, int $id, ?array $vehicle, array $errors, bool $saved): Response
    {
        return $this->render($response, 'admin/used/form.twig', [
            'active'     => 'used',
            'id'         => $id,
            'v'          => $vehicle,
            'errors'     => $errors,
            'saved'      => $saved,
            'brands'     => $this->repo()->brands(),
            'categories' => $this->repo()->categories(),
        ]);
    }

    /** Număr nenegativ din formular („12.400", „6500,50") sau null dacă e gol; -1 = invalid. */
    private function number(mixed $raw): ?float
    {
        $s = str_replace([' ', '.'], '', trim((string) $raw));
        $s = str_replace(',', '.', $s);
        if ($s === '') {
            return null;
        }
        return is_numeric($s) && (float) $s >= 0 ? (float) $s : -1.0;
    }

    /** @param array<string,mixed> $d @return list<string> */
    private function validate(array $d): array
    {
        $e = [];
        if ($d['title'] === '') {
            $e[] = 'Titlul e obligatoriu.';
        }
        $ids = fn (array $rows): array => array_column($rows, 'id');
        if (!in_array($d['brand_id'], $ids($this->repo()->brands()), true)) {
            $e[] = 'Alege marca.';
        }
        if (!in_array($d['category_id'], $ids($this->repo()->categories()), true)) {
            $e[] = 'Alege categoria.';
        }
        foreach (['price_eur' => 'Prețul', 'km' => 'Kilometrajul', 'cc' => 'Cilindreea'] as $k => $label) {
            if ($d[$k] !== null && $d[$k] < 0) {
                $e[] = $label . ' trebuie să fie un număr pozitiv.';
            }
        }
        $maxYear = (int) date('Y') + 1;
        if ($d['year'] !== null && ($d['year'] < 1950 || $d['year'] > $maxYear)) {
            $e[] = 'Anul trebuie să fie între 1950 și ' . $maxYear . '.';
        }
        // Limitele coloanelor (INT / SMALLINT / DECIMAL(10,2)) — altfel INSERT-ul ar da 500.
        foreach (['km' => 4000000, 'cc' => 65000, 'price_eur' => 9999999] as $k => $max) {
            if ($d[$k] !== null && $d[$k] > $max) {
                $e[] = 'Valoare prea mare la ' . ['km' => 'kilometri', 'cc' => 'cilindree', 'price_eur' => 'preț'][$k] . '.';
            }
        }
        return $e;
    }
}
