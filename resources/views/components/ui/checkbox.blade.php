{{--
  <x-ui.checkbox name="is_default" label="Usar como predeterminado" :checked="$m->is_default" value="1" unchecked="0" />
  - unchecked: si se indica, envía ese valor (campo hidden) cuando la casilla está vacía.
  - Respeta old() tras un error de validación.
--}}
@props(['name', 'label' => null, 'value' => 1, 'checked' => false, 'unchecked' => null, 'id' => null])
@php
    $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $isChecked = session()->hasOldInput() ? (bool) old($key) : (bool) $checked;
@endphp
<label class="check">
    @if ($unchecked !== null)<input type="hidden" name="{{ $name }}" value="{{ $unchecked }}">@endif
    <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @if($id) id="{{ $id }}" @endif @checked($isChecked) {{ $attributes }}>
    @if ($label !== null)<span>{{ $label }}</span>@endif{{ $slot }}
</label>
