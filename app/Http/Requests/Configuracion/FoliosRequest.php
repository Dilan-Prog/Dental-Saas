<?php

namespace App\Http\Requests\Configuracion;

use App\Models\FolioSequence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edición controlada de folios: el prefijo es editable y el "próximo folio"
 * solo puede AUMENTAR (nunca retroceder), para no duplicar folios emitidos.
 */
class FoliosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $folios = collect((array) $this->input('folios', []))->map(function ($row) {
            $row = (array) $row;
            $row['prefix'] = strtoupper(trim((string) ($row['prefix'] ?? '')));

            return $row;
        })->all();

        $this->merge(['folios' => $folios]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $ids = FolioSequence::query()->pluck('id')->all();

        return [
            'folios' => ['required', 'array', 'min:1'],
            'folios.*.prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/'],
            'folios.*.next' => ['required', 'integer', 'min:1', 'max:99999999'],
            'folios.*.id' => ['required', Rule::in($ids)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $sequences = FolioSequence::query()->get()->keyBy('id');

                foreach ((array) $this->input('folios', []) as $key => $row) {
                    $seq = $sequences->get($row['id'] ?? null);

                    if ($seq && isset($row['next']) && (int) $row['next'] - 1 < $seq->last_value) {
                        $validator->errors()->add("folios.$key.next", 'El próximo folio no puede ser menor a '.($seq->last_value + 1).' (ya se emitieron folios hasta '.$seq->last_value.').');
                    }
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'folios.*.prefix.required' => 'El prefijo del folio es obligatorio.',
            'folios.*.prefix.regex' => 'El prefijo solo admite letras mayúsculas, números y guion.',
            'folios.*.next.required' => 'Indica el próximo folio.',
            'folios.*.next.integer' => 'El próximo folio debe ser un número entero.',
            'folios.*.next.min' => 'El próximo folio debe ser mayor a cero.',
        ];
    }
}
