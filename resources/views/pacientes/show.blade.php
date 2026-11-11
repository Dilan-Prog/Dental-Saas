@extends('layouts.app')

@section('title', $patient->full_name)

@section('breadcrumbs')
    <a href="{{ route('dashboard') }}">Inicio</a>
    <x-ui.icon name="chevron-right" />
    <a href="{{ route('pacientes.index') }}">Pacientes</a>
    <x-ui.icon name="chevron-right" />
    <span class="is-current">{{ $patient->folio }}</span>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/pacientes.css') }}?v={{ @filemtime(public_path('css/modules/pacientes.css')) }}">
@endpush

@section('content')
@php
    $user = auth()->user();
    $canEdit = $user->hasPermission('pacientes', 'editar') && $editable;
    $canDelete = $user->hasPermission('pacientes', 'desactivar');
    $isAdmin = $user->isAdmin();
    $anonymized = $patient->anonymized_at !== null;
    $hasGuardian = $patient->guardians->isNotEmpty();
    $allergies = trim((string) $patient->medicalRecord?->allergies);
    $doctorName = $patient->assignedDoctor?->user?->full_name;
    $monthsLong = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $fmtDate = fn ($d) => $d ? $d->day.' de '.$monthsLong[$d->month - 1].' de '.$d->year : '—';
    $fmtTime = fn ($d) => $d->format('H:i');
    $patientQuery = ['patient' => $patient->id];
    $links = [
        ['label' => 'Expediente clínico', 'route' => 'expediente.show', 'params' => $patient, 'count' => null, 'icon' => 'clipboard'],
        ['label' => 'Citas', 'route' => 'agenda.index', 'params' => $patientQuery, 'count' => $counts['appointments'], 'icon' => 'calendar'],
        ['label' => 'Recetas', 'route' => 'recetas.index', 'params' => $patientQuery, 'count' => $counts['prescriptions'], 'icon' => 'pill'],
        ['label' => 'Presupuestos', 'route' => 'presupuestos.index', 'params' => $patientQuery, 'count' => $counts['quotes'], 'icon' => 'file-text'],
        ['label' => 'Facturas', 'route' => 'facturacion.index', 'params' => $patientQuery, 'count' => $counts['invoices'], 'icon' => 'receipt'],
    ];
@endphp

{{-- Encabezado --}}
<div class="page-header pac-head">
    <div class="row">
        <x-ui.avatar :name="$patient->full_name" size="lg" />
        <div>
            <h1 class="page-header__title">{{ $patient->full_name }}</h1>
            <div class="row row--wrap mt-1">
                <span class="mono text-muted">{{ $patient->folio }}</span>
                @if ($anonymized)
                    <x-ui.badge dot>Anonimizado</x-ui.badge>
                @elseif ($patient->trashed())
                    <x-ui.badge variant="danger" dot>Baja</x-ui.badge>
                @else
                    <x-ui.badge :variant="$patient->status === \App\Enums\RecordStatus::Active ? 'success' : 'neutral'" dot>{{ $patient->status->label() }}</x-ui.badge>
                @endif
                @if ($isMinor)<x-ui.badge variant="warning">Menor de edad</x-ui.badge>@endif
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        @if ($canEdit)
            <x-ui.button variant="secondary" icon="edit" data-modal-open="modal-editar-paciente">Editar</x-ui.button>
        @endif
        @if (Route::has('expediente.show'))
            <x-ui.button icon="clipboard" :href="route('expediente.show', $patient)">Expediente</x-ui.button>
        @endif
        <x-ui.dropdown>
            <x-slot name="trigger"><x-ui.button variant="secondary" icon="more-horizontal" icon-only aria-label="Más acciones" /></x-slot>
            @if ($editable)
                @if (Route::has('agenda.index'))<a class="dropdown-item" href="{{ route('agenda.index', $patientQuery) }}"><x-ui.icon name="calendar" /> Nueva cita</a>@endif
                @if (Route::has('recetas.create'))<a class="dropdown-item" href="{{ route('recetas.create', $patientQuery) }}"><x-ui.icon name="pill" /> Nueva receta</a>@endif
                @if (Route::has('presupuestos.create'))<a class="dropdown-item" href="{{ route('presupuestos.create', $patientQuery) }}"><x-ui.icon name="file-text" /> Nuevo presupuesto</a>@endif
                <div class="dropdown-sep"></div>
            @endif
            <a class="dropdown-item" href="{{ route('pacientes.index') }}"><x-ui.icon name="arrow-left" /> Volver al listado</a>
            @if ($canDelete && ! $anonymized)
                <div class="dropdown-sep"></div>
                @if ($patient->trashed())
                    <button type="button" class="dropdown-item" data-modal-open="modal-reactivar"><x-ui.icon name="refresh" /> Reactivar paciente</button>
                @else
                    <button type="button" class="dropdown-item dropdown-item--danger" data-modal-open="modal-baja"><x-ui.icon name="trash" /> Dar de baja</button>
                @endif
                @if ($isAdmin)
                    <button type="button" class="dropdown-item dropdown-item--danger" data-modal-open="modal-anonimizar"><x-ui.icon name="lock" /> Anonimizar datos</button>
                @endif
            @endif
        </x-ui.dropdown>
    </div>
