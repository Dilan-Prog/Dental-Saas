{{-- Enlace de acceso recién generado: se muestra UNA sola vez (flash). El token en claro no se guarda. --}}
@if (session('access_link'))
    @php $al = session('access_link'); @endphp
    <x-ui.alert variant="success" title="Enlace de acceso para {{ $al['name'] }}" class="mb-4" data-access-link>
        <p class="mb-2">Compártelo de forma privada con {{ $al['email'] }}. Vence en {{ $al['days'] }} días, funciona una sola vez y
            <strong>no volverá a mostrarse</strong>: si se pierde, genera otro.</p>
        <div class="usr-linkbox">
            <input type="text" class="input" value="{{ $al['url'] }}" readonly data-access-url aria-label="Enlace de acceso">
            <x-ui.button variant="secondary" size="sm" icon="copy" data-copy-access>Copiar</x-ui.button>
        </div>
    </x-ui.alert>
@endif
