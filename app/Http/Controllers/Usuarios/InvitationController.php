<?php

namespace App\Http\Controllers\Usuarios;

use App\Http\Controllers\Controller;
use App\Http\Requests\Usuarios\AcceptInvitationRequest;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\Usuarios\UserAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Aceptación pública de invitaciones: el usuario define su contraseña con un
 * enlace de un solo uso. Solo se guarda el hash SHA-256 del token.
 */
class InvitationController extends Controller
{
    public function show(string $token): View
    {
        $invitation = $this->find($token);

        return view('usuarios.invitation', [
            'token' => $token,
            'invitation' => $invitation,
            'user' => $invitation ? User::query()->where('email', $invitation->email)->first() : null,
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->find($token);

        if (! $invitation) {
            return redirect()->route('invitations.accept', $token);
        }

        $user = User::query()->where('email', $invitation->email)->first();
        $member = $user ? OrganizationMember::query()->where('user_id', $user->id)->first() : null;

        if (! $user || ! $member) {
            return redirect()->route('login')->with('status', 'La invitación ya no es válida.');
        }

        DB::transaction(function () use ($request, $invitation, $user, $member) {
            $user->forceFill([
                'password' => $request->validated('password'),
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();

            $member->update(['is_active' => true]);
            $invitation->update(['accepted_at' => now()]);
        });

        Auth::login($user);
        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);

        return redirect('/')->with('success', '¡Bienvenido! Tu contraseña quedó establecida.');
    }

    private function find(string $token): ?UserInvitation
    {
        return UserInvitation::pending()
            ->where('token_hash', UserAccessService::hashToken($token))
            ->first();
    }
}
