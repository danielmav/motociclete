<?php

declare(strict_types=1);

namespace App\Newsletter;

use InvalidArgumentException;
use RuntimeException;

/**
 * Import unic al excluderilor din Brevo (exporturi CSV): contactele dezabonate
 * devin dezabonate pe ambele liste, cele respinse devin `bounced`. Fără acest pas
 * am scrie unor oameni care s-au dezabonat deja în Brevo.
 */
final class BrevoImport
{
    public function __construct(private Repository $repo) {}

    /**
     * Adresele normalizate și unice din coloana EMAIL.
     * @return array<int,string>
     */
    public static function emailsFromCsv(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Nu pot citi fișierul: {$path}");
        }
        $header = fgets($fh);
        if ($header === false) {
            fclose($fh);
            throw new RuntimeException('Fișier gol.');
        }
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        $sep = substr_count($header, ';') >= substr_count($header, ',') ? ';' : ',';
        $cols = array_map(
            static fn ($c): string => strtoupper(trim((string) $c)),
            str_getcsv(rtrim($header, "\r\n"), $sep)
        );
        $idx = array_search('EMAIL', $cols, true);
        if ($idx === false) {
            fclose($fh);
            throw new RuntimeException('Nu găsesc coloana EMAIL în antet: ' . implode(' | ', $cols));
        }
        $out = [];
        while (($row = fgetcsv($fh, 0, $sep)) !== false) {
            $email = Address::normalize(isset($row[$idx]) ? (string) $row[$idx] : null);
            if ($email !== null) {
                $out[$email] = true;
            }
        }
        fclose($fh);
        return array_keys($out);
    }

    /**
     * @param array<int,string> $emails adrese deja normalizate
     * @param string $as 'unsubscribed' | 'bounced'
     * @return array{total:int,created:int,updated:int}
     */
    public function run(array $emails, string $as, bool $apply): array
    {
        if (!in_array($as, ['unsubscribed', 'bounced'], true)) {
            throw new InvalidArgumentException("Tip necunoscut: {$as}");
        }
        $report = ['total' => count($emails), 'created' => 0, 'updated' => 0];
        foreach ($emails as $email) {
            $existing = $this->repo->findByEmail($email);
            $existing === null ? $report['created']++ : $report['updated']++;
            if (!$apply) {
                continue;
            }
            $sub = $existing ?? $this->repo->ensureSubscriber($email, null, 'active');
            $id  = (int) $sub['id'];
            if ($as === 'bounced') {
                $this->repo->setStatus($id, 'bounced');
                continue;
            }
            foreach (array_keys(Repository::LISTS) as $list) {
                $this->repo->suppress($id, $list, 'brevo');
            }
        }
        return $report;
    }
}
