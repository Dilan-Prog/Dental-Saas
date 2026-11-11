@extends('layouts.app')

@section('title', 'Catálogo de medicamentos')

@section('breadcrumbs')
    <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="crumb-extra">Inicio</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <a href="{{ route('recetas.index') }}" class="crumb-extra">Recetas</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <span class="is-current truncate">Catálogo de medicamentos</span>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/recetas.css') }}?v={{ @filemtime(public_path('css/modules/recetas.css')) }}">
@endpush

@php
    $user = auth()->user();
    $canCreate = $user->hasPermission('recetas', 'crear');
    $canEdit = $user->hasPermission('recetas', 'editar');
    $mode = old('_mode', 'create');
    $formUrl = $mode === 'edit' ? old('_url', route('recetas.medicamentos.store')) : route('recetas.medicamentos.store');
@endphp

@section('content')
    <x-ui.page-header title="Catálogo de medicamentos" subtitle="Vademécum global y medicamentos propios de la clínica">
        <x-slot name="actions">
            <x-ui.button variant="secondary" icon="arrow-left" :href="route('recetas.index')">Volver a recetas</x-ui.button>
            @if ($canCreate)
                <x-ui.button icon="plus" data-med-new>Nuevo medicamento</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    <x-ui.card flush>
        <div class="table-toolbar">
            <form method="GET" action="{{ route('recetas.medicamentos.index') }}" class="row row--wrap" data-no-lock>
                <div class="input-wrap">
                    <x-ui.icon name="search" size="sm" />
                    <input type="search" name="q" class="input" value="{{ $filters['q'] }}" placeholder="Nombre, principio activo o presentación…" aria-label="Buscar medicamentos">
                </div>
                <select name="origen" class="select" aria-label="Origen" onchange="this.form.submit()">
                    <option value="">Todos los orígenes</option>
                    <option value="global" @selected($filters['origen'] === 'global')>Catálogo global</option>
                    <option value="clinica" @selected($filters['origen'] === 'clinica')>Propios de la clínica</option>
                </select>
                <select name="estado" class="select" aria-label="Estado" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    <option value="active" @selected($filters['estado'] === 'active')>Activos</option>
                    <option value="inactive" @selected($filters['estado'] === 'inactive')>Inactivos</option>
                </select>
                <x-ui.button type="submit" variant="secondary" size="sm">Buscar</x-ui.button>
            </form>
            <span class="text-muted text-sm">{{ $medications->total() }} medicamentos</span>
        </div>

        <x-ui.table>
            <x-slot name="head">
                <tr>
                    <th>Medicamento</th>
                    <th>Principio activo</th>
                    <th>Concentración</th>
                    <th>Presentación</th>
                    <th>Origen</th>
                    <th>Estado</th>
                    <th class="actions"></th>
                </tr>
            </x-slot>
            @forelse ($medications as $m)
                @php
                    $own = $m->organization_id !== null;
                    $payload = ['name' => $m->name, 'active_ingredient' => $m->active_ingredient, 'strength' => $m->strength, 'presentation' => $m->presentation, 'status' => $m->status->value];
                @endphp
                <tr>
                    <td class="fw-500">{{ $m->name }}</td>
                    <td>{{ $m->active_ingredient ?: '—' }}</td>
                    <td>{{ $m->strength ?: '—' }}</td>
                    <td>{{ $m->presentation ?: '—' }}</td>
                    <td>{!! $own ? '<span class="badge badge--info">Clínica</span>' : '<span class="badge badge--neutral">Global</span>' !!}</td>
                    <td>{!! $m->status->value === 'active' ? '<span class="badge badge--success">Activo</span>' : '<span class="badge badge--neutral">Inactivo</span>' !!}</td>
                    <td class="actions">
                        @if ($own && $canEdit)
                            <div class="row row--end">
                                <button type="button" class="icon-btn" aria-label="Editar {{ $m->name }}" title="Editar"
                                        data-med-edit
                                        data-url="{{ route('recetas.medicamentos.update', $m) }}"
                                        data-med="{{ json_encode($payload, JSON_UNESCAPED_UNICODE) }}">
                                    <x-ui.icon name="edit" />
                                </button>
                                <form method="POST" action="{{ route('recetas.medicamentos.toggle', $m) }}">
                                    @csrf @method('PATCH')
                                    <x-ui.button type="submit" variant="ghost" size="sm">{{ $m->status->value === 'active' ? 'Desactivar' : 'Activar' }}</x-ui.button>
                                </form>
                            </div>
                        @else
                            <span class="text-subtle text-xs">{{ $own ? '' : 'Solo lectura' }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><x-ui.empty-state icon="pill" title="Sin medicamentos" text="Ajusta la búsqueda o agrega un medicamento propio." /></td></tr>
            @endforelse
        </x-ui.table>

        @if ($medications->hasPages())
            <div class="pagination-wrap">{{ $medications->links() }}</div>
        @endif
    </x-ui.card>

    @if ($canCreate || $canEdit)
        <x-ui.modal id="med-form" size="sm" static open-on-error title="Medicamento" subtitle="Catálogo propio de la clínica"
                    :action="$formUrl" :method="$mode === 'edit' ? 'PUT' : 'POST'" novalidate data-med-form data-store-url="{{ route('recetas.medicamentos.store') }}">
            <input type="hidden" name="_mode" value="{{ $mode }}" data-f-mode>
            <input type="hidden" name="_url" value="{{ $formUrl }}" data-f-url>
            <div class="stack">
                <x-ui.field name="name" label="Nombre" required maxlength="200" placeholder="Ibuprofeno" :value="old('name')" />
                <x-ui.field name="active_ingredient" label="Principio activo" maxlength="200" placeholder="Ibuprofeno" :value="old('active_ingredient')" />
                <div class="form-grid">
                    <x-ui.field name="strength" label="Concentración" maxlength="80" placeholder="400 mg" :value="old('strength')" />
                    <x-ui.field name="presentation" label="Presentación" maxlength="120" placeholder="Tabletas" :value="old('presentation')" />
                </div>
                <div data-f-status-wrap @if ($mode !== 'edit') hidden @endif>
                    <x-ui.field name="status" label="Estado" as="select" :options="['active' => 'Activo', 'inactive' => 'Inactivo']" :selected="old('status', 'active')" />
                </div>
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/recetas.js') }}?v={{ @filemtime(public_path('js/modules/recetas.js')) }}"></script>
@endpush
