@extends('layouts.app')

@section('title', $role->name)

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/usuarios.css') }}?v={{ @filemtime(public_path('css/modules/usuarios.css')) }}">
@endpush

@section('content')
@php
    $can = fn ($action) => auth()->user()->hasPermission('roles', $action);
    $locked = $role->is_system_admin;
    $active = $role->status->value === 'active';
    $editable = $can('editar') && ! $locked;
    $stdActions = \App\Services\Usuarios\RoleService::STANDARD_ACTIONS;
    $checked = function ($permId, $key) use ($granted) {
        return session()->hasOldInput() ? in_array($permId, (array) old('permissions', [])) : isset($granted[$key]);
    };
    $describe = function ($h) use ($role) {
        $old = $h->old_value ?? [];
        $new = $h->new_value ?? [];
        return match ($h->change_type) {
            'role_created' => 'Rol creado.',
            'role_updated' => 'Datos del rol actualizados ('.($old['name'] ?? '—').' → '.($new['name'] ?? '—').').',
            'role_deactivated' => 'Rol desactivado'.(! empty($new['users']) ? '; '.$new['users'].' usuario(s) pasaron a «'.($new['reassigned_to'] ?? '—').'».' : '.'),
            'role_activated' => 'Rol reactivado.',
            'role_permissions_changed' => 'Permisos modificados: +'.count($new['added'] ?? []).' / −'.count($new['removed'] ?? []).'.',
            'user_role_assigned' => ($h->targetMember?->user?->full_name ?? 'Un usuario').' '.(($new['role_id'] ?? null) === $role->id ? 'fue asignado a este rol' : 'dejó este rol').(isset($old['role']) && ($new['role_id'] ?? null) === $role->id ? ' (antes: '.$old['role'].')' : (isset($new['role']) ? ' (ahora: '.$new['role'].')' : '')).'.',
            default => str_replace('_', ' ', $h->change_type),
        };
    };
@endphp

