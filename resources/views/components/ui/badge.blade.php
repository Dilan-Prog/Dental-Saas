{{--
  <x-ui.badge variant="success" dot>Confirmada</x-ui.badge>
  variant: primary | success | warning | danger | info | neutral (por defecto neutral). dot = punto de color.
  Sugerencia de mapeo de estados: pendiente=warning, confirmada=info, en_curso=primary, completada=success, cancelada/no_asistio=danger.
--}}
@props(['variant' => 'neutral', 'dot' => false])
<span {{ $attributes->merge(['class' => 'badge badge--'.$variant]) }}>@if($dot)<span class="badge__dot"></span>@endif{{ $slot }}</span>
