<x-guest-layout title="Iniciar sesión">
    <h1>Bienvenido de nuevo</h1>
    <p class="text-muted mb-4">Ingresa con tu correo para acceder a la clínica.</p>

    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="stack">
        @csrf

        <x-ui.field name="email" type="email" label="Correo electrónico" placeholder="correo@clinica.com" icon="mail" required autofocus autocomplete="username" />

        <x-ui.field name="password" type="password" label="Contraseña" icon="lock" placeholder="••••••••" required autocomplete="current-password" />

        <div class="row row--between">
            <x-ui.checkbox name="remember" label="Recordarme" />
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-sm">¿Olvidaste tu contraseña?</a>
            @endif
        </div>

        <x-ui.button type="submit" size="lg" block>Iniciar sesión</x-ui.button>
    </form>
</x-guest-layout>
