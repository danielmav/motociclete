<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\BikerShop\Client as BikerShop;
use App\Client\Repository as Garage;

/**
 * Aduce abonații din sursele de încredere (conturi + footer BikerShop, proprietari
 * My Garage) în tabelele nl_*. Reguli:
 *   - adaugă doar abonamente care lipsesc; un dezabonat nu se reactivează;
 *   - un abonat bounced/complained nu primește nimic;
 *   - un cont BikerShop care nu mai are bifa pierde abonamentele cu sursa bs_account;
 *   - dacă BikerShop nu răspunde sau răspunde parțial, nu se modifică nimic.
 */
final class Sync
{
    /** Sursă → listele în care intră. */
    public const LISTS_BY_SOURCE = [
        'bs_account' => ['oferte', 'stiri'],
        'bs_footer'  => ['oferte', 'stiri'],
        'garage'     => ['stiri'],
    ];

    /** Garda de răspuns parțial se aplică de la acest număr de conturi active în sus. */
    private const GUARD_MIN = 20;

    public function __construct(private Repository $repo) {}

    /**
     * Citește sursele. BikerShop întoarce null când e indisponibil.
     * @return array{bs_account:?array,bs_footer:?array,garage:array}
     */
    public static function gather(BikerShop $bs, Garage $garage): array
    {
        return [
            'bs_account' => $bs->newsletterAccounts(),
            'bs_footer'  => $bs->newsletterFooter(),
            'garage'     => $garage->ownersWithEmail(),
        ];
    }

    /**
     * @param array{bs_account:?array,bs_footer:?array,garage:array} $sources
     * @return array{aborted:?string,new_subscribers:int,activated:int,invalid:int,blocked:int,unsubscribed:int,added:array<string,int>}
     */
    public function run(array $sources, bool $apply): array
    {
        $report = [
            'aborted' => null, 'new_subscribers' => 0, 'activated' => 0,
            'invalid' => 0, 'blocked' => 0, 'unsubscribed' => 0,
            'added' => ['bs_account' => 0, 'bs_footer' => 0, 'garage' => 0],
        ];

        foreach (['bs_account', 'bs_footer'] as $key) {
            if (!is_array($sources[$key] ?? null)) {
                $report['aborted'] = "BikerShop indisponibil (sursa {$key}); nu s-a modificat nimic.";
                return $report;
            }
        }

        // 1. Curăță: email normalizat → nume, pe fiecare sursă.
        $clean = [];
        foreach (array_keys(self::LISTS_BY_SOURCE) as $source) {
            $clean[$source] = [];
            foreach ($sources[$source] ?? [] as $row) {
                $email = Address::normalize(isset($row['email']) ? (string) $row['email'] : null);
                if ($email === null) {
                    $report['invalid']++;
                    continue;
                }
                if (Address::isBlocked($email)) {
                    $report['blocked']++;
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                $clean[$source][$email] ??= ($name !== '' ? $name : null);
            }
        }

        // 2. Gardă: un răspuns mult mai mic decât ce avem deja = date parțiale.
        $current = $this->repo->activeEmailsBySource('bs_account');
        if (count($current) >= self::GUARD_MIN && count($clean['bs_account']) < count($current) / 2) {
            $report['aborted'] = sprintf(
                'BikerShop a întors %d conturi față de %d active; pare un răspuns parțial. Nu s-a modificat nimic.',
                count($clean['bs_account']),
                count($current)
            );
            return $report;
        }

        // 3. Adaugă ce lipsește.
        $seenNew = [];
        foreach (self::LISTS_BY_SOURCE as $source => $lists) {
            foreach ($clean[$source] as $email => $name) {
                $sub = $this->repo->findByEmail($email);
                if ($sub === null) {
                    if (!isset($seenNew[$email])) {
                        $seenNew[$email] = [];
                        $report['new_subscribers']++;
                    }
                    if (!$apply) {
                        // Dry-run: numără listele pe care le-ar primi, o singură dată fiecare.
                        foreach ($lists as $list) {
                            if (!isset($seenNew[$email][$list])) {
                                $seenNew[$email][$list] = true;
                                $report['added'][$source]++;
                            }
                        }
                        continue;
                    }
                    $sub = $this->repo->ensureSubscriber($email, $name, 'active');
                } elseif ($sub['status'] === 'pending') {
                    $report['activated']++;
                    if ($apply) {
                        $this->repo->activate((int) $sub['id']);
                    }
                } elseif ($sub['status'] !== 'active') {
                    continue; // bounced / complained: exclus definitiv
                }

                $id = (int) $sub['id'];
                $existing = $apply ? [] : $this->repo->subscriptions($id);
                foreach ($lists as $list) {
                    if ($apply) {
                        if ($this->repo->addSubscription($id, $list, $source)) {
                            $report['added'][$source]++;
                        }
                    } elseif (!isset($existing[$list])) {
                        $report['added'][$source]++;
                    }
                }
            }
        }

        // 4. Conturile care nu mai au bifa în BikerShop.
        foreach ($current as $email) {
            if (array_key_exists($email, $clean['bs_account'])) {
                continue;
            }
            $report['unsubscribed']++;
            if ($apply) {
                $sub = $this->repo->findByEmail($email);
                if ($sub !== null) {
                    $this->repo->unsubscribeSource((int) $sub['id'], 'bs_account');
                }
            }
        }

        return $report;
    }
}
