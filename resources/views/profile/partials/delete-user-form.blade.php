<x-ui.button variant="danger-outline" data-modal-open="confirm-user-deletion">Dar de baja mi cuenta</x-ui.button>

<x-ui.modal id="confirm-user-deletion" title="¿Dar de baja tu cuenta?"
            subtitle="Se cerrará tu sesión y tu usuario quedará inactivo. Confirma con tu contraseña."
            size="sm" open-on-error action="{{ route('profile.destroy') }}" method="DELETE">
    <x-ui.field name="password" type="password" label="Contraseña" bag="userDeletion" required autocomplete="current-password" />

    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit" variant="danger">Dar de baja</x-ui.button>
    </x-slot>
</x-ui.modal>
