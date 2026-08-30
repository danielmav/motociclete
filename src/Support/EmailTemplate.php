<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Layout HTML comun pentru emailurile trimise de site (identitatea vizuală a
 * portalului: header întunecat #0E0E10 cu logo, accent roșu #E10600, conținut pe
 * alb, footer cu adresă/program/contact/social). Table-based + CSS inline ca să
 * randeze corect în Outlook/Gmail. Logo-ul e servit de pe site (nu inline).
 *
 * Folosit de Mailer (toate emailurile portalului) și duplicat, fără namespace,
 * în drive-test/includes/mail.php (modulul n-are Composer).
 */
final class EmailTemplate
{
    public const SITE_URL = 'https://www.motociclete.com.ro';
    public const LOGO_URL = self::SITE_URL . '/assets/img/email-logo.png';
    public const RED      = '#E10600';
    public const INK      = '#0E0E10';

    /**
     * Împachetează $bodyHtml (deja escapat) în layout.
     * @param array{address?:string,schedule?:string,phone?:string,email?:string,social?:array<string,string>} $brand
     */
    public static function wrap(string $title, string $bodyHtml, array $brand = [], ?string $preheader = null): string
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $address  = $brand['address']  ?: 'Șos. Pipera 48, București';
        $schedule = $brand['schedule'] ?: 'Luni–Vineri 9–18 · Sâmbătă 10–14';
        $phone    = $brand['phone']    ?? '';
        $email    = $brand['email']    ?? '';
        $social   = array_filter((array) ($brand['social'] ?? []));

        $contact = [];
        if ($phone !== '') {
            $contact[] = '<a href="tel:' . $e(preg_replace('/\s+/', '', $phone)) . '" style="color:#0E0E10;text-decoration:none;font-weight:700">' . $e($phone) . '</a>';
        }
        if ($email !== '') {
            $contact[] = '<a href="mailto:' . $e($email) . '" style="color:#0E0E10;text-decoration:none">' . $e($email) . '</a>';
        }
        $socialHtml = '';
        foreach ($social as $name => $url) {
            $socialHtml .= '<a href="' . $e($url) . '" style="color:#52525B;text-decoration:none;margin:0 6px">' . $e(ucfirst((string) $name)) . '</a>';
        }
        $pre = $preheader !== null && $preheader !== '' ? $e($preheader) : $e(strip_tags($title));

        return '<!DOCTYPE html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#F4F4F5;-webkit-text-size-adjust:100%">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#F4F4F5">' . $pre . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F4F5;padding:24px 12px">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">'
            // header
            . '<tr><td align="center" style="background:' . self::INK . ';padding:22px 24px 18px;border-radius:10px 10px 0 0">'
            . '<a href="' . self::SITE_URL . '" style="text-decoration:none"><img src="' . self::LOGO_URL . '" width="160" alt="Dual Motors" style="display:block;width:160px;height:auto;border:0"></a>'
            . '</td></tr>'
            . '<tr><td style="background:' . self::RED . ';height:4px;font-size:0;line-height:0">&nbsp;</td></tr>'
            // body
            . '<tr><td style="background:#FFFFFF;padding:28px 28px 24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#18181B">'
            . '<h1 style="margin:0 0 16px;font-size:21px;line-height:1.25;font-weight:800;color:' . self::INK . '">' . $e($title) . '</h1>'
            . $bodyHtml
            . '</td></tr>'
            // footer
            . '<tr><td style="background:#FAFAFA;border-top:1px solid #E4E4E7;padding:18px 28px 22px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#52525B;border-radius:0 0 10px 10px">'
            . '<strong style="color:' . self::INK . ';font-size:13px">Dual Motors</strong> — dealer autorizat Yamaha &amp; CFMOTO<br>'
            . $e($address) . '<br>' . $e($schedule)
            . ($contact ? '<br>' . implode(' &nbsp;·&nbsp; ', $contact) : '')
            . '<br><a href="' . self::SITE_URL . '" style="color:' . self::RED . ';text-decoration:none;font-weight:700">motociclete.com.ro</a>'
            . ($socialHtml !== '' ? '<div style="margin-top:8px">' . $socialHtml . '</div>' : '')
            . '</td></tr>'
            . '<tr><td align="center" style="padding:14px 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#A1A1AA">'
            . 'Mesaj generat automat de motociclete.com.ro. Răspunde la acest email sau sună-ne dacă ai întrebări.'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /**
     * Transformă un body text (cum trimit controllerele) în HTML: blocurile de
     * linii „Cheie: valoare" devin tabel, restul paragrafe; codurile de 6 cifre
     * (OTP) sunt evidențiate.
     */
    public static function textToHtml(string $text): string
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        $html = '';
        $rows = [];
        $flushRows = static function () use (&$rows, &$html): void {
            if ($rows) {
                $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 14px;width:100%">' . implode('', $rows) . '</table>';
                $rows = [];
            }
        };
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') { $flushRows(); continue; }
            if (preg_match('/^([^:]{1,28}):\s+(.+)$/u', $line, $m)) {
                $rows[] = '<tr><td style="padding:6px 10px;border:1px solid #E4E4E7;background:#FAFAFA;color:#52525B;white-space:nowrap;width:34%">' . $e($m[1]) . '</td>'
                    . '<td style="padding:6px 10px;border:1px solid #E4E4E7;font-weight:600">' . $e($m[2]) . '</td></tr>';
                continue;
            }
            $flushRows();
            $safe = $e($line);
            $safe = preg_replace('/\b(\d{6})\b/', '<span style="display:inline-block;padding:4px 10px;border:1px solid ' . self::RED . ';border-radius:6px;font-size:22px;letter-spacing:4px;font-weight:800;color:' . self::RED . '">$1</span>', $safe);
            $html .= '<p style="margin:0 0 12px">' . $safe . '</p>';
        }
        $flushRows();
        return $html;
    }
}
