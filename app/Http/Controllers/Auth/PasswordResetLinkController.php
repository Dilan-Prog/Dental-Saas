<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Respuesta identica exista o no el correo (evita enumerar usuarios) y sin
        // propagar fallos del servidor de correo como error 500.
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('status', 'Si el correo está registrado, recibirás en unos minutos un enlace para restablecer tu contraseña.');
    }
}
