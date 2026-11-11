<x-guest-layout title="Recuperar contraseña">
    <h1>Recuperar contraseña</h1>
    <p class="text-muted mb-4">Escribe tu correo y te enviaremos un enlace para crear una nueva contraseña.</p>

    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="stack">
        @csrf

        <x-ui.field name="email" type="email" label="Correo electrónico" icon="mail" placeholder="correo@clinica.com" required autofocus />

        <x-ui.button type="submit" size="lg" block>Enviar enlace de recuperación</x-ui.button>
        <a href="{{ route('login') }}" class="text-center text-sm">Volver a iniciar sesión</a>
    </form>
</x-guest-layout>