</div>

{{-- Avisos --}}
<div class="stack mb-4">
    @if ($anonymized)
        <x-ui.alert variant="info" title="Paciente anonimizado">Sus datos de identificación fueron eliminados el {{ $fmtDate($patient->anonymized_at) }}. Se conserva la información clínica y los CFDI emitidos.</x-ui.alert>
    @elseif ($patient->trashed())
        <x-ui.alert variant="warning" title="Paciente dado de baja">Dado de baja el {{ $fmtDate($patient->deleted_at) }}. No aparece en el listado general. Su expediente se conserva.</x-ui.alert>
    @endif
    @if ($isMinor && ! $hasGuardian && $editable)
        <x-ui.alert variant="warning" title="Menor de edad sin tutor">Registra al menos un tutor en la sección «Tutores».</x-ui.alert>
    @endif
    @if ($allergies !== '')
        <x-ui.alert variant="danger" title="Alergias">{{ $allergies }}</x-ui.alert>
    @endif
</div>

<div class="grid grid-sidebar pac-grid">
    {{-- ============ Columna principal ============ --}}
    <div class="stack">
        <x-ui.card title="Datos del paciente">
            <dl class="dl">
                <dt>Folio</dt><dd class="mono">{{ $patient->folio }}</dd>
                <dt>Fecha de nacimiento</dt>
                <dd>{{ $patient->birth_date ? $patient->birth_date->format('d/m/Y').' · '.$patient->age.' '.($patient->age === 1 ? 'año' : 'años') : '—' }}</dd>
                <dt>Sexo</dt><dd>{{ $patient->sex->label() }}</dd>
                <dt>Teléfono</dt><dd class="mono">{{ $patient->phone ? \App\Services\Pacientes\PatientService::formatPhone($patient->phone) : '—' }}</dd>
                <dt>Correo</dt><dd>{{ $patient->email ?: '—' }}</dd>
                <dt>Doctor asignado</dt>
                <dd>@if ($doctorName)<span class="pac-doctor"><i class="pac-dot" style="background: {{ \App\Services\Pacientes\PatientService::doctorColor($patient->assigned_doctor_member_id) }}"></i>{{ $doctorName }}</span>@else Sin asignar @endif</dd>
                <dt>Alta en el sistema</dt><dd>{{ $fmtDate($patient->created_at) }}</dd>
            </dl>
        </x-ui.card>

        <x-ui.card title="Datos fiscales" subtitle="Para emitir facturas (CFDI) a nombre del paciente" id="fiscal">
            @if ($canEdit)
                <x-slot name="actions">
                    <x-ui.button size="sm" variant="secondary" icon="plus" data-fill-modal="modal-fiscal" data-record="{{ json_encode(['profile_id' => '', 'rfc' => '', 'legal_name' => '', 'tax_regime_code' => '', 'fiscal_postal_code' => '', 'default_cfdi_use' => '', 'is_default' => false]) }}" data-title="Agregar datos fiscales">Agregar</x-ui.button>
                </x-slot>
            @endif
            @forelse ($patient->fiscalProfiles as $f)
                <div class="list-item pac-item">
                    <div>
                        <div class="row row--wrap">
                            <span class="fw-600 mono">{{ $f->rfc }}</span>
                            @if ($f->is_default)<x-ui.badge variant="primary">Predeterminado</x-ui.badge>@endif
                        </div>
                        <div>{{ $f->legal_name }}</div>
                        <div class="text-sm text-muted">{{ $f->tax_regime_code }} · {{ $regimenes[$f->tax_regime_code] ?? '' }} · CP {{ $f->fiscal_postal_code }}@if ($f->default_cfdi_use) · Uso {{ $f->default_cfdi_use }}@endif</div>
                    </div>
                    @if ($canEdit)
                        <div class="row nowrap">
                            <x-ui.button size="sm" variant="ghost" icon="edit" icon-only aria-label="Editar datos fiscales" title="Editar"
                                data-fill-modal="modal-fiscal" data-title="Editar datos fiscales"
                                data-record="{{ json_encode(['profile_id' => $f->id, 'rfc' => $f->rfc, 'legal_name' => $f->legal_name, 'tax_regime_code' => $f->tax_regime_code, 'fiscal_postal_code' => $f->fiscal_postal_code, 'default_cfdi_use' => $f->default_cfdi_use, 'is_default' => (bool) $f->is_default], JSON_UNESCAPED_UNICODE) }}" />
                            @unless ($f->is_default)
                                <x-ui.confirm-form :action="route('pacientes.fiscal.default', [$patient, $f])" method="POST" variant="ghost" icon="check" icon-only
                                    label="Marcar como predeterminado" title="Predeterminado" confirmText="Aceptar" :message="'¿Usar '.$f->rfc.' como perfil fiscal predeterminado?'" />
                            @endunless
                            <x-ui.confirm-form :action="route('pacientes.fiscal.destroy', [$patient, $f])" variant="ghost" icon="trash" icon-only
                                label="Eliminar" title="Eliminar datos fiscales" confirmText="Eliminar" :message="'¿Eliminar los datos fiscales '.$f->rfc.'? Los CFDI ya emitidos no se modifican.'" />
                        </div>
                    @endif
                </div>
            @empty
                <x-ui.empty-state icon="receipt" title="Sin datos fiscales" text="Agrega el RFC del paciente si requiere factura." />
            @endforelse
        </x-ui.card>

        <x-ui.card title="Tutores" subtitle="Obligatorio para menores de 18 años" id="tutores">
            @if ($canEdit)
                <x-slot name="actions">
                    <x-ui.button size="sm" variant="secondary" icon="plus" data-fill-modal="modal-tutor" data-record="{{ json_encode(['guardian_id' => '', 'full_name' => '', 'relationship' => '', 'phone' => '', 'email' => '', 'is_primary' => false]) }}" data-title="Agregar tutor">Agregar</x-ui.button>
                </x-slot>
            @endif
            @forelse ($patient->guardians as $g)
                <div class="list-item pac-item">
                    <div>
                        <div class="row row--wrap">
                            <span class="fw-600">{{ $g->full_name }}</span>
                            @if ($g->relationship)<span class="text-muted">· {{ $g->relationship }}</span>@endif
                            @if ($g->is_primary)<x-ui.badge variant="primary">Principal</x-ui.badge>@endif
                        </div>
                        <div class="text-sm text-muted">{{ $g->phone ? \App\Services\Pacientes\PatientService::formatPhone($g->phone) : '' }}{{ $g->phone && $g->email ? ' · ' : '' }}{{ $g->email }}</div>
                    </div>
                    @if ($canEdit)
                        <div class="row nowrap">
                            <x-ui.button size="sm" variant="ghost" icon="edit" icon-only aria-label="Editar tutor" title="Editar"
                                data-fill-modal="modal-tutor" data-title="Editar tutor"
                                data-record="{{ json_encode(['guardian_id' => $g->id, 'full_name' => $g->full_name, 'relationship' => $g->relationship, 'phone' => $g->phone ? \App\Services\Pacientes\PatientService::formatPhone($g->phone) : '', 'email' => $g->email, 'is_primary' => (bool) $g->is_primary], JSON_UNESCAPED_UNICODE) }}" />
                            <x-ui.confirm-form :action="route('pacientes.guardians.destroy', [$patient, $g])" variant="ghost" icon="trash" icon-only
                                label="Eliminar" title="Eliminar tutor" confirmText="Eliminar" :message="'¿Eliminar a '.$g->full_name.' como tutor?'" />
                        </div>
                    @endif
                </div>
            @empty
                <x-ui.empty-state icon="users" title="Sin tutores" text="{{ $isMinor ? 'El paciente es menor de edad: agrega un tutor.' : 'No se requiere tutor para pacientes adultos.' }}" />
            @endforelse
        </x-ui.card>
    </div>

    {{-- ============ Columna lateral ============ --}}
    <div class="stack">
        <div class="grid pac-kpis">
            <x-ui.kpi label="Saldo pendiente" :value="\App\Support\Money::format($balance)" :delta="$hasBalance ? 'Facturas por cobrar' : 'Sin adeudos'" :trend="$hasBalance ? 'down' : 'up'" />
            <x-ui.kpi label="Última visita" :value="$lastVisit ? $lastVisit->format('d/m/Y') : '—'" :delta="$lastVisit ? $lastVisit->diffForHumans() : 'Sin visitas completadas'" />
        </div>

        <x-ui.card title="Accesos">
            @foreach ($links as $l)
                @php $has = Route::has($l['route']); @endphp
                <div class="list-item">
                    <span class="row"><x-ui.icon :name="$l['icon']" size="sm" />
                        @if ($has)<a href="{{ route($l['route'], $l['params']) }}">{{ $l['label'] }}</a>@else<span class="text-muted">{{ $l['label'] }}</span>@endif
                    </span>
                    @if ($l['count'] !== null)<x-ui.badge>{{ $l['count'] }}</x-ui.badge>@endif
                </div>
            @endforeach
        </x-ui.card>

        <x-ui.card title="Próximas citas">
            @forelse ($upcoming as $a)
                <div class="list-item">
                    <div>
                        <div class="fw-600">{{ $a->starts_at->format('d/m/Y') }} · {{ $fmtTime($a->starts_at) }}</div>
                        <div class="text-sm text-muted">{{ $a->treatment?->name ?? 'Consulta' }}@if ($a->doctor?->user) · {{ $a->doctor->user->full_name }}@endif</div>
                    </div>
                    <x-ui.badge :variant="$a->status === \App\Enums\AppointmentStatus::Confirmada ? 'info' : 'warning'">{{ $a->status->label() }}</x-ui.badge>
                </div>
            @empty
                <div class="text-muted text-sm">Sin citas programadas.</div>
            @endforelse
        </x-ui.card>
    </div>
