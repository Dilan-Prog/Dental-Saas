/* =====================================================================
   Módulo Agenda — JS plano.
   - Detalle de cita (modal) y acciones: cancelar, iniciar, reagendar,
     confirmar, completar, no asistió.
   - Nueva cita: buscador de pacientes, duración según tratamiento y
     horarios disponibles (JSON).
   Config: window.AGENDA (la define la vista). Usa window.UI de /js/app.js.
   ===================================================================== */
(function () {
    'use strict';

    var A = window.AGENDA || {};
    var UI = window.UI;
    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

    var VARIANT = { primary: 'primary', success: 'success', warning: 'warning', danger: 'danger', info: 'info', muted: 'neutral' };

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, self = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    function getJSON(url) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
    }

    function parseAppt(el) {
        var holder = el.closest('[data-appt]');
        if (!holder) { return null; }
        try { return JSON.parse(holder.getAttribute('data-appt')); } catch (e) { return null; }
    }

    function setValue(input, value) {
        if (!input) { return; }
        input.value = value == null ? '' : value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function clearErrors(scope) {
        $$('.has-error', scope).forEach(function (n) { n.classList.remove('has-error'); n.removeAttribute('aria-invalid'); });
        $$('.field__error', scope).forEach(function (n) { n.remove(); });
        $$('.alert', scope).forEach(function (n) { n.remove(); });
    }

    /* -------------------------------------------------------------
       Horarios disponibles (se usa en "Nueva cita" y "Reagendar")
       ------------------------------------------------------------- */
    function SlotsPicker(opts) {
        var seq = 0;

        function render(times) {
            opts.list.innerHTML = '';
            if (!times.length) {
                var p = document.createElement('span');
                p.className = 'ag-slots__none';
                p.textContent = 'No hay horarios disponibles ese día con esa duración.';
                opts.list.appendChild(p);
                return;
            }
            times.forEach(function (t) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'ag-slot' + (opts.time.value === t ? ' is-selected' : '');
                b.textContent = t;
                b.setAttribute('data-time', t);
                b.addEventListener('click', function () {
                    setValue(opts.time, t);
                    mark();
                });
                opts.list.appendChild(b);
            });
        }

        function mark() {
            $$('.ag-slot', opts.list).forEach(function (b) {
                b.classList.toggle('is-selected', b.getAttribute('data-time') === opts.time.value);
            });
        }

        function refresh() {
            var doctor = opts.doctor.value, date = opts.date.value;
            if (!doctor || !date) { opts.box.hidden = true; return; }

            var params = new URLSearchParams({ doctor_member_id: doctor, date: date });
            if (opts.duration && opts.duration.value) { params.set('duration_minutes', opts.duration.value); }
            if (opts.patient && opts.patient.value) { params.set('patient_id', opts.patient.value); }
            if (opts.ignore && opts.ignore()) { params.set('ignore', opts.ignore()); }

            var mine = ++seq;
            getJSON(A.urls.slots + '?' + params.toString()).then(function (res) {
                if (mine !== seq) { return; }
                opts.box.hidden = false;
                render(res.ok ? (res.body.data || []) : []);
            }).catch(function () { if (mine === seq) { opts.box.hidden = true; } });
        }

        var deb = debounce(refresh, 200);
        [opts.doctor, opts.date, opts.duration].forEach(function (el) {
            if (el) { el.addEventListener('change', deb); }
        });
        if (opts.duration) { opts.duration.addEventListener('input', deb); }
        if (opts.time) { opts.time.addEventListener('input', mark); }

        return { refresh: refresh };
    }

    /* -------------------------------------------------------------
       Nueva cita
       ------------------------------------------------------------- */
    var modalNew = $('#cita-nueva');
    var newForm = modalNew ? $('form', modalNew) : null;
    var newSlots = null;

    if (modalNew && newForm) {
        var elPatientId = $('[data-patient-id]', modalNew);
        var elPatientLabel = $('[data-patient-label]', modalNew);
        var elSearch = $('[data-patient-search]', modalNew);
        var elResults = $('[data-patient-results]', modalNew);
        var elDoctor = $('[data-doctor]', modalNew);
        var elTreatment = $('[data-treatment]', modalNew);
        var elDate = $('[data-date]', modalNew);
        var elTime = $('[data-time-input]', modalNew);
        var elDuration = $('[data-duration-input]', modalNew);
        var elNotes = $('[name="notes"]', modalNew);

        newSlots = SlotsPicker({
            doctor: elDoctor, date: elDate, time: elTime, duration: elDuration, patient: elPatientId,
            box: $('[data-slots]', modalNew), list: $('[data-slots-list]', modalNew)
        });

        // Buscador de pacientes
        var activeIdx = -1;
        var hideResults = function () { elResults.hidden = true; elResults.innerHTML = ''; activeIdx = -1; };

        var pick = function (p) {
            var label = p.name + (p.folio ? ' (' + p.folio + ')' : '');
            elPatientId.value = p.id;
            elPatientLabel.value = label;
            elSearch.value = label;
            elSearch.classList.remove('has-error');
            hideResults();
            newSlots.refresh();
        };

        var renderResults = function (items) {
            elResults.innerHTML = '';
            if (!items.length) {
                var empty = document.createElement('li');
                empty.className = 'is-empty';
                empty.textContent = 'Sin resultados';
                elResults.appendChild(empty);
            }
            items.forEach(function (p) {
                var li = document.createElement('li');
                var name = document.createElement('span');
                name.textContent = p.name;
                var meta = document.createElement('small');
                meta.textContent = p.folio || '';
                li.appendChild(name);
                li.appendChild(meta);
                li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(p); });
                elResults.appendChild(li);
            });
            elResults.hidden = false;
            activeIdx = -1;
        };

        var search = debounce(function () {
            var q = elSearch.value.trim();
            if (q.length < 2) { hideResults(); return; }
            getJSON(A.urls.patients + '?q=' + encodeURIComponent(q)).then(function (res) {
                if (elSearch.value.trim() === q) { renderResults(res.body.data || []); }
            }).catch(hideResults);
        }, 250);

        elSearch.addEventListener('input', function () {
            // Al teclear de nuevo, la selección anterior deja de valer.
            elPatientId.value = '';
            elPatientLabel.value = '';
            search();
        });
        elSearch.addEventListener('keydown', function (e) {
            var items = $$('li:not(.is-empty)', elResults);
            if (elResults.hidden || !items.length) { return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                activeIdx = (activeIdx + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                items.forEach(function (li, i) { li.classList.toggle('is-active', i === activeIdx); });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                (items[activeIdx >= 0 ? activeIdx : 0]).dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
            } else if (e.key === 'Escape') {
                hideResults();
            }
        });
        elSearch.addEventListener('blur', function () { setTimeout(hideResults, 120); });

        // La duración sigue al tratamiento elegido.
        elTreatment.addEventListener('change', function () {
            var opt = elTreatment.options[elTreatment.selectedIndex];
            var minutes = opt ? opt.getAttribute('data-duration') : null;
            if (minutes) { setValue(elDuration, minutes); }
        });

        // Restablece el formulario (sin tocar los valores si venimos de un error).
        var resetNew = function (prefill) {
            prefill = prefill || {};
            clearErrors(modalNew);
            elPatientId.value = prefill.patientId || '';
            elPatientLabel.value = prefill.patientLabel || '';
            elSearch.value = prefill.patientLabel || '';
            elTreatment.value = '';
            elDoctor.value = prefill.doctor || (A.filters && A.filters.doctor) || '';
            elDate.value = prefill.date || A.today || '';
            elTime.value = prefill.time || '';
            elDuration.value = A.config ? A.config.defaultDuration : 30;
            if (elNotes) { elNotes.value = ''; }
            hideResults();
        };

        var openNew = function (prefill) {
            resetNew(prefill);
            UI.openModal('cita-nueva');
            newSlots.refresh();
        };

        $$('[data-agenda-new]').forEach(function (b) {
            b.addEventListener('click', function () {
                var pre = A.preselected;
                resetNew(pre ? { patientId: pre.id, patientLabel: pre.name + ' (' + pre.folio + ')' } : {});
                newSlots.refresh();
            });
        });

        // Clic en una celda vacía del calendario
        document.addEventListener('click', function (e) {
            var cell = e.target.closest('[data-new-slot]');
            if (!cell) { return; }
            openNew({ date: cell.getAttribute('data-date'), time: cell.getAttribute('data-hour') });
        });

        // Apertura automática: ?patient=uuid, o error de validación al crear.
        if (A.createFailed) {
            UI.openModal('cita-nueva');
            newSlots.refresh();
        } else if (A.openNew && A.preselected) {
            openNew({ patientId: A.preselected.id, patientLabel: A.preselected.name + ' (' + A.preselected.folio + ')' });
        }
    }

    /* -------------------------------------------------------------
       Detalle y acciones sobre una cita
       ------------------------------------------------------------- */
    var modalDetail = $('#cita-detalle');

    function fillDetail(p) {
        if (!modalDetail) { return; }

        var map = {
            patient: p.patient + (p.folio ? ' (' + p.folio + ')' : ''),
            treatment: p.treatment,
            doctor: p.doctor,
            date_label: p.date_label,
            duration: p.duration + ' min',
            notes: p.notes || ''
        };
        $$('[data-f]', modalDetail).forEach(function (n) {
            var key = n.getAttribute('data-f');
            if (key === 'status_badge') {
                n.textContent = p.status_label;
                n.className = 'badge badge--' + (VARIANT[p.status_color] || 'neutral');
            } else if (key in map) {
                n.textContent = map[key];
            }
        });
        var notesRow = $('[data-f-row="notes"]', modalDetail);
        if (notesRow) { notesRow.hidden = !p.notes; }

        var show = $('[data-f-href="show"]', modalDetail);
        if (show) { show.setAttribute('href', p.urls.show); }

        $$('[data-act]', modalDetail).forEach(function (b) { b.hidden = !p.can[b.getAttribute('data-act')]; });
        $$('form[data-form]', modalDetail).forEach(function (f) {
            var name = f.getAttribute('data-form');
            f.hidden = !p.can[name];
            f.setAttribute('action', p.urls[name]);
        });
        modalDetail.__appt = p;
    }

    function formFor(name) { return $('[data-appt-form="' + name + '"]'); }

    function openAction(type, p, keepOld) {
        var form = formFor(type);
        if (!form || !p) { return; }
        var modal = form.closest('.modal-overlay');

        form.setAttribute('action', p.urls[type]);
        var idField = $('[data-appt-id]', form);
        if (idField) { idField.value = p.id; }

        var who = p.patient + ' (' + p.time + ')';

        if (type === 'cancel') {
            $('[data-cancel-text]', form).textContent = 'La cita de ' + who + ' se marcará como cancelada y el paciente recibirá un aviso.';
            if (!keepOld) { clearErrors(modal); $('[name="reason"]', form).value = ''; }
        } else if (type === 'start') {
            $('[data-start-text]', form).textContent = 'La cita de ' + who + ' pasará a en curso y se abrirá su consulta en el expediente.';
        } else if (type === 'reschedule') {
            $('[data-resched-text]', form).textContent = 'Cita de ' + p.patient + ' · ' + p.treatment + '. Actualmente: ' + p.date_label + '.';
            if (!keepOld) {
                clearErrors(modal);
                $('[data-r-doctor]', form).value = p.reschedule.doctor_member_id;
                $('[data-r-date]', form).value = p.reschedule.date;
                $('[data-r-time]', form).value = p.reschedule.start_time;
                $('[data-r-duration]', form).value = p.reschedule.duration_minutes;
                $('[name="change_reason"]', form).value = '';
            }
            resched.current = p;
            resched.picker.refresh();
        }

        if (modalDetail) { UI.closeModal('cita-detalle'); }
        UI.openModal(modal.id);
    }

    // Selector de horarios de "Reagendar"
    var resched = { current: null, picker: { refresh: function () {} } };
    var reForm = formFor('reschedule');
    if (reForm) {
        resched.picker = SlotsPicker({
            doctor: $('[data-r-doctor]', reForm), date: $('[data-r-date]', reForm), time: $('[data-r-time]', reForm),
            duration: $('[data-r-duration]', reForm),
            box: $('[data-r-slots]', reForm), list: $('[data-r-slots-list]', reForm),
            ignore: function () { return resched.current ? resched.current.id : null; }
        });
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-appt-open]');
        if (opener) {
            var p = parseAppt(opener);
            if (!p) { return; }
            var type = opener.getAttribute('data-appt-open');
            if (type === 'detail') {
                fillDetail(p);
                UI.openModal('cita-detalle');
            } else {
                openAction(type, p, false);
            }
            return;
        }

        var act = e.target.closest('[data-act]');
        if (act && modalDetail && modalDetail.contains(act)) {
            openAction(act.getAttribute('data-act'), modalDetail.__appt, false);
        }
    });

    // Reabrir cancelar / reagendar tras un error de validación del servidor.
    if (A.reopen && A.reopen.payload) {
        openAction(A.reopen.form, A.reopen.payload, true);
    }
})();
