{{--
  <x-ui.button variant="primary|secondary|ghost|danger|danger-outline" size="sm|lg" type="button|submit|reset"
               href="url" icon="plus" block loading>Texto</x-ui.button>
  - Con `href` renderiza <a>; sin él, <button> (type por defecto = "button": usa type="submit" en formularios).
  - `icon`: nombre de <x-ui.icon>. `iconOnly`: botón cuadrado solo con icono (pon aria-label).
  - Cualquier atributo extra (data-modal-open, data-confirm, wire, id...) se pasa al elemento.
--}}
@props(['variant' => 'primary', 'size' => null, 'type' => 'button', 'href' => null, 'icon' => null, 'block' => false, 'loading' => false, 'iconOnly' => false])
@php
    $cls = 'btn btn--'.$variant.($size ? ' btn--'.$size : '').($block ? ' btn--block' : '').($loading ? ' is-loading' : '').($iconOnly ? ' btn--icon' : '');
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $cls]) }}>
        @if ($icon)<x-ui.icon :name="$icon" size="sm" />@endif{{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $cls]) }}>
        @if ($icon)<x-ui.icon :name="$icon" size="sm" />@endif{{ $slot }}
    </button>
@endif
