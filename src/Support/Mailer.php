<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Tiny mailer for My Garage OTP codes and dealer notifications.
 *
 * In dev (or when SMTP is not configured) it APPENDS the message to
 * storage/logs/mail.log instead of sending — so the whole login flow is testable
 * without a mail server. When SMTP_HOST is set it sends over a minimal SMTP
 * client (AUTH LOGIN, optional STARTTLS). No external dependency.
 */
final class Mailer
{
    /** @param array<string,mixed> $cfg the 'mail' settings array */
    public function __construct(private array $cfg, private string $logDir, private bool $devLog = false) {}

    /** Send (or log) a plain-text email. Returns true on success/logged. */
    public function send(string $to, string $subject, string $body): bool
    {
        if ($this->devLog || empty($this->cfg['smtp_host'])) {
            return $this->logMail($to, $subject, $body);
        }
        try {
            return $this->smtpSend($to, $subject, $body);
        } catch (Throwable $e) {
            // Never let a mail failure break the flow; log it and fall back.
            $this->logMail($to, $subject, $body . "\n\n[SMTP ERROR] " . $e->getMessage());
            return false;
        }
    }

    private function logMail(string $to, string $subject, string $body): bool
    {
        $line = sprintf(
            "[%s] TO: %s\nSUBJECT: %s\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            str_repeat('-', 40),
            $body
        );
        @mkdir($this->logDir, 0775, true);
        return (bool) @file_put_contents($this->logDir . '/mail.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function smtpSend(string $to, string $subject, string $body): bool
    {
        $host = (string) $this->cfg['smtp_host'];
        $port = (int) $this->cfg['smtp_port'];
        $secure = (string) ($this->cfg['smtp_secure'] ?? 'tls');
        $from = (string) $this->cfg['from'];
        $fromName = (string) ($this->cfg['from_name'] ?? '');

        $transport = $secure === 'ssl' ? "ssl://{$host}" : $host;
        $fp = @fsockopen($transport, $port, $errno, $errstr, 15);
        if (!$fp) {
            throw new \RuntimeException("connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp): string {
            $data = '';
            while ($line = fgets($fp, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };

        $read(); // greeting
        $ehlo = 'motociclete.com.ro';
        $cmd("EHLO {$ehlo}");
        if ($secure === 'tls') {
            $cmd('STARTTLS');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS failed');
            }
            $cmd("EHLO {$ehlo}");
        }
        if (!empty($this->cfg['smtp_user'])) {
            $cmd('AUTH LOGIN');
            $cmd(base64_encode((string) $this->cfg['smtp_user']));
            $cmd(base64_encode((string) $this->cfg['smtp_pass']));
        }
        $cmd("MAIL FROM:<{$from}>");
        $cmd("RCPT TO:<{$to}>");
        $data = $cmd('DATA');
        if (strncmp($data, '354', 3) !== 0) {
            throw new \RuntimeException('DATA rejected: ' . trim($data));
        }
        $headers = "From: " . ($fromName ? "{$fromName} <{$from}>" : $from) . "\r\n"
            . "To: <{$to}>\r\n"
            . "Subject: " . $this->encodeHeader($subject) . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n";
        $message = str_replace("\n.", "\n..", str_replace("\r\n", "\n", $body));
        $message = str_replace("\n", "\r\n", $message);
        $resp = $cmd($headers . "\r\n" . $message . "\r\n.");
        $cmd('QUIT');
        fclose($fp);
        return strncmp($resp, '250', 3) === 0;
    }

    private function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s)
            ? '=?UTF-8?B?' . base64_encode($s) . '?='
            : $s;
    }
}
