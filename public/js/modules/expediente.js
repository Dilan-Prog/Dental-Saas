/* Módulo Expediente + Documentos: odontograma interactivo, zona de carga de archivos,
   filas dinámicas de documentos y pequeños ayudantes. Requiere /js/app.js (window.UI). */
(function () {
    'use strict';
    var doc = document;
    var $ = function (s, r) { return (r || doc).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || doc).querySelectorAll(s)); };

    // ---------------------------------------------------------------- Odontograma
    var WITH_SURFACES = ['caries', 'restauracion', 'endodoncia'];
    var CONDITION_LABELS = { sano: 'Sano', caries: 'Caries', endodoncia: 'Endodoncia', restauracion: 'Restauración', ausente: 'Ausente' };

    function odontoModal() { return doc.getElementById('odonto-modal'); }

    function toggleSurfaces(modal) {
        if (!modal) return;
        var select = $('[data-odonto-condition]', modal);
        var box = $('[data-odonto-surfaces]', modal);
        if (!select || !box) return;
        var on = WITH_SURFACES.indexOf(select.value) !== -1;
        box.hidden = !on;
        if (!on) $$('input[type=checkbox]', box).forEach(function (c) { c.checked = false; });
    }

    function fillOdontoModal(btn) {
        var modal = odontoModal();
        if (!modal) return;
        var tooth = btn.getAttribute('data-tooth');
        var cond = btn.getAttribute('data-condition') || 'sano';
        var surfaces = [];
        try { surfaces = JSON.parse(btn.getAttribute('data-surfaces') || '[]'); } catch (e) { surfaces = []; }

        $('[data-odonto-tooth]', modal).value = tooth;
        var title = $('.modal__title', modal);
        if (title) title.textContent = 'Pieza ' + tooth;
        var current = $('[data-odonto-current]', modal);
        if (current) current.textContent = 'Estado actual: ' + (CONDITION_LABELS[cond] || cond) + (surfaces.length ? ' (' + surfaces.join(', ') + ')' : '');

        var select = $('[data-odonto-condition]', modal);
        if (select) select.value = cond;
        $$('input[name="surfaces[]"]', modal).forEach(function (c) { c.checked = surfaces.indexOf(c.value) !== -1; });
        var tr = $('[data-odonto-treatment]', modal);
        if (tr) tr.value = btn.getAttribute('data-treatment') || '';
        toggleSurfaces(modal);
    }

    doc.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.tooth[data-tooth]');
        if (btn && !btn.disabled) fillOdontoModal(btn);
    });

    doc.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('[data-odonto-condition]')) toggleSurfaces(e.target.closest('.modal-overlay'));
        if (e.target.matches && e.target.matches('[data-autosubmit]') && e.target.value) e.target.form.submit();
    });

    // ---------------------------------------------------------------- Zona de carga de archivos
    function openModal(id) { if (window.UI && window.UI.openModal) window.UI.openModal(id); }

    $$('[data-dropzone]').forEach(function (zone) {
        var modalId = zone.getAttribute('data-dropzone');
        zone.addEventListener('click', function () { openModal(modalId); });
        zone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openModal(modalId); } });
        ['dragenter', 'dragover'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-over'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('is-over'); });
        });
        zone.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            var modal = doc.getElementById(modalId);
            var input = modal && $('[data-file-input]', modal);
            if (input && files && files.length) {
                try { input.files = files; } catch (err) { /* navegadores antiguos */ }
            }
            openModal(modalId);
        });
    });

    // ---------------------------------------------------------------- Filas dinámicas (documentos)
    doc.addEventListener('click', function (e) {
        var add = e.target.closest && e.target.closest('[data-add-row]');
        if (!add) return;
        var key = add.getAttribute('data-add-row');
        var holder = $('[data-rows="' + key + '"]');
        if (!holder) return;
        var rows = $$('[data-row]', holder);
        var last = rows[rows.length - 1];
        if (!last) return;
        var idx = rows.length;
        var clone = last.cloneNode(true);
        $$('input, select, textarea', clone).forEach(function (el) {
            if (el.name) el.name = el.name.replace(/\[\d+\]/, '[' + idx + ']');
            if (el.id) el.id = el.id.replace(/\.\d+\./, '.' + idx + '.').replace(/_\d+_/, '_' + idx + '_');
            if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = '';
            el.classList.remove('has-error');
        });
        $$('.field__error', clone).forEach(function (n) { n.remove(); });
        holder.appendChild(clone);
        var first = $('input', clone);
        if (first) first.focus();
    });

    // ---------------------------------------------------------------- Aceptación opcional (autorización)
    function syncAcceptance() {
        $$('[data-acceptance]').forEach(function (wrap) {
            var toggle = $('[data-acceptance-toggle]', wrap);
            var fields = $('[data-acceptance-fields]', wrap);
            if (toggle && fields) fields.hidden = !toggle.checked;
        });
    }
    doc.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('[data-acceptance-toggle]')) syncAcceptance(); });

    // ---------------------------------------------------------------- Inicio
    function init() {
        var modal = odontoModal();
        if (modal) {
            var tooth = $('[data-odonto-tooth]', modal);
            if (tooth && tooth.value) {
                var title = $('.modal__title', modal);
                if (title) title.textContent = 'Pieza ' + tooth.value;
            }
            toggleSurfaces(modal);
        }
        syncAcceptance();
    }
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init); else init();
})();
