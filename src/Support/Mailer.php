<?php

declare(strict_types=1);

namespace App\Support;

use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Mailer for My Garage OTP codes and dealer notifications.
 *
 * In dev (or when SMTP is not configured) it APPENDS the message to
 * storage/logs/mail.log instead of sending — so the whole login flow is testable
 * without a mail server. When SMTP_HOST is set it sends over SMTP using PHPMailer
 * (TLS/SSL + AUTH). Every send is also persisted to `email_log`.
 */
final class Mailer
{
    /** @param array<string,mixed> $cfg the 'mail' settings array */
    public function __construct(private array $cfg, private string $logDir, private bool $devLog = false, private ?\PDO $pdo = null) {}

    /**
     * Send (or log) a plain-text email. Returns true on success/logged. Every
     * call is persisted to `email_log` (admin Messages), tagged with $context.
     */
    public function send(string $to, string $subject, string $body, string $context = ''): bool
    {
        $ok = true;
        $status = 'sent';
        if ($this->devLog || empty($this->cfg['smtp_host'])) {
            $ok = $this->logMail($to, $subject, $body);
            $status = 'logged';
        } else {
            try {
                $ok = $this->smtpSend($to, $subject, $body);
                $status = $ok ? 'sent' : 'failed';
            } catch (Throwable $e) {
                // Never let a mail failure break the flow; log it and fall back.
                $this->logMail($to, $subject, $body . "\n\n[SMTP ERROR] " . $e->getMessage());
                $ok = false;
                $status = 'failed';
            }
        }
        $this->persist($to, $subject, $body, $context, $status);
        return $ok;
    }

    /** Persist the email to email_log; never let it break the mail flow. */
    private function persist(string $to, string $subject, string $body, string $context, string $status): void
    {
        if (!$this->pdo instanceof \PDO) {
            return;
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO email_log (to_addr, subject, body, context, status) VALUES (:t, :s, :b, :c, :st)'
            )->execute([':t' => $to, ':s' => $subject, ':b' => $body, ':c' => $context ?: null, ':st' => $status]);
        } catch (Throwable) {
            // email_log table may not exist yet; ignore.
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
        $secure = (string) ($this->cfg['smtp_secure'] ?? 'tls');
        $from = (string) $this->cfg['from'];
        $fromName = (string) ($this->cfg['from_name'] ?? '');

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $this->cfg['smtp_host'];
        $mail->Port = (int) $this->cfg['smtp_port'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

        if (!empty($this->cfg['smtp_user'])) {
            $mail->SMTPAuth = true;
            $mail->Username = (string) $this->cfg['smtp_user'];
            $mail->Password = (string) ($this->cfg['smtp_pass'] ?? '');
        }
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;

        return $mail->send();
    }
}
