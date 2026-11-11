@extends('layouts.app')

@section('title', 'Roles y permisos')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/configuracion.css') }}?v={{ @filemtime(public_path('css/modules/configuracion.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/modules/usuarios.css') }}?v={{ @filemtime(public_path('css/modules/usuarios.css')) }}">
@endpush

@section('content')
@php $can = fn ($action) => auth()->user()->hasPermission('roles', $action); @endphp

<div class="usr-layout">
    @include('configuracion.partials.nav', ['section' => 'roles'])

    <div class="usr-main">
        <x-ui.page-header title="Roles y permisos" subtitle="Crea roles y define qué puede hacer cada uno en cada módulo.">
            <x-slot name="actions">
                @if (auth()->user()->hasPermission('usuarios', 'ver'))
                    <x-ui.button variant="secondary" icon="users" :href="route('usuarios.index')">Usuarios</x-ui.button>
                @endif
                @if ($can('crear'))
                    <x-ui.button icon="plus" data-modal-open="role-modal">Nuevo rol</x-ui.button>
                @endif
            </x-slot>
        </x-ui.page-header>

        <x-ui.card flush>
            <x-ui.table>
                <x-slot name="head">
                    <tr>
                        <th>Rol</th>
                        <th>Descripción</th>
                        <th>Usuarios</th>
                        <th>Estado</th>
                        <th class="actions">Acciones</th>
                    </tr>
                </x-slot>

                @foreach ($roles as $role)
                    @php $active = $role->status->value === 'active'; @endphp
                    <tr>
                        <td>
                            <span class="fw-700">{{ $role->name }}</span>
                            @if ($role->is_system_admin)<x-ui.badge variant="violet">Sistema</x-ui.badge>@endif
                        </td>
                        <td class="text-muted">{{ $role->description ?: '—' }}</td>
                        <td>{{ $role->members_count }}</td>
                        <td>
                            @if ($active)<x-ui.badge variant="success">Activo</x-ui.badge>
                            @else<x-ui.badge variant="neutral">Inactivo</x-ui.badge>@endif
                        </td>
                        <td class="actions">
                            <div class="usr-actions">
                                <a href="{{ route('roles.show', $role) }}" class="usr-link">{{ $role->is_system_admin ? 'Ver permisos' : 'Editar permisos' }}</a>
                                @if ($role->is_system_admin)
                                    <span class="usr-locked"><x-ui.icon name="lock" size="sm" /> Bloqueado</span>
                                @elseif ($can('desactivar'))
                                    @if ($active)
                                        <button type="button" class="usr-link usr-link--danger" data-modal-open="deactivate-{{ $role->id }}">Desactivar</button>
                                    @else
                                        <form method="POST" action="{{ route('roles.estado', $role) }}" style="display:inline">
                                            @csrf
                                            <input type="hidden" name="active" value="1">
                                            <button type="submit" class="usr-link">Activar</button>
                                        </form>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <p class="text-muted text-sm mt-4">Al cambiar el rol de un usuario se cierra su sesión activa.</p>
    </div>
</div>

{{-- Nuevo rol (Figma 807:2536) --}}
@if ($can('crear'))
    <x-ui.modal id="role-modal" title="Nuevo rol" subtitle="Define el nombre; los permisos se asignan después."
                :action="route('roles.store')" method="POST" open-on-error>
        <div class="stack">
            <x-ui.field name="name" label="Nombre del rol" placeholder="Ej. Recepcionista" required bag="role" maxlength="100" autocomplete="off" />
            <x-ui.field name="description" label="Descripción" placeholder="Qué hace este rol" bag="role" maxlength="255" autocomplete="off" />
            <p class="text-sm fw-600 text-muted">Se crea sin permisos. Al guardar abriremos la matriz para que los asignes módulo por módulo.</p>
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Crear rol</x-ui.button>
        </x-slot>
    </x-ui.modal>
@endif

{{-- Desactivar rol (Figma 807:2607): un modal por rol activo no-sistema --}}
@if ($can('desactivar'))
    @foreach ($roles->where('is_system_admin', false)->filter(fn ($r) => $r->status->value === 'active') as $role)
        @php $replacements = $roles->filter(fn ($r) => $r->id !== $role->id && $r->status->value === 'active'); @endphp
        <x-ui.modal :id="'deactivate-'.$role->id" title="Desactivar rol" :subtitle="$role->name"
                    :action="route('roles.estado', $role)" method="POST">
            <div class="stack">
                <input type="hidden" name="active" value="0">
                @if ($role->members_count > 0)
                    <p class="text-muted">Este rol tiene {{ $role->members_count }} {{ $role->members_count === 1 ? 'usuario asignado' : 'usuarios asignados' }}. Elige a qué rol pasarán antes de desactivarlo.</p>
                    <x-ui.field name="replacement_role_id" label="Reasignar usuarios a" as="select" :options="$replacements->pluck('name', 'id')->all()" placeholder="Selecciona un rol" required />
                @else
                    <p class="text-muted">Este rol no tiene usuarios asignados.</p>
                @endif
                <p class="text-sm fw-600 text-muted">El rol no se elimina: queda inactivo y se puede reactivar. Las sesiones de los usuarios afectados se cerrarán.</p>
            </div>
            <x-slot name="footer">
                <div class="usr-footer-split">
                    <x-ui.button type="submit" variant="danger">Desactivar rol</x-ui.button>
                    <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                </div>
            </x-slot>
        </x-ui.modal>
    @endforeach
@endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/usuarios.js') }}?v={{ @filemtime(public_path('js/modules/usuarios.js')) }}"></script>
@endpush
