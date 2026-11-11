<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad para todas las respuestas HTML/JSON de la aplicacion.
 * CSP: solo recursos propios + Google Fonts (unica dependencia externa); se
 * permiten scripts/estilos en linea porque las vistas Blade los usan.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: blob:; "
                ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; "
                ."script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; "
                ."base-uri 'self'; object-src 'none'",
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
