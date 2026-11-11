{{--
  Modal de reversion de un movimiento. El action se fija por JS desde
  data-reverse-action del boton "Revertir" (y se conserva si hay errores de validacion).
--}}
<x-ui.modal id="modal-revertir" title="Revertir movimiento" subtitle="Crea un movimiento contrario; el original no se modifica"
            action="#" open-on-error data-reverse-form>
    <input type="hidden" name="_reverse_action" value="{{ old('_reverse_action') }}">
    <p class="text-muted text-sm" data-reverse-label></p>
    <x-ui.field label="Motivo de la reversión" name="reason" bag="reversion" as="textarea" rows="3" required maxlength="500"
                placeholder="Ej. Captura duplicada" />
    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit" variant="danger">Revertir movimiento</x-ui.button>
    </x-slot>
</x-ui.modal>
