<?php

declare(strict_types=1);

namespace App\Admin;

use App\Client\Repository as Client;
use App\Catalog\Repository as Catalog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * My Garage back-office: clients & their bikes, service/incident records, and an
 * appointments calendar built from `service_requests.preferred_date`. Reuses the
 * existing App\Client\Repository admin methods.
 */
final class GarageController extends BaseController
{
    private function client(): Client
    {
        return $this->container['client'];
    }

    private function catalog(): Catalog
    {
        return $this->container['catalog'];
    }

    /** GET {base}/garage */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $qp = $request->getQueryParams();
        $q = trim((string) ($qp['q'] ?? ''));
        $filters = [
            'judet'   => trim((string) ($qp['judet'] ?? '')),
            'unitate' => trim((string) ($qp['unitate'] ?? '')),
            'an'      => trim((string) ($qp['an'] ?? '')),
        ];
        $opts = $this->client()->adminBikeFilters();
        return $this->render($response, 'admin/garage/index.twig', [
            'active'  => 'garage',
            'q'       => $q,
            'filters' => $filters,
            'judete'  => $opts['judete'],
            'modele'  => $opts['modele'],
            'ani'     => $opts['ani'],
            'bikes'   => $this->client()->adminBikes($q !== '' ? $q : null, array_filter($filters)),
        ]);
    }

    /** GET {base}/garage/moto/{id} */
    public function bike(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $bike = $this->client()->adminBike((int) ($args['id'] ?? 0));
        if (!$bike) {
            $response->getBody()->write('Motocicletă inexistentă');
            return $response->withStatus(404);
        }
        $bikeId = (int) $bike['id'];
        return $this->render($response, 'admin/garage/bike.twig', [
            'active'    => 'garage',
            'bike'      => $bike,
            'products'  => $this->catalog()->adminProducts(),
            'service'   => $this->client()->serviceRecords($bikeId),
            'incidents' => $this->client()->incidents($bikeId),
            'saved'     => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** POST {base}/garage/moto/{id} */
    public function bikeSave(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->csrfOk($body) && $id > 0) {
            $action = (string) ($body['action'] ?? 'profile');
            if ($action === 'service') {
                $this->client()->addServiceRecord($id, $body);
            } elseif ($action === 'incident') {
                $this->client()->addIncident($id, $body);
            } else {
                $this->client()->updateBike($id, $body);
            }
        }
        return $this->to($response, '/garage/moto/' . $id . '?ok=1');
    }

    /** GET {base}/garage/calendar?ym=YYYY-MM */
    public function calendar(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $ym = (string) ($request->getQueryParams()['ym'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $ym = date('Y-m');
        }
        $first = $ym . '-01';
        $start = new \DateTimeImmutable($first);
        $daysInMonth = (int) $start->format('t');
        // Monday-first offset
        $lead = ((int) $start->format('N')) - 1;

        $byDay = [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT r.id, r.preferred_date, r.problem, r.status, cl.client AS owner, cl.telefon_norm, b.model_label, b.plate
                 FROM service_requests r
                 JOIN clienti cl ON cl.id = r.clienti_id
                 LEFT JOIN client_bikes b ON b.id = r.bike_id
                 WHERE r.preferred_date >= :a AND r.preferred_date < DATE_ADD(:a2, INTERVAL 1 MONTH)
                 ORDER BY r.preferred_date"
            );
            $stmt->execute([':a' => $first, ':a2' => $first]);
            foreach ($stmt->fetchAll() as $r) {
                $day = (int) date('j', strtotime((string) $r['preferred_date']));
                $byDay[$day][] = $r;
            }
        } catch (Throwable) {
            // ignore
        }

        return $this->render($response, 'admin/garage/calendar.twig', [
            'active'      => 'garage',
            'ym'          => $ym,
            'monthLabel'  => $this->roMonth($start),
            'daysInMonth' => $daysInMonth,
            'lead'        => $lead,
            'byDay'       => $byDay,
            'prev'        => $start->modify('-1 month')->format('Y-m'),
            'next'        => $start->modify('+1 month')->format('Y-m'),
            'today'       => date('Y-m-j'),
        ]);
    }

    private function roMonth(\DateTimeImmutable $d): string
    {
        $months = [1 => 'Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];
        return ($months[(int) $d->format('n')] ?? '') . ' ' . $d->format('Y');
    }
}
