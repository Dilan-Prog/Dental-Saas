{{--
  Campo completo: etiqueta + control + ayuda + error de validación.
  Forma corta (renderiza el control por ti):
      <x-ui.field name="full_name" label="Nombre completo" required placeholder="..." />
      <x-ui.field name="phone" label="Teléfono" mask="phone" />
      <x-ui.field name="sex" label="Sexo" as="select" :options="[...]" placeholder="Selecciona" />
      <x-ui.field name="notes" label="Notas" as="textarea" rows="4" />
  Forma libre (slot con tu propio control):
      <x-ui.field label="Fecha" name="date"> <x-ui.input name="date" type="date" /> </x-ui.field>
  Props: label, name, hint, required, type, as (input|select|textarea), options, value, selected, mask, icon, placeholder.
  `class` se aplica al contenedor (.field), p. ej. class="col-span-2". Resto de atributos van al control.
--}}
@props(['bag' => null, 'label' => null, 'name' => null, 'hint' => null, 'required' => false, 'type' => 'text', 'as' => 'input',
        'options' => [], 'value' => null, 'selected' => null, 'mask' => null, 'icon' => null, 'placeholder' => null, 'id' => null, 'rows' => 3])
@php
    $errs = $bag ? $errors->getBag($bag) : $errors;
    $key = $name ? trim(str_replace(['[', ']'], ['.', ''], $name), '.') : null;
    $fid = $id ?? $key;
    $err = $key ? $errs->first($key) : null;
    $control = $attributes->except('class');
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'field']) }}>
    @if ($label)
        <label class="field__label" @if($fid) for="{{ $fid }}" @endif>{{ $label }}@if($required)<span class="req" aria-hidden="true">*</span>@endif</label>
    @endif

    @if ($slot->isEmpty())
        @if ($as === 'select')
            <x-ui.select :name="$name" :id="$fid" :options="$options" :selected="$selected ?? $value" :placeholder="$placeholder" :required="$required" :bag="$bag" :attributes="$control" />
        @elseif ($as === 'textarea')
            <x-ui.textarea :name="$name" :id="$fid" :rows="$rows" :value="$value" :placeholder="$placeholder" :required="$required" :bag="$bag" :attributes="$control" />
        @else
            <x-ui.input :name="$name" :id="$fid" :type="$type" :value="$value" :mask="$mask" :icon="$icon" :placeholder="$placeholder" :required="$required" :bag="$bag" :attributes="$control" />
        @endif
    @else
        {{ $slot }}
    @endif

    @if ($err)
        <div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $err }}</div>
    @elseif ($hint)
        <div class="field__hint">{{ $hint }}</div>
    @endif
</div>
