/* Modulo Finanzas — JS vanilla (usa window.UI de /js/app.js) */
(function () {
    'use strict';

    var doc = document;
    function $(sel, ctx) { return (ctx || doc).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }
    function openModal(id) { if (window.UI && UI.openModal) { UI.openModal(id); } }

    function money(n) {
        return '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function toNumber(v) {
        var n = parseFloat(String(v || '').replace(/[^0-9.\-]/g, ''));
        return isNaN(n) ? null : n;
    }

    /* ---------- Nueva transaccion: tipo -> categorias; efectivo -> aviso/caja ---------- */
    var movForm = $('[data-fin-movement-form]');
    if (movForm) {
        var dirInput = $('[data-fin-direction-input]', movForm);
        var dirBtns = $$('[data-fin-direction]', movForm);
        var category = $('[data-fin-category]', movForm);
        var method = $('[data-fin-method]', movForm);
        var note = $('[data-fin-cash-note]', movForm);
        var sessionField = $('.fin-session-field', movForm);

        var applyDirection = function (dir, keepSelection) {
            dirInput.value = dir;
            dirBtns.forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-fin-direction') === dir); });
            $$('option[data-direction]', category).forEach(function (o) {
                var match = o.getAttribute('data-direction') === dir;
                o.hidden = !match;
                o.disabled = !match;
            });
            var current = category.options[category.selectedIndex];
            if (!keepSelection || (current && current.disabled)) { category.value = ''; }
        };
        var applyMethod = function () {
            var cash = method.value === 'efectivo';
            if (note) { note.hidden = !cash; }
            if (sessionField) { sessionField.classList.toggle('is-visible', cash); }
        };

        dirBtns.forEach(function (b) {
            b.addEventListener('click', function () { applyDirection(b.getAttribute('data-fin-direction'), false); });
        });
        method.addEventListener('change', applyMethod);
        applyDirection(dirInput.value || 'ingreso', true);
        applyMethod();
    }

    /* ---------- Corte de caja: diferencia en vivo ---------- */
    $$('[data-fin-close-form]').forEach(function (form) {
        var expected = toNumber(form.getAttribute('data-expected')) || 0;
        var counted = $('[data-fin-counted]', form);
        var out = $('[data-fin-difference]', form);
        if (!counted || !out) { return; }

        var refresh = function () {
            var c = toNumber(counted.value);
            out.classList.remove('is-ok', 'is-bad');
            if (c === null) { out.textContent = '—'; return; }
            var diff = Math.round((c - expected) * 100) / 100;
            out.textContent = (diff > 0 ? '+' : '') + money(diff);
            out.classList.add(diff === 0 ? 'is-ok' : 'is-bad');
        };
        counted.addEventListener('input', refresh);
        refresh();
    });

    /* ---------- Abrir turno desde la tarjeta de una caja ---------- */
    doc.addEventListener('click', function (e) {
        var openBtn = e.target.closest('[data-open-register]');
        if (openBtn) {
            var sel = $('#abrir-caja select[name="cash_register_id"]');
            if (sel) { sel.value = openBtn.getAttribute('data-open-register'); }
        }

        /* Revertir movimiento */
        var rev = e.target.closest('[data-reverse-movement]');
        if (rev) {
            var form = $('[data-fin-reverse-form]');
            if (form) {
                var summary = rev.getAttribute('data-concept') + ' · ' + rev.getAttribute('data-amount');
                form.setAttribute('action', rev.getAttribute('data-action'));
                $('[data-fin-reverse-action-input]', form).value = rev.getAttribute('data-action');
                $('[data-fin-reverse-summary]', form).textContent = 'Se revertirá: ' + summary;
                $('[data-fin-reverse-summary-input]', form).value = 'Se revertirá: ' + summary;
                var reason = $('textarea[name="reason"]', form);
                if (reason) { reason.value = ''; }
                openModal('revertir-movimiento');
            }
        }

        /* Editar caja */
        var editReg = e.target.closest('[data-edit-register]');
        if (editReg) {
            var rf = $('[data-fin-register-form]');
            if (rf) {
                rf.setAttribute('action', editReg.getAttribute('data-action'));
                $('[data-fin-register-action-input]', rf).value = editReg.getAttribute('data-action');
                $('[data-fin-register-name]', rf).value = editReg.getAttribute('data-name');
                $('[data-fin-register-status]', rf).value = editReg.getAttribute('data-status');
                openModal('editar-caja');
            }
        }

        /* Editar categoria */
        var editCat = e.target.closest('[data-edit-category]');
        if (editCat) {
            var cf = $('[data-fin-category-form]');
            if (cf) {
                cf.setAttribute('action', editCat.getAttribute('data-action'));
                $('[data-fin-category-action-input]', cf).value = editCat.getAttribute('data-action');
                $('[data-fin-category-name]', cf).value = editCat.getAttribute('data-name');
                openModal('editar-categoria');
            }
        }

        /* Filas clicables del historial de turnos */
        var row = e.target.closest('tr[data-href]');
        if (row && !e.target.closest('a, button, form, .dropdown')) {
            window.location.href = row.getAttribute('data-href');
        }
    });

    /* ---------- Cambiar de periodo limpia las fechas manuales ---------- */
    var filters = $('[data-fin-filters]');
    if (filters) {
        $$('.segmented button[name="preset"]', filters).forEach(function (btn) {
            btn.addEventListener('click', function () {
                $$('input[type="date"]', filters).forEach(function (i) { i.disabled = true; });
            });
        });
    }
})();
