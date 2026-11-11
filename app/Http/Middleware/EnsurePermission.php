<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('permission:pacientes,ver')
 * Acepta varias acciones separadas por coma tras el módulo (basta una).
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $module, string ...$actions): Response
    {
        $user = $request->user();
        abort_unless($user, 403);

        $actions = $actions ?: ['ver'];

        foreach ($actions as $action) {
            if ($this->allows($user, $module, $action)) {
                return $next($request);
            }
        }

        abort(403, 'No tienes permiso para realizar esta acción.');
    }

    private function allows($user, string $module, string $action): bool
    {
        if (method_exists($user, 'hasPermission')) {
            return (bool) $user->hasPermission($module, $action);
        }

        // Respaldo directo a BD (mientras User::hasPermission no exista).
        return DB::table('organization_members as m')
            ->join('roles as r', 'r.id', '=', 'm.role_id')
            ->leftJoin('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->leftJoin('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('m.user_id', $user->getAuthIdentifier())
            ->where('m.is_active', true)
            ->where('r.status', 'active')
            ->where(function ($q) use ($module, $action) {
                $q->where('r.is_system_admin', true)
                  ->orWhere(fn ($q2) => $q2->where('p.module', $module)->where('p.action', $action));
            })
            ->exists();
    }
}
