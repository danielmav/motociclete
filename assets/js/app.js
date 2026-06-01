/* Dual Motors — homepage interactions. Vanilla, no dependencies. */
(function () {
    'use strict';

    /* ---- Sticky header state ---- */
    var header = document.querySelector('[data-header]');
    function onScroll() {
        if (!header) return;
        header.classList.toggle('is-stuck', window.scrollY > 12);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    /* ---- Mega menu (desktop hover + focus, click toggle) ---- */
    var menus = Array.prototype.slice.call(document.querySelectorAll('[data-menu]'));
    function closeAll(except) {
        menus.forEach(function (m) {
            if (m !== except) {
                m.classList.remove('is-open');
                var b = m.querySelector('.nav__link');
                if (b) b.setAttribute('aria-expanded', 'false');
            }
        });
    }
    menus.forEach(function (item) {
        var btn = item.querySelector('.nav__link');
        item.addEventListener('mouseenter', function () { if (window.innerWidth > 860) { closeAll(item); item.classList.add('is-open'); } });
        item.addEventListener('mouseleave', function () { if (window.innerWidth > 860) item.classList.remove('is-open'); });
        if (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var open = item.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) closeAll(item);
            });
        }
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-menu]')) closeAll(null);
    });

    /* ---- Search overlay ---- */
    var search = document.querySelector('[data-search]');
    var searchInput = search ? search.querySelector('.search__input') : null;
    function openSearch() {
        if (!search) return;
        search.hidden = false;
        if (searchInput) setTimeout(function () { searchInput.focus(); }, 30);
    }
    function closeSearch() { if (search) search.hidden = true; }
    var sOpen = document.querySelector('[data-search-open]');
    var sClose = document.querySelector('[data-search-close]');
    if (sOpen) sOpen.addEventListener('click', function () { search && search.hidden ? openSearch() : closeSearch(); });
    if (sClose) sClose.addEventListener('click', closeSearch);
    // Search backend lands in a later milestone — keep the demo clean for now.
    var searchForm = search ? search.querySelector('.search__form') : null;
    if (searchForm) searchForm.addEventListener('submit', function (e) { e.preventDefault(); });

    /* ---- Esc closes any open layer ---- */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeAll(null); closeSearch(); }
    });

    /* ---- Mobile drawer ---- */
    var burger = document.querySelector('[data-burger]');
    if (burger) {
        burger.addEventListener('click', function () {
            document.body.classList.toggle('mobile-open');
        });
    }

    /* ---- Fit My Bike: live cascading make -> model -> year -> products ---- */
    var BASE = window.BASE || '';
    var fit = document.querySelector('[data-fit]');
    if (fit) {
        var makeSel = fit.querySelector('[data-fit-make]');
        var modelSel = fit.querySelector('[data-fit-model]');
        var yearSel = fit.querySelector('[data-fit-year]');
        var submit = fit.querySelector('[data-fit-submit]');
        var results = document.querySelector('[data-fit-results]');
        var titleEl = document.querySelector('[data-fit-title]');
        var statusEl = document.querySelector('[data-fit-status]');

        var fmtPrice = function (n) {
            return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' €';
        };
        var setOptions = function (sel, items, placeholder) {
            sel.innerHTML = '';
            var opt = document.createElement('option');
            opt.value = ''; opt.textContent = placeholder;
            sel.appendChild(opt);
            items.forEach(function (it) {
                var o = document.createElement('option');
                o.value = it.id; o.textContent = it.name;
                sel.appendChild(o);
            });
        };
        var getJSON = function (url) {
            return fetch(url, { headers: { 'X-Requested-With': 'fetch' } }).then(function (r) { return r.json(); });
        };
        var skeletons = function (n) {
            results.innerHTML = '';
            for (var i = 0; i < n; i++) {
                var d = document.createElement('div');
                d.className = 'card-acc card-acc--ph';
                d.innerHTML = '<div class="card-acc__media media-ph media-ph--sm"></div>' +
                    '<span class="card-acc__brand">BikerShop</span>' +
                    '<span class="card-acc__name skeleton-line"></span>' +
                    '<span class="card-acc__price skeleton-line skeleton-line--sm"></span>';
                results.appendChild(d);
            }
        };
        var renderProducts = function (items) {
            results.innerHTML = '';
            if (!items.length) {
                var empty = document.createElement('p');
                empty.className = 'fitbike__empty';
                empty.textContent = 'Nu am găsit produse pentru această selecție. Încearcă fără an sau alt model.';
                results.appendChild(empty);
                return;
            }
            items.forEach(function (a) {
                var card = document.createElement('a');
                card.className = 'card-acc';
                card.href = a.url; card.target = '_blank'; card.rel = 'noopener';
                var media = document.createElement('div');
                media.className = 'card-acc__media media-ph media-ph--sm';
                if (a.image) {
                    var img = document.createElement('img');
                    img.loading = 'lazy'; img.src = a.image; img.alt = a.name;
                    img.width = 300; img.height = 300;
                    media.appendChild(img);
                }
                var brand = document.createElement('span');
                brand.className = 'card-acc__brand'; brand.textContent = a.manufacturer || 'BikerShop';
                var name = document.createElement('span');
                name.className = 'card-acc__name'; name.textContent = a.name;
                var price = document.createElement('span');
                price.className = 'card-acc__price'; price.textContent = fmtPrice(a.price);
                card.appendChild(media); card.appendChild(brand); card.appendChild(name); card.appendChild(price);
                results.appendChild(card);
            });
        };

        makeSel.addEventListener('change', function () {
            modelSel.disabled = true; yearSel.disabled = true; submit.disabled = true;
            setOptions(modelSel, [], 'Se încarcă…');
            setOptions(yearSel, [], 'Toți anii');
            if (!makeSel.value) { setOptions(modelSel, [], 'Alege întâi marca'); return; }
            getJSON(BASE + '/api/fit/models?make=' + encodeURIComponent(makeSel.value)).then(function (d) {
                setOptions(modelSel, d.options || [], 'Alege modelul…');
                modelSel.disabled = false;
            });
        });

        modelSel.addEventListener('change', function () {
            yearSel.disabled = true; submit.disabled = !modelSel.value;
            setOptions(yearSel, [], 'Toți anii');
            if (!modelSel.value) return;
            getJSON(BASE + '/api/fit/years?make=' + encodeURIComponent(makeSel.value) + '&model=' + encodeURIComponent(modelSel.value)).then(function (d) {
                setOptions(yearSel, d.options || [], 'Toți anii');
                yearSel.disabled = false;
            });
        });

        fit.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!modelSel.value) return;
            if (results) skeletons(6);
            var url = BASE + '/api/fit/products?model=' + encodeURIComponent(modelSel.value) +
                (yearSel.value ? '&year=' + encodeURIComponent(yearSel.value) : '');
            getJSON(url).then(function (d) {
                renderProducts(d.items || []);
                var bike = makeSel.options[makeSel.selectedIndex].text + ' ' + modelSel.options[modelSel.selectedIndex].text +
                    (yearSel.value ? ' ' + yearSel.options[yearSel.selectedIndex].text : '');
                if (titleEl) titleEl.textContent = 'Compatibil cu ' + bike;
                if (statusEl) statusEl.textContent = (d.count || 0) + ' produse';
            });
        });
    }

    /* ---- Virtual tour: click-to-load iframe (keeps page light) ---- */
    var tour = document.querySelector('[data-tour]');
    var tourBtn = tour ? tour.querySelector('[data-tour-play]') : null;
    if (tour && tourBtn) {
        tourBtn.addEventListener('click', function () {
            var url = tour.getAttribute('data-tour-url');
            if (!url) return;
            var iframe = document.createElement('iframe');
            iframe.src = url;
            iframe.title = 'Tur virtual showroom Dual Motors';
            iframe.setAttribute('allow', 'gyroscope; accelerometer; fullscreen; xr-spatial-tracking');
            iframe.allowFullscreen = true;
            tour.appendChild(iframe);
            tour.classList.add('tour--playing');
        });
    }

    /* ---- Reveal on load + scroll ---- */
    var reveals = Array.prototype.slice.call(document.querySelectorAll('[data-reveal]'));
    if ('IntersectionObserver' in window && reveals.length) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
        reveals.forEach(function (el) { io.observe(el); });
    } else {
        reveals.forEach(function (el) { el.classList.add('is-in'); });
    }
})();
