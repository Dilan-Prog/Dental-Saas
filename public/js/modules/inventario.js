/* Inventario: comportamiento de las pantallas (resumen, kardex, proveedores). JS plano, sin dependencias. */
(function () {
    'use strict';

    var doc = document;
    function $(sel, ctx) { return (ctx || doc).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }

    // ---- Filas clicables (data-href) ---------------------------------------------------------
    doc.addEventListener('click', function (e) {
        var row = e.target.closest('tr[data-href]');
        if (!row) return;
        if (e.target.closest('a, button, input, select, textarea, label, form')) return;
        window.location.href = row.getAttribute('data-href');
    });

    // ---- Filtros con envio automatico --------------------------------------------------------
    $$('form[data-autosubmit]').forEach(function (form) {
        var timer = null;
        form.addEventListener('change', function (e) {
            if (e.target.matches('select, input[type="date"]')) form.submit();
        });
        var search = $('input[type="search"]', form);
        if (search) {
            search.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { form.submit(); }, 450);
            });
        }
        form.addEventListener('submit', function () { clearTimeout(timer); });
    });

    // ---- Reversion de movimientos (modal con action dinamico) -------------------------------
    var revertForm = $('#modal-revertir form');
    if (revertForm) {
        var hidden = $('input[name="_reverse_action"]', revertForm);
        var label = $('[data-reverse-label]', revertForm);
        if (hidden && hidden.value) revertForm.setAttribute('action', hidden.value);

        doc.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-reverse-action]');
            if (!btn) return;
            var action = btn.getAttribute('data-reverse-action');
            revertForm.setAttribute('action', action);
            if (hidden) hidden.value = action;
            if (label) label.textContent = btn.getAttribute('data-reverse-label') || '';
            var reason = $('[name="reason"]', revertForm);
            if (reason) reason.value = '';
        });
    }

    // ---- Edicion de proveedor (prellenado) ---------------------------------------------------
    var editForm = $('#modal-editar-proveedor form');
    if (editForm) {
        var editHidden = $('input[name="_edit_action"]', editForm);
        if (editHidden && editHidden.value) editForm.setAttribute('action', editHidden.value);

        doc.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-edit-action]');
            if (!btn) return;
            var action = btn.getAttribute('data-edit-action');
            editForm.setAttribute('action', action);
            if (editHidden) editHidden.value = action;
            $('[data-edit-name-input]', editForm).value = btn.getAttribute('data-edit-name') || '';
            $('[data-edit-phone-input]', editForm).value = btn.getAttribute('data-edit-phone') || '';
            $('[data-edit-email-input]', editForm).value = btn.getAttribute('data-edit-email') || '';
        });
    }

    // ---- Minimo y ubicacion por sucursal (prellenado) ---------------------------------------
    var stockModal = $('#modal-stock');
    if (stockModal) {
        doc.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-stock-branch]');
            if (!btn) return;
            $('[data-stock-branch-input]', stockModal).value = btn.getAttribute('data-stock-branch');
            $('[data-stock-min-input]', stockModal).value = (btn.getAttribute('data-stock-min') || '0').replace(/,/g, '');
            $('[data-stock-location-input]', stockModal).value = btn.getAttribute('data-stock-location') || '';
            var lbl = $('[data-stock-branch-label]', stockModal);
            if (lbl) lbl.textContent = 'Sucursal: ' + (btn.getAttribute('data-stock-branch-name') || '');
        });
    }

    // ---- Salida: mostrar solo los lotes del insumo / sucursal elegidos ------------------------
    var salida = $('#modal-salida');
    if (salida) {
        var lotSelect = $('[data-inv-lots]', salida);
        var itemSelect = $('[data-inv-item]', salida);
        var branchField = $('[data-inv-branch]', salida);

        var refreshLots = function () {
            if (!lotSelect) return;
            var item = itemSelect ? itemSelect.value : '';
            var branch = branchField ? branchField.value : '';
            var keep = false;
            $$('option[data-item]', lotSelect).forEach(function (opt) {
                var ok = item !== '' && opt.getAttribute('data-item') === item &&
                    (branch === '' || opt.getAttribute('data-branch') === branch);
                opt.hidden = !ok;
                opt.disabled = !ok;
                if (ok && opt.value === lotSelect.value) keep = true;
            });
            if (!keep) lotSelect.value = '';
        };

        if (itemSelect) itemSelect.addEventListener('change', refreshLots);
        if (branchField) branchField.addEventListener('change', refreshLots);
        refreshLots();
    }
})();
