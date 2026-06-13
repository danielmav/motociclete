/* app-v2.js — interactions for the redesigned home (/index-v2).
   Drives the type-first mega menu (desktop panels + brand filter + sidebar tabs)
   and the mobile 3-screen offcanvas. The mobile offcanvas reuses the markup
   already rendered in the desktop panels (no duplicated data). Vanilla, no deps;
   gated on .js so the page degrades to stacked panes without JS. */
(function () {
    'use strict';

    var header = document.querySelector('.headerv2');
    if (!header) { return; }

    var overlay = document.querySelector('[data-megav2-overlay]');
    var panelButtons = Array.prototype.slice.call(document.querySelectorAll('[data-panel-v2]'));
    var openKey = null;

    function panelEl(key) { return document.querySelector('[data-panel-body="' + key + '"]'); }
    function buttonEl(key) { return document.querySelector('[data-panel-v2="' + key + '"]'); }

    function closePanel() {
        if (!openKey) { return; }
        var p = panelEl(openKey), b = buttonEl(openKey);
        if (p) { p.setAttribute('hidden', ''); }
        if (b) { b.setAttribute('aria-expanded', 'false'); }
        if (overlay) { overlay.setAttribute('hidden', ''); }
        openKey = null;
    }

    function openPanel(key) {
        if (openKey === key) { closePanel(); return; }
        closePanel();
        var p = panelEl(key), b = buttonEl(key);
        if (!p) { return; }
        p.removeAttribute('hidden');
        if (b) { b.setAttribute('aria-expanded', 'true'); }
        if (overlay) { overlay.removeAttribute('hidden'); }
        openKey = key;
    }

    panelButtons.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            openPanel(btn.getAttribute('data-panel-v2'));
        });
    });

    if (overlay) { overlay.addEventListener('click', closePanel); }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePanel(); } });
    document.addEventListener('click', function (e) {
        if (openKey && !e.target.closest('.megav2') && !e.target.closest('[data-panel-v2]')) { closePanel(); }
    });

    /* ---- Brand pills: filter sidebar groups + visible cards ---- */
    Array.prototype.slice.call(document.querySelectorAll('[data-brand-pill]')).forEach(function (pill) {
        pill.addEventListener('click', function (e) {
            e.stopPropagation();
            var key = pill.getAttribute('data-panel');
            var brand = pill.getAttribute('data-brand-pill');
            var panel = panelEl(key);
            if (!panel) { return; }

            panel.querySelectorAll('[data-brand-pill]').forEach(function (p) { p.classList.remove('is-active'); });
            pill.classList.add('is-active');

            panel.querySelectorAll('.stabgroup').forEach(function (g) {
                var show = brand === 'all' || g.getAttribute('data-brand-group') === brand;
                g.classList.toggle('is-hidden', !show);
            });

            // If the active tab got hidden, jump to the first visible one.
            var active = panel.querySelector('.stabv2.is-active');
            if (!active || active.closest('.stabgroup').classList.contains('is-hidden')) {
                var firstVisible = panel.querySelector('.stabgroup:not(.is-hidden) .stabv2');
                if (firstVisible) { activateStab(panel, firstVisible); }
            }
        });
    });

    /* ---- Sidebar tabs: switch panes ---- */
    function activateStab(panel, stab) {
        panel.querySelectorAll('.stabv2').forEach(function (s) { s.classList.remove('is-active'); });
        stab.classList.add('is-active');
        var id = stab.getAttribute('data-stab');
        panel.querySelectorAll('.panev2').forEach(function (pane) {
            pane.classList.toggle('is-active', pane.getAttribute('data-pane') === id);
        });
        var content = panel.querySelector('.megav2__content');
        if (content) { content.scrollTop = 0; }
    }

    Array.prototype.slice.call(document.querySelectorAll('.stabv2')).forEach(function (stab) {
        stab.addEventListener('click', function (e) {
            e.stopPropagation();
            var panel = stab.closest('.megav2');
            if (panel) { activateStab(panel, stab); }
        });
    });

    /* ======================================================================
       MOBILE OFFCANVAS — reads the already-rendered desktop panels
       ====================================================================== */
    var oc = document.querySelector('[data-ocv2]');
    var burger = document.querySelector('[data-burger-v2]');
    if (!oc || !burger) { return; }

    var ocBack = oc.querySelector('[data-ocv2-back]');
    var ocClose = oc.querySelector('[data-ocv2-close]');
    var ocCrumb = oc.querySelector('[data-ocv2-crumb]');
    var ocPills = oc.querySelector('[data-ocv2-pills]');
    var ocSubs = oc.querySelector('[data-ocv2-subcats]');
    var ocGrid = oc.querySelector('[data-ocv2-grid]');
    var ocTitle = oc.querySelector('[data-ocv2-prodtitle]');
    var depth = 1;
    var curSection = null;

    function ocOpen() { oc.removeAttribute('hidden'); requestAnimationFrame(function () { oc.classList.add('is-open'); }); burger.classList.add('is-open'); }
    function ocReset() {
        oc.classList.remove('is-open', 'at-2', 'at-3');
        burger.classList.remove('is-open');
        depth = 1; curSection = null;
        ocCrumb.textContent = 'Meniu';
        ocBack.setAttribute('hidden', '');
        setTimeout(function () { oc.setAttribute('hidden', ''); }, 280);
    }
    function ocGoTo(d) {
        depth = d;
        oc.classList.toggle('at-2', d === 2);
        oc.classList.toggle('at-3', d === 3);
        ocBack.toggleAttribute('hidden', d === 1);
    }

    burger.addEventListener('click', function () {
        if (oc.classList.contains('is-open')) { ocReset(); } else { ocOpen(); }
    });
    ocClose.addEventListener('click', ocReset);
    ocBack.addEventListener('click', function () {
        if (depth === 3) { ocGoTo(2); ocCrumb.textContent = sectionLabel(curSection); }
        else if (depth === 2) { ocGoTo(1); ocCrumb.textContent = 'Meniu'; }
    });

    function sectionLabel(key) {
        var b = buttonEl(key);
        return b ? b.textContent.trim() : 'Meniu';
    }

    /* Screen 1 → 2 */
    Array.prototype.slice.call(oc.querySelectorAll('[data-ocv2-open]')).forEach(function (item) {
        item.addEventListener('click', function () {
            var key = item.getAttribute('data-ocv2-open');
            var panel = panelEl(key);
            if (!panel) { return; }
            curSection = key;
            ocCrumb.textContent = sectionLabel(key);

            // Brand pills (only if the desktop panel has them).
            ocPills.innerHTML = '';
            var hasPills = !!panel.querySelector('.megav2__brandstrip');
            ocPills.toggleAttribute('hidden', !hasPills);
            if (hasPills) {
                panel.querySelectorAll('[data-brand-pill]').forEach(function (p) {
                    var pill = document.createElement('button');
                    pill.className = 'bpillv2 bpillv2--' + p.getAttribute('data-brand-pill') + (p.classList.contains('is-active') ? ' is-active' : '');
                    pill.textContent = p.textContent.trim();
                    pill.addEventListener('click', function () {
                        ocPills.querySelectorAll('.bpillv2').forEach(function (x) { x.classList.remove('is-active'); });
                        pill.classList.add('is-active');
                        renderSubs(panel, p.getAttribute('data-brand-pill'));
                    });
                    ocPills.appendChild(pill);
                });
            }

            renderSubs(panel, 'all');
            ocGoTo(2);
        });
    });

    function renderSubs(panel, brand) {
        ocSubs.innerHTML = '';
        panel.querySelectorAll('.stabgroup').forEach(function (group) {
            var gBrand = group.getAttribute('data-brand-group');
            if (brand !== 'all' && gBrand !== brand) { return; }
            var label = group.querySelector('.stabgroup__label');
            if (label) {
                var gl = document.createElement('div');
                gl.className = 'ocv2__grouplabel';
                gl.textContent = label.textContent.trim();
                ocSubs.appendChild(gl);
            }
            group.querySelectorAll('.stabv2').forEach(function (stab) {
                var id = stab.getAttribute('data-stab');
                var name = stab.querySelector('.stabv2__name');
                var count = stab.querySelector('.stabv2__count');
                var btn = document.createElement('button');
                btn.className = 'ocv2__sub';
                btn.innerHTML = '<span class="ocv2__sub-name">' + (name ? name.textContent : '') + '</span>' +
                    '<span class="ocv2__sub-count">' + (count ? count.textContent : '') + ' modele <span class="ocv2__arrow">›</span></span>';
                btn.addEventListener('click', function () { openProducts(panel, id, name ? name.textContent : ''); });
                ocSubs.appendChild(btn);
            });
        });
    }

    function openProducts(panel, paneId, title) {
        var pane = panel.querySelector('.panev2[data-pane="' + paneId + '"]');
        var grid = pane ? pane.querySelector('.megav2__grid') : null;
        ocGrid.innerHTML = grid ? grid.innerHTML : '<p style="padding:1rem;color:var(--muted)">Produse în curând.</p>';
        ocTitle.textContent = title;
        ocCrumb.textContent = title;
        ocGoTo(3);
        oc.querySelector('[data-ocv2-screen="3"]').scrollTop = 0;
    }
})();

