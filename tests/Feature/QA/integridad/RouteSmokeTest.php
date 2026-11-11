<?php

namespace Tests\Feature\QA\integridad;

use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Smoke test: cada ruta GET del sistema responde (sin 5xx) para el administrador,
 * con ids reales tomados del demo. Las rutas con parametros que no son modelos
 * (tokens, archivos firmados) se omiten.
 */
class RouteSmokeTest extends TestCase
{
    /** Rutas GET que requieren un contexto especial (firma, token, descarga) y se validan en sus modulos. */
    private const SKIP = [
        'expediente.archivos.download', // URL firmada
        'invitations.accept',           // token de un solo uso
        'invitations.complete',
        'password.reset',
        'storage.local',
        'sanctum.csrf-cookie',
        'ignition.healthCheck', 'ignition.executeSolution', 'ignition.updateConfig',
    ];

    /** Rutas que sirven un archivo subido o un documento generado: 404 es valido si el demo no lo tiene. */
    private const NOT_FOUND_OK = [
        'configuracion.clinica.logo',
        'configuracion.perfil.avatar',
        'configuracion.perfil.firma',
        'documentos.show',
        'finanzas.movimientos.receipt',
        'usuarios.firma',
    ];

    public function test_every_get_route_responds_without_server_errors_for_admin(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAsAdmin();

        $failures = [];
        $visited = 0;

        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $name = (string) $route->getName();
            if (in_array($name, self::SKIP, true) || str_starts_with($route->uri(), '_ignition') || str_starts_with($route->uri(), 'sanctum')) {
                continue;
            }

            $url = $this->resolveUrl($route);
            if ($url === null) {
                continue;
            }

            $visited++;
            $response = $this->get($url);
            $status = $response->getStatusCode();

            if ($status >= 500 || $status === 403 || ($status === 404 && ! in_array($name, self::NOT_FOUND_OK, true))) {
                $detail = $response->exception ? get_class($response->exception) . ': ' . $response->exception->getMessage() : '';
                $failures[] = sprintf('%d %s (%s) %s', $status, $url, $name ?: $route->uri(), substr($detail, 0, 160));
            }
        }

        $this->assertGreaterThan(40, $visited, 'Se esperaba recorrer mas rutas GET.');
        $this->assertSame([], $failures, "Rutas con error:\n" . implode("\n", $failures));
    }

    private function resolveUrl(LaravelRoute $route): ?string
    {
        $uri = $route->uri();
        $models = [];

        foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $param) {
            $models[$param->getName()] = $param->getType()?->getName();
        }

        $params = [];
        foreach ($route->parameterNames() as $name) {
            $optional = str_contains($uri, '{' . $name . '?}');

            if (! isset($models[$name])) {
                if ($optional) {
                    continue;
                }

                return null; // parametro libre (token, etc.): se omite
            }

            $class = $models[$name];
            $instance = $class::withoutGlobalScopes()->first();
            if (! $instance) {
                return $optional ? null : null;
            }
            $params[$name] = $instance->getRouteKey();
        }

        try {
            return route($route->getName() ?: '__none__', $params);
        } catch (\Throwable) {
            $url = '/' . ltrim($uri, '/');
            foreach ($params as $k => $v) {
                $url = str_replace(['{' . $k . '}', '{' . $k . '?}'], $v, $url);
            }

            return str_contains($url, '{') ? null : $url;
        }
    }
}
