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

    /* ---- Product gallery: thumbs swap the main image ---- */
    var gallery = document.querySelector('[data-gallery]');
    if (gallery) {
        var mainImg = gallery.querySelector('[data-gallery-main]');
        var thumbs = Array.prototype.slice.call(document.querySelectorAll('[data-gallery-thumb]'));
        thumbs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var src = btn.getAttribute('data-src');
                if (!src || !mainImg) return;
                mainImg.src = src;
                thumbs.forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
            });
        });
    }

    /* ---- Lightbox: zoom + prev/next navigation (gallery + detail images) ---- */
    (function () {
        var lbMain    = document.querySelector('[data-gallery-main]');
        var thumbBtns = Array.prototype.slice.call(document.querySelectorAll('[data-gallery-thumb]'));
        var detailImgs = Array.prototype.slice.call(document.querySelectorAll('[data-lightbox]'));
        if (!lbMain && !detailImgs.length) return;

        // Build unified image list: [gallery thumbs …, detail images …]
        var images = [];
        if (thumbBtns.length) {
            thumbBtns.forEach(function (btn) {
                images.push({ src: btn.getAttribute('data-src'), alt: '' });
            });
        } else if (lbMain) {
            images.push({ src: lbMain.src, alt: lbMain.alt });
        }
        var galleryCount = images.length;
        detailImgs.forEach(function (img) {
            images.push({ src: img.src, alt: img.alt });
        });
        if (!images.length) return;

        var currentIdx = 0;

        // Build DOM
        var lb = document.createElement('div');
        lb.className = 'lightbox';
        lb.setAttribute('role', 'dialog');
        lb.setAttribute('aria-modal', 'true');
        lb.setAttribute('aria-label', 'Imagine mărită');

        var lbImg = document.createElement('img');
        lbImg.className = 'lightbox__img';

        var lbClose = document.createElement('button');
        lbClose.className = 'lightbox__close';
        lbClose.setAttribute('aria-label', 'Închide');
        lbClose.innerHTML = '&times;';

        var lbPrev = document.createElement('button');
        lbPrev.className = 'lightbox__nav lightbox__nav--prev';
        lbPrev.setAttribute('aria-label', 'Imaginea anterioară');
        lbPrev.innerHTML = '&#8592;';

        var lbNext = document.createElement('button');
        lbNext.className = 'lightbox__nav lightbox__nav--next';
        lbNext.setAttribute('aria-label', 'Imaginea următoare');
        lbNext.innerHTML = '&#8594;';

        var lbCounter = document.createElement('span');
        lbCounter.className = 'lightbox__counter';

        lb.appendChild(lbPrev);
        lb.appendChild(lbImg);
        lb.appendChild(lbNext);
        lb.appendChild(lbClose);
        lb.appendChild(lbCounter);
        document.body.appendChild(lb);

        var multi = images.length > 1;
        lbPrev.style.display = multi ? '' : 'none';
        lbNext.style.display = multi ? '' : 'none';
        lbCounter.style.display = multi ? '' : 'none';

        function show(idx) {
            currentIdx = (idx + images.length) % images.length;
            lbImg.src = images[currentIdx].src;
            lbImg.alt = images[currentIdx].alt || '';
            if (multi) lbCounter.textContent = (currentIdx + 1) + ' / ' + images.length;
        }
        function open(idx) {
            show(idx);
            lb.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            lbClose.focus();
        }
        function close() {
            lb.classList.remove('is-open');
            document.body.style.overflow = '';
        }

        // Main gallery image click — open at currently active thumb
        if (lbMain) {
            lbMain.addEventListener('click', function () {
                var active = document.querySelector('[data-gallery-thumb].is-active');
                var idx = active ? Math.max(0, thumbBtns.indexOf(active)) : 0;
                open(idx);
            });
        }

        // Detail image clicks
        detailImgs.forEach(function (img, i) {
            img.addEventListener('click', function () { open(galleryCount + i); });
        });

        // Nav buttons
        lbPrev.addEventListener('click', function (e) { e.stopPropagation(); show(currentIdx - 1); });
        lbNext.addEventListener('click', function (e) { e.stopPropagation(); show(currentIdx + 1); });

        // Close
        lbClose.addEventListener('click', close);
        lb.addEventListener('click', function (e) { if (e.target === lb) close(); });

        // Keyboard
        document.addEventListener('keydown', function (e) {
            if (!lb.classList.contains('is-open')) return;
            if (e.key === 'Escape')      close();
            if (e.key === 'ArrowLeft')   show(currentIdx - 1);
            if (e.key === 'ArrowRight')  show(currentIdx + 1);
        });

        // Touch swipe
        var touchX = 0;
        lb.addEventListener('touchstart', function (e) { touchX = e.changedTouches[0].clientX; }, { passive: true });
        lb.addEventListener('touchend', function (e) {
            var dx = e.changedTouches[0].clientX - touchX;
            if (Math.abs(dx) > 50) { dx < 0 ? show(currentIdx + 1) : show(currentIdx - 1); }
        });
    })();

    /* ---- Spec tabs ---- */
    var tabsRoot = document.querySelector('[data-tabs]');
    if (tabsRoot) {
        var btns = Array.prototype.slice.call(tabsRoot.querySelectorAll('[data-tab]'));
        var panels = Array.prototype.slice.call(tabsRoot.querySelectorAll('[data-panel]'));
        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-tab');
                btns.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                panels.forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-panel') === id); });
            });
        });
    }

    /* ---- Lazy YouTube embed ---- */
    var video = document.querySelector('[data-video]');
    if (video) {
        var play = video.querySelector('[data-video-play]');
        if (play) {
            play.addEventListener('click', function () {
                var id = video.getAttribute('data-video-id');
                if (!id) return;
                var iframe = document.createElement('iframe');
                iframe.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';
                iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
                iframe.allowFullscreen = true;
                iframe.title = 'Video';
                video.innerHTML = '';
                video.appendChild(iframe);
            });
        }
    }
})();
