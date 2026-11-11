{{--
  Pestañas. Dos modos:

  1) Paneles en la misma página (JS):
      <x-ui.tabs :items="['info' => 'Información', 'notas' => 'Notas']" active="info" hash>
          <x-ui.tab name="info"> ... </x-ui.tab>
          <x-ui.tab name="notas"> ... </x-ui.tab>
      </x-ui.tabs>
      `hash`: guarda la pestaña activa en #hash de la URL.

  2) Navegación por enlaces (una ruta por pestaña, p. ej. Expediente):
      <x-ui.tabs :items="['historial' => ['label' => 'Historial', 'href' => route(...)], 'notas' => [...]]" active="historial" />
      Cada ítem acepta: label, href, icon, count.
  Props: items (clave => etiqueta | array), active (clave activa), hash.
--}}
@props(['items' => [], 'active' => null, 'hash' => false])
@php $current = $active ?? array_key_first($items); @endphp
<div data-tabs @if($hash) data-tabs-hash @endif>
    <div class="tabs" role="tablist">
        @foreach ($items as $key => $item)
            @php
                $label = is_array($item) ? ($item['label'] ?? $key) : $item;
                $href = is_array($item) ? ($item['href'] ?? null) : null;
                $icon = is_array($item) ? ($item['icon'] ?? null) : null;
                $count = is_array($item) ? ($item['count'] ?? null) : null;
                $on = (string) $key === (string) $current;
            @endphp
            @if ($href)
                <a href="{{ $href }}" data-tab="{{ $key }}" class="tab {{ $on ? 'is-active' : '' }}" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}">
                    @if($icon)<x-ui.icon :name="$icon" size="sm" />@endif{{ $label }}@if($count !== null) <x-ui.badge>{{ $count }}</x-ui.badge>@endif
                </a>
            @else
                <button type="button" data-tab="{{ $key }}" class="tab {{ $on ? 'is-active' : '' }}" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}">
                    @if($icon)<x-ui.icon :name="$icon" size="sm" />@endif{{ $label }}@if($count !== null) <x-ui.badge>{{ $count }}</x-ui.badge>@endif
                </button>
            @endif
        @endforeach
    </div>
    {{ $slot }}
</div>
