{{-- Botón de peligro (compatibilidad Breeze; type=submit). Preferible <x-ui.button variant="danger">. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn btn--danger']) }}>{{ $slot }}</button>
