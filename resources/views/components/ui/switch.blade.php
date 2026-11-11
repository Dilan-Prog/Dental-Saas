{{--
  <x-ui.switch name="is_active" label="Activo" :checked="$u->is_active" unchecked="0" />
  Interruptor on/off. Mismas props que checkbox.
--}}
@props(['name', 'label' => null, 'value' => 1, 'checked' => false, 'unchecked' => null, 'id' => null])
@php
    $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $isChecked = session()->hasOldInput() ? (bool) old($key) : (bool) $checked;
@endphp
<label class="switch">
    @if ($unchecked !== null)<input type="hidden" name="{{ $name }}" value="{{ $unchecked }}">@endif
    <input type="checkbox" role="switch" name="{{ $name }}" value="{{ $value }}" @if($id) id="{{ $id }}" @endif @checked($isChecked) {{ $attributes }}>
    @if ($label !== null)<span>{{ $label }}</span>@endif{{ $slot }}
</label>