<div class="usr-layout">
    <aside>@include('usuarios.partials.submenu')</aside>

    <div class="usr-main">
        <a href="{{ route('roles.index') }}" class="usr-back"><x-ui.icon name="arrow-left" size="sm" /> Roles</a>

        <x-ui.page-header :title="$role->name" :subtitle="$role->description ?: 'Sin descripción'">
            <x-slot name="actions">
                @if ($active)<x-ui.badge variant="success">Activo</x-ui.badge>@else<x-ui.badge variant="neutral">Inactivo</x-ui.badge>@endif
                @if ($locked)<x-ui.badge variant="violet">Sistema</x-ui.badge>@endif
                @if ($editable)
                    <x-ui.button variant="secondary" icon="edit" data-modal-open="role-edit-modal">Editar datos</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('roles.index')">Cancelar</x-ui.button>
                    <x-ui.button type="submit" form="perm-form">Guardar cambios</x-ui.button>
                @endif
            </x-slot>
        </x-ui.page-header>

        @if ($locked)
            <x-ui.alert variant="info" title="Rol bloqueado" class="mb-4">El administrador del sistema tiene acceso total. No se puede editar, desactivar ni quedarse sin usuarios activos.</x-ui.alert>
        @elseif (! $active)
            <x-ui.alert variant="warning" title="Rol inactivo" class="mb-4">Reactívalo desde el listado para poder asignarle usuarios.</x-ui.alert>
        @endif

        <x-ui.tabs :items="['permisos' => 'Permisos', 'usuarios' => 'Usuarios asignados ('.$role->members_count.')', 'historial' => 'Historial de cambios', 'vista' => 'Vista previa del menú']" active="permisos" hash>

            {{-- ---------------------------- Permisos ---------------------------- --}}
            <x-ui.tab name="permisos">
                <div class="usr-roles-grid">
                    <form method="POST" action="{{ route('roles.permisos', $role) }}" id="perm-form" data-permission-matrix>
                        @csrf
                        @method('PUT')
                        <x-ui.card flush>
                            <x-ui.table class="usr-matrix">
                                <x-slot name="head">
                                    <tr>
                                        <th>Módulo</th>
                                        @foreach ($stdActions as $label)<th class="usr-matrix__col">{{ $label }}</th>@endforeach
                                        <th>Acciones especiales</th>
                                    </tr>
                                </x-slot>
                                @foreach ($matrix as $row)
                                    <tr data-module="{{ $row['module'] }}" class="{{ $row['sub'] ? 'usr-matrix__sub' : '' }}">
                                        <th scope="row">{{ $row['label'] }}</th>
                                        @foreach ($stdActions as $action => $label)
                                            @php $permId = $row['cells'][$action]; @endphp
                                            <td class="usr-matrix__col">
                                                @if ($permId)
                                                    <label class="check usr-cell">
                                                        <input type="checkbox" name="permissions[]" value="{{ $permId }}"
                                                               data-action="{{ $action }}" aria-label="{{ $label }} en {{ $row['label'] }}"
                                                               @checked($locked || $checked($permId, $row['module'].'.'.$action)) @disabled(! $editable)>
                                                    </label>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td>
                                            @forelse ($row['specials'] as $action => $sp)
                                                <label class="check usr-special">
                                                    <input type="checkbox" name="permissions[]" value="{{ $sp['id'] }}" data-action="{{ $action }}"
                                                           @checked($locked || $checked($sp['id'], $row['module'].'.'.$action)) @disabled(! $editable)>
                                                    <span>{{ $sp['label'] }}</span>
                                                </label>
                                            @empty
                                                <span class="text-muted">—</span>
                                            @endforelse
                                        </td>
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        </x-ui.card>
                    </form>

                    <x-ui.card title="Resumen del rol">
                        <div class="stack stack--sm">
                            <div class="text-muted">Usuarios asignados: <strong>{{ $role->members_count }}</strong></div>
                            <div class="text-muted">Módulos con acceso: <strong>{{ $modulesWithAccess }} de {{ count($matrix) }}</strong></div>
                            <div class="text-muted">Última modificación:
                                @if ($lastChange)
                                    <strong>{{ $lastChange->created_at->timezone(config('app.timezone'))->format('d/m/Y') }}</strong> por {{ $lastChange->actor?->full_name ?? 'Sistema' }}
                                @else
                                    —
                                @endif
                            </div>
                            @unless ($locked)
                                <x-ui.alert variant="warning">Al guardar, los permisos aplican de inmediato a los usuarios con este rol y se cierran sus sesiones registradas.</x-ui.alert>
                            @endunless
                            <p class="text-sm fw-600 text-muted">Cualquier permiso distinto de «Ver» requiere «Ver» en el mismo módulo. Los datos clínicos del expediente solo se pueden editar si «Expedientes › Editar» está activo.</p>
                        </div>
                    </x-ui.card>
                </div>
            </x-ui.tab>

            {{-- ------------------------ Usuarios asignados ------------------------ --}}
            <x-ui.tab name="usuarios">
                @if ($can('editar') && ! $locked && $active)
                    <div class="row row--end mb-4">
                        <x-ui.button icon="users" data-modal-open="assign-modal">Asignar usuarios</x-ui.button>
                    </div>
                @endif
                <x-ui.card flush>
                    <x-ui.table>
                        <x-slot name="head">
                            <tr><th>Usuario</th><th>Correo</th><th>Último acceso</th><th class="actions"></th></tr>
                        </x-slot>
                        @forelse ($members as $m)
                            <tr>
                                <td><div class="row"><x-ui.avatar :name="$m->user->full_name" size="sm" /> <span class="fw-700">{{ $m->user->full_name }}</span></div></td>
                                <td class="text-muted">{{ $m->user->email }}</td>
                                <td class="text-muted">{{ $m->user->last_login_at ? $m->user->last_login_at->timezone(config('app.timezone'))->diffForHumans() : 'Nunca' }}</td>
                                <td class="actions">
                                    @if ($can('editar') && ! $locked)
                                        <x-ui.confirm-form :action="route('roles.usuarios.quitar', [$role, $m])" method="DELETE" label="Quitar"
                                            title="Quitar del rol" message="{{ $m->user->full_name }} se quedará sin rol y sin acceso a ningún módulo hasta que le asignes otro. ¿Continuar?"
                                            variant="danger-outline" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-ui.empty-state icon="users" title="Sin usuarios" text="Ningún usuario tiene este rol todavía." /></td></tr>
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
                <p class="text-muted text-sm mt-4">Al cambiar el rol de un usuario se cierra su sesión activa.</p>
            </x-ui.tab>

            {{-- ------------------------------ Historial ------------------------------ --}}
            <x-ui.tab name="historial">
                <x-ui.card flush>
                    <x-ui.table>
                        <x-slot name="head"><tr><th>Fecha</th><th>Usuario</th><th>Cambio</th></tr></x-slot>
                        @forelse ($history as $h)
                            <tr>
                                <td class="text-muted" style="white-space:nowrap">{{ $h->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                                <td>{{ $h->actor?->full_name ?? 'Sistema' }}</td>
                                <td>
                                    {{ $describe($h) }}
                                    @if ($h->change_type === 'role_permissions_changed' && (! empty($h->new_value['added']) || ! empty($h->new_value['removed'])))
                                        <div class="text-sm text-muted">
                                            @if (! empty($h->new_value['added']))<span class="usr-diff usr-diff--add">+ {{ implode(', ', $h->new_value['added']) }}</span>@endif
                                            @if (! empty($h->new_value['removed']))<span class="usr-diff usr-diff--del">− {{ implode(', ', $h->new_value['removed']) }}</span>@endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-ui.empty-state icon="clock" title="Sin cambios registrados" text="Los cambios de permisos y asignaciones aparecerán aquí." /></td></tr>
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            </x-ui.tab>

            {{-- ------------------------- Vista previa del menú ------------------------- --}}
            <x-ui.tab name="vista">
                <x-ui.card title="Así verá el menú este rol" class="usr-preview">
                    <div class="stack stack--sm">
                        @foreach ($menuPreview as $item)
                            <div class="usr-preview__item {{ $item['visible'] ? 'is-visible' : '' }}">
                                <span>{{ $item['label'] }}</span>
                                @if ($item['visible'])<x-ui.badge variant="success">Visible</x-ui.badge>@else<x-ui.badge variant="neutral">Oculto</x-ui.badge>@endif
                            </div>
                        @endforeach
                    </div>
                    <p class="text-sm text-muted mt-4">Un módulo aparece en el menú cuando el rol tiene «Ver» en él.</p>
                </x-ui.card>
            </x-ui.tab>
        </x-ui.tabs>
    </div>
</div>

{{-- Editar datos del rol --}}
@if ($editable)
    <x-ui.modal id="role-edit-modal" title="Editar rol" :subtitle="$role->name" :action="route('roles.update', $role)" method="PUT" open-on-error>
        <div class="stack">
            <x-ui.field name="name" label="Nombre del rol" :value="$role->name" required bag="role_edit" maxlength="100" autocomplete="off" />
            <x-ui.field name="description" label="Descripción" :value="$role->description" bag="role_edit" maxlength="255" autocomplete="off" />
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Guardar</x-ui.button>
        </x-slot>
    </x-ui.modal>
@endif

{{-- Asignar usuarios (Figma 807:2560) --}}
@if ($can('editar') && ! $locked && $active)
    <x-ui.modal id="assign-modal" title="Asignar usuarios" :subtitle="'Rol: '.$role->name" :action="route('roles.usuarios.asignar', $role)" method="POST" open-on-error>
        <div class="stack stack--sm">
            @forelse ($assignable as $m)
                <label class="check usr-assign">
                    <input type="checkbox" name="member_ids[]" value="{{ $m->id }}" @checked(in_array($m->id, (array) old('member_ids', [])))>
                    <x-ui.avatar :name="$m->user->full_name" size="sm" />
                    <span>
                        <span class="fw-700">{{ $m->user->full_name }}</span><br>
                        <span class="text-muted text-sm">Rol actual: {{ $m->role?->name ?? 'Sin rol' }}</span>
                    </span>
                </label>
            @empty
                <p class="text-muted">Todos los usuarios ya tienen este rol.</p>
            @endforelse

            @if ($errors->getBag('role_assign')->any())
                <div class="field__error has-error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $errors->getBag('role_assign')->first() }}</div>
            @endif

            <x-ui.alert variant="warning">Al asignar el rol se cerrará la sesión activa de los usuarios afectados.</x-ui.alert>
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Asignar</x-ui.button>
        </x-slot>
    </x-ui.modal>
@endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/usuarios.js') }}?v={{ @filemtime(public_path('js/modules/usuarios.js')) }}"></script>
@endpush
