<?php
/**
 * Informarea armonizată UE privind garanția legală de conformitate
 * (Regulamentul de punere în aplicare (UE) 2025/1960, aplicabil din 27.09.2026).
 *
 * Afișează fișierul oficial RO (color, NEMODIFICAT) într-un modal, deschis din:
 *  - bara de sus a headerului (displayNav1) — memento general pe tot magazinul;
 *  - pagina fiecărui produs (displayProductAdditionalInfo);
 *  - checkout, chiar înainte de plasarea comenzii (displayPaymentTop — randat de ets_onepagecheckout);
 *  - subsolul paginii (displayFooterAfter) — vizibil și pe mobil, unde bara de sus lipsește.
 * Fiecare declanșator e un link către imaginea oficială (merge și fără JS) și e însoțit
 * de linkul clicabil către aceeași destinație ca și codul QR (cerință din ghidul Comisiei).
 *
 * Sursa e versionată în repo-ul motociclete (database/bikershop/modules/); pe server
 * stă în ~/public_html/modules/dmlegalguarantee/.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class DmLegalGuarantee extends Module
{
    public const YOUR_EUROPE_URL = 'https://europa.eu/youreurope/garan%C8%9Bii';

    private const HOOKS = [
        'displayHeader',
        'displayNav1',
        'displayProductAdditionalInfo',
        'displayPaymentTop',
        'displayFooterAfter',
        'displayBeforeBodyClosingTag',
    ];

    public function __construct()
    {
        $this->name = 'dmlegalguarantee';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Dual Motors';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = 'Garanția legală (Reg. UE 2025/1960)';
        $this->description = 'Afișează informarea armonizată UE privind garanția legală de conformitate în header, pe produs, la checkout și în subsol.';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install(): bool
    {
        return parent::install() && $this->registerHook(self::HOOKS);
    }

    public function hookDisplayHeader(): void
    {
        $this->context->controller->registerStylesheet(
            'dmlegalguarantee-css',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 200]
        );
        $this->context->controller->registerJavascript(
            'dmlegalguarantee-js',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 200]
        );
    }

    public function hookDisplayNav1(): string
    {
        return $this->renderLink('nav');
    }

    public function hookDisplayProductAdditionalInfo(): string
    {
        return $this->renderLink('product');
    }

    public function hookDisplayPaymentTop(): string
    {
        return $this->renderLink('checkout');
    }

    public function hookDisplayFooterAfter(): string
    {
        return $this->renderLink('footer');
    }

    public function hookDisplayBeforeBodyClosingTag(): string
    {
        $this->assignCommon();

        return $this->fetch('module:' . $this->name . '/views/templates/hook/modal.tpl');
    }

    private function renderLink(string $place): string
    {
        $this->assignCommon();
        $this->context->smarty->assign('dmlg_place', $place);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/link.tpl');
    }

    private function assignCommon(): void
    {
        $this->context->smarty->assign([
            'dmlg_img' => $this->_path . 'views/img/garantia-legala-ro.png',
            'dmlg_ye_url' => self::YOUR_EUROPE_URL,
        ]);
    }
}
