{{--
  Fila de concepto del formulario de presupuesto.
  Variables: $i (índice o "__INDEX__" para la plantilla JS), $row (array), $treatmentOptions (id => nombre).
--}}
<tr class="quote-line" data-line>
    <td class="quote-line__treatment">
        <x-ui.select :name="'items['.$i.'][treatment_id]'" :options="$treatmentOptions" :selected="$row['treatment_id'] ?? ''"
                     placeholder="Selecciona un tratamiento" class="js-treatment" aria-label="Tratamiento" />
    </td>
    <td class="quote-line__tooth">
        <x-ui.input :name="'items['.$i.'][tooth_fdi]'" :value="$row['tooth_fdi'] ?? ''" inputmode="numeric" maxlength="2"
                    placeholder="—" mask="digits" aria-label="Pieza dental (FDI)" />
    </td>
    <td class="quote-line__qty">
        <x-ui.input type="number" :name="'items['.$i.'][quantity]'" :value="$row['quantity'] ?? '1'" min="0.0001" max="100000" step="any"
                    class="js-qty" aria-label="Cantidad" />
    </td>
    <td class="quote-line__price">
        <x-ui.input type="number" :name="'items['.$i.'][unit_price]'" :value="$row['unit_price'] ?? ''" min="0" max="99999999" step="any"
                    class="js-price" aria-label="Precio unitario" />
    </td>
    <td class="quote-line__discount">
        <x-ui.input type="number" :name="'items['.$i.'][discount_pct]'" :value="$row['discount_pct'] ?? '0'" min="0" max="100" step="any"
                    class="js-discount" aria-label="Descuento %" />
    </td>
    <td class="quote-line__amount fw-500 mono nowrap"><span class="js-line-amount">$0.00</span></td>
    <td class="actions">
        <button type="button" class="icon-btn icon-btn--danger" data-remove-line aria-label="Quitar concepto" title="Quitar concepto">
            <x-ui.icon name="trash" size="sm" />
        </button>
    </td>
</tr>
