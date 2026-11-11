<?php

namespace App\Http\Requests\Expediente;

/** Adenda (corrección versionada) a una nota firmada. */
class StoreAddendumRequest extends ExpedienteRequest
{
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:5', 'max:5000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Escribe el contenido de la adenda.',
            'reason.required' => 'Indica el motivo de la corrección.',
        ];
    }
}
