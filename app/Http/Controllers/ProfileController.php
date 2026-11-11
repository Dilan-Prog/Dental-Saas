<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Da de baja la cuenta (baja logica: is_active = false). No se borra el
     * registro porque esta referenciado por documentos clinicos y fiscales.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // No dejar el consultorio sin ningun administrador activo.
        if ($user->isAdmin()) {
            $otherAdmins = \Illuminate\Support\Facades\DB::table('organization_members as m')
                ->join('roles as r', 'r.id', '=', 'm.role_id')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.is_active', true)->where('u.is_active', true)
                ->where('u.id', '!=', $user->id)
                ->where(fn ($q) => $q->where('r.is_system_admin', true)->orWhere('r.code', 'admin_sistema'))
                ->count();

            if ($otherAdmins === 0) {
                return Redirect::back()->with('error', 'No puedes dar de baja tu cuenta: eres el único administrador activo del consultorio.');
            }
        }

        Auth::logout();

        // Baja logica: usuario y membresia (el trigger revoca las sesiones registradas).
        \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
            \Illuminate\Support\Facades\DB::table('organization_members')->where('user_id', $user->id)->update(['is_active' => false]);
            $user->forceFill(['is_active' => false])->save();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
