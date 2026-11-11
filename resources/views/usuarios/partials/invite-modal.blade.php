{{-- Modal "Invitar usuario" (Figma 646:307). Errores en el bag "invite"; se reabre solo si hay errores. --}}
<x-ui.modal id="invite-modal" title="Invitar usuario"
            subtitle="Generaremos un enlace de acceso de un solo uso para que defina su contraseña."
            :action="route('usuarios.store')" method="POST" open-on-error>

    <div class="stack">
        <x-ui.field name="full_name" label="Nombre completo" placeholder="Ej. Dra. Ana López" required bag="invite" maxlength="200" autocomplete="off" />
        <x-ui.field name="email" type="email" label="Correo electrónico" placeholder="nombre@clinica.mx" required bag="invite" maxlength="254" autocomplete="off" />
        <x-ui.field name="role_id" label="Rol" as="select" :options="$activeRoles->pluck('name', 'id')->all()" placeholder="Selecciona un rol" required bag="invite" />

        <div class="field">
            <label class="field__label">Sucursales<span class="req" aria-hidden="true">*</span></label>
            <div class="usr-checklist">
                @foreach ($branches as $b)
                    <label class="check">
                        <input type="checkbox" name="branch_ids[]" value="{{ $b->id }}"
                               @checked(in_array($b->id, (array) old('branch_ids', $branches->count() === 1 ? [$b->id] : [])))>
                        <span>{{ $b->name }}</span>
                    </label>
                @endforeach
            </div>
            @if ($errors->getBag('invite')->has('branch_ids') || $errors->getBag('invite')->has('branch_ids.*'))
                <div class="field__error has-error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $errors->getBag('invite')->first('branch_ids') ?: $errors->getBag('invite')->first('branch_ids.*') }}</div>
            @endif
        </div>

        <div class="usr-doctor" data-doctor-block>
            <x-ui.switch name="is_doctor" label="Es profesional de la salud (aparece como doctor en la agenda)" :checked="false" data-doctor-toggle />
            <div class="form-grid mt-4" data-doctor-fields @if (! old('is_doctor')) hidden @endif>
                <x-ui.field name="professional_license" label="Cédula profesional" placeholder="7 u 8 dígitos" mask="digits" bag="invite" maxlength="8" inputmode="numeric" />
                <x-ui.field name="specialty" label="Especialidad" placeholder="Ej. Endodoncia" bag="invite" maxlength="120" />
            </div>
        </div>
    </div>

    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit">Invitar</x-ui.button>
    </x-slot>
</x-ui.modal>
