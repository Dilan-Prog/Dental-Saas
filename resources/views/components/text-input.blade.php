{{-- Input de texto (compatibilidad Breeze). Marca .has-error si hay error para su `name`. --}}
@props(['disabled' => false])
@php $k = $attributes->get('name'); @endphp
<input @disabled($disabled) {{ $attributes->merge(['class' => 'input'.($k && $errors->has($k) ? ' has-error' : '')]) }}>
