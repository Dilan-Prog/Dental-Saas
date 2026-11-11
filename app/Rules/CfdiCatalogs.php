<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida que el valor exista en un catálogo SAT de config/sat.php.
 * Uso: new CfdiCatalogs('regimenes') · 'usos_cfdi' · 'formas_pago' ·
 * 'metodos_pago' · 'motivos_cancelacion' · 'unidades' · 'objeto_impuesto' ·
 * 'tipos_relacion' · 'tipos_comprobante' · 'impuestos'.
 */
class CfdiCatalogs implements ValidationRule
{
    public function __construct(private string $catalog)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! array_key_exists((string) $value, self::options($this->catalog))) {
            $fail('El valor de :attribute no es válido en el catálogo del SAT.');
        }
    }

    /** @return array<string,string> clave => descripción */
    public static function options(string $catalog): array
    {
        return config("sat.{$catalog}", []);
    }

    public static function label(string $catalog, ?string $key): ?string
    {
        return $key === null ? null : (self::options($catalog)[$key] ?? null);
    }

    /** Lista "clave,clave,..." para usar con la regla in: */
    public static function keys(string $catalog): array
    {
        return array_map('strval', array_keys(self::options($catalog)));
    }
}
