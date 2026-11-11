{{-- Modal "Cerrar caja" / corte (Figma 808:3950). Requiere $s (CashSession abierta, con cashRegister.branch) --}}
@php
    use App\Services\Finanzas\FinanceFormatter as Fmt;
    use App\Support\Money;

    $mine = old('_session') === $s->id;
    $bagName = $mine ? 'closeCash' : 'none_'.$s->id;
    $expected = $s->expectedCash();
@endphp
<x-ui.modal :id="'corte-'.$s->id" title="Cerrar caja"
            :subtitle="'Turno · '.Fmt::date($s->opened_at).' · '.($s->cashRegister?->name ?? 'Caja')"
            :action="route('finanzas.turnos.close', $s)" open-on-error data-fin-close-form data-expected="{{ $expected }}">
    <input type="hidden" name="_session" value="{{ $s->id }}">
    <div class="stack">
        <div class="grid grid-3 fin-close-tiles">
            <div class="fin-tile"><div class="fin-tile__label">Monto inicial</div><div class="fin-tile__value mono">{{ Money::format($s->opening_amount) }}</div></div>
            <div class="fin-tile"><div class="fin-tile__label">Esperado en caja</div><div class="fin-tile__value mono">{{ Money::format($expected) }}</div></div>
            <div class="fin-tile"><div class="fin-tile__label">Diferencia</div><div class="fin-tile__value mono" data-fin-difference>—</div></div>
        </div>

        <x-ui.field label="Efectivo contado" name="counted_cash" :bag="$bagName" mask="money" inputmode="decimal"
                    :value="$mine ? null : $expected" required data-fin-counted
                    hint="Cuenta el efectivo físico. El esperado lo calcula el sistema: apertura + efectivo cobrado − efectivo pagado ± ajustes." />
        <x-ui.field label="Notas del corte" name="notes" :bag="$bagName" as="textarea" rows="3" placeholder="Opcional si no hay diferencia" maxlength="1000" />
    </div>

    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit">Cerrar caja</x-ui.button>
    </x-slot>
</x-ui.modal>
