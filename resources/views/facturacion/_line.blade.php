{{-- Una partida (concepto) del formulario de factura.
     Variables: $i (indice o '__i__' en la plantilla), $line (array), $unidades, $objetos, $taxProfiles, $template (bool). --}}
@php
    $tpl = $template ?? false;
    $e = fn (string $f) => $tpl ? null : $errors->first("items.$i.$f");
    $cls = fn (string $f) => $e($f) ? ' has-error' : '';
    $v = fn (string $f, $d = '') => $tpl ? ($line[$f] ?? $d) : ($line[$f] ?? $d);
@endphp
<div class="fact-line" data-line>
    <button type="button" class="icon-btn icon-btn--flat fact-line__remove" data-remove-line aria-label="Quitar concepto" title="Quitar concepto"><x-ui.icon name="x" size="sm" /></button>
    <input type="hidden" data-f="treatment_id" name="items[{{ $i }}][treatment_id]" value="{{ $v('treatment_id', '') }}">
    <input type="hidden" data-f="quote_item_id" name="items[{{ $i }}][quote_item_id]" value="{{ $v('quote_item_id', '') }}">

    <div class="field fact-line__desc">
        <label class="field__label">Concepto <span data-line-n>{{ is_numeric($i) ? $i + 1 : '' }}</span> <span class="req">*</span></label>
        <input class="input{{ $cls('description') }}" data-f="description" name="items[{{ $i }}][description]" maxlength="300" required value="{{ $v('description') }}" placeholder="Servicio dental">
        @if ($e('description'))<div class="field__error">{{ $e('description') }}</div>@endif
    </div>
    <div class="field fact-line__key">
        <label class="field__label">Clave SAT <span class="req">*</span></label>
        <input class="input mono{{ $cls('sat_product_key') }}" data-f="sat_product_key" name="items[{{ $i }}][sat_product_key]" data-mask="digits" inputmode="numeric" maxlength="8" required value="{{ $v('sat_product_key') }}" placeholder="85121800">
        @if ($e('sat_product_key'))<div class="field__error">{{ $e('sat_product_key') }}</div>@endif
    </div>
    <div class="field fact-line__unit">
        <label class="field__label">Unidad <span class="req">*</span></label>
        <select class="select{{ $cls('sat_unit_key') }}" data-f="sat_unit_key" name="items[{{ $i }}][sat_unit_key]" required>
            @foreach ($unidades as $k => $label)<option value="{{ $k }}" @selected((string) $v('sat_unit_key', 'E48') === (string) $k)>{{ $label }}</option>@endforeach
        </select>
        @if ($e('sat_unit_key'))<div class="field__error">{{ $e('sat_unit_key') }}</div>@endif
    </div>
    <div class="field fact-line__qty">
        <label class="field__label">Cantidad <span class="req">*</span></label>
        <input class="input{{ $cls('quantity') }}" data-f="quantity" name="items[{{ $i }}][quantity]" inputmode="decimal" required value="{{ $v('quantity', '1') }}">
        @if ($e('quantity'))<div class="field__error">{{ $e('quantity') }}</div>@endif
    </div>
    <div class="field fact-line__price">
        <label class="field__label">Precio unit. <span class="req">*</span></label>
        <input class="input{{ $cls('unit_price') }}" data-f="unit_price" name="items[{{ $i }}][unit_price]" inputmode="decimal" required value="{{ $v('unit_price') }}" placeholder="0.00">
        @if ($e('unit_price'))<div class="field__error">{{ $e('unit_price') }}</div>@endif
    </div>
    <div class="field fact-line__disc">
        <label class="field__label">Desc. %</label>
        <input class="input{{ $cls('discount_pct') }}" data-f="discount_pct" name="items[{{ $i }}][discount_pct]" inputmode="decimal" value="{{ $v('discount_pct', '0') }}">
        @if ($e('discount_pct'))<div class="field__error">{{ $e('discount_pct') }}</div>@endif
    </div>
    <div class="field fact-line__obj">
        <label class="field__label">Objeto de impuesto</label>
        <select class="select{{ $cls('tax_object_code') }}" data-f="tax_object_code" name="items[{{ $i }}][tax_object_code]">
            @foreach ($objetos as $k => $label)<option value="{{ $k }}" @selected((string) $v('tax_object_code', '02') === (string) $k)>{{ $label }}</option>@endforeach
        </select>
        @if ($e('tax_object_code'))<div class="field__error">{{ $e('tax_object_code') }}</div>@endif
    </div>
    <div class="field fact-line__tax">
        <label class="field__label">Impuesto</label>
        <select class="select{{ $cls('tax_profile_id') }}" data-f="tax_profile_id" name="items[{{ $i }}][tax_profile_id]">
            <option value="">—</option>
            @foreach ($taxProfiles as $t)
                <option value="{{ $t->id }}" @selected((string) $v('tax_profile_id', '') === (string) $t->id)>{{ $t->name }}</option>
            @endforeach
        </select>
        @if ($e('tax_profile_id'))<div class="field__error">{{ $e('tax_profile_id') }}</div>@endif
    </div>
    <div class="fact-line__total"><span>Importe del concepto (con impuestos)</span><strong data-line-total>$0.00</strong></div>
</div>
