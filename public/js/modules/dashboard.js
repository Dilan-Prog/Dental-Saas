/* Modulo Dashboard (se carga en todas las paginas desde notifications/panel):
   - contador de la campana (corrige el badge aunque el layout no lo haya pintado)
   - buscador global del topbar con resultados en vivo (GET /buscar?format=json)
*/
(function () {
    'use strict';

    var panel = document.querySelector('[data-notif-panel]');
    if (!panel) return;

    // ---------- Contador de la campana ----------
    (function bell() {
        var unread = parseInt(panel.getAttribute('data-unread') || '0', 10);
        var toggle = panel.closest('[data-dropdown]');
        var btn = toggle && toggle.querySelector('[data-dropdown-toggle]');
        if (!btn) return;
        var dot = btn.querySelector('.dot-count');
        if (unread > 0) {
            if (!dot) {
                dot = document.createElement('span');
                dot.className = 'dot-count';
                btn.appendChild(dot);
            }
            dot.textContent = unread > 9 ? '9+' : String(unread);
        } else if (dot) {
            dot.remove();
        }
    })();

    // ---------- Buscador global ----------
    var searchUrl = panel.getAttribute('data-search-url');
    var input = document.querySelector('[data-global-search]');
    if (!searchUrl || !input) return;

    var form = input.closest('form');
    var wrap = input.closest('.search');
    if (form) form.setAttribute('action', searchUrl); // Enter => pagina de resultados

    var box = document.createElement('div');
    box.className = 'gsearch';
    box.hidden = true;
    box.setAttribute('role', 'listbox');
    (wrap || input.parentNode).appendChild(box);

    var timer = null;
    var seq = 0;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function hide() { box.hidden = true; }

    function render(data, term) {
        var html = '';
        var total = 0;
        Object.keys(data.groups || {}).forEach(function (key) {
            var g = data.groups[key];
            if (!g.items || !g.items.length) return;
            html += '<div class="gsearch__group">' + esc(g.title) + '</div>';
            g.items.forEach(function (it) {
                total++;
                var tag = it.url ? 'a' : 'div';
                html += '<' + tag + ' class="gsearch__item"' + (it.url ? ' href="' + esc(it.url) + '"' : '') + '>'
                    + '<span>' + esc(it.label) + '</span>'
                    + (it.sub ? '<span class="gsearch__sub">' + esc(it.sub) + '</span>' : '')
                    + '</' + tag + '>';
            });
        });
        if (!total) {
            html = '<div class="gsearch__empty">Sin resultados para “' + esc(term) + '”</div>';
        } else {
            html += '<a class="gsearch__all" href="' + esc(searchUrl) + '?q=' + encodeURIComponent(term) + '">Ver todos los resultados</a>';
        }
        box.innerHTML = html;
        box.hidden = false;
    }

    function run() {
        var term = input.value.trim();
        if (term.length < 2) { hide(); return; }
        var my = ++seq;
        fetch(searchUrl + '?format=json&q=' + encodeURIComponent(term), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) { if (my === seq) render(data, term); })
            .catch(function () { if (my === seq) hide(); });
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(run, 250);
    });
    input.addEventListener('focus', function () { if (input.value.trim().length >= 2 && box.innerHTML) box.hidden = false; });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') hide();
        if (e.key === 'ArrowDown') {
            var first = box.querySelector('.gsearch__item');
            if (first) { e.preventDefault(); first.focus(); }
        }
    });
    box.addEventListener('keydown', function (e) {
        var items = Array.prototype.slice.call(box.querySelectorAll('.gsearch__item'));
        var i = items.indexOf(document.activeElement);
        if (e.key === 'ArrowDown' && i < items.length - 1) { e.preventDefault(); items[i + 1].focus(); }
        if (e.key === 'ArrowUp') { e.preventDefault(); if (i > 0) items[i - 1].focus(); else input.focus(); }
        if (e.key === 'Escape') { hide(); input.focus(); }
    });
    document.addEventListener('click', function (e) {
        if (!box.hidden && !(wrap && wrap.contains(e.target))) hide();
    });
})();
