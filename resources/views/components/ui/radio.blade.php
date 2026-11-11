{{--
  <x-ui.radio name="method" value="efectivo" label="Efectivo" :checked="$m === 'efectivo'" />
  Respeta old() tras un error de validación.
--}}
@props(['name', 'value', 'label' => null, 'checked' => false])
@php
    $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $isChecked = session()->hasOldInput() ? ((string) old($key) === (string) $value) : (bool) $checked;
@endphp
<label class="radio">
    <input type="radio" name="{{ $name }}" value="{{ $value }}" @checked($isChecked) {{ $attributes }}>
    @if ($label !== null)<span>{{ $label }}</span>@endif{{ $slot }}
</label>
