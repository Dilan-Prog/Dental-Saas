{{--
  Modales de la agenda. mode = 'full' (index: nueva + detalle + acciones) | 'actions' (show: solo acciones).
  JS: public/js/modules/agenda.js. Cada cita viaja en data-appt='{json}'.
--}}
@php
    $mode = $mode ?? 'full';
    $doctorOptions = $formDoctors ?? $doctors ?? collect();
    $multiBranch = isset($branches) && $branches->count() > 1;
@endphp

@if ($mode === 'full' && ($canCreate ?? false))
    {{-- ===================== Nueva cita ===================== --}}
    <x-ui.modal id="cita-nueva" title="Nueva cita" subtitle="Agenda · {{ optional($branches->firstWhere('id', $defaultBranchId))->name ?? 'Clínica' }}"
                :action="route('citas.store')" method="POST" static>
        <input type="hidden" name="_view" value="{{ $view }}">
        <input type="hidden" name="_form" value="create">
        <div class="stack">
            <x-ui.field name="patient_id" label="Paciente" required>
                <div class="ag-patient" data-patient-picker>
                    <input type="hidden" name="patient_id" value="{{ old('patient_id', $preselected->id ?? '') }}" data-patient-id>
                    <input type="text" class="input {{ $errors->has('patient_id') ? 'has-error' : '' }}" placeholder="Busca un paciente por nombre o folio"
                           autocomplete="off" data-patient-search
                           value="{{ old('patient_label', $preselected ? $preselected->full_name.' ('.$preselected->folio.')' : '') }}">
                    <input type="hidden" name="patient_label" value="{{ old('patient_label', $preselected ? $preselected->full_name.' ('.$preselected->folio.')' : '') }}" data-patient-label>
                    <ul class="ag-suggest" data-patient-results hidden></ul>
                </div>
            </x-ui.field>

            @if ($multiBranch)
                <x-ui.field name="branch_id" label="Sucursal">
                    <x-ui.select name="branch_id" :selected="$defaultBranchId" :options="$branches->pluck('name', 'id')->all()" data-branch />
                </x-ui.field>
            @endif

            <x-ui.field name="doctor_member_id" label="Doctor" required>
                <x-ui.select name="doctor_member_id" :selected="$filters['doctor'] ?? null" placeholder="Selecciona un doctor"
                             :options="$doctorOptions->mapWithKeys(fn ($d) => [$d->id => $d->name.($d->doctorProfile?->specialty ? ' · '.$d->doctorProfile->specialty : '')])->all()"
                             data-doctor />
            </x-ui.field>

            <x-ui.field name="treatment_id" label="Tratamiento" required>
                <select name="treatment_id" id="treatment_id" class="select {{ $errors->has('treatment_id') ? 'has-error' : '' }}" data-treatment>
                    <option value="">Selecciona un tratamiento</option>
                    @foreach ($treatments as $t)
                        <option value="{{ $t->id }}" data-duration="{{ $t->duration_minutes }}" @selected(old('treatment_id') === $t->id)>{{ $t->name }} · {{ $t->duration_minutes }} min</option>
                    @endforeach
                </select>
            </x-ui.field>

            <div class="grid grid-3 ag-when">
                <x-ui.field name="date" label="Fecha" required>
                    <x-ui.input type="date" name="date" :min="now()->toDateString()" data-date />
                </x-ui.field>
                <x-ui.field name="start_time" label="Hora de inicio" required>
                    <x-ui.input type="time" name="start_time" step="300" data-time-input />
                </x-ui.field>
                <x-ui.field name="duration_minutes" label="Duración (min)" required>
                    <x-ui.input type="number" name="duration_minutes" :value="$config['default_duration']" :min="$config['min_duration']" :max="$config['max_duration']" step="5" data-duration-input />
                </x-ui.field>
            </div>

            <div class="ag-slots" data-slots hidden>
                <div class="ag-slots__title">Horarios disponibles</div>
                <div class="ag-slots__list" data-slots-list></div>
            </div>

            <x-ui.field name="notes" label="Notas">
                <x-ui.textarea name="notes" rows="2" placeholder="Notas para la cita" />
            </x-ui.field>
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Guardar</x-ui.button>
        </x-slot>
    </x-ui.modal>

    {{-- ===================== Detalle de cita ===================== --}}
    <x-ui.modal id="cita-detalle" title="Detalle de cita" subtitle="Agenda · {{ optional($branches->firstWhere('id', $defaultBranchId))->name ?? 'Clínica' }}">
        <dl class="ag-dl">
            <div><dt>Paciente</dt><dd data-f="patient"></dd></div>
            <div><dt>Tratamiento</dt><dd data-f="treatment"></dd></div>
            <div><dt>Doctor</dt><dd data-f="doctor"></dd></div>
            <div><dt>Fecha</dt><dd data-f="date_label"></dd></div>
            <div><dt>Duración</dt><dd data-f="duration"></dd></div>
            <div><dt>Estado</dt><dd><span class="badge" data-f="status_badge"></span></dd></div>
            <div data-f-row="notes"><dt>Notas</dt><dd data-f="notes"></dd></div>
        </dl>
        <x-slot name="footer">
            <div class="ag-actions">
                <div class="ag-actions__left">
                    <button type="button" class="btn btn--danger" data-act="cancel" hidden>Cancelar cita</button>
                    <form method="POST" data-form="noshow" hidden>@csrf
                        <button type="submit" class="btn btn--secondary" data-confirm="¿Marcar la cita como «No asistió»?" data-confirm-text="Marcar">No asistió</button>
                    </form>
                </div>
                <div class="ag-actions__right">
                    <a class="btn btn--ghost" data-f-href="show">Ver ficha</a>
                    <button type="button" class="btn btn--secondary" data-act="reschedule" hidden>Reagendar</button>
                    <form method="POST" data-form="confirm" hidden>@csrf
                        <button type="submit" class="btn btn--secondary">Confirmar</button>
                    </form>
                    <form method="POST" data-form="complete" hidden>@csrf
                        <button type="submit" class="btn btn--primary" data-confirm="¿Marcar la cita como completada?" data-confirm-text="Completar" data-confirm-variant="primary">Completar</button>
                    </form>
                    <button type="button" class="btn btn--primary" data-act="start" hidden>Iniciar</button>
                </div>
            </div>
        </x-slot>
    </x-ui.modal>
