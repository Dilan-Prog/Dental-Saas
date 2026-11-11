<?php

namespace App\Http\Requests\Finanzas;

use App\Enums\MovementDirection;
use App\Models\FinancialCategory;
use App\Services\Finanzas\MovementService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Crear / editar categoria financiera (nombre unico por tipo de movimiento). */
class SaveCategoryRequest extends FinanzasRequest
{
    protected $errorBag = 'category';

    protected function prepareForValidation(): void
    {
        $this->trimStrings('name');
    }

    public function rules(): array
    {
        /** @var FinancialCategory|null $category */
        $category = $this->route('category');
        $direction = $category ? $category->direction->value : $this->input('direction');

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:100',
                Rule::unique('financial_categories', 'name')
                    ->where('organization_id', $this->organizationId())
                    ->where('direction', in_array($direction, MovementDirection::values(), true) ? $direction : '__none__')
                    ->ignore($category?->id),
            ],
            // El tipo solo se elige al crear: cambiarlo falsearia los movimientos ya registrados.
            'direction' => [$category ? 'prohibited' : 'required', Rule::in(MovementDirection::values())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $name = (string) $this->input('name');
            /** @var FinancialCategory|null $category */
            $category = $this->route('category');

            if ($name !== '' && MovementService::isSystemCategoryName($name) && ! ($category && MovementService::isSystemCategoryName($category->name))) {
                $v->errors()->add('name', 'Ese nombre esta reservado para las reversiones del sistema.');
            }
            if ($category && MovementService::isSystemCategoryName($category->name)) {
                $v->errors()->add('name', 'Las categorias de reversion son del sistema y no se pueden editar.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la categoria es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar 100 caracteres.',
            'name.unique' => 'Ya existe una categoria con ese nombre para este tipo de movimiento.',
            'direction.required' => 'Selecciona si la categoria es de ingresos o de egresos.',
            'direction.in' => 'El tipo de movimiento no es valido.',
            'direction.prohibited' => 'El tipo de una categoria existente no se puede cambiar.',
        ];
    }
}
