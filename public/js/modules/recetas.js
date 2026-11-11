/* =====================================================================
   Módulo Recetas: formulario (typeahead de paciente y medicamento,
   renglones dinámicos) y catálogo de medicamentos. JS plano, sin build.
   Configuración inyectada por la vista: window.RX
   ===================================================================== */
(function () {
    'use strict';

    var CFG = window.RX || {};
    var MAX_ROWS = 20;

    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, self = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    function initials(name) {
        return (name || '').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join('').toUpperCase();
    }

    function getJSON(url) {
        return fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : []; })
            .catch(function () { return []; });
    }

    /* ------------------------ Lista desplegable ------------------------ */
    function renderSuggest(box, items, labelFn, subFn, onPick, emptyText) {
        box.innerHTML = '';
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'rx-suggest__empty';
            empty.textContent = emptyText || 'Sin resultados';
            box.appendChild(empty);
        }
        items.forEach(function (it) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = labelFn(it);
            var sub = subFn ? subFn(it) : '';
            if (sub) { var s = document.createElement('small'); s.textContent = sub; b.appendChild(s); }
            b.addEventListener('mousedown', function (e) { e.preventDefault(); onPick(it); });
            box.appendChild(b);
        });
        box.hidden = false;
    }

    function hideOnOutside(box, input) {
        document.addEventListener('click', function (e) {
            if (e.target !== input && !box.contains(e.target)) box.hidden = true;
        });
        input.addEventListener('keydown', function (e) { if (e.key === 'Escape') box.hidden = true; });
    }

    /* --------------------------- Paciente ------------------------------ */
    function initPatient(form) {
        var q = $('[data-patient-q]', form);
        var hid = $('[data-patient-id]', form);
        if (!q || !hid) return;
        var box = $('.rx-suggest', q.parentNode);
        var summary = $('[data-patient-summary]', form);
        var allergy = $('[data-patient-allergy]', form);

        function show(p) {
            if (!p) {
                summary.hidden = true; allergy.hidden = true; return;
            }
            $('[data-ps-avatar]', summary).textContent = initials(p.name);
            $('[data-ps-name]', summary).textContent = p.name;
            $('[data-ps-meta]', summary).textContent = ' · ' + (p.age !== null && p.age !== undefined ? p.age + ' años' : 'edad no registrada') + (p.sex ? ' · ' + p.sex : '');
            summary.hidden = false;
            if (p.allergies) { $('[data-allergy-text]', allergy).textContent = p.allergies; allergy.hidden = false; } else { allergy.hidden = true; }
        }

        var search = debounce(function () {
            var term = q.value.trim();
            getJSON(CFG.patientsUrl + '?q=' + encodeURIComponent(term)).then(function (items) {
                renderSuggest(box, items, function (p) { return p.name + ' · ' + p.folio; },
                    function (p) { return (p.age !== null && p.age !== undefined ? p.age + ' años' : '') + (p.sex ? ' · ' + p.sex : ''); },
                    function (p) { hid.value = p.id; q.value = p.label; box.hidden = true; q.classList.remove('has-error'); show(p); },
                    'No se encontraron pacientes activos');
            });
        }, 250);

        q.addEventListener('input', function () {
            // Al teclear se invalida la selección hasta elegir de la lista.
            hid.value = '';
            show(null);
            search();
        });
        q.addEventListener('focus', function () { if (!hid.value) search(); });
        hideOnOutside(box, q);
    }

    /* -------------------------- Renglones ------------------------------ */
    function initRows(form) {
        var wrap = $('[data-rows]', form);
        var tpl = $('[data-row-template]', form);
        var count = $('[data-count]', form);
        var addBtn = $('[data-row-add]', form);
        if (!wrap || !tpl) return;

        function rows() { return $$('[data-row]', wrap); }

        function reindex() {
            rows().forEach(function (row, i) {
                $$('[name]', row).forEach(function (el) {
                    el.name = el.name.replace(/items\[[^\]]*\]/, 'items[' + i + ']');
                });
            });
            if (count) count.textContent = rows().length;
            if (addBtn) addBtn.disabled = rows().length >= MAX_ROWS;
            rows().forEach(function (row) {
                var rm = $('[data-row-remove]', row);
                if (rm) rm.hidden = rows().length === 1 && !hasContent(row);
            });
        }

        function hasContent(row) {
            return $$('[data-f]', row).some(function (el) { return el.type !== 'hidden' && el.value.trim() !== ''; });
        }

        function addRow() {
            if (rows().length >= MAX_ROWS) return null;
            var html = tpl.innerHTML.replace(/__I__/g, String(rows().length));
            var holder = document.createElement('div');
            holder.innerHTML = html.trim();
            var row = holder.firstElementChild;
            wrap.appendChild(row);
            initMedication(row);
            reindex();
            return row;
        }

        function clearRow(row) {
            $$('[data-f]', row).forEach(function (el) { el.value = ''; el.classList.remove('has-error'); });
            $$('.field__error', row).forEach(function (e) { e.remove(); });
        }

        if (addBtn) addBtn.addEventListener('click', function () {
            var row = addRow();
            if (row) { var f = $('[data-med-name]', row); if (f) f.focus(); }
        });

        wrap.addEventListener('click', function (e) {
            var rm = e.target.closest('[data-row-remove]');
            if (!rm) return;
            var row = rm.closest('[data-row]');
            if (rows().length === 1) { clearRow(row); } else { row.remove(); }
            reindex();
        });

        wrap.addEventListener('input', function (e) {
            if (e.target.classList) e.target.classList.remove('has-error');
            reindex();
        });

        rows().forEach(initMedication);
        reindex();
    }

    function initMedication(row) {
        var name = $('[data-med-name]', row);
        if (!name || name.__rx) return;
        name.__rx = true;
        var box = $('.rx-suggest', name.parentNode);
        var medId = $('[data-f="medication_id"]', row);
        var strength = $('[data-f="strength"]', row);
        var pres = $('[data-f="presentation"]', row);

        var search = debounce(function () {
            var term = name.value.trim();
            getJSON(CFG.medsUrl + '?q=' + encodeURIComponent(term)).then(function (items) {
                renderSuggest(box, items, function (m) { return m.label; },
                    function (m) { return m.strength || ''; },
                    function (m) {
                        name.value = m.name;
                        medId.value = m.id;
                        strength.value = m.strength || '';
                        if (m.presentation && !pres.value.trim()) pres.value = m.presentation;
                        box.hidden = true;
                    },
                    'Sin coincidencias: se usará el texto escrito');
            });
        }, 250);

        name.addEventListener('input', function () {
            // Texto libre: ya no corresponde a un medicamento del catálogo.
            medId.value = '';
            strength.value = '';
            search();
        });
        name.addEventListener('focus', function () { if (!medId.value) search(); });
        hideOnOutside(box, name);
    }

    /* -------------------- Formulario de receta (modal) ----------------- */
    function initRxForm() {
        var form = $('[data-rx-form]');
        if (!form) return;
        initPatient(form);
        initRows(form);

        var overlay = $('#rx-form');
        if (!overlay) return;

        // El modal de crear/editar vive en su propia URL: al cerrarlo se vuelve al listado.
        overlay.addEventListener('modal:close', function () {
            if (CFG.returnUrl) window.location.href = CFG.returnUrl;
        });

        if (CFG.openForm && window.UI) window.UI.openModal('rx-form');

        // Validación ligera previa al envío (el servidor valida todo de nuevo).
        form.addEventListener('submit', function (e) {
            var submitter = e.submitter;
            if (submitter && submitter.value !== 'emitir') return;
            var firstBad = null;
            $$('[data-row]', form).forEach(function (row) {
                ['medication_name', 'dose', 'frequency'].forEach(function (f) {
                    var el = $('[data-f="' + f + '"]', row);
                    if (el && el.value.trim() === '') { el.classList.add('has-error'); firstBad = firstBad || el; }
                });
            });
            var hid = $('[data-patient-id]', form);
            if (hid && !hid.value) { $('[data-patient-q]', form).classList.add('has-error'); firstBad = firstBad || $('[data-patient-q]', form); }
            var dx = $('#diagnosis', form);
            if (dx && dx.value.trim() === '') { dx.classList.add('has-error'); firstBad = firstBad || dx; }
            if (firstBad) {
                e.preventDefault();
                e.stopImmediatePropagation();
                firstBad.focus();
                if (window.UI) window.UI.toast('Completa paciente, diagnóstico y los datos de cada medicamento para firmar y emitir.', 'warning', 'Faltan datos');
            }
        }, true);
    }

    /* ----------------------- Catálogo de medicamentos ------------------ */
    function initCatalog() {
        var form = $('[data-med-form]');
        if (!form) return;
        var overlay = $('#med-form');
        var title = $('.modal__title', overlay);
        var statusWrap = $('[data-f-status-wrap]', form);
        var modeInput = $('[data-f-mode]', form);
        var urlInput = $('[data-f-url]', form);
        var createUrl = form.getAttribute('data-store-url') || form.getAttribute('action');

        function setMethod(method) {
            var spoof = $('input[name="_method"]', form);
            if (method === 'POST') { if (spoof) spoof.remove(); return; }
            if (!spoof) { spoof = document.createElement('input'); spoof.type = 'hidden'; spoof.name = '_method'; form.insertBefore(spoof, form.firstChild); }
            spoof.value = method;
        }

        function fill(data) {
            ['name', 'active_ingredient', 'strength', 'presentation', 'status'].forEach(function (k) {
                var el = form.elements[k];
                if (el) el.value = (data && data[k] != null) ? data[k] : (k === 'status' ? 'active' : '');
            });
            $$('.has-error', form).forEach(function (e) { e.classList.remove('has-error'); });
            $$('.field__error', form).forEach(function (e) { e.remove(); });
        }

        document.addEventListener('click', function (e) {
            var nw = e.target.closest('[data-med-new]');
            if (nw) {
                fill(null);
                form.setAttribute('action', createUrl);
                setMethod('POST');
                modeInput.value = 'create'; urlInput.value = createUrl;
                title.textContent = 'Nuevo medicamento';
                if (statusWrap) statusWrap.hidden = true;
                window.UI.openModal('med-form');
                return;
            }
            var ed = e.target.closest('[data-med-edit]');
            if (ed) {
                var data = {};
                try { data = JSON.parse(ed.getAttribute('data-med')); } catch (err) { data = {}; }
                fill(data);
                var url = ed.getAttribute('data-url');
                form.setAttribute('action', url);
                setMethod('PUT');
                modeInput.value = 'edit'; urlInput.value = url;
                title.textContent = 'Editar medicamento';
                if (statusWrap) statusWrap.hidden = false;
                window.UI.openModal('med-form');
            }
        });

        // Tras un error de validación se conserva el modo (crear/editar) del envío anterior.
        if (modeInput.value === 'edit') {
            setMethod('PUT');
            title.textContent = 'Editar medicamento';
        } else {
            title.textContent = 'Nuevo medicamento';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initRxForm();
        initCatalog();
    });
})();
