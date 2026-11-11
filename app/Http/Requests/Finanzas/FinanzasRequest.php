<?php

namespace App\Http\Requests\Finanzas;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

/** Base de los FormRequest de Finanzas: autorizacion por middleware `permission:` y utilidades. */
abstract class FinanzasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Quita $, comas y espacios de campos de importe ("$1,250.50" => "1250.50"). */
    protected function normalizeMoney(string ...$keys): void
    {
        $merge = [];
        foreach ($keys as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $clean = preg_replace('/[\s$,]/', '', $value);
                $merge[$key] = $clean;
            }
        }
        if ($merge) {
            $this->merge($merge);
        }
    }

    /** Recorta espacios de los campos de texto indicados y convierte vacios en null. */
    protected function trimStrings(string ...$keys): void
    {
        $merge = [];
        foreach ($keys as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $value = trim(preg_replace('/\s+/u', ' ', $value));
                $merge[$key] = $value === '' ? null : $value;
            }
        }
        if ($merge) {
            $this->merge($merge);
        }
    }

    protected function organizationId(): ?string
    {
        return Organization::currentId();
    }

    /** Mensajes comunes (los de cada request se suman). */
    protected function moneyMessages(string $field, string $label): array
    {
        return [
            "{$field}.required" => "El campo {$label} es obligatorio.",
            "{$field}.numeric" => "El campo {$label} debe ser un importe numerico (ej. 1250.50).",
            "{$field}.decimal" => "El campo {$label} admite como maximo 2 decimales.",
            "{$field}.max" => "El campo {$label} no puede superar $99,999,999,999.99.",
            "{$field}.gt" => "El campo {$label} debe ser mayor a cero.",
            "{$field}.min" => "El campo {$label} no puede ser negativo.",
        ];
    }
}
