<?php

namespace App\Services\Configuracion;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * Bitácora de cambios de configuración. Guarda solo los NOMBRES de los campos
 * modificados (nunca valores), para no exponer datos fiscales ni referencias
 * a secretos.
 */
class Auditor
{
    /**
     * @param  array<int, string>  $changedKeys
     */
    public static function log(string $entityType, string $entityId, string $action, array $changedKeys = []): void
    {
        AuditLog::query()->create([
            'actor_user_id' => Auth::id(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'changed_fields' => $changedKeys ? array_values($changedKeys) : null,
        ]);
    }
}
