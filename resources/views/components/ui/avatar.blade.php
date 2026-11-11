{{--
  <x-ui.avatar name="Renata Aguilar Vega" size="sm|lg" soft />
  Círculo con las iniciales (2 primeras palabras). soft = versión clara. tone="auto" = color estable por nombre (verde/naranja/azul/cian).
--}}
@props(['name' => '', 'size' => null, 'soft' => false, 'tone' => null])
@php $toneClass = $tone === 'auto' ? ' avatar--t'.(crc32(mb_strtolower(trim($name))) % 4) : ''; $ini = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''); @endphp
<span {{ $attributes->merge(['class' => 'avatar'.($size ? ' avatar--'.$size : '').($soft ? ' avatar--soft' : '').$toneClass, 'title' => $name]) }}>{{ $ini ?: '?' }}</span>
