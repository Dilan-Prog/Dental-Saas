{{--
  <x-ui.alert variant="info|success|warning|danger" title="Atención">Mensaje</x-ui.alert>
  Aviso en línea (no desaparece). Para avisos temporales usa UI.toast() o session('success').
--}}
@props(['variant' => 'info', 'title' => null])
@php $icons = ['info' => 'info', 'success' => 'check-circle', 'warning' => 'alert-triangle', 'danger' => 'x-circle']; @endphp
<div {{ $attributes->merge(['class' => 'alert'.($variant !== 'info' ? ' alert--'.$variant : ''), 'role' => 'alert']) }}>
    <x-ui.icon :name="$icons[$variant] ?? 'info'" />
    <div>@if($title)<div class="fw-700">{{ $title }}</div>@endif{{ $slot }}</div>
</div>
