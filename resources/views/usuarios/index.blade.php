@extends('layouts.app')

@section('title', 'Usuarios')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/usuarios.css') }}?v={{ @filemtime(public_path('css/modules/usuarios.css')) }}">
@endpush

@section('content')
@php
    $can = fn ($action) => auth()->user()->hasPermission('usuarios', $action);
    $activeRoles = $roles->filter(fn ($r) => $r->status->value === 'active')->values();
    $hasFilters = collect($filters)->filter()->isNotEmpty();
@endphp

<div class="usr-layout">
    <aside>@include('usuarios.partials.submenu')</aside>

    <div class="usr-main">
        <x-ui.page-header title="Usuarios y roles" subtitle="Personas con acceso a la clínica, su rol y sus sucursales.">
            <x-slot name="actions">
                @if (auth()->user()->hasPermission('roles', 'ver'))
                    <x-ui.button variant="secondary" icon="shield" :href="route('roles.index')">Roles y permisos</x-ui.button>
                @endif
                @if ($can('crear'))
                    <x-ui.button icon="plus" data-modal-open="invite-modal">Invitar usuario</x-ui.button>
                @endif
            </x-slot>
        </x-ui.page-header>

        @include('usuarios.partials.access-link')

        <x-ui.card flush>
            <form method="GET" action="{{ route('usuarios.index') }}" class="table-toolbar usr-filters" data-filters>
                <x-ui.input name="q" :value="$filters['q'] ?? null" icon="search" placeholder="Buscar por nombre o correo" maxlength="100" aria-label="Buscar usuario" />
                <x-ui.select name="role" :options="$roles->pluck('name', 'id')->all()" :selected="$filters['role'] ?? null" placeholder="Todos los roles" aria-label="Filtrar por rol" />
                <x-ui.select name="branch" :options="$branches->pluck('name', 'id')->all()" :selected="$filters['branch'] ?? null" placeholder="Todas las sucursales" aria-label="Filtrar por sucursal" />
                <x-ui.select name="status" :options="['activo' => 'Activos', 'pendiente' => 'Invitación pendiente', 'inactivo' => 'Inactivos']" :selected="$filters['status'] ?? null" placeholder="Todos los estados" aria-label="Filtrar por estado" />
                @if ($hasFilters)
                    <x-ui.button variant="ghost" :href="route('usuarios.index')">Limpiar</x-ui.button>
                @endif
                <noscript><x-ui.button type="submit" variant="secondary">Filtrar</x-ui.button></noscript>
            </form>

            <x-ui.table>
                <x-slot name="head">
                    <tr>
                        <th>Usuario</th>
                        <th>Correo</th>
                        <th>Rol</th>
                        <th>Sucursales</th>
                        <th>Último acceso</th>
                        <th>Estado</th>
                        <th class="actions"></th>
                    </tr>
                </x-slot>

                @forelse ($members as $m)
                    @php
                        $status = $statuses[$m->id];
                        $role = $m->role;
                        $roleVariant = $role?->is_system_admin ? 'violet' : ($m->doctorProfile || $role?->code === 'doctor' ? 'info' : 'neutral');
                    @endphp
                    <tr>
                        <td>
                            <div class="row">
                                <x-ui.avatar :name="$m->user->full_name" />
                                <div>
                                    <div class="fw-700">{{ $m->user->full_name }}
                                        @if ($m->user_id === auth()->id())<x-ui.badge>Tú</x-ui.badge>@endif
                                    </div>
                                    @if ($m->doctorProfile?->specialty)<div class="text-muted text-sm">{{ $m->doctorProfile->specialty }}</div>@endif
                                </div>
                            </div>
                        </td>
                        <td class="text-muted">{{ $m->user->email }}</td>
                        <td>
                            @if ($role)
                                <x-ui.badge :variant="$roleVariant">{{ $role->name }}</x-ui.badge>
                                @if ($role->status->value !== 'active')<span class="text-muted text-sm">(inactivo)</span>@endif
                            @else
                                <x-ui.badge variant="warning">Sin rol</x-ui.badge>
                            @endif
                        </td>
                        <td class="text-muted">{{ $m->branches->pluck('name')->join(', ') ?: '—' }}</td>
                        <td class="text-muted">{{ $m->user->last_login_at ? $m->user->last_login_at->timezone(config('app.timezone'))->diffForHumans() : 'Nunca' }}</td>
                        <td>
                            @if ($status === 'activo')
                                <x-ui.badge variant="success" dot>Activo</x-ui.badge>
                            @elseif ($status === 'pendiente')
                                <x-ui.badge variant="warning" dot>Invitación pendiente</x-ui.badge>
                            @else
                                <x-ui.badge variant="neutral" dot>Inactivo</x-ui.badge>
                            @endif
                        </td>
                        <td class="actions">
                            <x-ui.button variant="ghost" size="sm" icon="edit" icon-only :href="route('usuarios.edit', $m)" aria-label="Editar {{ $m->user->full_name }}" title="Editar" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            @if ($hasFilters)
                                <x-ui.empty-state icon="search" title="Sin resultados" text="Ningún usuario coincide con los filtros.">
                                    <x-ui.button variant="secondary" :href="route('usuarios.index')">Quitar filtros</x-ui.button>
                                </x-ui.empty-state>
                            @else
                                <x-ui.empty-state icon="users" title="Aún no hay usuarios" text="Invita al primero para dar acceso a tu equipo." />
                            @endif
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>

            <div class="table-foot">
                <span>{{ $members->total() }} {{ $members->total() === 1 ? 'usuario' : 'usuarios' }}@if ($hasFilters) de {{ $totalMembers }}@endif</span>
                {{ $members->links() }}
            </div>
        </x-ui.card>
    </div>
</div>

@if ($can('crear'))
    @include('usuarios.partials.invite-modal')
@endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/usuarios.js') }}?v={{ @filemtime(public_path('js/modules/usuarios.js')) }}"></script>
@endpush
