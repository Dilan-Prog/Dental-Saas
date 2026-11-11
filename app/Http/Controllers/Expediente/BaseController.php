<?php

namespace App\Http\Controllers\Expediente;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\Expediente\DbErrors;
use App\Services\Expediente\ExpedienteException;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Utilidades comunes de los controladores del módulo Expediente/Documentos. */
abstract class BaseController extends Controller
{
    /** id de organization_members del usuario autenticado (lo fija el middleware active.member). */
    protected function memberId(Request $request): string
    {
        return (string) $request->attributes->get('member_id');
    }

    protected function isAdmin(Request $request): bool
    {
        return (bool) $request->user()?->isAdmin();
    }

    protected function toPatient(Patient $patient, string $tab = 'resumen', array $extra = []): string
    {
        return route('expediente.show', ['patient' => $patient->id, 'tab' => $tab] + $extra);
    }

    /**
     * Ejecuta una acción de negocio capturando errores de regla de negocio y de base de datos
     * (triggers/CHECK/FK) para devolver un mensaje claro en lugar de un error 500.
     */
    protected function attempt(Closure $action, string $success, string $redirectTo): RedirectResponse
    {
        try {
            $action();
        } catch (ExpedienteException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return back()->withInput()->with('error', DbErrors::message($e));
        }

        return redirect($redirectTo)->with('success', $success);
    }
}
