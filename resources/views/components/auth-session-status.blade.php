{{-- Mensaje de estado en pantallas de autenticación (p. ej. "Te enviamos el enlace"). <x-auth-session-status :status="session('status')" /> --}}
@props(['status'])
@if ($status)
    <x-ui.alert variant="success" {{ $attributes->merge(['class' => 'mb-4']) }}>{{ $status }}</x-ui.alert>
@endif
