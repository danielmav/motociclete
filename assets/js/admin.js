/* admin.js — Dual Motors back-office.
   Reusable widgets driven by data-attributes:
   - [data-editor="field"]  + <textarea name="field">  → Quill WYSIWYG
   - [data-imgmgr]                                       → drag-drop upload + image list
   - [data-rows] / [data-row-add] / [data-row-del]       → repeatable label/value rows
   Talks to window.ADMIN_BASE + '/upload' with window.CSRF. */
(function () {
  'use strict';
  var ADMIN_BASE = window.ADMIN_BASE || '';
  var CSRF = window.CSRF || '';

  /* ---------- WYSIWYG ---------- */
  function initEditors() {
    if (typeof Quill === 'undefined') return;
    document.querySelectorAll('[data-editor]').forEach(function (el) {
      var name = el.getAttribute('data-editor');
      var form = el.closest('form');
      var ta = form && form.querySelector('textarea[name="' + name + '"]');
      if (!ta) return;
      var q = new Quill(el, {
        theme: 'snow',
        modules: { toolbar: [
          [{ header: [2, 3, false] }],
          ['bold', 'italic', 'underline'],
          [{ list: 'ordered' }, { list: 'bullet' }],
          ['link', 'blockquote'],
          ['clean']
        ] }
      });
      if (ta.value) { q.clipboard.dangerouslyPasteHTML(ta.value); }
      ta.style.display = 'none';
      form.addEventListener('submit', function () {
        var html = q.root.innerHTML;
        ta.value = (html === '<p><br></p>') ? '' : html;
      });
    });
  }

  /* ---------- Image manager (drag-drop upload) ---------- */
  function initImageManagers() {
    document.querySelectorAll('[data-imgmgr]').forEach(function (mgr) {
      var drop = mgr.querySelector('[data-dropzone]');
      var list = mgr.querySelector('[data-images]');
      var single = mgr.hasAttribute('data-single');
      var ctx = {
        context: mgr.getAttribute('data-context') || '',
        brand: mgr.getAttribute('data-brand') || '',
        type: mgr.getAttribute('data-type') || '',
        name: mgr.getAttribute('data-name') || 'images',
        store: mgr.getAttribute('data-store') || 'filename' // hidden value = filename | url
      };
      // wire existing tiles
      list.querySelectorAll('.adm-img').forEach(function (t) { wireTile(t, list, single); });

      var input = document.createElement('input');
      input.type = 'file'; input.accept = 'image/*'; if (!single) input.multiple = true;
      drop.appendChild(input);
      drop.addEventListener('click', function () { input.click(); });
      input.addEventListener('change', function () { upload(input.files, mgr, list, single, ctx); input.value = ''; });
      ['dragover', 'dragenter'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.add('is-over'); }); });
      ['dragleave', 'drop'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.remove('is-over'); }); });
      drop.addEventListener('drop', function (ev) { if (ev.dataTransfer && ev.dataTransfer.files.length) upload(ev.dataTransfer.files, mgr, list, single, ctx); });

      enableReorder(list);
    });
  }

  function upload(files, mgr, list, single, ctx) {
    if (!files || !files.length) return;
    var fd = new FormData();
    for (var i = 0; i < files.length; i++) { fd.append('files[]', files[i]); }
    fd.append('context', ctx.context);
    fd.append('brand', ctx.brand);
    fd.append('type', ctx.type);
    fd.append('_csrf', CSRF);
    mgr.classList.add('is-busy');
    fetch(ADMIN_BASE + '/upload', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        (res.ok || []).forEach(function (item) {
          if (single) { list.innerHTML = ''; }
          list.appendChild(makeTile(item, ctx, single));
        });
        if (res.errors && res.errors.length) { alert(res.errors.join('\n')); }
      })
      .catch(function () { alert('Încărcarea a eșuat.'); })
      .finally(function () { mgr.classList.remove('is-busy'); });
  }

  function makeTile(item, ctx, single) {
    var value = item[ctx.store] || item.filename;
    var t = document.createElement('div');
    t.className = 'adm-img';
    t.setAttribute('data-filename', item.filename);
    t.draggable = !single;
    t.innerHTML =
      '<img src="' + item.url + '" alt="">' +
      '<div class="adm-img__bar">' +
        (single ? '' : '<span class="adm-img__btn" data-move title="Mută">↕</span>') +
        '<button type="button" class="adm-img__btn" data-del title="Șterge">Șterge</button>' +
      '</div>' +
      '<input type="hidden" name="' + ctx.name + (single ? '' : '[]') + '" value="' + value + '">';
    wireTile(t, null, single);
    return t;
  }

  function wireTile(tile, list, single) {
    tile.draggable = !single;
    var del = tile.querySelector('[data-del]');
    if (del) del.addEventListener('click', function () { tile.remove(); });
  }

  function enableReorder(list) {
    var dragged = null;
    list.addEventListener('dragstart', function (e) {
      var t = e.target.closest('.adm-img'); if (!t) return;
      dragged = t; t.classList.add('dragging');
    });
    list.addEventListener('dragend', function () { if (dragged) dragged.classList.remove('dragging'); dragged = null; });
    list.addEventListener('dragover', function (e) {
      e.preventDefault();
      var after = getAfter(list, e.clientX, e.clientY);
      if (!dragged) return;
      if (after == null) list.appendChild(dragged); else list.insertBefore(dragged, after);
    });
  }
  function getAfter(list, x, y) {
    var els = [].slice.call(list.querySelectorAll('.adm-img:not(.dragging)'));
    var closest = { dist: Infinity, el: null };
    els.forEach(function (el) {
      var b = el.getBoundingClientRect();
      var d = Math.hypot(x - (b.left + b.width / 2), y - (b.top + b.height / 2));
      if (d < closest.dist) closest = { dist: d, el: el };
    });
    return closest.el;
  }

  /* ---------- Repeatable rows (specs/stats) ---------- */
  function initRows() {
    document.querySelectorAll('[data-row-add]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var box = document.querySelector(btn.getAttribute('data-row-add'));
        if (!box) return;
        var tpl = box.querySelector('[data-row-tpl]');
        if (!tpl) return;
        var node = tpl.content ? tpl.content.cloneNode(true) : null;
        if (node) box.appendChild(node);
      });
    });
    document.addEventListener('click', function (e) {
      var del = e.target.closest('[data-row-del]');
      if (del) { var row = del.closest('.adm-row'); if (row) row.remove(); }
    });
  }

  /* ---------- Slug helper ---------- */
  function initSlug() {
    document.querySelectorAll('[data-slug-from]').forEach(function (slugEl) {
      var src = document.querySelector(slugEl.getAttribute('data-slug-from'));
      if (!src) return;
      src.addEventListener('input', function () {
        if (slugEl.dataset.touched) return;
        slugEl.value = src.value.toLowerCase()
          .replace(/[ăâ]/g, 'a').replace(/î/g, 'i').replace(/ș/g, 's').replace(/ț/g, 't')
          .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
      });
      slugEl.addEventListener('input', function () { slugEl.dataset.touched = '1'; });
    });
  }

  /* ---------- Yamaha import modal ---------- */
  function initYamahaModal() {
    var modal = document.querySelector('[data-yamaha-modal]');
    if (!modal) return;
    function open() { modal.hidden = false; var u = modal.querySelector('input[name="yamaha_url"]'); if (u) u.focus(); }
    function close() { modal.hidden = true; }
    document.querySelectorAll('[data-yamaha-open]').forEach(function (b) { b.addEventListener('click', open); });
    modal.querySelectorAll('[data-yamaha-close]').forEach(function (b) { b.addEventListener('click', close); });
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
    // show "se preia..." state while the fetch runs (full POST → redirect)
    var form = modal.querySelector('form');
    if (form) form.addEventListener('submit', function () { modal.classList.add('is-busy'); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initEditors();
    initImageManagers();
    initRows();
    initSlug();
    initYamahaModal();
  });
})();
