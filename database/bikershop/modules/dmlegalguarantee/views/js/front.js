/* Garanția legală (Reg. UE 2025/1960) — deschide informarea completă la primul clic. */
(function () {
    'use strict';
    var lastFocus = null;

    function modal() { return document.querySelector('[data-dmlg-modal]'); }

    function open() {
        var m = modal();
        if (!m) { return false; }
        lastFocus = document.activeElement;
        m.hidden = false;
        document.body.classList.add('dmlg-open');
        var x = m.querySelector('.dmlg-modal__x');
        if (x) { x.focus(); }
        return true;
    }

    function close() {
        var m = modal();
        if (!m || m.hidden) { return; }
        m.hidden = true;
        document.body.classList.remove('dmlg-open');
        if (lastFocus) { lastFocus.focus(); }
    }

    // Delegare: declanșatorii pot apărea și în conținut reîncărcat prin AJAX (checkout, variante produs).
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-dmlg-open]') : null;
        if (t) {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) { return; }
            if (open()) { e.preventDefault(); }
            return;
        }
        if (e.target.closest && e.target.closest('[data-dmlg-close]')) { close(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { close(); }
    });
})();