/* ======================================================================
   HERO SLIDER — 4 rotating slides, auto-advance + dots/arrows
   ====================================================================== */
(function () {
    'use strict';
    var root = document.querySelector('[data-hero-slider]');
    if (!root) { return; }

    var slides = Array.prototype.slice.call(root.querySelectorAll('[data-hero-slide]'));
    var dots = Array.prototype.slice.call(root.querySelectorAll('[data-hero-dot]'));
    if (slides.length < 2) { return; }

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var DELAY = 6000;
    var index = 0;
    var timer = null;

    function show(i) {
        index = (i + slides.length) % slides.length;
        slides.forEach(function (s, n) {
            var on = n === index;
            s.classList.toggle('is-active', on);
            s.setAttribute('aria-hidden', on ? 'false' : 'true');
        });
        dots.forEach(function (d, n) {
            var on = n === index;
            d.classList.toggle('is-active', on);
            d.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    function next() { show(index + 1); }
    function prev() { show(index - 1); }

    function start() {
        if (reduce) { return; }
        stop();
        timer = setInterval(next, DELAY);
    }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function restart() { stop(); start(); }

    dots.forEach(function (d, n) {
        d.addEventListener('click', function () { show(n); restart(); });
    });
    var nextBtn = root.querySelector('[data-hero-next]');
    var prevBtn = root.querySelector('[data-hero-prev]');
    if (nextBtn) { nextBtn.addEventListener('click', function () { next(); restart(); }); }
    if (prevBtn) { prevBtn.addEventListener('click', function () { prev(); restart(); }); }

    // Pause while hovered/focused; pause when tab hidden.
    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', start);
    root.addEventListener('focusin', stop);
    root.addEventListener('focusout', start);
    document.addEventListener('visibilitychange', function () { document.hidden ? stop() : start(); });

    start();
})();
