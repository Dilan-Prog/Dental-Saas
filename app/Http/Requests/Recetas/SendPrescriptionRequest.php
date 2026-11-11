<?php

namespace App\Http\Requests\Recetas;

use App\Services\Recetas\PrescriptionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Envío / impresión de una receta emitida. */
class SendPrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in(PrescriptionService::CHANNELS)],
        ];
    }

    public function messages(): array
    {
        return [
            'channel.required' => 'Seleccione el canal de envío.',
            'channel.in' => 'El canal de envío no es válido (impresión, correo o WhatsApp).',
        ];
    }
}
