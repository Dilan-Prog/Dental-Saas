<?php

namespace Tests\Feature\QA\seguridad;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\CreatesApplication;

/**
 * Fuzzer de seguridad (QA6). NO corre en la suite normal: requiere una base ya
 * sembrada con datos demo y la variable QA_FUZZ=1, por ejemplo:
 *
 *   mysql -uroot -e "CREATE DATABASE dental_saas_qa6"
 *   DB_DATABASE=dental_saas_qa6 php artisan migrate:fresh --seed
 *   QA_FUZZ=1 DB_DATABASE=dental_saas_qa6 php artisan test --filter=RouteFuzzTest
 *
 * Cada peticion se ejecuta dentro de una transaccion que se revierte, salvo la
 * variante XSS (persistente) que se limpia al final con la base de pruebas.
 */
class RouteFuzzTest extends BaseTestCase
{
    use CreatesApplication;

    private const BAD_BODY = '/SQLSTATE|Stack trace|Whoops|ErrorException|TypeError|Undefined (variable|array key|index|offset|property)|Call to (undefined|a member)|Illuminate\\\\|vendor\\\\laravel|Integrity constraint/i';

    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('QA_FUZZ') || DB::getDatabaseName() === 'dental_saas' || DB::getDatabaseName() === 'laravel') {
            $this->markTestSkipped('Fuzzer: define QA_FUZZ=1 y DB_DATABASE=dental_saas_qa6 (base sembrada).');
        }

        Organization::flushCurrent();
        $this->keys = $this->collectKeys();
    }

    // ------------------------------------------------------------------ rutas

    /** @return array<int, LaravelRoute> */
    private function writeRoutes(): array
    {
        return array_values(array_filter(iterator_to_array(Route::getRoutes()), function (LaravelRoute $r) {
            $m = array_diff($r->methods(), ['HEAD']);
            $writes = array_intersect($m, ['POST', 'PUT', 'PATCH', 'DELETE']);
            $uri = $r->uri();

            return $writes && ! Str::startsWith($uri, ['_ignition', 'sanctum', 'api', 'login', 'forgot-password', 'reset-password']);
        }));
    }

    private function isPublic(LaravelRoute $r): bool
    {
        return Str::startsWith($r->uri(), ['invitaciones', 'login', 'forgot-password', 'reset-password']);
    }

    private function ids(string $table, string $col = 'id'): array
    {
        return DB::table($table)->pluck($col)->all();
    }

    /** Valor real para cada parametro de ruta (id valido del propio recurso). */
    private function paramValues(LaravelRoute $route): array
    {
        $name = (string) $route->getName();
        $p = $route->parameterNames();
        $out = [];
        $first = fn (string $table) => (string) (DB::table($table)->orderBy('id')->value('id') ?: Str::uuid());

        foreach ($p as $param) {
            $out[$param] = match ($param) {
                'appointment' => $first('appointments'),
                'branch' => $first('branches'),
                'patient' => $first('patients'),
                'invoice' => $first('invoices'),
                'register' => $first('cash_registers'),
                'session' => $first('cash_sessions'),
                'item' => $first('inventory_items'),
                'supplier' => $first('suppliers'),
                'role' => (string) DB::table('roles')->where('code', 'doctor')->value('id'),
                'quote' => $first('quotes'),
                'prescription' => $first('prescriptions'),
                'medication' => $first('medications'),
                'taxProfile' => $first('tax_profiles'),
                'payment' => $first('payments'),
                'recipient' => $first('notification_recipients'),
                'template' => 'historia-clinica',
                'token' => 'x',
                'note' => $first('clinical_notes'),
                'file' => $first('clinical_files'),
                'profile' => $first('patient_fiscal_profiles'),
                'guardian' => $first('patient_guardians'),
                'member' => (string) DB::table('organization_members')->where('user_id', '!=', $this->admin()->id)->value('id'),
                'treatment' => Str::startsWith($name, 'expediente.') ? $first('patient_treatments') : $first('treatments'),
                'category' => Str::startsWith($name, 'finanzas.') ? $first('financial_categories') : $first('treatment_categories'),
                'movement' => Str::startsWith($name, 'inventario.') ? $first('inventory_movements') : $first('financial_movements'),
                default => (string) Str::uuid(),
            };
        }

        // Pares padre-hijo coherentes.
        if (isset($out['file'], $out['patient'])) {
            $out['patient'] = (string) DB::table('clinical_files')->where('id', $out['file'])->value('patient_id') ?: $out['patient'];
        }
        if (isset($out['profile'], $out['patient'])) {
            $out['patient'] = (string) DB::table('patient_fiscal_profiles')->where('id', $out['profile'])->value('patient_id') ?: $out['patient'];
        }
        if (isset($out['guardian'], $out['patient'])) {
            $out['patient'] = (string) DB::table('patient_guardians')->where('id', $out['guardian'])->value('patient_id') ?: $out['patient'];
        }
        if (isset($out['note'], $out['patient'])) {
            $out['patient'] = (string) DB::table('clinical_notes as n')
                ->join('clinical_encounters as e', 'e.id', '=', 'n.encounter_id')
                ->join('medical_records as m', 'm.id', '=', 'e.medical_record_id')
                ->where('n.id', $out['note'])->value('m.patient_id') ?: $out['patient'];
        }
        if (isset($out['treatment'], $out['patient']) && Str::startsWith($name, 'expediente.')) {
            $out['patient'] = (string) DB::table('patient_treatments')->where('id', $out['treatment'])->value('patient_id') ?: $out['patient'];
        }
        if (isset($out['member'], $out['role'])) {
            $out['role'] = (string) DB::table('organization_members')->where('id', $out['member'])->value('role_id') ?: $out['role'];
        }

        return $out;
    }

    private function url(LaravelRoute $route, array $params): string
    {
        $uri = $route->uri();
        foreach ($params as $k => $v) {
            $uri = preg_replace('/\{'.preg_quote($k, '/').'\??\}/', rawurlencode((string) $v), $uri);
        }

        return '/'.ltrim($uri, '/');
    }

    // ------------------------------------------------------------- payloads

    private function collectKeys(): array
    {
        $keys = [];
        foreach (Route::getRoutes() as $route) {
            $class = $this->formRequestClass($route);
            if (! $class) {
                continue;
            }
            try {
                $req = $class::create('/', 'POST');
                $req->setContainer($this->app)->setRedirector($this->app['redirect']);
                $rules = method_exists($req, 'rules') ? $this->app->call([$req, 'rules']) : [];
            } catch (\Throwable) {
                $rules = [];
            }
            foreach (array_keys($rules) as $k) {
                $keys[$k] = true;
            }
        }
        // Campos habituales por si rules() dependia de la ruta/usuario.
        foreach (['name', 'full_name', 'email', 'phone', 'password', 'password_confirmation', 'current_password', 'rfc', 'amount',
            'total', 'status', 'notes', 'reason', 'motivo', 'patient_id', 'doctor_member_id', 'branch_id', 'treatment_id',
            'starts_at', 'ends_at', 'quantity', 'unit_cost', 'q', 'page', 'per_page', 'sort', 'dir', 'idempotency_key'] as $k) {
            $keys[$k] = true;
        }

        return array_keys($keys);
    }

    private function formRequestClass(LaravelRoute $route): ?string
    {
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            return null;
        }
        [$c, $m] = explode('@', $action);
        if (! method_exists($c, $m)) {
            return null;
        }
        foreach ((new ReflectionMethod($c, $m))->getParameters() as $p) {
            $t = $p->getType();
            if ($t && ! $t->isBuiltin() && is_subclass_of($t->getName(), FormRequest::class)) {
                return $t->getName();
            }
        }

        return null;
    }

    private function payload(mixed $value): array
    {
        $data = [];
        foreach ($this->keys as $k) {
            if (str_contains($k, '*')) {
                Arr::set($data, str_replace('*', '0', $k), $value);
            } else {
                Arr::set($data, $k, $value);
            }
        }

        return $data;
    }

    private function variants(): array
    {
        return [
            'vacio' => [],
            'str10k' => $this->payload(str_repeat('A', 10000)),
            'nul' => $this->payload("a\0b"),
            'xss' => $this->payload('"><script>alert(1)</script>'),
            'sqli' => $this->payload("' OR '1'='1' -- "),
            'negativo' => $this->payload(-1),
            'huge' => $this->payload('1e999'),
            'nan' => $this->payload('NaN'),
            'array' => $this->payload([]),
            'array2' => $this->payload(['a' => ['b' => 'c']]),
            'baduuid' => $this->payload('not-a-uuid'),
            'baddate' => $this->payload('9999-99-99'),
            'emoji' => $this->payload('😀😀'),
            'null' => $this->payload(null),
        ];
    }

    private function admin(): User
    {
        return User::where('email', 'admin@dental.test')->firstOrFail();
    }

    private function assertSane($response, string $label): void
    {
        $status = $response->getStatusCode();
        $content = (string) $response->getContent();
        $ex = $response->exception ?? null;
        $this->assertLessThan(500, $status, "$label => HTTP $status ".($ex ? get_class($ex).': '.Str::limit($ex->getMessage(), 300) : ''));

        if (! $response->isRedirection() || true) {
            $this->assertDoesNotMatchRegularExpression(self::BAD_BODY, $content, "$label => cuerpo con traza/SQL");
        }
        $flash = json_encode([session('error'), session('errors')?->getBags()]);
        $this->assertDoesNotMatchRegularExpression('/SQLSTATE|Integrity constraint|Illuminate\\\\/', (string) $flash, "$label => flash/errores con SQL");
    }

    private function send(string $method, string $url, array $data, ?User $user = null)
    {
        $this->flushSession();
        $user ??= $this->admin();
        DB::beginTransaction();
        try {
            return $this->actingAs($user)->call($method, $url, $data);
        } finally {
            DB::rollBack();
        }
    }

    // ------------------------------------------------------------------ tests

    public function test_hostile_inputs_never_cause_500_or_leak(): void
    {
        $failures = [];
        foreach ($this->writeRoutes() as $route) {
            if ($this->isPublic($route)) {
                continue;
            }
            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $params = $this->paramValues($route);
            $url = $this->url($route, $params);

            foreach ($this->variants() as $variant => $data) {
                try {
                    $res = $this->send($method, $url, $data);
                    $this->assertSane($res, "$method $url [$variant]");
                } catch (\Throwable $e) {
                    $failures[] = $e->getMessage();
                }
            }
        }

        $this->assertSame([], array_slice($failures, 0, 60), count($failures).' fallos de robustez');
    }

    public function test_every_write_route_requires_authentication(): void
    {
        foreach ($this->writeRoutes() as $route) {
            if ($this->isPublic($route)) {
                continue;
            }
            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $url = $this->url($route, $this->paramValues($route));
            $this->flushSession();
            $res = $this->call($method, $url, []);
            $this->assertTrue(
                $res->isRedirection() && str_contains((string) $res->headers->get('Location'), 'login'),
                "$method $url sin sesion deberia redirigir a login (HTTP {$res->getStatusCode()})"
            );
        }
    }

    public function test_permission_matrix_is_enforced_by_middleware(): void
    {
        $users = [
            'doctor' => User::where('email', 'doctor@dental.test')->firstOrFail(),
            'recepcion' => User::where('email', 'recepcion@dental.test')->firstOrFail(),
        ];
        $unprotected = [];
        $violations = [];

        foreach ($this->writeRoutes() as $route) {
            if ($this->isPublic($route)) {
                continue;
            }
            $perm = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $url = $this->url($route, $this->paramValues($route));

            if (! $perm) {
                $unprotected[] = "$method {$route->uri()}";
                continue;
            }
            [$module, $actions] = explode(',', substr($perm, 11), 2) + [1 => 'ver'];
            $actions = explode(',', $actions);

            foreach ($users as $label => $u) {
                $allowed = collect($actions)->contains(fn ($a) => $u->hasPermission($module, $a));
                $u->flushPermissionCache();
                $res = $this->send($method, $url, [], $u);
                if (! $allowed && $res->getStatusCode() !== 403) {
                    $violations[] = "$label $method $url debia ser 403, fue {$res->getStatusCode()}";
                }
                if ($res->getStatusCode() >= 500) {
                    $violations[] = "$label $method $url => 500";
                }
            }
        }

        fwrite(STDERR, "\n[QA6] Rutas de escritura SIN middleware permission: ".implode(' | ', $unprotected)."\n");
        $this->assertSame([], $violations);
    }

    public function test_stored_xss_in_every_text_column_is_escaped_on_every_page(): void
    {
        $payload = '<script>alert(1)</script>';
        DB::beginTransaction();

        try {
            $cols = DB::select("select table_name t, column_name c from information_schema.columns
                where table_schema = database() and data_type in ('varchar','text','longtext','mediumtext')
                and (character_maximum_length is null or character_maximum_length >= 30)
                and column_name not regexp '(_id|_key|_hash|password|token|sha256|storage|mime|currency|code|folio|rfc|email|phone)$|^(id|email|password|remember_token)$'
                and table_name not in ('migrations','failed_jobs','password_reset_tokens','personal_access_tokens','sessions','cache','cache_locks','audit_log','user_sessions')");
            $touched = 0;
            foreach ($cols as $col) {
                try {
                    DB::beginTransaction();
                    $touched += DB::table($col->t)->update([$col->c => $payload]) > 0 ? 1 : 0;
                    DB::commit();
                } catch (\Throwable) {
                    DB::rollBack();   // CHECK / trigger / enum: se omite esa columna
                }
            }
            $this->assertGreaterThan(20, $touched, 'se inyecto el payload en pocas columnas');

            $bad = [];
            foreach (Route::getRoutes() as $route) {
                if (! in_array('GET', $route->methods(), true) || $this->isPublic($route)
                    || Str::contains($route->uri(), ['logout', 'descargar', 'exportar', 'storage', 'archivos', '_ignition', 'sanctum', 'api/'])) {
                    continue;
                }
                $url = $this->url($route, $this->paramValues($route));
                $this->flushSession();
                $res = $this->actingAs($this->admin())->get($url.'?q='.rawurlencode($payload).'&search='.rawurlencode($payload));
                $type = (string) $res->headers->get('Content-Type');
                if ($res->getStatusCode() >= 500) {
                    $bad[] = "$url => HTTP {$res->getStatusCode()}";
                } elseif (str_contains($type, 'html') && str_contains((string) $res->getContent(), $payload)) {
                    $bad[] = "$url => payload sin escapar";
                }
            }
            $this->assertSame([], $bad);
        } finally {
            DB::rollBack();
        }
    }
}
