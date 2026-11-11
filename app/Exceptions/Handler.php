<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Defensa en profundidad: un campo con TIPO equivocado (array donde se espera
        // texto, etc.) es entrada invalida del cliente, no un error del servidor.
        $this->renderable(function (Throwable $e, $request) {
            if (! $this->isTypeConfusion($e) || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return null;
            }

            $message = 'El formulario contiene datos con un formato no válido. Revisa los campos e inténtalo de nuevo.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'errors' => ['form' => [$message]]], 422);
            }

            return redirect()->back()->withInput($request->except($this->dontFlash))->withErrors(['form' => $message]);
        });
    }

    private function isTypeConfusion(Throwable $e): bool
    {
        $m = $e->getMessage();

        if ($e instanceof \TypeError && str_contains($m, 'array given')) {
            return true;
        }

        if ($e instanceof \ErrorException && str_contains($m, 'Array to string conversion')) {
            return true;
        }

        return $e instanceof \ValueError && str_contains($m, 'null bytes');
    }
}
