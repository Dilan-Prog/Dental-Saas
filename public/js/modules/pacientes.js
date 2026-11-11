/* Módulo Pacientes — comportamiento de listado, ficha y formularios. Vanilla JS. */
(function () {
    'use strict';

    var doc = document;
    function $(s, r) { return (r || doc).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || doc).querySelectorAll(s)); }

    // ------------------------------------------------------------------ listado
    // Los filtros se aplican solos al cambiar un select; la búsqueda al pulsar Enter.
    $$('form[data-autosubmit]').forEach(function (form) {
        $$('select', form).forEach(function (sel) {
            sel.addEventListener('change', function () { form.submit(); });
        });
    });

    // Filas del listado clicables (salvo que se pulse un botón, enlace o formulario).
    doc.addEventListener('click', function (e) {
        var row = e.target.closest('tr[data-href]');
        if (!row || e.target.closest('a, button, form, input, select, label, .dropdown')) { return; }
        window.location.href = row.getAttribute('data-href');
    });

    // ------------------------------------------------------ tutor (menor de edad)
    function ageFrom(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!m) { return null; }
        var birth = new Date(+m[1], +m[2] - 1, +m[3]);
        if (isNaN(birth.getTime())) { return null; }
        var today = new Date();
        var age = today.getFullYear() - birth.getFullYear();
        var md = today.getMonth() - birth.getMonth();
        if (md < 0 || (md === 0 && today.getDate() < birth.getDate())) { age--; }
        return age;
    }

    $$('[data-guardian-section]').forEach(function (box) {
        var form = box.closest('form');
        var birth = form && $('[data-birth-date]', form);
        if (!birth) { return; }
        var hasGuardian = box.getAttribute('data-has-guardian') === '1';
        var hasValues = $$('input, select', box).some(function (el) { return el.value; });

        function sync() {
            var age = ageFrom(birth.value);
            var minor = age !== null && age < 18 && !hasGuardian;
            box.hidden = !(minor || hasValues);
        }
        birth.addEventListener('change', sync);
        birth.addEventListener('input', sync);
        sync();
    });

    // ------------------------------------- modales de datos fiscales / tutores
    // Botones [data-fill-modal="id"] [data-record='{"campo":"valor"}'] [data-title="..."].
    doc.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-fill-modal]');
        if (!btn) { return; }
        e.preventDefault();

        var modal = doc.getElementById(btn.getAttribute('data-fill-modal'));
        if (!modal) { return; }
        var record = {};
        try { record = JSON.parse(btn.getAttribute('data-record') || '{}'); } catch (err) { record = {}; }

        // Limpia errores previos del formulario.
        $$('.has-error', modal).forEach(function (el) { el.classList.remove('has-error'); });
        $$('.field__error', modal).forEach(function (el) { el.parentNode.removeChild(el); });

        Object.keys(record).forEach(function (name) {
            var el = $('[name="' + name + '"]', modal);
            if (!el) { return; }
            if (el.type === 'checkbox') { el.checked = !!record[name]; }
            else { el.value = record[name] == null ? '' : record[name]; }
            if (window.UI && UI.applyMask && el.hasAttribute('data-mask')) { UI.applyMask(el); }
        });

        var title = $('.modal__title', modal);
        if (title && btn.getAttribute('data-title')) { title.textContent = btn.getAttribute('data-title'); }

        if (window.UI) { UI.openModal(modal.id); }
        var first = $('input:not([type=hidden]), select', modal);
        if (first) { setTimeout(function () { try { first.focus(); } catch (err) {} }, 60); }
    });
})();
