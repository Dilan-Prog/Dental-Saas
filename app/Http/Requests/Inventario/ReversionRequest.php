<?php

namespace App\Http\Requests\Inventario;

/** Reversion de un movimiento (una sola vez, con motivo). Bolsa de errores: reversion. */
class ReversionRequest extends InventarioRequest
{
    protected $errorBag = 'reversion';

    protected array $trimFields = ['reason'];

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Indique el motivo de la reversión.'] + parent::messages();
    }
}
