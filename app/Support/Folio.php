<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Folios consecutivos atómicos (PAC-0001, RX-0001, PRE-0001, F-0001).
 *
 * Incrementa folio_sequences.last_value con UPDATE ... LAST_INSERT_ID() (misma
 * semántica que el procedimiento next_folio, pero sin depender de la colación
 * de los parámetros del procedimiento). Debe llamarse dentro de la misma
 * transacción que crea el documento para no dejar huecos si ésta se revierte.
 */
class Folio
{
    public const PATIENT = 'patient';
    public const PRESCRIPTION = 'prescription';
    public const QUOTE = 'quote';
    public const INVOICE = 'invoice';

    public static function next(string $documentType, string $series = '', ?string $organizationId = null): string
    {
        $organizationId ??= DB::table('organizations')->orderBy('created_at')->value('id');

        $affected = DB::update(
            'UPDATE folio_sequences SET `last_value` = LAST_INSERT_ID(`last_value` + 1)
              WHERE organization_id = ? AND document_type = ? AND series = ?',
            [$organizationId, $documentType, $series]
        );

        if ($affected === 0) {
            throw new RuntimeException("No hay secuencia de folios configurada para '{$documentType}'.");
        }

        $row = DB::selectOne(
            'SELECT prefix, LAST_INSERT_ID() AS value FROM folio_sequences
              WHERE organization_id = ? AND document_type = ? AND series = ?',
            [$organizationId, $documentType, $series]
        );

        return $row->prefix . str_pad((string) $row->value, 4, '0', STR_PAD_LEFT);
    }
}
