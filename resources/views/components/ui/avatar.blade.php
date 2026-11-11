{{--
  <x-ui.avatar name="Renata Aguilar Vega" size="sm|lg" soft />
  Círculo con las iniciales (2 primeras palabras). soft = versión clara.
--}}
@props(['name' => '', 'size' => null, 'soft' => false])
@php $ini = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''); @endphp
<span {{ $attributes->merge(['class' => 'avatar'.($size ? ' avatar--'.$size : '').($soft ? ' avatar--soft' : ''), 'title' => $name]) }}>{{ $ini ?: '?' }}</span>
