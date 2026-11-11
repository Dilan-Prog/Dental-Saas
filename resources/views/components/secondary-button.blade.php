{{-- Botón secundario (compatibilidad Breeze; type=button). Preferible <x-ui.button variant="secondary">. --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn btn--secondary']) }}>{{ $slot }}</button>
