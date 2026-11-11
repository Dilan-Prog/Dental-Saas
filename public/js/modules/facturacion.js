/* Modulo Facturacion: formulario de factura (conceptos y totales), metodo de pago,
   motivo de cancelacion y pago multiple. JS vanilla, sin dependencias. */
(function () {
    'use strict';
    var doc = document;
    var $ = function (s, r) { return (r || doc).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || doc).querySelectorAll(s)); };

    function round2(n) { return Math.round((n + Number.EPSILON) * 100) / 100; }
    function money(n) {
        return '$' + Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function num(v) { var n = parseFloat(String(v == null ? '' : v).replace(/[^0-9.\-]/g, '')); return isNaN(n) ? 0 : n; }

    /* ------------------------------ formulario de factura ------------------------------ */
    var form = $('[data-invoice-form]');
    if (form) {
        var data = {};
        try { data = JSON.parse($('#fact-data').textContent); } catch (e) {}
        var taxes = data.taxProfiles || {};
        var lines = $('#fact-lines');
        var tpl = $('#fact-line-tpl');

        var computeLine = function (row) {
            var qty = num($('[data-f="quantity"]', row).value);
            var price = num($('[data-f="unit_price"]', row).value);
            var pct = Math.min(100, Math.max(0, num($('[data-f="discount_pct"]', row).value)));
            var objeto = $('[data-f="tax_object_code"]', row).value;
            var tid = $('[data-f="tax_profile_id"]', row).value;
            var amount = round2(qty * price);
            var discount = round2(amount * pct / 100);
            var base = round2(amount - discount);
            var tax = 0;
            if ((objeto === '02' || objeto === '03') && tid && taxes[tid] && !taxes[tid].is_exempt) {
                tax = round2(base * Number(taxes[tid].rate));
            }
            return { amount: amount, discount: discount, tax: tax, total: round2(base + tax) };
        };

        var recalc = function () {
            var sub = 0, dis = 0, tax = 0;
            $$('.fact-line', lines).forEach(function (row) {
                var c = computeLine(row);
                sub += c.amount; dis += c.discount; tax += c.tax;
                $('[data-line-total]', row).textContent = money(c.total);
            });
            $('[data-sum="subtotal"]', form).textContent = money(round2(sub));
            $('[data-sum="discount"]', form).textContent = '-' + money(round2(dis));
            $('[data-sum="tax"]', form).textContent = money(round2(tax));
            $('[data-sum="total"]', form).textContent = money(round2(sub - dis + tax));
        };

        var syncTaxField = function (row) {
            var objeto = $('[data-f="tax_object_code"]', row).value;
            var sel = $('[data-f="tax_profile_id"]', row);
            var needs = objeto === '02' || objeto === '03';
            sel.disabled = !needs;
            if (!needs) { sel.value = ''; }
        };

        var reindex = function () {
            $$('.fact-line', lines).forEach(function (row, i) {
                $$('[data-f]', row).forEach(function (el) {
                    el.name = 'items[' + i + '][' + el.getAttribute('data-f') + ']';
                });
                var n = $('[data-line-n]', row); if (n) n.textContent = i + 1;
                var rm = $('[data-remove-line]', row); if (rm) rm.hidden = lines.children.length <= 1;
            });
        };

        var addLine = function () {
            var holder = doc.createElement('div');
            holder.innerHTML = tpl.innerHTML.trim();
            var row = holder.firstElementChild;
            lines.appendChild(row);
            syncTaxField(row);
            reindex(); recalc();
            return row;
        };

        $('[data-add-line]', form).addEventListener('click', function () {
            var row = addLine();
            var d = $('[data-f="description"]', row); if (d) d.focus();
        });

        form.addEventListener('click', function (e) {
            var rm = e.target.closest('[data-remove-line]');
            if (!rm) return;
            var row = rm.closest('.fact-line');
            if (lines.children.length > 1) { row.remove(); reindex(); recalc(); }
        });
        form.addEventListener('input', function (e) {
            if (e.target.closest('.fact-line')) recalc();
        });
        form.addEventListener('change', function (e) {
            var row = e.target.closest('.fact-line');
            if (row) { if (e.target.matches('[data-f="tax_object_code"]')) syncTaxField(row); recalc(); }
        });

        /* ---- receptor: perfiles fiscales del paciente ---- */
        var patientSel = $('#patient_id', form);
        var profileSel = $('#fiscal_profile_id', form);
        var modeInputs = $$('input[name="receiver_mode"]', form);
        var box = $('[data-receiver-box]', form);
        var useSel = $('#cfdi_use', form);
        var methodSel = $('#cfdi_payment_method', form);
        var formSel = $('#cfdi_payment_form', form);

        var currentMode = function () {
            var m = modeInputs.filter(function (i) { return i.checked; })[0];
            return m ? m.value : 'patient';
        };

        var renderReceiver = function (keepProfile) {
            var pid = patientSel.value;
            var list = (data.profiles && data.profiles[pid]) || [];
            var mode = currentMode();
            var selected = keepProfile ? profileSel.getAttribute('data-selected') || profileSel.value : '';

            profileSel.innerHTML = '';
            list.forEach(function (p) {
                var o = doc.createElement('option');
                o.value = p.id; o.textContent = p.rfc + ' · ' + p.legal_name + (p.default ? ' (predeterminado)' : '');
                profileSel.appendChild(o);
            });
            if (selected) profileSel.value = selected;
            if (!profileSel.value && list.length) {
                var def = list.filter(function (p) { return p.default; })[0] || list[0];
                profileSel.value = def.id;
            }
            profileSel.closest('.field').hidden = mode !== 'patient' || !list.length;

            if (!pid) { box.textContent = 'Selecciona un paciente.'; box.classList.remove('is-warning'); return; }
            if (mode === 'publico_general') {
                box.classList.remove('is-warning');
                box.textContent = 'Publico en general (XAXX010101000) · regimen 616 · uso de CFDI S01.';
                if (useSel && !keepProfile) useSel.value = 'S01';
                return;
            }
            if (!list.length) {
                box.classList.add('is-warning');
                box.textContent = 'El paciente no tiene datos fiscales. Puedes guardar el borrador, pero no podras emitir hasta capturar RFC, regimen y codigo postal fiscal en su expediente.';
                return;
            }
            var p = list.filter(function (x) { return x.id === profileSel.value; })[0] || list[0];
            box.classList.remove('is-warning');
            box.textContent = p.legal_name + ' · RFC ' + p.rfc + ' · regimen ' + p.regime + ' · C.P. ' + p.cp;
            if (useSel && !keepProfile && p.use) useSel.value = p.use;
        };

        patientSel.addEventListener('change', function () { renderReceiver(false); });
        profileSel.addEventListener('change', function () { renderReceiver(true); });
        modeInputs.forEach(function (i) { i.addEventListener('change', function () { renderReceiver(true); }); });

        /* ---- PUE/PPD => forma de pago ---- */
        var syncMethod = function () {
            var ppd = methodSel.value === 'PPD';
            if (ppd) { formSel.value = '99'; }
            else if (formSel.value === '99') { formSel.value = '04'; }
            Array.prototype.forEach.call(formSel.options, function (o) {
                o.disabled = ppd ? o.value !== '99' : o.value === '99';
            });
        };
        methodSel.addEventListener('change', syncMethod);

        $$('.fact-line', lines).forEach(syncTaxField);
        renderReceiver(true);
        syncMethod();
        reindex();
        recalc();
    }

    /* ------------------------------ modal registrar pago ------------------------------ */
    $$('[data-method-group]').forEach(function (group) {
        var input = $('input[type="hidden"]', group.parentNode);
        var ref = group.closest('form').querySelector('[data-ref-field]');
        var refInput = ref ? ref.querySelector('input') : null;
        var refReq = ref ? ref.querySelector('.req, [data-ref-req]') : null;

        var set = function (value) {
            input.value = value;
            $$('button', group).forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-method') === value); });
            if (refInput) {
                var required = value === 'transferencia';
                refInput.required = required;
                if (refReq) refReq.hidden = !required;
                refInput.placeholder = { tarjeta: 'AUTH-', transferencia: 'Clave de rastreo / SPEI', efectivo: 'Opcional', domiciliado: 'Opcional' }[value] || '';
            }
        };
        group.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-method]');
            if (b) { e.preventDefault(); set(b.getAttribute('data-method')); }
        });
        set(input.value || 'tarjeta');
    });

    /* ------------------------------ modal cancelar factura ------------------------------ */
    var reason = $('#cancel_reason_code');
    if (reason) {
        var sub = $('[data-substitute-field]');
        var syncReason = function () {
            var is01 = reason.value === '01';
            sub.hidden = !is01;
            var sel = $('select', sub); if (sel) { sel.required = is01; if (!is01) sel.value = ''; }
        };
        reason.addEventListener('change', syncReason);
        syncReason();
    }

    /* ------------------------------ pago multiple ------------------------------ */
    var multi = $('[data-multi-payment]');
    if (multi) {
        var totalEl = $('[data-multi-total]', multi);
        var recalcMulti = function () {
            var t = 0;
            $$('input[data-app]', multi).forEach(function (i) {
                var max = num(i.getAttribute('data-max'));
                var v = num(i.value);
                i.classList.toggle('has-error', v > max + 0.0001);
                t += v;
            });
            totalEl.textContent = money(round2(t));
        };
        multi.addEventListener('input', recalcMulti);
        multi.addEventListener('click', function (e) {
            var b = e.target.closest('[data-fill]');
            if (!b) return;
            var i = $('input[data-app]', b.closest('tr'));
            i.value = i.getAttribute('data-max'); recalcMulti();
        });
        recalcMulti();
    }
})();
