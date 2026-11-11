{{--
  <x-ui.input name="email" type="email" :value="$model->email" mask="phone|rfc|curp|postal|digits|upper|money"
              icon="search" placeholder="..." required />
  - Prop `bag`: nombre de bolsa de errores (p. ej. updatePassword). Rellena con old($name, $value) (excepto type=password) y marca .has-error si $errors tiene el campo.
  - Nombres tipo array "items[0][qty]" se normalizan a "items.0.qty" para buscar errores.
  - Para etiqueta + error usa <x-ui.field>.
--}}
@props(['bag' => null, 'name' => null, 'type' => 'text', 'id' => null, 'value' => null, 'mask' => null, 'icon' => null])
@php
    $errs = $bag ? $errors->getBag($bag) : $errors;
    $key = $name ? trim(str_replace(['[', ']'], ['.', ''], $name), '.') : null;
    $hasError = $key && $errs->has($key);
    $val = $type === 'password' ? null : ($name ? old($key, $value) : $value);
    $cls = 'input'.($hasError ? ' has-error' : '');
@endphp
@if ($icon)<div class="input-wrap"><x-ui.icon :name="$icon" size="sm" />@endif
<input type="{{ $type }}"
       @if($name) name="{{ $name }}" id="{{ $id ?? $key }}" @elseif($id) id="{{ $id }}" @endif
       @if($val !== null && $type !== 'file') value="{{ $val }}" @endif
       @if($mask) data-mask="{{ $mask }}" @endif
       @if($hasError) aria-invalid="true" @endif
       {{ $attributes->merge(['class' => $cls]) }}>
@if ($icon)</div>@endif
