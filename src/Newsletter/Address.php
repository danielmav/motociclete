<?php

declare(strict_types=1);

namespace App\Newsletter;

/**
 * Adrese de email pentru newsletter: normalizare + lista de adrese la care nu
 * trimitem niciodată (fictive, de marketplace, de test). Fără dependențe.
 */
final class Address
{
    private const MAX_LEN = 190;

    /** Domenii (și subdomeniile lor) la care nu trimitem. */
    private const BLOCKED_DOMAINS = ['bikershop.ro', 'emag.ro', 'tfbnw.net'];

    /** Prefixe ale părții locale generate automat (comenzi din marketplace). */
    private const BLOCKED_PREFIXES = ['guest-emag-'];

    /** Adresa în litere mici, fără spații la capete; null dacă nu e validă. */
    public static function normalize(?string $raw): ?string
    {
        $email = strtolower(trim((string) $raw));
        if ($email === '' || strlen($email) > self::MAX_LEN) {
            return null;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** @param string $email adresă deja normalizată */
    public static function isBlocked(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return true;
        }
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if (str_starts_with($local, $prefix)) {
                return true;
            }
        }
        foreach (self::BLOCKED_DOMAINS as $blocked) {
            if ($domain === $blocked || str_ends_with($domain, '.' . $blocked)) {
                return true;
            }
        }
        return false;
    }

    /** normalize() + null pentru adresele blocate. */
    public static function clean(?string $raw): ?string
    {
        $email = self::normalize($raw);
        return ($email === null || self::isBlocked($email)) ? null : $email;
    }
}
