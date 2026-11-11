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
 * QA: robustez de rutas que modifican datos (POST/PUT/PATCH/DELETE) con payload vacio.
 *  - invitado: nunca 200, nunca 500 (redirige a login o similar),
 *  - recepcion/doctor sin permiso: 403 (jamas 200 ni 500),
 *  - admin con payload vacio: errores de validacion o redireccion, jamas 500.
 */
class MutationRoutesRobustnessTest extends TestCase
{
    private const SKIP = '#^(login|logout|forgot-password|reset-password|password|invitaciones/|profile$|_|sanctum|up$|storage|api/)#';

    /** @return array<string,string>|null */
    private function params(LaravelRoute $route): ?array
    {
        $names = $route->parameterNames();
        $out = [];
        $action = $route->getAction('uses');
        if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action);
            if (class_exists($class) && method_exists($class, $method)) {
                foreach ((new ReflectionMethod($class, $method))->getParameters() as $p) {
                    $t = $p->getType();
                    if ($t && ! $t->isBuiltin() && in_array($p->getName(), $names, true) && is_subclass_of($t->getName(), Model::class)) {
                        $m = ($t->getName())::query()->first();
                        if (! $m) {
                            return null;
                        }
                        $out[$p->getName()] = $m->getRouteKey();
                    }
                }
            }
        }

        return count($out) === count($names) ? $out : null;
    }

    /** @return array<int,array{0:string,1:string,2:string}> */
    private function mutationRoutes(): array
    {
        $list = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (preg_match(self::SKIP, $uri)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $params = $this->params($route);
                if ($params === null) {
                    continue;
                }
                $url = '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $params[$m[1]] ?? '', $uri), '/');
                $perm = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
                $list[] = [$method, $url, ($route->getName() ?: $uri).($perm ? '' : ' [sin permission]')];
            }
        }

        return $list;
    }

    public function test_guests_cannot_mutate_and_nothing_returns_500(): void
    {
        $this->seed(DemoSeeder::class);
        Organization::flushCurrent();
        $problems = [];
        $this->assertGreaterThan(80, count($this->mutationRoutes()), 'Se esperaban al menos 80 rutas de escritura verificables');

        foreach ($this->mutationRoutes() as [$method, $url, $label]) {
            $this->flushSession();
            auth()->logout();
            $code = $this->call($method, $url, [])->getStatusCode();
            if ($code >= 500 || $code === 200 || $code === 201 || $code === 204) {
                $problems[] = "invitado {$method} {$url} ({$label}) => {$code}";
            }
        }
        $this->assertSame([], $problems, "\n".implode("\n", $problems));
    }

    public function test_unauthorized_roles_get_403_and_never_500(): void
    {
        $this->seed(DemoSeeder::class);
        Organization::flushCurrent();
        $problems = [];
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $routes[$route->getName() ?: $route->uri()] = $route;
        }

        foreach (['recepcion@dental.test', 'doctor@dental.test'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            foreach ($this->mutationRoutes() as [$method, $url, $label]) {
                $this->flushSession();
                Organization::flushCurrent();
                $code = $this->actingAs($user->fresh())->call($method, $url, [])->getStatusCode();
                if ($code >= 500) {
                    $problems[] = "{$email} {$method} {$url} ({$label}) => {$code}";
                }
                $name = explode(' ', $label)[0];
                $r = $routes[$name] ?? null;
                if ($r) {
                    $perms = collect($r->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))
                        ->map(function ($m) {
                            [$mod, $acts] = array_pad(explode(',', substr($m, 11), 2), 2, 'ver');

                            return array_map(fn ($a) => [$mod, $a], explode(',', $acts));
                        })->flatten(1);
                    if ($perms->isNotEmpty() && ! $perms->contains(fn ($p) => $user->fresh()->hasPermission($p[0], $p[1])) && $code !== 403) {
                        $problems[] = "ESCALADA {$email} {$method} {$url} ({$label}) => {$code} (esperaba 403)";
                    }
                }
            }
        }
        $this->assertSame([], $problems, "\n".implode("\n", $problems));
    }

    public function test_admin_empty_payloads_never_produce_500(): void
    {
        $this->seed(DemoSeeder::class);
        Organization::flushCurrent();
        $admin = User::where('email', 'admin@dental.test')->firstOrFail();
        $problems = [];

        foreach ($this->mutationRoutes() as [$method, $url, $label]) {
            $this->flushSession();
            Organization::flushCurrent();
            $code = $this->actingAs($admin->fresh())->from('/previa')->call($method, $url, [])->getStatusCode();
            if ($code >= 500) {
                $problems[] = "admin {$method} {$url} ({$label}) => {$code}";
            }
        }
        $this->assertSame([], $problems, "\n".implode("\n", $problems));
    }
}
