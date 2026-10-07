<?php

declare(strict_types=1);

namespace App\Newsletter;

use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Trimite UN mesaj de newsletter (HTML + text) prin SMTP. Nu trece prin
 * Support\Mailer: acela salvează corpul fiecărui email în `email_log`.
 *
 * În dev sau fără gazdă SMTP configurată nu trimite nimic: adaugă mesajul în
 * storage/logs/newsletter.log și păstrează ultimul HTML în newsletter-last.html.
 */
final class Transport
{
    private string $lastError = '';

    /** @param array<string,mixed> $cfg blocul `newsletter` din config/settings.php */
    public function __construct(private array $cfg, private string $logDir, private bool $dev = false)
    {
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /** @param array<string,string> $headers headere suplimentare (ex. List-Unsubscribe) */
    public function send(string $to, string $subject, string $html, string $text, array $headers = []): bool
    {
        $this->lastError = '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'Adresă de email invalidă.';
            return false;
        }
        foreach (array_merge([$subject], array_keys($headers), array_values($headers)) as $value) {
            if (preg_match('/[\r\n]/', (string) $value)) {
                $this->lastError = 'Subiectul și headerele nu pot conține linii noi.';
                return false;
            }
        }
        if ($this->dev || empty($this->cfg['smtp_host'])) {
            return $this->log($to, $subject, $html, $text, $headers);
        }
        try {
            $m = new PHPMailer(true);
            $m->isSMTP();
            $m->Host = (string) $this->cfg['smtp_host'];
            $m->Port = (int) ($this->cfg['smtp_port'] ?? 587);
            $m->SMTPAuth = ($this->cfg['smtp_user'] ?? '') !== '';
            $m->Username = (string) ($this->cfg['smtp_user'] ?? '');
            $m->Password = (string) ($this->cfg['smtp_pass'] ?? '');
            $secure = (string) ($this->cfg['smtp_secure'] ?? '');
            $m->SMTPSecure = $secure;
            $m->SMTPAutoTLS = $secure !== '';
            $m->CharSet = 'UTF-8';
            // Quoted-printable: HTML-ul are linii lungi, iar peste 998 de caractere pe linie
            // mesajul pleacă „trimis" dar nu ajunge.
            $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $m->setFrom((string) $this->cfg['from'], (string) ($this->cfg['from_name'] ?? ''));
            if (!empty($this->cfg['reply_to'])) {
                $m->addReplyTo((string) $this->cfg['reply_to']);
            }
            $m->addAddress($to);
            foreach ($headers as $name => $value) {
                $m->addCustomHeader((string) $name, (string) $value);
            }
            $m->Subject = $subject;
            $m->isHTML(true);
            $m->Body = $html;
            $m->AltBody = $text;
            return $m->send();
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /** @param array<string,string> $headers */
    private function log(string $to, string $subject, string $html, string $text, array $headers): bool
    {
        $lines = [
            '[' . date('Y-m-d H:i:s') . '] TO: ' . $to,
            'FROM: ' . ($this->cfg['from_name'] ?? '') . ' <' . ($this->cfg['from'] ?? '') . '>',
            'SUBJECT: ' . $subject,
        ];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = str_repeat('-', 40);
        $lines[] = rtrim($text);
        $lines[] = '';
        $dir = rtrim($this->logDir, '/\\');
        if (@file_put_contents($dir . '/newsletter.log', implode("\n", $lines) . "\n", FILE_APPEND) === false) {
            $this->lastError = "Nu pot scrie în {$dir}/newsletter.log.";
            return false;
        }
        @file_put_contents($dir . '/newsletter-last.html', $html);
        return true;
    }
}
