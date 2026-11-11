<x-guest-layout title="Crear contraseña">
    @if (! $invitation || ! $user)
        <h1>Enlace no válido</h1>
        <p class="text-muted mb-4">Este enlace de acceso ya se usó, fue revocado o venció. Pide a un administrador de la clínica que genere uno nuevo.</p>
        <x-ui.button :href="route('login')" variant="secondary" block>Ir a iniciar sesión</x-ui.button>
    @else
        <h1>Hola, {{ $user->full_name }}</h1>
        <p class="text-muted mb-4">Define la contraseña con la que entrarás a la clínica. Tu correo de acceso es <strong>{{ $user->email }}</strong>.</p>

        <form method="POST" action="{{ route('invitations.complete', $token) }}" class="stack">
            @csrf

            <x-ui.field name="password" type="password" label="Contraseña nueva" icon="lock" required autocomplete="new-password"
                        hint="Mínimo 8 caracteres, con mayúsculas, minúsculas y un número." />
            <x-ui.field name="password_confirmation" type="password" label="Confirmar contraseña" icon="lock" required autocomplete="new-password" />

            <x-ui.button type="submit" size="lg" block>Guardar y entrar</x-ui.button>
        </form>
    @endif
</x-guest-layout>
