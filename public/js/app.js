/* =====================================================================
   Dental SaaS - JS base (vanilla, sin build).
   API pública:  window.UI.openModal(id) / closeModal(id) / toast(msg, type, title)
                 window.UI.confirm(msg, opts) -> Promise<boolean>
   Atributos:    data-modal-open="id"   data-modal-close
                 data-dropdown / data-dropdown-toggle
                 data-tabs / data-tab="x" / data-tab-panel="x"
                 data-confirm="mensaje" (form, button o enlace)
                 data-mask="phone|rfc|curp|postal|digits|upper|money"
                 data-check-all="selector" (checkbox maestro)
                 data-no-lock (el form no deshabilita su botón al enviar)
                 data-theme-toggle, data-sidebar-toggle
   ===================================================================== */
(function () {
    'use strict';
    var doc = document;
    var $ = function (s, r) { return (r || doc).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || doc).querySelectorAll(s)); };
    var ICONS = {
        success: '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
        error: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        warning: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>'
    };
    var svg = function (name) { return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">' + (ICONS[name] || ICONS.info) + '</svg>'; };

    /* ----------------------------- TEMA ------------------------------ */
    function applyTheme(t) {
        doc.documentElement.setAttribute('data-theme', t);
        try { localStorage.setItem('theme', t); } catch (e) {}
        $$('[data-theme-toggle]').forEach(function (b) { b.setAttribute('aria-pressed', t === 'dark'); });
    }

    /* ---------------------------- MODALES ---------------------------- */
    var lastFocus = null;
    function openModal(id) {
        var m = typeof id === 'string' ? doc.getElementById(id) : id;
        if (!m) return;
        lastFocus = doc.activeElement;
        m.hidden = false;
        // forzar reflow para la transición
        void m.offsetWidth;
        m.classList.add('is-open');
        doc.body.classList.add('has-modal');
        var f = $('[autofocus], input:not([type=hidden]), select, textarea, button.btn--primary', m);
        if (f) setTimeout(function () { f.focus(); }, 60);
        m.dispatchEvent(new CustomEvent('modal:open', { bubbles: true }));
    }
    function closeModal(id) {
        var m = typeof id === 'string' ? doc.getElementById(id) : id;
        if (!m) return;
        m.classList.remove('is-open');
        setTimeout(function () { if (!m.classList.contains('is-open')) m.hidden = true; }, 190);
        if (!$$('.modal-overlay.is-open').length) doc.body.classList.remove('has-modal');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
        m.dispatchEvent(new CustomEvent('modal:close', { bubbles: true }));
    }

    /* ----------------------------- TOASTS ---------------------------- */
    function toastRoot() {
        var r = $('#toast-root');
        if (!r) { r = doc.createElement('div'); r.id = 'toast-root'; r.className = 'toast-root'; r.setAttribute('aria-live', 'polite'); doc.body.appendChild(r); }
        return r;
    }
    function toast(message, type, title, ms) {
        type = type || 'info';
        var el = doc.createElement('div');
        el.className = 'toast toast--' + type;
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.innerHTML = '<span class="toast__icon">' + svg(type) + '</span><div class="toast__body">' +
            (title ? '<div class="toast__title"></div>' : '') + '<div class="toast__msg"></div></div>' +
            '<button type="button" class="toast__close" aria-label="Cerrar">&times;</button>';
        if (title) $('.toast__title', el).textContent = title;
        $('.toast__msg', el).textContent = message;
        var close = function () { el.classList.add('is-leaving'); setTimeout(function () { el.remove(); }, 220); };
        $('.toast__close', el).addEventListener('click', close);
        toastRoot().appendChild(el);
        var t = setTimeout(close, ms || (type === 'error' ? 8000 : 4500));
        el.addEventListener('mouseenter', function () { clearTimeout(t); });
        return el;
    }

    /* ---------------------------- CONFIRMAR -------------------------- */
    function confirmDialog(message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var ov = doc.createElement('div');
            ov.className = 'modal-overlay';
            ov.innerHTML = '<div class="modal modal--sm" role="alertdialog" aria-modal="true"><div class="modal__header"><div>' +
                '<div class="modal__title"></div><div class="modal__subtitle"></div></div></div>' +
                '<div class="modal__footer"><button type="button" class="btn btn--secondary" data-r="0"></button>' +
                '<button type="button" class="btn" data-r="1"></button></div></div>';
            $('.modal__title', ov).textContent = opts.title || 'Confirmar acción';
            $('.modal__subtitle', ov).textContent = message || '¿Deseas continuar?';
            $('[data-r="0"]', ov).textContent = opts.cancelText || 'Cancelar';
            var ok = $('[data-r="1"]', ov);
            ok.textContent = opts.confirmText || 'Confirmar';
            ok.className = 'btn ' + (opts.variant === 'primary' ? 'btn--primary' : 'btn--danger');
            doc.body.appendChild(ov);
            void ov.offsetWidth;
            ov.classList.add('is-open');
            doc.body.classList.add('has-modal');
            ok.focus();
            var done = function (v) {
                ov.classList.remove('is-open');
                setTimeout(function () { ov.remove(); }, 190);
                if (!$$('.modal-overlay.is-open').length) doc.body.classList.remove('has-modal');
                doc.removeEventListener('keydown', onKey, true);
                resolve(v);
            };
            var onKey = function (e) { if (e.key === 'Escape') { e.stopPropagation(); done(false); } };
            doc.addEventListener('keydown', onKey, true);
            ov.addEventListener('click', function (e) {
                if (e.target === ov) return done(false);
                var b = e.target.closest('[data-r]');
                if (b) done(b.getAttribute('data-r') === '1');
            });
        });
    }

    /* ------------------------------ TABS ----------------------------- */
    function activateTab(group, name) {
        $$('[data-tab]', group).forEach(function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on);
        });
        var scope = group.parentElement || doc;
        $$('[data-tab-panel]', scope).forEach(function (p) {
            if (p.closest('[data-tabs]') && p.closest('[data-tabs]') !== group) return;
            p.hidden = p.getAttribute('data-tab-panel') !== name;
        });
    }

    /* ---------------------------- MÁSCARAS --------------------------- */
    var MASKS = {
        phone: function (v) {
            var d = v.replace(/\D/g, '').slice(0, 10);
            if (d.length > 6) return d.slice(0, 2) + ' ' + d.slice(2, 6) + ' ' + d.slice(6);
            if (d.length > 2) return d.slice(0, 2) + ' ' + d.slice(2);
            return d;
        },
        rfc: function (v) { return v.toUpperCase().replace(/[^A-Z0-9&Ñ]/g, '').slice(0, 13); },
        curp: function (v) { return v.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 18); },
        postal: function (v) { return v.replace(/\D/g, '').slice(0, 5); },
        digits: function (v) { return v.replace(/\D/g, ''); },
        upper: function (v) { return v.toUpperCase(); },
        money: function (v) {
            v = v.replace(/[^0-9.]/g, '');
            var p = v.split('.');
            return p.length > 1 ? p[0] + '.' + p.slice(1).join('').slice(0, 2) : p[0];
        }
    };
    function applyMask(el) {
        var fn = MASKS[el.getAttribute('data-mask')];
        if (!fn) return;
        var v = fn(el.value);
        if (v !== el.value) el.value = v;
    }

    /* --------------------------- DELEGACIÓN -------------------------- */
    doc.addEventListener('click', function (e) {
        var t = e.target;

        var th = t.closest('[data-theme-toggle]');
        if (th) { applyTheme(doc.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'); return; }

        var st = t.closest('[data-sidebar-toggle]');
        if (st) { doc.body.classList.toggle('sidebar-open'); return; }
        if (t.closest('.sidebar-backdrop') || (t.closest('.sidebar .nav-item') && window.innerWidth < 768)) doc.body.classList.remove('sidebar-open');

        var mo = t.closest('[data-modal-open]');
        if (mo) { e.preventDefault(); openModal(mo.getAttribute('data-modal-open')); return; }
        var mc = t.closest('[data-modal-close]');
        if (mc) { var ov = mc.closest('.modal-overlay'); if (ov) { e.preventDefault(); closeModal(ov); } return; }
        if (t.classList && t.classList.contains('modal-overlay') && !t.hasAttribute('data-static')) { closeModal(t); return; }

        var dd = t.closest('[data-dropdown-toggle]');
        if (dd) {
            e.preventDefault();
            var box = dd.closest('[data-dropdown]');
            var was = box.classList.contains('is-open');
            $$('[data-dropdown].is-open').forEach(function (d) { d.classList.remove('is-open'); });
            if (!was) box.classList.add('is-open');
            dd.setAttribute('aria-expanded', !was);
            return;
        }
        if (!t.closest('[data-dropdown]')) $$('[data-dropdown].is-open').forEach(function (d) { d.classList.remove('is-open'); });

        var tab = t.closest('[data-tab]');
        if (tab && tab.tagName !== 'A') {
            var grp = tab.closest('[data-tabs]');
            if (grp) { activateTab(grp, tab.getAttribute('data-tab')); if (grp.hasAttribute('data-tabs-hash')) history.replaceState(null, '', '#' + tab.getAttribute('data-tab')); }
            return;
        }

        var cf = t.closest('button[data-confirm], a[data-confirm]');
        if (cf && !cf.__confirmed) {
            e.preventDefault();
            e.stopPropagation();
            confirmDialog(cf.getAttribute('data-confirm'), { confirmText: cf.getAttribute('data-confirm-text'), title: cf.getAttribute('data-confirm-title'), variant: cf.getAttribute('data-confirm-variant') }).then(function (ok) {
                if (!ok) return;
                cf.__confirmed = true;
                cf.click();
                cf.__confirmed = false;
            });
            return;
        }
    }, true);

    doc.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var open = $$('.modal-overlay.is-open');
            var top = open[open.length - 1];
            if (top && !top.hasAttribute('data-static')) closeModal(top);
            $$('[data-dropdown].is-open').forEach(function (d) { d.classList.remove('is-open'); });
            doc.body.classList.remove('sidebar-open');
        }
        if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(doc.activeElement.tagName)) {
            var s = $('[data-global-search]');
            if (s) { e.preventDefault(); s.focus(); }
        }
    });

    doc.addEventListener('input', function (e) {
        var t = e.target;
        if (t.hasAttribute && t.hasAttribute('data-mask')) applyMask(t);
        if (t.classList && t.classList.contains('has-error')) {
            t.classList.remove('has-error');
            var f = t.closest('.field');
            var err = f && $('.field__error', f);
            if (err) err.remove();
        }
    });

    // Formularios: confirmación y anti doble-click
    doc.addEventListener('submit', function (e) {
        var f = e.target;
        if (!(f instanceof HTMLFormElement)) return;
        if (f.hasAttribute('data-confirm') && !f.__confirmed) {
            e.preventDefault();
            confirmDialog(f.getAttribute('data-confirm'), { confirmText: f.getAttribute('data-confirm-text'), title: f.getAttribute('data-confirm-title'), variant: f.getAttribute('data-confirm-variant') }).then(function (ok) {
                if (!ok) return;
                f.__confirmed = true;
                if (f.requestSubmit) f.requestSubmit(); else f.submit();
            });
            return;
        }
        if (f.hasAttribute('data-no-lock') || e.defaultPrevented) return;
        if (f.__locked) { e.preventDefault(); return; }
        f.__locked = true;
        setTimeout(function () {
            $$('button[type=submit], button:not([type]), input[type=submit]', f).forEach(function (b) { b.disabled = true; if (b.classList.contains('btn')) b.classList.add('is-loading'); });
        }, 0);
    });
    window.addEventListener('pageshow', function () {
        $$('form').forEach(function (f) { f.__locked = false; f.__confirmed = false; });
        $$('.btn.is-loading').forEach(function (b) { b.disabled = false; b.classList.remove('is-loading'); });
    });

    // Checkbox maestro
    doc.addEventListener('change', function (e) {
        var m = e.target.closest && e.target.closest('[data-check-all]');
        if (m) $$(m.getAttribute('data-check-all')).forEach(function (c) { c.checked = m.checked; });
    });

    /* ------------------------------ INIT ----------------------------- */
    function init() {
        applyTheme(doc.documentElement.getAttribute('data-theme') || 'light');
        $$('[data-mask]').forEach(applyMask);
        $$('[data-tabs]').forEach(function (g) {
            var first = $('[data-tab].is-active', g) || $('[data-tab]', g);
            var h = g.hasAttribute('data-tabs-hash') && location.hash ? location.hash.slice(1) : null;
            if (h && $('[data-tab="' + h + '"]', g)) first = $('[data-tab="' + h + '"]', g);
            if (first && first.tagName !== 'A') activateTab(g, first.getAttribute('data-tab'));
        });
        $$('.modal-overlay').forEach(function (m) { if (!m.classList.contains('is-open')) m.hidden = true; });
        // Modales con errores de validación: abrir automáticamente (data-open-on-error)
        $$('.modal-overlay[data-open-on-error]').forEach(function (m) { if ($('.has-error', m)) openModal(m); });
        // Mensajes flash enviados por el layout
        var fl = window.__flash || [];
        fl.forEach(function (f) { toast(f.message, f.type, f.title); });
        // Enfocar primer campo con error
        var firstErr = $('.has-error');
        if (firstErr && !firstErr.closest('.modal-overlay[hidden]')) { try { firstErr.focus({ preventScroll: false }); } catch (e) {} }
    }
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init); else init();

    window.UI = { openModal: openModal, closeModal: closeModal, toast: toast, confirm: confirmDialog, applyMask: applyMask, setTheme: applyTheme };
})();
