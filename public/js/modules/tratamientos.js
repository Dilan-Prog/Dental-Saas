/* Módulo Tratamientos: modales de alta/edición reutilizados (tratamiento, categoría, impuesto). */
(function () {
    'use strict';

    var doc = document;
    function $(sel, root) { return (root || doc).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); }

    function setMethod(form, method) {
        var spoof = $('input[name="_method"]', form);
        if (method === 'PUT') {
            if (!spoof) {
                spoof = doc.createElement('input');
                spoof.type = 'hidden';
                spoof.name = '_method';
                form.appendChild(spoof);
            }
            spoof.value = 'PUT';
        } else if (spoof) {
            spoof.parentNode.removeChild(spoof);
        }
    }

    function clearErrors(form) {
        $$('.has-error', form).forEach(function (el) { el.classList.remove('has-error'); el.removeAttribute('aria-invalid'); });
        $$('.field__error', form).forEach(function (el) { el.parentNode.removeChild(el); });
    }

    function setField(form, name, value) {
        var el = $('[name="' + name + '"]', form);
        if (!el) return;
        if (el.type === 'checkbox') { el.checked = !!value; return; }
        el.value = value == null ? '' : value;
    }

    /** Prepara el modal en modo "nuevo" o "editar". */
    function prepare(modalId, mode, id, title, values) {
        var modal = doc.getElementById(modalId);
        if (!modal) return null;
        var form = $('form', modal);
        clearErrors(form);
        form.reset();
        // reset() devuelve los campos al valor del HTML; se rellenan explícitamente.
        Object.keys(values || {}).forEach(function (k) { setField(form, k, values[k]); });

        var idField = $('[data-edit-id]', form);
        if (idField) idField.value = mode === 'edit' ? id : '';
        form.action = mode === 'edit'
            ? form.getAttribute('data-update-url').replace('__ID__', id)
            : form.getAttribute('data-store-url');
        setMethod(form, mode === 'edit' ? 'PUT' : 'POST');

        var titleEl = doc.getElementById(modalId + '-title');
        if (titleEl) titleEl.textContent = title;
        return { modal: modal, form: form };
    }

    /* ---------- Tratamientos ---------- */
    function openTreatment(data) {
        var edit = !!data;
        var ctx = prepare('treatment-modal', edit ? 'edit' : 'new', edit ? data.id : null,
            edit ? 'Tratamiento a Editar' : 'Tratamiento Nuevo',
            edit ? {
                name: data.name, category_name: data.category, duration_minutes: data.duration,
                price: data.price, sat_product_key: data.sat, sat_unit_key: data.unit || 'E48', tax_profile_id: data.tax || ''
            } : null);
        if (!ctx) return;

        var del = $('[data-treatment-delete]', ctx.modal);
        var delForm = doc.getElementById('treatment-delete-form');
        if (del) del.hidden = !edit;
        if (delForm && edit) delForm.action = ctx.form.getAttribute('data-destroy-url').replace('__ID__', data.id);
    }

    $$('[data-treatment-new]').forEach(function (btn) {
        btn.addEventListener('click', function () { openTreatment(null); });
    });

    $$('[data-treatment-row]').forEach(function (row) {
        function open() {
            var data;
            try { data = JSON.parse(row.getAttribute('data-treatment')); } catch (e) { return; }
            openTreatment(data);
            if (window.UI) window.UI.openModal('treatment-modal');
        }
        row.addEventListener('click', function (e) {
            if (e.target.closest('form, [data-treatment-edit]')) return;
            open();
        });
        var edit = $('[data-treatment-edit]', row);
        if (edit) edit.addEventListener('click', open);
    });

    /* ---------- Categorías ---------- */
    $$('[data-category-new]').forEach(function (btn) {
        btn.addEventListener('click', function () { prepare('category-modal', 'new', null, 'Nueva categoría', null); });
    });
    $$('[data-category-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            prepare('category-modal', 'edit', btn.getAttribute('data-id'), 'Editar categoría', { name: btn.getAttribute('data-name') });
            if (window.UI) window.UI.openModal('category-modal');
        });
    });

    /* ---------- Impuestos ---------- */
    $$('[data-tax-new]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            prepare('tax-modal', 'new', null, 'Nuevo perfil de impuestos', { tax_code: '002' });
        });
    });
    $$('[data-tax-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            prepare('tax-modal', 'edit', btn.getAttribute('data-id'), 'Editar perfil de impuestos', {
                name: btn.getAttribute('data-name'),
                tax_code: btn.getAttribute('data-code'),
                rate_pct: btn.getAttribute('data-rate'),
                is_exempt: btn.getAttribute('data-exempt') === '1'
            });
            if (window.UI) window.UI.openModal('tax-modal');
        });
    });

    /* Exento => tasa 0 */
    var tax = doc.getElementById('tax-modal');
    if (tax) {
        var exempt = $('input[type="checkbox"][name="is_exempt"]', tax);
        var rate = $('[name="rate_pct"]', tax);
        if (exempt && rate) {
            exempt.addEventListener('change', function () { if (exempt.checked) rate.value = '0'; });
        }
    }
})();
