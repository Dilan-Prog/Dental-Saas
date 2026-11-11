<x-guest-layout title="Nueva contraseña">
    <h1>Crea tu nueva contraseña</h1>
    <p class="text-muted mb-4">Usa al menos 8 caracteres.</p>

    <form method="POST" action="{{ route('password.store') }}" class="stack">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-ui.field name="email" type="email" label="Correo electrónico" icon="mail" :value="$request->email" required autofocus autocomplete="username" />
        <x-ui.field name="password" type="password" label="Nueva contraseña" icon="lock" required autocomplete="new-password" />
        <x-ui.field name="password_confirmation" type="password" label="Confirmar contraseña" icon="lock" required autocomplete="new-password" />

        <x-ui.button type="submit" size="lg" block>Restablecer contraseña</x-ui.button>
    </form>
</x-guest-layout>
