{{-- Mensajes de error bajo un campo (compatibilidad Breeze). <x-input-error :messages="$errors->get('email')" /> --}}
@props(['messages'])
@if ($messages)
    <div {{ $attributes->merge(['class' => 'field__error', 'role' => 'alert']) }} style="flex-direction:column;align-items:flex-start;gap:2px">
        @foreach ((array) $messages as $message)<span>{{ $message }}</span>@endforeach
    </div>
@endif
