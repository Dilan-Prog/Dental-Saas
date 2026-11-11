@php
    use App\Enums\RecordStatus;
    use App\Models\Branch;
    use App\Rules\CfdiCatalogs;
    use App\Support\Money;

    $user = auth()->user();
    $canCreate = $user->hasPermission('tratamientos', 'crear');
    $canEdit = $user->hasPermission('tratamientos', 'editar');
    $canToggle = $user->hasPermission('tratamientos', 'desactivar');
    $clinicName = Branch::query()->where('is_main', true)->value('name') ?? 'Clínica';
    $trim = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';

    $tabs = [
        'tratamientos' => ['label' => 'Tratamientos', 'href' => route('tratamientos.index')],
        'categorias' => ['label' => 'Categorías', 'href' => route('tratamientos.index', ['tab' => 'categorias']), 'count' => $categories->count()],
        'impuestos' => ['label' => 'Impuestos', 'href' => route('tratamientos.index', ['tab' => 'impuestos']), 'count' => $taxProfiles->count()],
    ];

    $editId = old('_edit_id');
    $catId = old('_cat_id');
    $taxId = old('_tax_id');
    $taxOptions = $taxProfiles->where('status', RecordStatus::Active)->mapWithKeys(fn ($p) => [$p->id => $p->name]);
    if ($editId && ($current = $treatments->firstWhere('id', $editId)?->taxProfile) && ! $taxOptions->has($current->id)) {
        $taxOptions[$current->id] = $current->name.' (inactivo)';
    }
