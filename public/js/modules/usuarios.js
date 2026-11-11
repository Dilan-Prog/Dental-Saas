/* Módulo Usuarios y Roles — JS plano (sin dependencias) */
(function () {
    'use strict';

    var doc = document;
    function $(sel, ctx) { return (ctx || doc).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }

    // ---- Filtros del listado: enviar al cambiar un select y con debounce al escribir ----
    var filters = $('[data-filters]');
    if (filters) {
        $$('select', filters).forEach(function (s) { s.addEventListener('change', function () { filters.submit(); }); });
        var q = $('input[name="q"]', filters), t;
        if (q) {
            q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { filters.submit(); }, 500); });
            // dejar el cursor al final tras recargar con búsqueda activa
            if (q.value && doc.activeElement === doc.body) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
        }
    }

    // ---- Copiar el enlace de acceso ----
    $$('[data-copy-access]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = $('[data-access-url]', btn.closest('[data-access-link]') || doc);
            if (!input) return;
            input.select();
            var done = function () { if (window.UI && UI.toast) UI.toast('Enlace copiado al portapapeles.', 'success'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(done, function () { doc.execCommand('copy'); done(); });
            } else { doc.execCommand('copy'); done(); }
        });
    });

    // ---- Bloque de perfil de doctor: mostrar/ocultar campos ----
    $$('[data-doctor-block]').forEach(function (block) {
        var toggle = $('[data-doctor-toggle]', block);
        var fields = $('[data-doctor-fields]', block);
        if (!toggle || !fields) return;
        var sync = function () { fields.hidden = !toggle.checked; };
        toggle.addEventListener('change', sync);
        sync();
    });

    // ---- Vista previa de la imagen de firma ----
    var sigInput = $('[data-signature-input]');
    var sigPreview = $('[data-signature-preview]');
    if (sigInput && sigPreview) {
        sigInput.addEventListener('change', function () {
            var f = sigInput.files && sigInput.files[0];
            if (!f) return;
            if (f.size > 1024 * 1024) {
                if (window.UI && UI.toast) UI.toast('La imagen de firma no puede pesar más de 1 MB.', 'error');
                sigInput.value = '';
                return;
            }
            sigPreview.src = URL.createObjectURL(f);
            sigPreview.hidden = false;
        });
    }

    // ---- Matriz de permisos: "Ver" es requisito de las demás acciones del módulo ----
    var form = $('[data-permission-matrix]');
    if (form) {
        $$('tr[data-module]', form).forEach(function (row) {
            var view = $('input[data-action="ver"]', row);
            var others = $$('input[data-action]', row).filter(function (i) { return i.getAttribute('data-action') !== 'ver'; });
            if (!view) return;

            others.forEach(function (i) {
                i.addEventListener('change', function () { if (i.checked) view.checked = true; });
            });
            view.addEventListener('change', function () {
                if (!view.checked) others.forEach(function (i) { i.checked = false; });
            });
        });

        // Avisar si se intenta salir con cambios sin guardar
        var dirty = false;
        form.addEventListener('change', function () { dirty = true; });
        form.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    }
})();