</div>

{{-- ============ Modales ============ --}}
@if ($canEdit)
    <x-ui.modal id="modal-editar-paciente" title="Editar paciente" subtitle="Datos personales y de contacto" size="lg"
                :action="route('pacientes.update', $patient)" method="PUT" novalidate>
        @include('pacientes.partials.patient-fields', [
            'bag' => 'patientEdit', 'patient' => $patient, 'withFiscal' => false, 'withStatus' => true, 'hasGuardian' => $hasGuardian,
            'doctors' => $doctors, 'sexOptions' => $sexOptions, 'regimenes' => $regimenes, 'usosCfdi' => $usosCfdi, 'relationships' => $relationships,
        ])
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Guardar cambios</x-ui.button>
        </x-slot>
    </x-ui.modal>

    <x-ui.modal id="modal-fiscal" title="Datos fiscales" subtitle="RFC, razón social, régimen y código postal" size="lg"
                :action="route('pacientes.fiscal.store', $patient)" method="POST" novalidate>
        <input type="hidden" name="profile_id" value="{{ old('profile_id') }}">
        <div class="form-grid">
            <x-ui.field name="rfc" label="RFC" required bag="fiscal" mask="rfc" placeholder="XAXX010101000" maxlength="13" autocomplete="off" />
            <x-ui.field name="legal_name" label="Razón social" required bag="fiscal" placeholder="Nombre o razón social" maxlength="300" />
            <x-ui.field name="tax_regime_code" as="select" label="Régimen fiscal" required bag="fiscal" :options="$regimenes" placeholder="Selecciona" />
            <x-ui.field name="fiscal_postal_code" label="Código postal fiscal" required bag="fiscal" mask="postal" placeholder="00000" maxlength="5" />
            <x-ui.field class="col-span-full" name="default_cfdi_use" as="select" label="Uso de CFDI" bag="fiscal" :options="$usosCfdi" placeholder="Selecciona" />
            <div class="col-span-full"><x-ui.checkbox name="is_default" value="1" label="Usar como perfil fiscal predeterminado" /></div>
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Guardar</x-ui.button>
        </x-slot>
    </x-ui.modal>

    <x-ui.modal id="modal-tutor" title="Tutor" subtitle="Responsable del paciente" size="md"
                :action="route('pacientes.guardians.store', $patient)" method="POST" novalidate>
        <input type="hidden" name="guardian_id" value="{{ old('guardian_id') }}">
        <div class="stack">
            <x-ui.field name="full_name" label="Nombre completo" required bag="guardian" placeholder="Nombre del tutor" maxlength="250" />
            <x-ui.field name="relationship" as="select" label="Parentesco" bag="guardian" :options="array_combine($relationships, $relationships)" placeholder="Selecciona" />
            <x-ui.field name="phone" label="Teléfono" bag="guardian" mask="phone" type="tel" placeholder="55 0000 0000" />
            <x-ui.field name="email" type="email" label="Correo" bag="guardian" placeholder="correo@ejemplo.com" />
            <x-ui.checkbox name="is_primary" value="1" label="Tutor principal" />
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Guardar</x-ui.button>
        </x-slot>
    </x-ui.modal>
