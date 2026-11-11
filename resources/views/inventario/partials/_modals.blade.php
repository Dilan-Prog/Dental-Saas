{{--
  Modales de inventario (compartidos por el resumen y el kardex).
  Variables: $branches, $modalItems, $suppliers, $categories, $encounters, $openLots
  Opcionales: $preItem (InventoryItem preseleccionado, en el kardex).
  Cada formulario usa su propia bolsa de errores (insumo, entrada, salida, ajuste, inicial, reversion)
  y el modal se reabre solo si su bolsa trae errores (open-on-error).
--}}
@php
    $preItem = $preItem ?? null;
    $itemOptions = $modalItems->mapWithKeys(fn ($i) => [$i->id => $i->name . ' · ' . $i->sku])->all();
    $branchOptions = $branches->mapWithKeys(fn ($b) => [$b->id => $b->name])->all();
    $supplierOptions = $suppliers->mapWithKeys(fn ($s) => [$s->id => $s->name])->all();
    $encounterOptions = $encounters->mapWithKeys(fn ($e) => [$e->id => $e->label])->all();
    $multiBranch = $branches->count() > 1;
    $defaultBranch = $branches->firstWhere('is_main', true)?->id ?? $branches->first()?->id;
    $units = ['Pieza', 'Caja', 'Paquete', 'Cartucho', 'Jeringa', 'Frasco', 'Bolsa', 'Kit', 'Tubo', 'Rollo', 'Par', 'Litro', 'Mililitro', 'Gramo'];
@endphp

<datalist id="inv-units">@foreach ($units as $u)<option value="{{ $u }}">@endforeach</datalist>
<datalist id="inv-categories">@foreach ($categories as $c)<option value="{{ $c }}">@endforeach</datalist>

{{-- ============ Nuevo insumo (Figma 808:4007) ============ --}}
<x-ui.modal id="modal-nuevo-insumo" title="Nuevo insumo" subtitle="Alta de insumo en inventario" size="lg"
            :action="route('inventario.items.store')" open-on-error>
    <div class="form-grid">
        <x-ui.field label="Nombre" name="name" bag="insumo" required placeholder="Ej. Guantes nitrilo (M)" maxlength="200" />
        <x-ui.field label="SKU" name="sku" bag="insumo" required placeholder="Ej. GNT-NIT-M" maxlength="60" mask="upper" />
        <x-ui.field label="Categoría" name="category" bag="insumo" placeholder="Protección" maxlength="100" list="inv-categories" />
        <x-ui.field label="Proveedor" name="supplier_id" as="select" bag="insumo" :options="$supplierOptions" placeholder="Sin proveedor" />
        <x-ui.field label="Unidad de medida" name="unit_of_measure" bag="insumo" required placeholder="Ej. Caja" maxlength="30" list="inv-units" />
        @if ($multiBranch)
            <x-ui.field label="Sucursal" name="branch_id" as="select" bag="insumo" :options="$branchOptions" :selected="$defaultBranch" required />
        @else
            <input type="hidden" name="branch_id" value="{{ $defaultBranch }}">
            <div></div>
        @endif
    </div>
    <div class="grid grid-3 mt-4 inv-stock-fields">
        <x-ui.field label="Stock inicial" name="initial_stock" bag="insumo" type="number" step="0.0001" min="0" placeholder="0"
                    hint="Opcional. Genera el inventario inicial (solo una vez)." />
        <x-ui.field label="Stock mínimo" name="min_quantity" bag="insumo" type="number" step="0.0001" min="0" placeholder="0"
                    hint="Genera la alerta de reabasto." />
        <x-ui.field label="Costo unitario" name="unit_cost" bag="insumo" type="number" step="0.0001" min="0" placeholder="$0.00"
                    hint="Obligatorio si hay stock inicial." />
    </div>
    <div class="form-grid mt-4">
        <x-ui.field label="Ubicación" name="location" bag="insumo" placeholder="Ej. Estante A-1" maxlength="100" />
    </div>
    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit">Guardar insumo</x-ui.button>
    </x-slot>
</x-ui.modal>

{{-- ============ Registrar entrada (Figma 808:4040) ============ --}}
<x-ui.modal id="modal-entrada" title="Registrar entrada" subtitle="Suma stock a un insumo"
            :action="route('inventario.movimientos.entrada')" open-on-error>
    <div class="form-grid">
        <x-ui.field label="Insumo" name="item_id" as="select" bag="entrada" class="col-span-2" :options="$itemOptions"
                    :selected="$preItem?->id" placeholder="Selecciona un insumo" required data-inv-item />
        @if ($multiBranch)
            <x-ui.field label="Sucursal" name="branch_id" as="select" bag="entrada" :options="$branchOptions" :selected="$defaultBranch" required />
        @else
            <input type="hidden" name="branch_id" value="{{ $defaultBranch }}">
        @endif
        <x-ui.field label="Cantidad" name="quantity" bag="entrada" type="number" step="0.0001" min="0.0001" placeholder="0" required />
        <x-ui.field label="Costo unitario" name="unit_cost" bag="entrada" type="number" step="0.0001" min="0" placeholder="$0.00" required />
        <x-ui.field label="Proveedor" name="supplier_id" as="select" bag="entrada" :options="$supplierOptions" :selected="$preItem?->supplier_id" placeholder="Sin proveedor" />
        <x-ui.field label="Referencia" name="reference" bag="entrada" placeholder="Ej. Factura proveedor" maxlength="120" />
        <x-ui.field label="Lote (opcional)" name="lot_code" bag="entrada" placeholder="Ej. L2406-A" maxlength="60" mask="upper" />
        <x-ui.field label="Caducidad (opcional)" name="expires_on" bag="entrada" type="date" min="{{ now()->toDateString() }}" />
    </div>
    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit">Registrar entrada</x-ui.button>
    </x-slot>
