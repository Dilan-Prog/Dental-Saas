<form method="POST" action="{{ route('profile.update') }}" class="stack">
    @csrf
    @method('PATCH')

    <x-ui.field name="full_name" label="Nombre completo" :value="$user->full_name" required autocomplete="name" />
    <x-ui.field name="email" type="email" label="Correo electrónico" :value="$user->email" required autocomplete="username" />

    <div class="form-actions">
        <x-ui.button type="submit">Guardar cambios</x-ui.button>
    </div>
</form>
