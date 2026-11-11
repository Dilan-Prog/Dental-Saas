{{--
  <x-ui.textarea name="notes" rows="4" :value="$x->notes" />
  Rellena con old($name, $value) y marca .has-error.
--}}
@props(['bag' => null, 'name' => null, 'id' => null, 'rows' => 3, 'value' => null])
@php
    $errs = $bag ? $errors->getBag($bag) : $errors;
    $key = $name ? trim(str_replace(['[', ']'], ['.', ''], $name), '.') : null;
    $hasError = $key && $errs->has($key);
    $val = $name ? old($key, $value) : $value;
@endphp
<textarea @if($name) name="{{ $name }}" id="{{ $id ?? $key }}" @elseif($id) id="{{ $id }}" @endif rows="{{ $rows }}"
          @if($hasError) aria-invalid="true" @endif
          {{ $attributes->merge(['class' => 'textarea'.($hasError ? ' has-error' : '')]) }}>{{ $val }}</textarea>
