<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['q' => trim((string) $this->input('q', ''))]);
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'format' => ['nullable', 'in:json,html'],
        ];
    }

    public function messages(): array
    {
        return [
            'q.max' => 'La búsqueda no puede superar 100 caracteres.',
            'format.in' => 'Formato de respuesta no válido.',
        ];
    }

    public function wantsJsonResult(): bool
    {
        return $this->input('format') === 'json' || $this->expectsJson();
    }
}
