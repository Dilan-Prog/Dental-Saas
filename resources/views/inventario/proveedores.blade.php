@extends('layouts.app')

@section('title', 'Proveedores')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/inventario.css') }}">
@endpush

@php
    $fmtPhone = function (?string $p) {
        $d = preg_replace('/\D+/', '', (string) $p);

        return strlen($d) === 10 ? '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6) : ($p ?: '—');
    };
@endphp

@section('breadcrumbs')
    <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="crumb-extra">Inicio</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <a href="{{ route('inventario.index') }}" class="crumb-extra">Inventario</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <span class="is-current truncate">Proveedores</span>
@endsection

@section('content')
    <x-ui.page-header title="Proveedores" :subtitle="$suppliers->total() . ' ' . ($suppliers->total() === 1 ? 'proveedor' : 'proveedores')">
        <x-slot name="actions">
            <x-ui.button variant="ghost" icon="arrow-left" :href="route('inventario.index')">Inventario</x-ui.button>
            @if ($can['create'])
                <x-ui.button icon="plus" data-modal-open="modal-nuevo-proveedor">Nuevo proveedor</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    <x-ui.card flush>
        <form method="GET" action="{{ route('inventario.proveedores.index') }}" class="table-toolbar" data-autosubmit>
            <div class="input-wrap">
                <x-ui.icon name="search" size="sm" />
                <input class="input" type="search" name="q" value="{{ $q }}" maxlength="100" placeholder="Buscar proveedor, teléfono o correo..." aria-label="Buscar">
            </div>
            <x-ui.select name="status" :options="['active' => 'Activos', 'inactive' => 'Inactivos', 'all' => 'Todos']" :selected="$status" aria-label="Estado" />
        </form>

        <x-ui.table>
            <x-slot name="head">
                <tr><th>Proveedor</th><th>Teléfono</th><th>Correo</th><th class="text-right">Insumos</th><th>Estado</th><th class="actions"></th></tr>
            </x-slot>
            @forelse ($suppliers as $s)
                <tr class="{{ $s->status === \App\Enums\RecordStatus::Inactive ? 'inv-row--inactive' : '' }}">
                    <td class="fw-600">{{ $s->name }}</td>
                    <td class="mono">{{ $fmtPhone($s->contact_phone) }}</td>
                    <td>{{ $s->contact_email ?: '—' }}</td>
                    <td class="text-right mono">{{ $s->items_count }}</td>
                    <td>
                        <x-ui.badge :variant="$s->status === \App\Enums\RecordStatus::Active ? 'success' : 'neutral'" dot>{{ $s->status->label() }}</x-ui.badge>
                    </td>
                    <td class="actions">
                        @if ($can['edit'])
                            <x-ui.button variant="ghost" size="sm" icon="edit" icon-only aria-label="Editar" data-modal-open="modal-editar-proveedor"
                                         data-edit-action="{{ route('inventario.proveedores.update', $s) }}"
                                         data-edit-name="{{ $s->name }}" data-edit-phone="{{ $s->contact_phone }}" data-edit-email="{{ $s->contact_email }}" />
                        @endif
                        @if ($can['toggle'])
                            <x-ui.confirm-form :action="route('inventario.proveedores.toggle', $s)" method="PATCH"
                                               :message="$s->status === \App\Enums\RecordStatus::Active ? '¿Desactivar este proveedor?' : '¿Activar este proveedor?'"
                                               :label="$s->status === \App\Enums\RecordStatus::Active ? 'Desactivar' : 'Activar'"
                                               variant="ghost" icon-only icon="refresh" />
                            @if ($s->items_count === 0)
                                <x-ui.confirm-form :action="route('inventario.proveedores.destroy', $s)" method="DELETE"
                                                   message="¿Eliminar este proveedor? No se puede deshacer." label="Eliminar"
                                                   variant="danger-outline" icon-only icon="trash" />
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">
                    <x-ui.empty-state icon="building" title="{{ $q !== '' || $status !== 'active' ? 'Sin resultados' : 'Aún no hay proveedores' }}"
                                      text="{{ $q !== '' || $status !== 'active' ? 'Ningún proveedor coincide con el filtro.' : 'Registra a quién le compras tus insumos.' }}">
                        @if ($can['create'] && $q === '' && $status === 'active')
                            <x-ui.button icon="plus" data-modal-open="modal-nuevo-proveedor">Nuevo proveedor</x-ui.button>
                        @endif
                    </x-ui.empty-state>
                </td></tr>
            @endforelse
        </x-ui.table>
        <div class="table-foot">
            <span>{{ $suppliers->total() }} {{ $suppliers->total() === 1 ? 'proveedor' : 'proveedores' }}</span>
            <div class="pagination-wrap">{{ $suppliers->links() }}</div>
        </div>
    </x-ui.card>

    @if ($can['create'])
        <x-ui.modal id="modal-nuevo-proveedor" title="Nuevo proveedor" subtitle="Alta de proveedor de insumos"
                    :action="route('inventario.proveedores.store')" open-on-error>
            <div class="form-grid">
                <x-ui.field label="Nombre" name="name" bag="proveedor" class="col-span-2" required maxlength="200" placeholder="Ej. MediSupply" />
                <x-ui.field label="Teléfono" name="contact_phone" bag="proveedor" mask="phone" maxlength="30" placeholder="55 1234 5678" />
                <x-ui.field label="Correo" name="contact_email" bag="proveedor" type="email" maxlength="254" placeholder="ventas@proveedor.com" />
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar proveedor</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif

    @if ($can['edit'])
        <x-ui.modal id="modal-editar-proveedor" title="Editar proveedor" subtitle="Datos de contacto" method="PUT"
                    action="#" open-on-error data-edit-form>
            <input type="hidden" name="_edit_action" value="{{ old('_edit_action') }}">
            <div class="form-grid">
                <x-ui.field label="Nombre" name="name" bag="proveedor_edit" class="col-span-2" required maxlength="200" data-edit-name-input />
                <x-ui.field label="Teléfono" name="contact_phone" bag="proveedor_edit" mask="phone" maxlength="30" data-edit-phone-input />
                <x-ui.field label="Correo" name="contact_email" bag="proveedor_edit" type="email" maxlength="254" data-edit-email-input />
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar cambios</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/inventario.js') }}"></script>
@endpush
