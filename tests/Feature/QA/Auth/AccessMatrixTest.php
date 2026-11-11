<?php

namespace Tests\Feature\QA\Auth;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * QA: matriz de acceso. Recorre TODAS las rutas GET con datos demo reales para
 * admin, doctor, recepcion e invitado y comprueba:
 *  - nunca 500 / excepciones,
 *  - invitado siempre redirigido a login (salvo rutas publicas),
 *  - 403 exactamente cuando el rol no tiene el permiso `permission:modulo,accion`.
 */
class AccessMatrixTest extends TestCase
{
    private const PUBLIC_PREFIXES = ['login', 'forgot-password', 'reset-password', 'invitacion', 'invitaciones', 'invitations'];

    /** @return array<string,string>|null */
    private function resolveParams(LaravelRoute $route): ?array
    {
        $names = $route->parameterNames();
        $params = [];

        $action = $route->getAction('uses');
        if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action);
            if (class_exists($class) && method_exists($class, $method)) {
                foreach ((new ReflectionMethod($class, $method))->getParameters() as $p) {
                    $t = $p->getType();
                    if ($t && ! $t->isBuiltin() && in_array($p->getName(), $names, true)
                        && is_subclass_of($t->getName(), Model::class)) {
                        $m = ($t->getName())::query()->first();
                        if (! $m) {
                            return null;
                        }
                        $params[$p->getName()] = $m->getRouteKey();
                    }
                }
            }
        }

        foreach ($names as $n) {
            if (! isset($params[$n])) {
                return null;
            }
        }

        return $params;
    }

    /** @return array<int,array{0:string,1:string}> */
    private function permissionsOf(LaravelRoute $route): array
    {
        $out = [];
        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'permission:')) {
                [$mod, $acts] = array_pad(explode(',', substr($mw, 11), 2), 2, 'ver');
                foreach (explode(',', $acts) as $a) {
                    $out[] = [$mod, $a];
                }
            }
        }

        return $out;
    }

    public function test_access_matrix_for_all_get_routes(): void
    {
        $this->seed(DemoSeeder::class);
        Organization::flushCurrent();

        $users = [
            'admin' => User::where('email', 'admin@dental.test')->firstOrFail(),
            'doctor' => User::where('email', 'doctor@dental.test')->firstOrFail(),
            'recepcion' => User::where('email', 'recepcion@dental.test')->firstOrFail(),
        ];

        $problems = [];
        $report = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            if (preg_match('#^(_|sanctum|up$|api/|storage|build)#', $uri)) {
                continue;
            }
            // descargas firmadas: requieren URL firmada, se prueban aparte
            if (in_array('signed', $route->gatherMiddleware(), true)) {
                continue;
            }

            $params = $this->resolveParams($route);
            if ($params === null) {
                $report[] = "SKIP {$uri}";

                continue;
            }

            $url = '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $params[$m[1]] ?? '', $uri), '/');
            $perms = $this->permissionsOf($route);
            $isPublic = collect(self::PUBLIC_PREFIXES)->contains(fn ($p) => str_starts_with($uri, $p)) || $uri === '/';
            $label = ($route->getName() ?: $uri)." {$url}";
            $checked++;

            // invitado
            $this->flushSession();
            auth()->logout();
            $g = $this->get($url);
            $gs = $g->getStatusCode();
            if ($gs >= 500) {
                $problems[] = "[500 invitado] {$label} => {$gs}";
            } elseif (! $isPublic && $uri !== '/' && ! in_array($gs, [302, 401, 403, 404], true)) {
                $problems[] = "[invitado accede] {$label} => {$gs}";
            } elseif (! $isPublic && $gs === 302 && ! str_contains((string) $g->headers->get('Location'), 'login')) {
                $problems[] = "[invitado redirige a otro sitio] {$label} => ".$g->headers->get('Location');
            }

            foreach ($users as $role => $user) {
                $this->flushSession();
                $resp = $this->actingAs($user)->get($url);
                $code = $resp->getStatusCode();
                $row = str_pad($role, 10)."{$code} {$label}";

                if ($code >= 500) {
                    $problems[] = "[500] {$role} {$label} => {$code}";
                }

                if ($perms && $code !== 302 && $code < 500) {
                    $allowed = true;
                    foreach ($perms as [$mod, $act]) {
                        // el middleware admite cualquiera de las acciones listadas
                    }
                    $allowed = collect($perms)->contains(fn ($p) => $user->hasPermission($p[0], $p[1]));
                    if ($allowed && $code === 403) {
                        $problems[] = "[403 indebido] {$role} {$label}";
                    }
                    if (! $allowed && $code === 200) {
                        $problems[] = "[acceso sin permiso] {$role} {$label} perms=".json_encode($perms);
                    }
                }
                $report[] = $row;
            }
            auth()->logout();
        }


        $this->assertGreaterThan(40, $checked, 'Se esperaban al menos 40 rutas GET verificadas');
        $this->assertSame([], $problems, "Problemas de acceso:\n".implode("\n", $problems));
    }
}
