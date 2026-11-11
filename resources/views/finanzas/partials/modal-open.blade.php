{{-- Modal "Abrir caja". Requiere $registers (cajas activas con branch). Opcional: $preselect (id de caja) --}}
@php
    $options = $registers->mapWithKeys(fn ($r) => [$r->id => $r->name.' · '.($r->branch?->name ?? 'Sucursal')])->all();
@endphp
<x-ui.modal id="abrir-caja" title="Abrir caja" subtitle="Inicia un turno con el fondo de caja" open-on-error
            action="{{ route('finanzas.turnos.open') }}">
    <div class="stack">
        <x-ui.field label="Caja" name="cash_register_id" bag="openCash" as="select" :options="$options" :selected="$preselect ?? (count($options) === 1 ? array_key_first($options) : null)" placeholder="Selecciona la caja" required />
        <x-ui.field label="Monto inicial (fondo de caja)" name="opening_amount" bag="openCash" mask="money" inputmode="decimal" value="0" placeholder="0.00" required
                    hint="Efectivo con el que inicia el turno; puede ser $0.00." />
    </div>
    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit">Abrir caja</x-ui.button>
    </x-slot>
</x-ui.modal>
