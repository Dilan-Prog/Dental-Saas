<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:200'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:254', Rule::unique(User::class, 'email')->ignore($this->user()->id)],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'El nombre es obligatorio.',
            'full_name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'Escribe un correo valido.',
            'email.unique' => 'Ese correo ya esta en uso.',
        ];
    }
}
