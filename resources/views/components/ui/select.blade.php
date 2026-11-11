{{--
  <x-ui.select name="sex" :options="['female' => 'Femenino', 'male' => 'Masculino']" :selected="$p->sex" placeholder="Selecciona..." />
  - options: array valor => etiqueta (o pasa <option> propios en el slot).
  - Rellena con old($name, $selected) y marca .has-error.
--}}
@props(['bag' => null, 'name' => null, 'id' => null, 'options' => [], 'selected' => null, 'placeholder' => null])
@php
    $errs = $bag ? $errors->getBag($bag) : $errors;
    $key = $name ? trim(str_replace(['[', ']'], ['.', ''], $name), '.') : null;
    $hasError = $key && $errs->has($key);
    $current = $name ? old($key, $selected) : $selected;
@endphp
<select @if($name) name="{{ $name }}" id="{{ $id ?? $key }}" @elseif($id) id="{{ $id }}" @endif
        @if($hasError) aria-invalid="true" @endif
        {{ $attributes->merge(['class' => 'select'.($hasError ? ' has-error' : '')]) }}>
    @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
    @foreach ($options as $v => $label)
        <option value="{{ $v }}" @selected((string) $current === (string) $v)>{{ $label }}</option>
    @endforeach
    {{ $slot }}
</select>