@endif

{{-- ===================== Cancelar cita ===================== --}}
<x-ui.modal id="cita-cancelar" title="¿Cancelar la cita?" size="sm" action="#" method="POST" static data-appt-form="cancel">
    <input type="hidden" name="_form" value="cancel">
    <input type="hidden" name="_appointment" value="{{ old('_appointment') }}" data-appt-id>
    <p class="text-muted ag-msg" data-cancel-text>La cita se marcará como cancelada y el paciente recibirá un aviso.</p>
    <x-ui.field name="reason" id="c_reason" bag="cancel" label="Motivo de la cancelación" required>
        <x-ui.textarea name="reason" id="c_reason" bag="cancel" rows="3" placeholder="Ej. El paciente avisó que no podrá asistir" maxlength="500" />
    </x-ui.field>
    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Volver</x-ui.button>
        <x-ui.button variant="danger" type="submit">Cancelar cita</x-ui.button>
    </x-slot>
</x-ui.modal>

{{-- ===================== Iniciar cita ===================== --}}
<x-ui.modal id="cita-iniciar" title="¿Iniciar la cita?" size="sm" action="#" method="POST" data-appt-form="start">
    <p class="text-muted ag-msg" data-start-text>La cita pasará a en curso.</p>
    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Volver</x-ui.button>
        <x-ui.button type="submit">Iniciar</x-ui.button>
    </x-slot>
</x-ui.modal>

{{-- ===================== Reagendar ===================== --}}
<x-ui.modal id="cita-reagendar" title="Reagendar cita" subtitle="Elige la nueva fecha y hora" action="#" method="POST" static data-appt-form="reschedule">
    <input type="hidden" name="_form" value="reschedule">
    <input type="hidden" name="_appointment" value="{{ old('_appointment') }}" data-appt-id>
    <div class="stack">
        @if ($errors->getBag('reschedule')->any())
            <x-ui.alert variant="danger">{{ $errors->getBag('reschedule')->first() }}</x-ui.alert>
        @endif
        <p class="text-muted ag-msg" data-resched-text></p>
        <x-ui.field name="doctor_member_id" id="r_doctor" bag="reschedule" label="Doctor">
            <x-ui.select name="doctor_member_id" id="r_doctor" bag="reschedule" :options="$doctorOptions->mapWithKeys(fn ($d) => [$d->id => $d->name])->all()" data-r-doctor />
        </x-ui.field>
        <div class="grid grid-3 ag-when">
            <x-ui.field name="date" id="r_date" bag="reschedule" label="Fecha" required>
                <x-ui.input type="date" name="date" id="r_date" bag="reschedule" :min="now()->toDateString()" data-r-date />
            </x-ui.field>
            <x-ui.field name="start_time" id="r_time" bag="reschedule" label="Hora de inicio" required>
                <x-ui.input type="time" name="start_time" id="r_time" bag="reschedule" step="300" data-r-time />
            </x-ui.field>
            <x-ui.field name="duration_minutes" id="r_duration" bag="reschedule" label="Duración (min)">
                <x-ui.input type="number" name="duration_minutes" id="r_duration" bag="reschedule" :min="$config['min_duration'] ?? 15" :max="$config['max_duration'] ?? 480" step="5" data-r-duration />
            </x-ui.field>
        </div>
        <div class="ag-slots" data-r-slots hidden>
            <div class="ag-slots__title">Horarios disponibles</div>
            <div class="ag-slots__list" data-r-slots-list></div>
        </div>
        <x-ui.field name="change_reason" id="r_reason" bag="reschedule" label="Motivo (opcional)">
            <x-ui.textarea name="change_reason" id="r_reason" bag="reschedule" rows="2" maxlength="500" placeholder="Ej. A solicitud del paciente" />
        </x-ui.field>
    </div>
    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit">Reagendar</x-ui.button>
    </x-slot>
</x-ui.modal>
