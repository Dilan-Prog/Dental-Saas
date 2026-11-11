{{-- Modal "Nueva nota clínica" (nota simple; Figma 808:2982). Requiere: $patient, $doctors, $defaultDoctorId, $treatments, $canSign. --}}
<x-ui.modal id="nueva-nota" title="Nueva nota clínica" :subtitle="$patient->full_name.' · '.$patient->folio" open-on-error
            :action="route('expediente.notas.store', $patient->id)">
    <input type="hidden" name="note_type" value="simple">
    <div class="stack">
        <x-ui.field name="treatment_id" label="Tratamiento" as="select" required
                    :options="$treatments->pluck('name', 'id')->all()" placeholder="Selecciona un tratamiento" />
        <x-ui.field name="tooth_fdi" label="Pieza dental" mask="digits" maxlength="2" placeholder="Ej. 26" hint="Numeración FDI (11-48 permanentes, 51-85 temporales)." />
        <x-ui.field name="responsible_doctor_member_id" label="Doctor responsable" as="select" required
                    :options="$doctors->mapWithKeys(fn ($m) => [$m->id => $m->user?->full_name])->all()"
                    :selected="$defaultDoctorId" />
        <x-ui.field name="objective" label="Nota" as="textarea" rows="4" required placeholder="Describe el procedimiento y los hallazgos" />
    </div>
    <x-slot name="footer">
        <x-ui.button variant="ghost" :href="route('expediente.notas.create', $patient->id)">Usar formato SOAP</x-ui.button>
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button variant="secondary" type="submit" name="action" value="guardar">Guardar borrador</x-ui.button>
        @if ($canSign)
            <x-ui.button type="submit" name="action" value="firmar">Firmar nota</x-ui.button>
        @endif
    </x-slot>
</x-ui.modal>