</x-ui.modal>

{{-- ============ Registrar salida ============ --}}
<x-ui.modal id="modal-salida" title="Registrar salida" subtitle="Resta stock por uso, merma o traspaso"
            :action="route('inventario.movimientos.salida')" open-on-error>
    <div class="form-grid">
        <x-ui.field label="Insumo" name="item_id" as="select" bag="salida" class="col-span-2" :options="$itemOptions"
                    :selected="$preItem?->id" placeholder="Selecciona un insumo" required data-inv-item />
        @if ($multiBranch)
            <x-ui.field label="Sucursal" name="branch_id" as="select" bag="salida" :options="$branchOptions" :selected="$defaultBranch" required data-inv-branch />
        @else
            <input type="hidden" name="branch_id" value="{{ $defaultBranch }}" data-inv-branch>
        @endif
        <x-ui.field label="Cantidad" name="quantity" bag="salida" type="number" step="0.0001" min="0.0001" placeholder="0" required />
        <x-ui.field label="Lote (opcional)" name="lot_id" bag="salida">
            <x-ui.select name="lot_id" bag="salida" placeholder="Sin lote" data-inv-lots>
                @foreach ($openLots as $lot)
                    <option value="{{ $lot->id }}" data-item="{{ $lot->item_id }}" data-branch="{{ $lot->branch_id }}" @selected(old('lot_id') === $lot->id)>
                        {{ $lot->lot_code }} · {{ rtrim(rtrim(number_format((float) $lot->quantity_on_hand, 4, '.', ''), '0'), '.') }} disp.{{ $lot->expires_on ? ' · cad. ' . $lot->expires_on->format('d/m/Y') : '' }}
                    </option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Motivo" name="reason" bag="salida" class="col-span-2" required placeholder="Ej. Uso en consulta, merma, caducado" maxlength="500" />
        <x-ui.field label="Consulta (opcional)" name="encounter_id" as="select" bag="salida" :options="$encounterOptions" placeholder="Sin consulta" />
        <x-ui.field label="Referencia" name="reference" bag="salida" placeholder="Ej. Folio de consumo" maxlength="120" />
    </div>
    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit">Registrar salida</x-ui.button>
    </x-slot>
</x-ui.modal>

{{-- ============ Ajuste ============ --}}
<x-ui.modal id="modal-ajuste" title="Ajuste de inventario" subtitle="Corrige la existencia: positivo suma, negativo resta"
            :action="route('inventario.movimientos.ajuste')" open-on-error>
    <div class="form-grid">
        <x-ui.field label="Insumo" name="item_id" as="select" bag="ajuste" class="col-span-2" :options="$itemOptions"
                    :selected="$preItem?->id" placeholder="Selecciona un insumo" required data-inv-item />
        @if ($multiBranch)
            <x-ui.field label="Sucursal" name="branch_id" as="select" bag="ajuste" :options="$branchOptions" :selected="$defaultBranch" required />
        @else
            <input type="hidden" name="branch_id" value="{{ $defaultBranch }}">
        @endif
        <x-ui.field label="Cantidad (+/−)" name="quantity" bag="ajuste" type="number" step="0.0001" placeholder="-2 ó 5" required
                    hint="Positivo suma, negativo resta. No puede ser cero." />
        <x-ui.field label="Costo unitario (si suma)" name="unit_cost" bag="ajuste" type="number" step="0.0001" min="0" placeholder="Opcional" />
        <x-ui.field label="Motivo" name="reason" bag="ajuste" class="col-span-2" required placeholder="Ej. Conteo físico, error de captura" maxlength="500" />
        <x-ui.field label="Referencia" name="reference" bag="ajuste" placeholder="Ej. Conteo 2026-06" maxlength="120" />
    </div>
    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit">Registrar ajuste</x-ui.button>
    </x-slot>
</x-ui.modal>

{{-- ============ Inventario inicial (insumos ya creados sin inicial en la sucursal) ============ --}}
<x-ui.modal id="modal-inicial" title="Inventario inicial" subtitle="Solo se registra una vez por insumo y sucursal"
            :action="route('inventario.movimientos.inicial')" open-on-error>
    <div class="form-grid">
        <x-ui.field label="Insumo" name="item_id" as="select" bag="inicial" class="col-span-2" :options="$itemOptions"
                    :selected="$preItem?->id" placeholder="Selecciona un insumo" required />
        @if ($multiBranch)
            <x-ui.field label="Sucursal" name="branch_id" as="select" bag="inicial" :options="$branchOptions" :selected="$defaultBranch" required />
        @else
            <input type="hidden" name="branch_id" value="{{ $defaultBranch }}">
        @endif
        <x-ui.field label="Cantidad" name="quantity" bag="inicial" type="number" step="0.0001" min="0.0001" placeholder="0" required />
        <x-ui.field label="Costo unitario" name="unit_cost" bag="inicial" type="number" step="0.0001" min="0" placeholder="$0.00" required />
        <x-ui.field label="Referencia" name="reference" bag="inicial" placeholder="Ej. Conteo de apertura" maxlength="120" />
    </div>
    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit">Guardar inventario inicial</x-ui.button>
    </x-slot>
</x-ui.modal>
