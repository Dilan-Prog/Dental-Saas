@extends('layouts.app')

@section('title', 'Categorías financieras')

@section('breadcrumbs')
    <a href="{{ route('dashboard') }}" class="crumb-extra">Inicio</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <a href="{{ route('finanzas.index') }}" class="crumb-extra">Finanzas</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <span class="is-current">Categorías</span>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/finanzas.css') }}?v={{ @filemtime(public_path('css/modules/finanzas.css')) }}">
@endpush

@php
    use App\Services\Finanzas\MovementService;

    $user = auth()->user();
    $canCreate = $user->hasPermission('finanzas', 'crear');
    $canEdit = $user->hasPermission('finanzas', 'editar');
    $canToggle = $user->hasPermission('finanzas', 'desactivar');
    $editing = filled(old('_category_action'));
    $bagNew = $editing ? 'none_new' : 'category';
    $bagEdit = $editing ? 'category' : 'none_edit';
@endphp

@section('content')
    <x-ui.page-header title="Finanzas" subtitle="Categorías de ingresos y egresos">
        <x-slot name="actions">
            @if ($canCreate)
                <x-ui.button icon="plus" data-modal-open="nueva-categoria">Nueva categoría</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    @include('finanzas.partials.tabs', ['active' => 'categorias'])

    <div class="grid grid-2">
        @foreach ([['Ingresos', $income, 'ingreso'], ['Egresos', $expense, 'egreso']] as [$title, $list, $dir])
            <x-ui.card :title="$title" :subtitle="$list->count().' categorías'" flush>
                <x-ui.table>
                    <x-slot name="head"><tr><th>Nombre</th><th class="text-right">Movimientos</th><th>Estado</th><th class="actions"></th></tr></x-slot>
                    @forelse ($list as $c)
                        @php $system = MovementService::isSystemCategoryName($c->name); $active = $c->status->value === 'active'; @endphp
                        <tr class="{{ $active ? '' : 'fin-row--muted' }}">
                            <td><strong>{{ $c->name }}</strong>@if ($system) <x-ui.badge variant="info">Sistema</x-ui.badge>@endif</td>
                            <td class="text-right mono">{{ $c->movements_count }}</td>
                            <td>@if ($active)<x-ui.badge variant="success" dot>Activa</x-ui.badge>@else<x-ui.badge>Inactiva</x-ui.badge>@endif</td>
                            <td class="actions">
                                @if (! $system)
                                    @if ($canEdit)
                                        <x-ui.button size="sm" variant="ghost" icon="edit" icon-only aria-label="Editar categoría" data-edit-category
                                                     data-action="{{ route('finanzas.categorias.update', $c) }}" data-name="{{ $c->name }}" />
                                    @endif
                                    @if ($canToggle)
                                        <x-ui.confirm-form :action="route('finanzas.categorias.toggle', $c)" method="PATCH"
                                                           :message="$active ? '¿Desactivar «'.$c->name.'»? Ya no aparecerá en nuevas transacciones; el historial se conserva.' : '¿Activar «'.$c->name.'»?'"
                                                           :label="$active ? 'Desactivar' : 'Activar'" :variant="$active ? 'danger-outline' : 'secondary'" />
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-ui.empty-state title="Sin categorías" /></td></tr>
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        @endforeach
    </div>

    @if ($canCreate)
        <x-ui.modal id="nueva-categoria" title="Nueva categoría" open-on-error action="{{ route('finanzas.categorias.store') }}">
            <div class="stack">
                <x-ui.field label="Tipo" name="direction" :bag="$bagNew" as="select" :options="$directions" selected="egreso" required />
                <x-ui.field label="Nombre" name="name" :bag="$bagNew" required maxlength="100" placeholder="Ej. Mantenimiento de equipo" />
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Crear categoría</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif

    @if ($canEdit)
        <x-ui.modal id="editar-categoria" title="Editar categoría" subtitle="El tipo no se puede cambiar" open-on-error
                    action="{{ old('_category_action', '#') }}" method="PUT" data-fin-category-form>
            <input type="hidden" name="_category_action" value="{{ old('_category_action') }}" data-fin-category-action-input>
            <div class="stack">
                <x-ui.field label="Nombre" name="name" :bag="$bagEdit" required maxlength="100" data-fin-category-name />
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/finanzas.js') }}?v={{ @filemtime(public_path('js/modules/finanzas.js')) }}"></script>
@endpush
