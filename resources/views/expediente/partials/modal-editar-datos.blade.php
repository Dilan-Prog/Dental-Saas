{{-- Modal "Editar datos del paciente" (Figma 808:2999). --}}
@if (auth()->user()->hasPermission('pacientes', 'editar') && ! $patient->anonymized_at)
    <x-ui.modal id="editar-datos" title="Editar datos del paciente" :subtitle="$patient->folio" size="sm" open-on-error
                :action="route('expediente.datos.update', $patient->id)" method="PUT">
        <div class="stack">
            <x-ui.field name="full_name" label="Nombre completo" required :value="$patient->full_name" maxlength="250" autocomplete="off" />
            <x-ui.field name="birth_date" label="Fecha de nacimiento" type="date" required :value="$patient->birth_date?->format('Y-m-d')" max="{{ now()->format('Y-m-d') }}" min="1900-01-02" />
            <x-ui.field name="phone" label="Teléfono" required mask="phone" :value="$patient->phone" placeholder="55 1234 5678" />
            <x-ui.field name="email" label="Email" type="email" :value="$patient->email" maxlength="254" />
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Guardar</x-ui.button>
        </x-slot>
    </x-ui.modal>
@endif
