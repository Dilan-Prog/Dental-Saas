<?php

namespace App\Services\Pacientes;

use App\Rules\Rfc;

/**
 * Coherencia entre el RFC y el régimen fiscal (catálogo c_RegimenFiscal del SAT).
 *
 *  - RFC de persona física (13 caracteres) => régimen de persona física.
 *  - RFC de persona moral (12 caracteres)  => régimen de persona moral.
 *  - RFC genérico (público en general / extranjero) => 616 Sin obligaciones fiscales.
 */
class FiscalCoherence
{
    public const FISICA = ['605', '606', '607', '608', '610', '611', '612', '614', '615', '616', '621', '625', '626'];

    public const MORAL = ['601', '603', '610', '620', '622', '623', '624', '626'];

    /** Devuelve el mensaje de error o null si es coherente. */
    public static function check(?string $rfc, ?string $regimen): ?string
    {
        if ($rfc === null || $rfc === '' || $regimen === null || $regimen === '') {
            return null;
        }

        $rfc = strtoupper(trim($rfc));

        if (in_array($rfc, Rfc::GENERICOS, true)) {
            return $regimen === '616'
                ? null
                : 'Con un RFC genérico el régimen fiscal debe ser 616 (Sin obligaciones fiscales).';
        }

        $esMoral = mb_strlen($rfc) === 12;

        if ($esMoral && ! in_array($regimen, self::MORAL, true)) {
            return 'El régimen fiscal seleccionado no corresponde a una persona moral (RFC de 12 caracteres).';
        }

        if (! $esMoral && ! in_array($regimen, self::FISICA, true)) {
            return 'El régimen fiscal seleccionado no corresponde a una persona física (RFC de 13 caracteres).';
        }

        return null;
    }
}
