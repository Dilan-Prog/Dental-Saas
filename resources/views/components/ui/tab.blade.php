{{-- Panel de una pestaña. Ver <x-ui.tabs>. Props: name (debe coincidir con la clave del ítem). --}}
@aware(['active' => null])
@props(['name'])
<div data-tab-panel="{{ $name }}" class="tab-panel" @if($active !== null && (string) $active !== (string) $name) hidden @endif role="tabpanel">{{ $slot }}</div>
