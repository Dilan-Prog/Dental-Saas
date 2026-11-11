<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Elimina bytes NUL ("\0") de toda la entrada (query, body, JSON). Un NUL en un campo
 * de texto no es legitimo y hace fallar a DateTime/consultas con errores 500.
 */
class StripNullBytes
{
    public function handle(Request $request, Closure $next)
    {
        $request->query->replace($this->clean($request->query->all()));
        $request->request->replace($this->clean($request->request->all()));

        if ($request->isJson()) {
            $request->json()->replace($this->clean($request->json()->all()));
        }

        return $next($request);
    }

    private function clean(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_contains($value, "\0") ? str_replace("\0", '', $value) : $value;
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $this->clean($v), $value);
        }

        return $value;
    }
}
