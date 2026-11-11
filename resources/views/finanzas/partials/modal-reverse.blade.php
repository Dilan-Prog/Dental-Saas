{{-- Modal generico de reversion: el JS (finanzas.js) fija la accion y el resumen del movimiento. --}}
<x-ui.modal id="revertir-movimiento" title="Revertir movimiento" subtitle="Se registrará el movimiento contrapuesto; el original no se modifica" open-on-error
            action="{{ old('_reverse_action', '#') }}" data-fin-reverse-form>
    <input type="hidden" name="_reverse_action" value="{{ old('_reverse_action') }}" data-fin-reverse-action-input>
    <div class="stack">
        <div class="fin-reverse-summary text-sm" data-fin-reverse-summary>{{ old('_reverse_summary') }}</div>
        <input type="hidden" name="_reverse_summary" value="{{ old('_reverse_summary') }}" data-fin-reverse-summary-input>
        <x-ui.field label="Motivo de la reversión" name="reason" bag="reverse" as="textarea" rows="3" required maxlength="200"
                    placeholder="Ej. Captura duplicada" />
    </div>
    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit" variant="danger">Revertir</x-ui.button>
    </x-slot>
</x-ui.modal>