@endif

@if ($canDelete && ! $anonymized)
    @if ($patient->trashed())
        <x-ui.modal id="modal-reactivar" title="¿Reactivar paciente?" size="sm" :action="route('pacientes.restore', $patient)" method="POST">
            <p class="text-muted">{{ $patient->full_name }} ({{ $patient->folio }}) volverá a aparecer en el listado como paciente activo.</p>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Reactivar</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @else
        <x-ui.modal id="modal-baja" title="¿Dar de baja al paciente?" size="sm" :action="route('pacientes.destroy', $patient)" method="DELETE">
            <p class="text-muted">{{ $patient->full_name }} ({{ $patient->folio }}) se dará de baja y dejará de aparecer en el listado. Su expediente, citas y documentos se conservan y podrá reactivarse. No se puede dar de baja con citas pendientes o saldo por cobrar.</p>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button variant="danger" type="submit">Dar de baja</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif

    @if ($isAdmin)
        <x-ui.modal id="modal-anonimizar" title="Anonimizar datos del paciente" size="sm" :action="route('pacientes.anonymize', $patient)" method="POST">
            <div class="stack">
                <x-ui.alert variant="danger" title="Acción irreversible">Se eliminarán el nombre, la fecha de nacimiento, el teléfono, el correo, los datos fiscales y los tutores. El expediente clínico y los CFDI emitidos se conservan.</x-ui.alert>
                <x-ui.field name="confirm_folio" label="Escribe el folio {{ $patient->folio }} para confirmar" required bag="anonymize" autocomplete="off" placeholder="{{ $patient->folio }}" />
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button variant="danger" type="submit">Anonimizar</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif
@endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/pacientes.js') }}?v={{ @filemtime(public_path('js/modules/pacientes.js')) }}"></script>
    @php
        $openModal = null;
        foreach (['patientEdit' => 'modal-editar-paciente', 'fiscal' => 'modal-fiscal', 'guardian' => 'modal-tutor', 'anonymize' => 'modal-anonimizar'] as $bagName => $modalId) {
            if ($errors->getBag($bagName)->any()) { $openModal = $modalId; break; }
        }
        if (! $openModal && request()->boolean('editar') && $canEdit) { $openModal = 'modal-editar-paciente'; }
    @endphp
    @if ($openModal)
        <script>document.addEventListener('DOMContentLoaded', function () { UI.openModal(@json($openModal)); });</script>
    @endif
@endpush
