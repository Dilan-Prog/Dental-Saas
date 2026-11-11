<?php

namespace App\Http\Requests\Finanzas;

use App\Enums\MovementDirection;
use App\Enums\PaymentMethod;
use App\Services\Finanzas\FinanceReportService;
use Illuminate\Validation\Rule;

/** Filtros (GET) del resumen y de la exportacion CSV. */
class FinanzasFilterRequest extends FinanzasRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStrings('q');
        // Los filtros vacios llegan como cadena vacia desde el formulario.
        $this->merge(array_filter(
            $this->only(['preset', 'from', 'to', 'category_id', 'method', 'direction', 'branch_id', 'q']),
            fn ($v) => $v !== null && $v !== ''
        ) + array_fill_keys(['category_id', 'method', 'direction', 'branch_id', 'q', 'from', 'to', 'preset'], null));
    }

    public function rules(): array
    {
        $org = $this->organizationId();

        return [
            'preset' => ['nullable', Rule::in(array_keys(FinanceReportService::PRESETS))],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
            'category_id' => ['nullable', 'uuid', Rule::exists('financial_categories', 'id')->where('organization_id', $org)],
            'method' => ['nullable', Rule::in(PaymentMethod::values())],
            'direction' => ['nullable', Rule::in(MovementDirection::values())],
            'branch_id' => ['nullable', 'uuid', Rule::exists('branches', 'id')->where('organization_id', $org)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'preset.in' => 'El periodo seleccionado no es valido.',
            'from.date_format' => 'La fecha inicial no es valida (use AAAA-MM-DD).',
            'from.after_or_equal' => 'La fecha inicial no puede ser anterior al 2000.',
            'to.date_format' => 'La fecha final no es valida (use AAAA-MM-DD).',
            'to.after_or_equal' => 'La fecha final no puede ser anterior a la fecha inicial.',
            'category_id.exists' => 'La categoria seleccionada no existe.',
            'method.in' => 'El metodo de pago seleccionado no es valido.',
            'direction.in' => 'El tipo de movimiento no es valido.',
            'branch_id.exists' => 'La sucursal seleccionada no existe.',
            'q.max' => 'La busqueda no puede superar 100 caracteres.',
        ];
    }

    /** Filtros normalizados (Carbon) listos para el servicio de reportes. */
    public function reportFilters(): array
    {
        return app(FinanceReportService::class)->filters($this->validated());
    }
}
