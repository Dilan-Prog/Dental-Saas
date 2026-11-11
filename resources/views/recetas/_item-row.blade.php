{{--
  Renglón de medicamento del formulario de receta.
  Variables: $i (índice o "__I__" para la plantilla), $item (array con los datos), $errors.
--}}
@php
    $item = $item ?? [];
    $err = fn (string $k) => $i === '__I__' ? null : $errors->first("items.$i.$k");
    $cls = fn (string $k) => 'input'.($err($k) ? ' has-error' : '');
@endphp
<div class="rx-row" data-row>
    <input type="hidden" name="items[{{ $i }}][medication_id]" value="{{ $item['medication_id'] ?? '' }}" data-f="medication_id">
    <input type="hidden" name="items[{{ $i }}][strength]" value="{{ $item['strength'] ?? '' }}" data-f="strength">

    <div class="rx-row__grid">
        <div class="field rx-row__med">
            <label class="field__label">Medicamento <span class="req" aria-hidden="true">*</span></label>
            <div class="rx-suggest-wrap">
                <input type="text" class="{{ $cls('medication_name') }}" name="items[{{ $i }}][medication_name]" value="{{ $item['medication_name'] ?? '' }}"
                       placeholder="Buscar en vademécum…" maxlength="200" autocomplete="off" data-f="medication_name" data-med-name>
                <div class="rx-suggest" hidden></div>
            </div>
            @if ($err('medication_name'))<div class="field__error">{{ $err('medication_name') }}</div>@endif
        </div>
        <div class="field">
            <label class="field__label">Presentación</label>
            <input type="text" class="{{ $cls('presentation') }}" name="items[{{ $i }}][presentation]" value="{{ $item['presentation'] ?? '' }}"
                   placeholder="Tabletas" maxlength="120" data-f="presentation">
            @if ($err('presentation'))<div class="field__error">{{ $err('presentation') }}</div>@endif
        </div>
        <div class="field">
            <label class="field__label">Dosis <span class="req" aria-hidden="true">*</span></label>
            <input type="text" class="{{ $cls('dose') }}" name="items[{{ $i }}][dose]" value="{{ $item['dose'] ?? '' }}"
                   placeholder="1 tableta" maxlength="120" data-f="dose">
            @if ($err('dose'))<div class="field__error">{{ $err('dose') }}</div>@endif
        </div>
        <div class="field">
            <label class="field__label">Frecuencia <span class="req" aria-hidden="true">*</span></label>
            <input type="text" class="{{ $cls('frequency') }}" name="items[{{ $i }}][frequency]" value="{{ $item['frequency'] ?? '' }}"
                   placeholder="cada 8 horas" maxlength="120" data-f="frequency">
            @if ($err('frequency'))<div class="field__error">{{ $err('frequency') }}</div>@endif
        </div>
        <button type="button" class="icon-btn icon-btn--danger rx-row__remove" data-row-remove aria-label="Quitar medicamento" title="Quitar medicamento">
            <x-ui.icon name="trash" />
        </button>
    </div>

    <div class="rx-row__grid rx-row__grid--2">
        <div class="field">
            <label class="field__label">Duración</label>
            <input type="text" class="{{ $cls('duration') }}" name="items[{{ $i }}][duration]" value="{{ $item['duration'] ?? '' }}"
                   placeholder="7 días" maxlength="120" data-f="duration">
            @if ($err('duration'))<div class="field__error">{{ $err('duration') }}</div>@endif
        </div>
        <div class="field">
            <label class="field__label">Vía</label>
            <input type="text" class="{{ $cls('route') }}" name="items[{{ $i }}][route]" value="{{ $item['route'] ?? '' }}"
                   placeholder="Oral" maxlength="60" list="rx-routes" data-f="route">
            @if ($err('route'))<div class="field__error">{{ $err('route') }}</div>@endif
        </div>
        <div class="field rx-row__instr">
            <label class="field__label">Indicaciones al paciente</label>
            <input type="text" class="{{ $cls('instructions') }}" name="items[{{ $i }}][instructions]" value="{{ $item['instructions'] ?? '' }}"
                   placeholder="Tomar con alimentos…" maxlength="1000" data-f="instructions">
            @if ($err('instructions'))<div class="field__error">{{ $err('instructions') }}</div>@endif
        </div>
    </div>
</div>
