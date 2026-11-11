@extends('configuracion.layout')

@section('cfg')
    @php
        $editId = old('_edit_id');
        $storeUrl = route('configuracion.sucursales.store');
    @endphp

    <x-ui.page-header title="Sucursales">
        <x-slot name="actions">
            <x-ui.button icon="plus" data-branch-new>Nueva sucursal</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <x-ui.card flush>
        <x-ui.table>
            <x-slot name="head">
                <tr>
                    <th>Sucursal</th>
                    <th>Dirección</th>
                    <th>Responsable</th>
                    <th>Doctores</th>
                    <th>Estado</th>
                    <th class="actions"></th>
                </tr>
            </x-slot>
            @forelse ($branches as $branch)
                @php $inactive = $branch->status->value !== 'active'; @endphp
                <tr class="{{ $inactive ? 'is-muted' : '' }}">
                    <td>
                        <strong>{{ $branch->name }}</strong>
                        @if ($branch->is_main)<x-ui.badge variant="primary" class="ml-2">Principal</x-ui.badge>@endif
                    </td>
                    <td>
                        {{ $branch->address_line ?: '—' }}
                        @if ($branch->phone)<div class="text-xs text-muted">{{ $formatPhone($branch->phone) }}</div>@endif
                    </td>
                    <td>{{ $branch->manager?->full_name ?: '—' }}</td>
                    <td>{{ $doctors[$branch->id] ?? 0 }}</td>
                    <td>
                        <x-ui.badge :variant="$inactive ? 'neutral' : 'success'" dot>{{ $inactive ? 'Inactiva' : 'Activa' }}</x-ui.badge>
                    </td>
                    <td class="actions">
                        <x-ui.dropdown>
                            <x-slot name="trigger">
                                <x-ui.button variant="ghost" size="sm" icon="more-horizontal" icon-only aria-label="Acciones de {{ $branch->name }}" />
                            </x-slot>
                            <button type="button" class="dropdown-item" data-branch-edit
                                data-action="{{ route('configuracion.sucursales.update', $branch) }}"
                                data-id="{{ $branch->id }}"
                                data-name="{{ $branch->name }}"
                                data-address="{{ $branch->address_line }}"
                                data-phone="{{ $formatPhone($branch->phone) }}"
                                data-manager="{{ $branch->manager_user_id }}"
                                data-main="{{ $branch->is_main ? 1 : 0 }}"
                                data-inactive="{{ $inactive ? 1 : 0 }}">
                                <x-ui.icon name="edit" /> Editar
                            </button>
                            @if (! $branch->is_main && ! $inactive)
                                <form method="POST" action="{{ route('configuracion.sucursales.main', $branch) }}"
                                      data-confirm="«{{ $branch->name }}» será la sucursal principal y «{{ $branches->firstWhere('is_main', true)?->name }}» dejará de serlo."
                                      data-confirm-title="Cambiar sucursal principal" data-confirm-text="Hacer principal" data-confirm-variant="primary">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="dropdown-item"><x-ui.icon name="building" /> Hacer principal</button>
                                </form>
                            @endif
                            @if (! $branch->is_main)
                                <div class="dropdown-sep"></div>
                                @if ($inactive)
                                    <form method="POST" action="{{ route('configuracion.sucursales.status', $branch) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="status" value="active">
                                        <button type="submit" class="dropdown-item"><x-ui.icon name="check-circle" /> Activar</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('configuracion.sucursales.status', $branch) }}"
                                          data-confirm="¿Desactivar «{{ $branch->name }}»? Dejará de estar disponible para nuevas citas y movimientos."
                                          data-confirm-title="Desactivar sucursal" data-confirm-text="Desactivar">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="status" value="inactive">
                                        <button type="submit" class="dropdown-item dropdown-item--danger"><x-ui.icon name="x-circle" /> Desactivar</button>
                                    </form>
                                @endif
                            @endif
                        </x-ui.dropdown>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><x-ui.empty-state title="Aún no hay sucursales" icon="building" /></td></tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    {{-- Modal crear / editar (un solo formulario; el JS ajusta acción, método y valores) --}}
    <x-ui.modal id="sucursal-modal" :title="$editId ? 'Editar sucursal' : 'Nueva sucursal'" subtitle="Datos de la sucursal" open-on-error
                :action="$editId ? route('configuracion.sucursales.update', $editId) : $storeUrl" method="POST" data-branch-form
                novalidate>
        <input type="hidden" name="_method" value="{{ $editId ? 'PUT' : 'POST' }}" data-branch-method>
        <input type="hidden" name="_edit_id" value="{{ $editId }}" data-branch-id>

        <div class="stack">
            <x-ui.field name="name" label="Nombre" required maxlength="150" placeholder="Ej. Sucursal Sur" />
            <x-ui.field name="address_line" label="Dirección" maxlength="300" placeholder="Calle, número, colonia, ciudad" />
            <div class="form-grid">
                <x-ui.field name="phone" label="Teléfono" mask="phone" placeholder="55 1234 5678" inputmode="tel" />
                <x-ui.field name="manager_user_id" label="Responsable" as="select" placeholder="Sin responsable" :options="$managers->all()" />
            </div>
            <x-ui.checkbox name="is_main" value="1" label="Establecer como sucursal principal" data-branch-main />
            @error('is_main')<div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $message }}</div>@enderror
        </div>

        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Guardar</x-ui.button>
        </x-slot>
    </x-ui.modal>
@endsection

@push('scripts')
    <script>window.__branchStoreUrl = @json($storeUrl);</script>
@endpush
