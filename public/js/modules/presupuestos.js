/* Módulo Presupuestos: filas clicables, selector de paciente y formulario de conceptos
   con recálculo en vivo. El servidor recalcula y es la fuente de verdad al guardar. */
(function () {
    'use strict';

    var doc = document;

    function $(sel, root) { return (root || doc).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); }

    /* ---- Listado: fila clicable ---- */
    $$('tr[data-href]').forEach(function (tr) {
        tr.addEventListener('click', function (e) {
            if (e.target.closest('a, button, form, input, select')) return;
            window.location.href = tr.getAttribute('data-href');
        });
    });

    var form = $('#quote-form');
    if (!form) return;

    /* ---- Importes (mismo redondeo half-up por concepto que el servidor) ---- */
    function toCents(value) {
        var n = parseFloat(value);
        return isFinite(n) ? n : 0;
    }
    function round2(n) {
        // half-up a 2 decimales con corrección de coma flotante
        return Math.round((n + Number.EPSILON) * 100) / 100;
    }
    function money(n) {
        return '$' + round2(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    var treatments = {};
    try { treatments = JSON.parse(form.getAttribute('data-treatments') || '{}'); } catch (e) { treatments = {}; }

    var linesBody = $('#quote-lines');
    var template = $('#line-template');
    var nextIndex = $$('[data-line]', linesBody).length;

    function recalc() {
        var subtotal = 0, discount = 0, taxes = {};

        $$('[data-line]', linesBody).forEach(function (row) {
            var t = treatments[$('.js-treatment', row).value] || null;
            var qty = Math.max(0, toCents($('.js-qty', row).value));
            var price = Math.max(0, toCents($('.js-price', row).value));
            var pct = Math.min(100, Math.max(0, toCents($('.js-discount', row).value)));

            var lineSubtotal = round2(qty * price);
            var lineDiscount = round2(lineSubtotal * pct / 100);
            var base = round2(lineSubtotal - lineDiscount);
            var tax = 0;

            if (t && !t.exempt) {
                tax = round2(base * parseFloat(t.rate || 0));
                if (tax > 0) {
                    var key = t.tax || 'IVA';
                    taxes[key] = round2((taxes[key] || 0) + tax);
                }
            }

            subtotal = round2(subtotal + lineSubtotal);
            discount = round2(discount + lineDiscount);
            $('.js-line-amount', row).textContent = money(base);
        });

        var taxTotal = 0;
        var taxesHtml = '';
        Object.keys(taxes).forEach(function (label) {
            taxTotal = round2(taxTotal + taxes[label]);
            taxesHtml += '<div class="quote-totals__row"><span>' + escapeHtml(label) + '</span><span class="mono">' + money(taxes[label]) + '</span></div>';
        });

        $('#total-subtotal').textContent = money(subtotal);
        $('#row-discount').hidden = discount <= 0;
        $('#total-discount').textContent = '-' + money(discount);
        $('#total-taxes').innerHTML = taxesHtml;
        $('#total-total').textContent = money(round2(subtotal - discount + taxTotal));
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    form.addEventListener('input', function (e) {
        if (e.target.closest('[data-line]')) recalc();
    });

    form.addEventListener('change', function (e) {
        var select = e.target.closest('.js-treatment');
        if (!select) return;
        // Al elegir un tratamiento se propone su precio de catálogo.
        var t = treatments[select.value];
        var price = $('.js-price', select.closest('[data-line]'));
        if (t && price) price.value = parseFloat(t.price);
        recalc();
    });

    /* ---- Agregar / quitar conceptos ---- */
    $('#add-line').addEventListener('click', function () {
        var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
        linesBody.insertAdjacentHTML('beforeend', html);
        var added = linesBody.lastElementChild;
        var first = $('.js-treatment', added);
        if (first) first.focus();
        recalc();
    });

    linesBody.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-remove-line]');
        if (!btn) return;
        var rows = $$('[data-line]', linesBody);
        if (rows.length <= 1) {
            // Siempre queda un concepto: se limpia en lugar de quitarlo.
            var row = rows[0];
            $('.js-treatment', row).value = '';
            $('.js-price', row).value = '';
            $('.js-qty', row).value = '1';
            $('.js-discount', row).value = '0';
        } else {
            btn.closest('[data-line]').remove();
        }
        recalc();
    });

    /* ---- Selector de paciente (búsqueda remota) ---- */
    var picker = $('[data-patient-picker]');
    if (picker) {
        var hidden = $('#patient_id');
        var input = $('#patient-search');
        var results = $('[data-patient-results]', picker);
        var url = form.getAttribute('data-patient-search');
        var timer = null, seq = 0;

        function hideResults() { results.hidden = true; results.innerHTML = ''; }

        function render(items) {
            results.innerHTML = '';
            if (!items.length) {
                var empty = doc.createElement('li');
                empty.className = 'patient-picker__empty';
                empty.textContent = 'Sin pacientes que coincidan';
                results.appendChild(empty);
            }
            items.forEach(function (p) {
                var li = doc.createElement('li');
                li.setAttribute('role', 'option');
                var name = doc.createElement('span');
                name.textContent = p.name;
                var meta = doc.createElement('small');
                meta.textContent = p.folio + (p.phone ? ' · ' + p.phone : '');
                li.appendChild(name);
                li.appendChild(meta);
                li.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    hidden.value = p.id;
                    input.value = p.name + ' · ' + p.folio;
                    input.classList.remove('has-error');
                    hideResults();
                });
                results.appendChild(li);
            });
            results.hidden = false;
        }

        input.addEventListener('input', function () {
            // Si se escribe de nuevo, se invalida la selección previa.
            hidden.value = '';
            clearTimeout(timer);
            var term = input.value.trim();
            if (term.length < 2) { hideResults(); return; }
            timer = setTimeout(function () {
                var mine = ++seq;
                fetch(url + '?q=' + encodeURIComponent(term), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (items) { if (mine === seq) render(items); })
                    .catch(function () { hideResults(); });
            }, 250);
        });

        input.addEventListener('blur', function () { setTimeout(hideResults, 150); });
        input.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideResults(); });
    }

    recalc();
})();
