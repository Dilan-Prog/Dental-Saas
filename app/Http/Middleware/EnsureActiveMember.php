<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que el usuario autenticado esté activo y tenga una membresía
 * activa en la clínica (única organización del sistema).
 */
class EnsureActiveMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $orgId = DB::table('organizations')->where('status', 'active')->orderBy('created_at')->value('id');

        $active = (bool) DB::table('users')->where('id', $user->getAuthIdentifier())->value('is_active');

        $member = $orgId ? DB::table('organization_members')
            ->where('organization_id', $orgId)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('is_active', true)
            ->first(['id', 'role_id']) : null;

        if (! $active || ! $member) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Tu cuenta está inactiva o no tiene acceso a la clínica.',
            ]);
        }

        $request->attributes->set('organization_id', $orgId);
        $request->attributes->set('member_id', $member->id);

        return $next($request);
    }
}