@endphp
<x-app-layout title="Tratamientos" :breadcrumbs="['Configuración' => Route::has('configuracion.index') ? route('configuracion.index') : null, 'Tratamientos']">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/modules/tratamientos.css') }}">
    @endpush

    <x-ui.page-header title="Configuración" subtitle="Administra tu clínica y preferencias" />

    <div class="settings-layout">
        @include('tratamientos._menu')

        <div class="settings-content">
            <x-ui.tabs :items="$tabs" :active="$tab" />

            {{-- ============================ TRATAMIENTOS ============================ --}}
            @if ($tab === 'tratamientos')
                <x-ui.card flush>
                    <div class="table-toolbar">
                        <div class="card__title">Catálogo de tratamientos</div>
                        @if ($canCreate)
                            <x-ui.button icon="plus" data-treatment-new data-modal-open="treatment-modal">Nuevo tratamiento</x-ui.button>
                        @endif
                    </div>

                    <form method="GET" action="{{ route('tratamientos.index') }}" class="treatments-filters">
                        <x-ui.input name="q" :value="$filters['q']" icon="search" placeholder="Buscar por nombre o clave SAT…" maxlength="100" aria-label="Buscar" />
                        <x-ui.select name="categoria" :options="$categories->pluck('name', 'id')" :selected="$filters['categoria']" placeholder="Todas las categorías" aria-label="Categoría" />
                        <x-ui.select name="estado" :options="['activos' => 'Activos', 'inactivos' => 'Inactivos', 'todos' => 'Todos']" :selected="$filters['estado']" aria-label="Estado" />
                        <x-ui.button type="submit" variant="secondary">Filtrar</x-ui.button>
                    </form>

                    <x-ui.table clickable>
                        <x-slot name="head">
                            <tr><th>Tratamiento</th><th>Categoría</th><th>Duración</th><th>Clave SAT</th><th>Impuesto</th><th class="text-right">Precio</th><th class="actions"></th></tr>
                        </x-slot>
                        @forelse ($treatments as $t)
                            @php
                                $payload = [
                                    'id' => $t->id, 'name' => $t->name, 'category' => $t->category?->name ?? '',
                                    'duration' => $t->duration_minutes, 'sat' => $t->sat_product_key, 'unit' => $t->sat_unit_key,
                                    'price' => $trim($t->price), 'tax' => $t->tax_profile_id,
                                ];
                            @endphp
                            <tr @if ($canEdit) data-treatment-row data-treatment='@json($payload)' @endif class="{{ $t->status === RecordStatus::Inactive ? 'is-inactive' : '' }}">
                                <td class="fw-500">{{ $t->name }}@if ($t->status === RecordStatus::Inactive) <x-ui.badge>Inactivo</x-ui.badge>@endif</td>
                                <td>@if ($t->category)<x-ui.badge variant="neutral">{{ $t->category->name }}</x-ui.badge>@else<span class="text-subtle">—</span>@endif</td>
                                <td class="nowrap">{{ $t->duration_minutes }} min</td>
                                <td class="mono text-muted">{{ $t->sat_product_key }}</td>
                                <td class="text-muted nowrap">{{ $t->taxProfile?->name ?? 'Exento' }}</td>
                                <td class="text-right mono fw-500 nowrap">{{ Money::format($t->price) }}</td>
                                <td class="actions nowrap">
                                    @if ($canEdit)
                                        <button type="button" class="icon-btn" data-treatment-edit aria-label="Editar {{ $t->name }}" title="Editar"><x-ui.icon name="edit" size="sm" /></button>
                                    @endif
                                    @if ($canToggle)
                                        <x-ui.confirm-form :action="route('tratamientos.toggle', $t)" method="PATCH" variant="ghost" size="sm" iconOnly
                                            :icon="$t->status === RecordStatus::Active ? 'x-circle' : 'check-circle'"
                                            :title="$t->status === RecordStatus::Active ? 'Desactivar tratamiento' : 'Activar tratamiento'"
                                            :message="$t->status === RecordStatus::Active ? '«'.$t->name.'» dejará de aparecer en presupuestos y citas nuevas.' : '«'.$t->name.'» volverá a estar disponible.'"
                                            :label="$t->status === RecordStatus::Active ? 'Desactivar' : 'Activar'" confirmText="Confirmar" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7">
                                <x-ui.empty-state icon="tooth" title="Sin tratamientos" text="No hay tratamientos que coincidan con los filtros.">
                                    @if ($canCreate)<x-ui.button icon="plus" data-modal-open="treatment-modal" data-treatment-new>Nuevo tratamiento</x-ui.button>@endif
                                </x-ui.empty-state>
                            </td></tr>
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
                <div class="pagination-wrap">{{ $treatments->links() }}</div>

            {{-- ============================ CATEGORÍAS ============================ --}}
            @elseif ($tab === 'categorias')
                <x-ui.card flush>
                    <div class="table-toolbar">
                        <div class="card__title">Categorías de tratamientos</div>
                        @if ($canCreate)<x-ui.button icon="plus" data-category-new data-modal-open="category-modal">Nueva categoría</x-ui.button>@endif
                    </div>
                    <x-ui.table>
                        <x-slot name="head"><tr><th>Categoría</th><th>Tratamientos</th><th class="actions"></th></tr></x-slot>
                        @forelse ($categories as $c)
                            <tr>
                                <td class="fw-500">{{ $c->name }}</td>
                                <td class="text-muted">{{ $c->treatments_count }}</td>
                                <td class="actions nowrap">
                                    @if ($canEdit)
                                        <button type="button" class="icon-btn" data-category-edit data-id="{{ $c->id }}" data-name="{{ $c->name }}" aria-label="Editar {{ $c->name }}" title="Editar"><x-ui.icon name="edit" size="sm" /></button>
                                    @endif
                                    @if ($canToggle)
                                        <x-ui.confirm-form :action="route('tratamientos.categorias.destroy', $c)" method="DELETE" variant="ghost" size="sm" iconOnly icon="trash"
                                            title="Eliminar categoría" :message="$c->treatments_count > 0 ? '«'.$c->name.'» tiene tratamientos asignados y no podrá eliminarse.' : '¿Eliminar la categoría «'.$c->name.'»?'" label="Eliminar" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-ui.empty-state icon="tooth" title="Sin categorías" text="Las categorías se crean al registrar un tratamiento o desde aquí." /></td></tr>
                        @endforelse
                    </x-ui.table>
                </x-ui.card>

            {{-- ============================ IMPUESTOS ============================ --}}
            @else
                <x-ui.card flush>
                    <div class="table-toolbar">
                        <div>
                            <div class="card__title">Perfiles de impuestos</div>
                            <div class="text-xs text-muted">La tasa se copia a cada concepto del presupuesto: cambiarla no altera documentos ya creados.</div>
                        </div>
                        @if ($canCreate)<x-ui.button icon="plus" data-tax-new data-modal-open="tax-modal">Nuevo perfil</x-ui.button>@endif
                    </div>
                    <x-ui.table>
                        <x-slot name="head"><tr><th>Nombre</th><th>Impuesto</th><th class="text-right">Tasa</th><th>Estado</th><th>Tratamientos</th><th class="actions"></th></tr></x-slot>
                        @forelse ($taxProfiles as $p)
                            <tr>
                                <td class="fw-500">{{ $p->name }}@if ($p->is_exempt) <x-ui.badge variant="info">Exento</x-ui.badge>@endif</td>
                                <td class="text-muted">{{ CfdiCatalogs::label('impuestos', $p->tax_code) ?? $p->tax_code }} <span class="mono text-subtle">({{ $p->tax_code }})</span></td>
                                <td class="text-right mono">{{ $trim(bcmul((string) $p->rate, '100', 4)) }}%</td>
                                <td><x-ui.badge :variant="$p->status === RecordStatus::Active ? 'success' : 'neutral'">{{ $p->status === RecordStatus::Active ? 'Activo' : 'Inactivo' }}</x-ui.badge></td>
                                <td class="text-muted">{{ $p->treatments_count }}</td>
                                <td class="actions nowrap">
                                    @if ($canEdit)
                                        <button type="button" class="icon-btn" data-tax-edit aria-label="Editar {{ $p->name }}" title="Editar"
                                            data-id="{{ $p->id }}" data-name="{{ $p->name }}" data-code="{{ $p->tax_code }}"
                                            data-rate="{{ $trim(bcmul((string) $p->rate, '100', 4)) }}" data-exempt="{{ $p->is_exempt ? 1 : 0 }}"><x-ui.icon name="edit" size="sm" /></button>
                                    @endif
                                    @if ($canToggle)
                                        <x-ui.confirm-form :action="route('tratamientos.impuestos.toggle', $p)" method="PATCH" variant="ghost" size="sm" iconOnly
                                            :icon="$p->status === RecordStatus::Active ? 'x-circle' : 'check-circle'"
                                            :title="$p->status === RecordStatus::Active ? 'Desactivar perfil' : 'Activar perfil'"
                                            :message="$p->status === RecordStatus::Active ? 'El perfil «'.$p->name.'» ya no se ofrecerá en tratamientos nuevos.' : 'El perfil «'.$p->name.'» volverá a estar disponible.'"
                                            :label="$p->status === RecordStatus::Active ? 'Desactivar' : 'Activar'" confirmText="Confirmar" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-ui.empty-state icon="receipt" title="Sin perfiles de impuestos" /></td></tr>
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>
    </div>

    {{-- ============================ MODALES ============================ --}}
    @if ($canCreate || $canEdit)
        <datalist id="category-list">@foreach ($categories as $c)<option value="{{ $c->name }}">@endforeach</datalist>
        <datalist id="sat-keys">@foreach (config('sat.claves_producto_sugeridas', []) as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</datalist>

        <x-ui.modal id="treatment-modal" :title="$editId ? 'Tratamiento a editar' : 'Nuevo tratamiento'" :subtitle="'Tratamientos · '.$clinicName" open-on-error
            :action="$editId ? route('tratamientos.update', $editId) : route('tratamientos.store')" :method="$editId ? 'PUT' : 'POST'"
            data-store-url="{{ route('tratamientos.store') }}" data-update-url="{{ route('tratamientos.update', '__ID__') }}" data-destroy-url="{{ route('tratamientos.destroy', '__ID__') }}">
            <input type="hidden" name="_edit_id" value="{{ $editId }}" data-edit-id>
            <div class="stack">
                <x-ui.field name="name" label="Nombre del tratamiento" required placeholder="Escribe el nombre del tratamiento" maxlength="200" />
                <x-ui.field name="category_name" label="Nombre de la categoría" placeholder="Escribe la categoría" maxlength="100" list="category-list" autocomplete="off"
                            hint="Si no existe, se crea automáticamente." />
                <div class="form-grid">
                    <x-ui.field name="duration_minutes" label="Duración del tratamiento (min)" type="number" required min="1" max="1440" step="1" placeholder="Ej. 30" />
                    <x-ui.field name="price" label="Precio (MXN, sin IVA)" type="number" required min="0" step="any" placeholder="Precio del tratamiento" />
                </div>
                <div class="form-grid">
                    <x-ui.field name="sat_product_key" label="Clave SAT (producto/servicio)" required mask="digits" maxlength="8" placeholder="85121800" list="sat-keys" autocomplete="off" />
                    <x-ui.field name="sat_unit_key" label="Unidad SAT" as="select" required :options="CfdiCatalogs::options('unidades')" selected="E48" />
                </div>
                <x-ui.field name="tax_profile_id" label="Impuesto" as="select" :options="$taxOptions" :selected="$defaultTaxProfileId" placeholder="Exento / sin impuesto" />
            </div>
            <x-slot name="footer">
                @if ($canToggle)
                    <button type="submit" form="treatment-delete-form" class="btn btn--danger" data-treatment-delete @if(! $editId) hidden @endif>Eliminar</button>
                @endif
                <span class="flex-1"></span>
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar</x-ui.button>
            </x-slot>
        </x-ui.modal>

        @if ($canToggle)
            <form id="treatment-delete-form" method="POST" action="{{ $editId ? route('tratamientos.destroy', $editId) : '' }}" hidden
                  data-confirm="El tratamiento se quitará del catálogo. Las citas y facturas ya registradas conservan su historial."
                  data-confirm-title="¿Eliminar tratamiento?" data-confirm-text="Eliminar">
                @csrf @method('DELETE')
            </form>
        @endif

        <x-ui.modal id="category-modal" :title="$catId ? 'Editar categoría' : 'Nueva categoría'" :subtitle="'Tratamientos · '.$clinicName" size="sm" open-on-error
            :action="$catId ? route('tratamientos.categorias.update', $catId) : route('tratamientos.categorias.store')" :method="$catId ? 'PUT' : 'POST'"
            data-store-url="{{ route('tratamientos.categorias.store') }}" data-update-url="{{ route('tratamientos.categorias.update', '__ID__') }}">
            <input type="hidden" name="_cat_id" value="{{ $catId }}" data-edit-id>
            <x-ui.field bag="category" name="name" label="Nombre de la categoría" required maxlength="100" placeholder="Ej. Preventiva" />
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar</x-ui.button>
            </x-slot>
        </x-ui.modal>

        <x-ui.modal id="tax-modal" :title="$taxId ? 'Editar perfil de impuestos' : 'Nuevo perfil de impuestos'" :subtitle="'Tratamientos · '.$clinicName" size="sm" open-on-error
            :action="$taxId ? route('tratamientos.impuestos.update', $taxId) : route('tratamientos.impuestos.store')" :method="$taxId ? 'PUT' : 'POST'"
            data-store-url="{{ route('tratamientos.impuestos.store') }}" data-update-url="{{ route('tratamientos.impuestos.update', '__ID__') }}">
            <input type="hidden" name="_tax_id" value="{{ $taxId }}" data-edit-id>
            <div class="stack">
                <x-ui.field bag="taxProfile" name="name" label="Nombre" required maxlength="100" placeholder="Ej. IVA 16%" />
                <div class="form-grid">
                    <x-ui.field bag="taxProfile" name="tax_code" label="Impuesto" as="select" required :options="CfdiCatalogs::options('impuestos')" selected="002" />
                    <x-ui.field bag="taxProfile" name="rate_pct" label="Tasa (%)" type="number" required min="0" max="100" step="any" placeholder="16" />
                </div>
                <x-ui.checkbox name="is_exempt" label="Exento (tasa 0%)" value="1" unchecked="0" />
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif

    @push('scripts')
        <script src="{{ asset('js/modules/tratamientos.js') }}"></script>
    @endpush
</x-app-layout>
