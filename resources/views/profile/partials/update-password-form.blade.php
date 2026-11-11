<form method="POST" action="{{ route('password.update') }}" class="stack">
    @csrf
    @method('PUT')

    <x-ui.field name="current_password" type="password" label="Contraseña actual" bag="updatePassword" required autocomplete="current-password" />
    <x-ui.field name="password" type="password" label="Nueva contraseña" bag="updatePassword" hint="Mínimo 8 caracteres." required autocomplete="new-password" />
    <x-ui.field name="password_confirmation" type="password" label="Confirmar nueva contraseña" bag="updatePassword" required autocomplete="new-password" />

    <div class="form-actions">
        <x-ui.button type="submit">Actualizar contraseña</x-ui.button>
    </div>
</form>
