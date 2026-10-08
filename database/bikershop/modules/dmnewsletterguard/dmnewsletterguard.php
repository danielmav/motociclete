<?php
/**
 * Protecție anti-bot pentru formularul de abonare la newsletter (ps_emailsubscription).
 *
 * În 7–8 oct. 2026 boți au abonat în mod repetat adrese străine din formularul din subsol;
 * fiecare abonare trimitea emailul cu cupon, iar respingerile au umplut căsuța magazinului.
 * Modulul respinge abonarea, prin hook-ul actionNewsletterRegistrationBefore, în trei cazuri:
 *  - câmpul-capcană (invizibil pentru oameni, pus în formular prin displayNewsletterRegistration) e completat;
 *  - lipsește jetonul cerut de JS la trimitere (semnat, legat de adresă, valabil între MIN_AGE și MAX_AGE
 *    secunde) — doar când DMNG_ENFORCE_TOKEN = 1, altfel cazul e doar notat în jurnal;
 *  - s-au abonat deja HOURLY_CAP adrese în ultima oră, pe tot magazinul (plafon de avarie).
 * Dezabonarea și bifa de newsletter din contul de client nu trec pe aici.
 *
 * Paginile sunt ținute în LiteSpeed 24h: până nu se golește cache-ul din BO, formularele vechi n-au
 * JS-ul care cere jetonul → verificarea jetonului se pornește separat, după golire
 * (install_dmnewsletterguard.php --enforce). Capcana și plafonul sunt active de la instalare.
 *
 * Sursa e versionată în repo-ul motociclete (database/bikershop/modules/); pe server
 * stă în ~/public_html/modules/dmnewsletterguard/.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class DmNewsletterGuard extends Module
{
    public const HONEYPOT = 'nl_website';
    public const TOKEN_FIELD = 'dmng_token';
    public const MIN_AGE = 2;
    public const MAX_AGE = 900;
    public const HOURLY_CAP = 10;
    public const CFG_ENFORCE = 'DMNG_ENFORCE_TOKEN';

    public const HOOKS = [
        'displayHeader',
        'displayNewsletterRegistration',
        'actionNewsletterRegistrationBefore',
    ];

    public function __construct()
    {
        $this->name = 'dmnewsletterguard';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Dual Motors';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = 'Protecție abonare newsletter';
        $this->description = 'Respinge abonările automate la newsletter: câmp-capcană, jeton cerut la trimitere și plafon pe oră.';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook(self::HOOKS)
            && Configuration::updateGlobalValue(self::CFG_ENFORCE, 0);
    }

    public function uninstall(): bool
    {
        Configuration::deleteByName(self::CFG_ENFORCE);

        return parent::uninstall();
    }

    public function hookDisplayHeader(): void
    {
        $this->context->controller->registerJavascript(
            'dmnewsletterguard-js',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 200]
        );
    }

    public function hookDisplayNewsletterRegistration(): string
    {
        // Fără display:none / type=hidden: boții care sar peste câmpurile ascunse l-ar ocoli.
        return '<div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">'
            . '<label>Website <input type="text" name="' . self::HONEYPOT . '" value="" tabindex="-1" autocomplete="off"></label>'
            . '</div>';
    }

    public function hookActionNewsletterRegistrationBefore(array $params): void
    {
        // '0' = abonare, '1' = dezabonare (constantele din ps_emailsubscription).
        if ((string) ($params['action'] ?? '') !== '0' || ($params['hookError'] ?? null) !== null) {
            return;
        }
        $email = trim((string) ($params['email'] ?? ''));

        if (trim((string) Tools::getValue(self::HONEYPOT)) !== '') {
            $this->note('capcana', $email);
            $params['hookError'] = 'Abonarea nu a putut fi validată. Reîncarcă pagina și încearcă din nou.';

            return;
        }

        if (!self::tokenValid((string) Tools::getValue(self::TOKEN_FIELD), $email)) {
            if ((int) Configuration::get(self::CFG_ENFORCE)) {
                $this->note('jeton', $email);
                $params['hookError'] = 'Abonarea nu a putut fi validată. Reîncarcă pagina și încearcă din nou.';

                return;
            }
            $this->note('jeton (doar notat)', $email);
        }

        $recent = (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT email) FROM `' . _DB_PREFIX_ . 'emailsubscription` WHERE newsletter_date_add > NOW() - INTERVAL 1 HOUR'
        );
        if ($recent >= self::HOURLY_CAP) {
            $this->note('plafon', $email);
            $params['hookError'] = 'Sunt prea multe abonări în acest moment. Te rugăm să încerci din nou peste o oră.';
        }
    }

    public static function issueToken(string $email, ?int $now = null): string
    {
        $ts = $now ?? time();

        return $ts . '.' . self::sign($ts, $email);
    }

    public static function tokenValid(string $token, string $email, ?int $now = null): bool
    {
        if (!preg_match('/^(\d{10})\.([0-9a-f]{64})$/', $token, $m)) {
            return false;
        }
        $age = ($now ?? time()) - (int) $m[1];

        return $age >= self::MIN_AGE && $age <= self::MAX_AGE && hash_equals(self::sign((int) $m[1], $email), $m[2]);
    }

    private static function sign(int $ts, string $email): string
    {
        return hash_hmac('sha256', $ts . '|' . mb_strtolower(trim($email)), _COOKIE_KEY_);
    }

    private function note(string $reason, string $email): void
    {
        $domain = strpos($email, '@') !== false ? substr($email, strrpos($email, '@') + 1) : '?';
        $line = date('Y-m-d H:i:s') . "\t" . $reason . "\t" . Tools::getRemoteAddr() . "\t@" . preg_replace('/[^a-z0-9.\-]/i', '', $domain) . "\n";
        @file_put_contents(_PS_ROOT_DIR_ . '/var/logs/dmnewsletterguard.log', $line, FILE_APPEND | LOCK_EX);
    }
}
