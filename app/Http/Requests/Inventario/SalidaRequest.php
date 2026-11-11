<?php

namespace App\Http\Requests\Inventario;

/** Registrar salida. Motivo obligatorio. Bolsa de errores: salida. */
class SalidaRequest extends InventarioRequest
{
    protected $errorBag = 'salida';

    protected array $trimFields = ['reference', 'reason'];

    protected array $numericFields = ['quantity'];

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', $this->itemExists()],
            'branch_id' => ['required', 'uuid', $this->branchExists()],
            'quantity' => $this->positiveQty(),
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'reference' => ['nullable', 'string', 'max:120'],
            'lot_id' => ['nullable', 'uuid', $this->lotExists()],
            'encounter_id' => ['nullable', 'uuid', $this->encounterExists()],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + ['reason.required' => 'Indique el motivo de la salida.'];
    }
}
