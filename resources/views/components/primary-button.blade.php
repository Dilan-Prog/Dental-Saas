{{-- Botón primario (compatibilidad Breeze; type=submit por defecto). Preferible <x-ui.button>. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn btn--primary']) }}>{{ $slot }}</button>
