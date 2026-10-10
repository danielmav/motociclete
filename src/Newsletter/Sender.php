<?php

declare(strict_types=1);

namespace App\Newsletter;

use Throwable;

/**
 * O rulare a cozii de trimitere (chemată de cron la câteva minute): ia o tranșă din
 * campaniile active, personalizează mesajul pentru fiecare destinatar și îl dă
 * funcției de trimitere. Respectă limita pe rulare și pe 24 de ore și pune campania în
 * pauză la eșecuri repetate sau la rate prea mari de respingeri/reclamații.
 *
 * Funcția de trimitere e injectată (în producție Transport, în teste un fals).
 */
final class Sender
{
    private const CHUNK = 25;
    private const STALE_MINUTES = 15;

    /** @var callable(string,string,string,string,array<string,string>): array{ok:bool,error:string,message_id:?string} */
    private $send;
    /** @var callable(): void|null */
    private $pause;
    private string $siteUrl;

    /**
     * @param callable(string,string,string,string,array<string,string>): array{ok:bool,error:string,message_id:?string} $send
     * @param callable(): void|null $pause pauza dintre două mesaje (limita de viteză a releului)
     */
    public function __construct(
        private Sends $sends,
        private Tracking $tracking,
        callable $send,
        string $siteUrl,
        ?callable $pause = null
    ) {
        $this->send    = $send;
        $this->pause   = $pause;
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    /**
     * @return array{sent:int,failed:int,retry:int,skipped:int,stale:int,paused:array<int,string>,finished:array<int,int>,day_limit:bool}
     */
    public function run(int $perRun, int $perDay): array
    {
        $report = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'stale' => 0,
                   'paused' => [], 'finished' => [], 'day_limit' => false];
        $report['stale'] = $this->sends->releaseStale(self::STALE_MINUTES);

        $left = max(0, $perDay) - $this->sends->sentLast24h();
        if ($left <= 0) {
            $report['day_limit'] = true;
        }
        $budget = min(max(0, $perRun), max(0, $left));

        foreach ($this->sends->activeCampaigns() as $c) {
            $id = (int) $c['id'];
            if ($reason = $this->sends->health($id)) {
                $this->sends->pause($id, $reason);
                $report['paused'][$id] = $reason;
                continue;
            }
            if ($budget > 0) {
                $budget = $this->campaign($c, $budget, $report);
            }
            if ($this->sends->finishIfDone($id)) {
                $report['finished'][] = $id;
            }
        }
        return $report;
    }

    /**
     * Headerele cerute de Gmail/Yahoo pentru dezabonarea cu un clic (RFC 8058).
     * @return array<string,string>
     */
    public static function headers(string $unsubUrl): array
    {
        return [
            'List-Unsubscribe'      => '<' . $unsubUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $report
     * @return int bugetul rămas
     */
    private function campaign(array $c, int $budget, array &$report): int
    {
        $id   = (int) $c['id'];
        $html = Links::tracked((string) $c['html'], $this->tracking->register($id, (string) $c['html']), $this->siteUrl . '/nl/c');
        $text = (string) ($c['body_text'] ?? '');
        $view = $this->siteUrl . '/newsletter/c/' . $id . '-' . $c['view_key'];
        $tried = [];

        while ($budget > 0) {
            $rows = $this->sends->batch($id, min($budget, self::CHUNK), $tried);
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $sendId  = (int) $row['id'];
                $tried[] = $sendId;
                // Starea de ACUM a abonatului, nu cea din momentul punerii la trimis.
                if ($row['email'] === null || $row['sub_status'] !== 'active' || $row['list_status'] !== 'active') {
                    $this->sends->markSkipped($sendId, 'Dezabonat sau exclus înainte de trimitere.');
                    $report['skipped']++;
                    continue;
                }
                if (!$this->sends->claim($sendId)) {
                    continue;
                }
                $this->sends->started($id);
                $budget--;

                $prefs = $this->siteUrl . '/newsletter/dezabonare/' . $row['token'];
                $unsub = $prefs . '?l=' . $c['list_key'] . '&c=' . $id;
                $vars  = [
                    'UNSUB_URL' => $unsub, 'PREFS_URL' => $prefs, 'VIEW_URL' => $view,
                    'EMAIL' => (string) $row['email'], 'TOKEN' => (string) $row['token'],
                ];
                try {
                    $result = ($this->send)(
                        (string) $row['email'],
                        (string) $c['subject'],
                        Renderer::personalize($html, $vars),
                        Renderer::personalize($text, $vars, false),
                        self::headers($unsub)
                    );
                } catch (Throwable $e) {
                    $result = ['ok' => false, 'error' => $e->getMessage(), 'message_id' => null];
                }

                if ($result['ok']) {
                    $this->sends->markSent($sendId, $result['message_id'] ?? null);
                    $this->sends->failStreak($id, false);
                    $report['sent']++;
                } else {
                    $state = $this->sends->markRetry($sendId, (string) ($result['error'] ?? ''));
                    $report[$state === 'failed' ? 'failed' : 'retry']++;
                    if ($this->sends->failStreak($id, true) >= Sends::FAIL_STREAK_PAUSE) {
                        $reason = Sends::FAIL_STREAK_PAUSE . ' de trimiteri eșuate la rând. Ultima eroare: ' . ($result['error'] ?? '');
                        $this->sends->pause($id, $reason);
                        $report['paused'][$id] = $reason;
                        return $budget;
                    }
                }
                if ($budget <= 0) {
                    break;
                }
                if ($this->pause !== null) {
                    ($this->pause)();
                }
            }
            // Pauză pusă între timp din admin sau de un răspuns al releului.
            if (!$this->sends->isActive($id)) {
                break;
            }
        }
        return $budget;
    }
}
