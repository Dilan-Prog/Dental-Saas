{{-- Etiqueta de campo (compatibilidad Breeze). <x-input-label for="email" value="Correo" required /> --}}
@props(['value' => null, 'required' => false])
<label {{ $attributes->merge(['class' => 'field__label']) }}>{{ $value ?? $slot }}@if($required)<span class="req" aria-hidden="true">*</span>@endif</label>
