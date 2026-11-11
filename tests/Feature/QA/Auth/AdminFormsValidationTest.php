<?php

namespace Tests\Feature\QA\Auth;

use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * QA: validacion de formularios de Configuracion, Usuarios y Roles.
 * Cada caso: [etiqueta, metodo, url, payload, campo con error esperado | null si debe aceptarse].
 */
class AdminFormsValidationTest extends TestCase
{
    /** @return array<int,string> */
    private function errorKeys(TestResponse $r): array
    {
        $keys = [];
        $errors = session('errors');
        if ($errors) {
            foreach ($errors->getBags() as $bag) {
                $keys = array_merge($keys, array_keys($bag->messages()));
            }
        }
        if ($r->getStatusCode() === 422) {
            $keys = array_merge($keys, array_keys((array) ($r->json('errors') ?? [])));
        }

        return array_values(array_unique($keys));
    }

    private function roleId(string $code): string
    {
        return (string) DB::table('roles')->where('organization_id', $this->organizationId())->where('code', $code)->value('id');
    }

    /** @param array<int,array{0:string,1:string,2:string,3:array,4:?string}> $cases */
    private function runCases(array $cases): void
    {
        $problems = [];
        foreach ($cases as [$label, $method, $url, $payload, $expect]) {
            $this->flushSession();
            $this->actingAsAdmin();
            Organization::flushCurrent();
            $r = $this->from('/previa')->call($method, $url, $payload);
            $code = $r->getStatusCode();
            $keys = $this->errorKeys($r);

            if ($code >= 500) {
                $problems[] = "[500] {$label}";

                continue;
            }
            if ($expect === null) {
                if ($keys) {
                    $problems[] = "[rechaza valido] {$label}: ".implode(',', $keys);
                }
            } else {
                $hit = collect($keys)->contains(fn ($k) => $k === $expect || str_starts_with($k, $expect.'.'));
                if (! $hit) {
                    $problems[] = "[acepta invalido] {$label} (esperaba error en {$expect}; obtuvo [".implode(',', $keys)."] status {$code})";
                }
            }
        }
        $this->assertSame([], $problems, "\n".implode("\n", $problems));
    }

    public function test_clinica_form(): void
    {
        $ok = [
            'commercial_name' => 'Clínica Dental Sonrisa', 'rfc' => 'CDS010101AB1', 'phone' => '5512345678',
            'email' => 'contacto@sonrisa.mx', 'address_line' => 'Av. Reforma 100', 'address_postal_code' => '06600',
            'timezone' => 'America/Mexico_City', 'currency' => 'MXN',
        ];
        $u = '/configuracion/clinica';
        $c = fn (string $l, array $o, ?string $e) => [$l, 'PUT', $u, $o + $ok, $e];

        $this->runCases([
            $c('valido', [], null),
            $c('nombre vacio', ['commercial_name' => ''], 'commercial_name'),
            $c('nombre 1 char', ['commercial_name' => 'A'], 'commercial_name'),
            $c('nombre 201', ['commercial_name' => str_repeat('a', 201)], 'commercial_name'),
            $c('rfc malo', ['rfc' => 'ABC'], 'rfc'),
            $c('rfc fecha imposible (31 feb)', ['rfc' => 'CDS010231AB1'], 'rfc'),
            $c('rfc mes 13', ['rfc' => 'CDS011301AB1'], 'rfc'),
            $c('rfc 14 chars', ['rfc' => 'CDS010101AB12X'], 'rfc'),
            $c('rfc generico no permitido', ['rfc' => 'XAXX010101000'], 'rfc'),
            $c('telefono corto', ['phone' => '12345'], 'phone'),
            $c('telefono letras', ['phone' => 'abcdefghij'], 'phone'),
            $c('cp 4 digitos', ['address_postal_code' => '0660'], 'address_postal_code'),
            $c('cp letras', ['address_postal_code' => '06A00'], 'address_postal_code'),
            $c('cp 6 digitos', ['address_postal_code' => '066000'], 'address_postal_code'),
            $c('email malo', ['email' => 'sin-arroba'], 'email'),
            $c('email 255', ['email' => str_repeat('a', 250).'@x.mx'], 'email'),
            $c('direccion 301', ['address_line' => str_repeat('d', 301)], 'address_line'),
            $c('zona horaria invalida', ['timezone' => 'Mars/Phobos'], 'timezone'),
            $c('moneda invalida', ['currency' => 'USD'], 'currency'),
            $c('moneda vacia', ['currency' => ''], 'currency'),
            $c('rfc/telefono/email vacios aceptados', ['rfc' => '', 'phone' => '', 'email' => '', 'address_postal_code' => ''], null),
        ]);
    }

    public function test_clinica_logo_upload_rules(): void
    {
        Storage::fake('local');
        $ok = ['commercial_name' => 'Clínica', 'timezone' => 'America/Mexico_City', 'currency' => 'MXN'];
        $u = '/configuracion/clinica';

        $php = UploadedFile::fake()->createWithContent('logo.png', '<?php echo "x";');
        $big = UploadedFile::fake()->image('big.png')->size(3000);
        $svgBad = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $svgOk = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');
        $txt = UploadedFile::fake()->createWithContent('logo.txt', 'hola');
        $good = UploadedFile::fake()->image('logo.png', 200, 200);

        $this->runCases([
            ['logo con php disfrazado de png', 'POST', $u, ['_method' => 'PUT'] + $ok + ['logo' => $php], 'logo'],
            ['logo 3MB', 'POST', $u, ['_method' => 'PUT'] + $ok + ['logo' => $big], 'logo'],
            ['svg con script', 'POST', $u, ['_method' => 'PUT'] + $ok + ['logo' => $svgBad], 'logo'],
            ['txt', 'POST', $u, ['_method' => 'PUT'] + $ok + ['logo' => $txt], 'logo'],
            ['svg valido', 'POST', $u, ['_method' => 'PUT'] + $ok + ['logo' => $svgOk], null],
            ['png valido', 'POST', $u, ['_method' => 'PUT'] + $ok + ['logo' => $good], null],
        ]);
    }

    public function test_facturacion_form(): void
    {
        $ok = [
            'legal_name' => 'Clínica Dental Sonrisa SA de CV', 'rfc' => 'CDS010101AB1', 'tax_regime_code' => '601',
            'fiscal_postal_code' => '06600', 'pac_provider' => 'Finkok', 'default_series' => 'A',
            'csd_certificate_secret_ref' => 'vault://clinica/csd-cer', 'csd_key_secret_ref' => 'vault://clinica/csd-key',
            'pac_credentials_secret_ref' => 'vault://clinica/pac',
        ];
        $u = '/configuracion/facturacion';
        $c = fn (string $l, array $o, ?string $e) => [$l, 'PUT', $u, $o + $ok, $e];

        $this->runCases([
            $c('valido', [], null),
            $c('rfc malo', ['rfc' => 'XX'], 'rfc'),
            $c('rfc generico', ['rfc' => 'XAXX010101000'], 'rfc'),
            $c('regimen inexistente', ['tax_regime_code' => '999'], 'tax_regime_code'),
            $c('regimen fisica con rfc moral', ['tax_regime_code' => '612'], 'tax_regime_code'),
            $c('regimen moral con rfc fisica', ['rfc' => 'PEGJ800101AB1', 'tax_regime_code' => '601'], 'tax_regime_code'),
            $c('cp fiscal malo', ['fiscal_postal_code' => '1'], 'fiscal_postal_code'),
            $c('pac desconocido', ['pac_provider' => 'MiPac'], 'pac_provider'),
            $c('serie minusculas con espacio', ['default_series' => 'a b'], 'default_series'),
            $c('serie 11 chars', ['default_series' => str_repeat('A', 11)], 'default_series'),
            $c('secreto: certificado PEM pegado', ['csd_certificate_secret_ref' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----"], 'csd_certificate_secret_ref'),
            $c('secreto: password con espacios', ['pac_credentials_secret_ref' => 'mi password secreta'], 'pac_credentials_secret_ref'),
            $c('secreto: 256 chars', ['csd_key_secret_ref' => 'v'.str_repeat('a', 255)], 'csd_key_secret_ref'),
            $c('timbrado sin razon social', ['legal_name' => ''], 'legal_name'),
            $c('razon social 301', ['legal_name' => str_repeat('r', 301)], 'legal_name'),
            $c('sin pac ni csd todo vacio es valido', [
                'legal_name' => '', 'rfc' => '', 'tax_regime_code' => '', 'fiscal_postal_code' => '', 'pac_provider' => '',
                'default_series' => '', 'csd_certificate_secret_ref' => '', 'csd_key_secret_ref' => '', 'pac_credentials_secret_ref' => '',
            ], null),
        ]);
    }

    public function test_folios_cannot_go_backwards_or_break_format(): void
    {
        $seq = DB::table('folio_sequences')->where('organization_id', $this->organizationId())->first();
        DB::table('folio_sequences')->where('id', $seq->id)->update(['last_value' => 50]);
        $u = '/configuracion/facturacion/folios';
        $row = fn (array $o) => ['folios' => [array_merge(['id' => $seq->id, 'prefix' => $seq->prefix, 'next' => 60], $o)]];

        $this->runCases([
            ['folio sube', 'PUT', $u, $row([]), null],
            ['folio baja (51 <= 60 ya usado)', 'PUT', $u, $row(['next' => 10]), 'folios'],
            ['folio cero', 'PUT', $u, $row(['next' => 0]), 'folios'],
            ['folio negativo', 'PUT', $u, $row(['next' => -5]), 'folios'],
            ['folio decimal', 'PUT', $u, $row(['next' => '70.5']), 'folios'],
            ['prefijo minusculas se normaliza', 'PUT', $u, $row(['prefix' => 'pac-', 'next' => 100]), null],
            ['prefijo con espacio', 'PUT', $u, $row(['prefix' => 'PA C', 'next' => 100]), 'folios'],
            ['prefijo con simbolos', 'PUT', $u, $row(['prefix' => 'PA$%', 'next' => 100]), 'folios'],
            ['prefijo 11 chars', 'PUT', $u, $row(['prefix' => str_repeat('A', 11), 'next' => 100]), 'folios'],
            ['id ajeno', 'PUT', $u, $row(['id' => (string) \Illuminate\Support\Str::uuid(), 'next' => 100]), 'folios'],
        ]);
    }

    public function test_sucursales_form(): void
    {
        $main = $this->mainBranchId();
        $u = '/configuracion/sucursales';
        $ok = ['name' => 'Sucursal Norte', 'address_line' => 'Calle 1', 'phone' => '5511223344'];
        $c = fn (string $l, array $o, ?string $e) => [$l, 'POST', $u, $o + $ok, $e];

        $this->runCases([
            $c('valida', [], null),
            $c('nombre duplicado', ['name' => 'Sucursal Norte'], 'name'),
            $c('nombre duplicado distinto caso', ['name' => 'sucursal norte'], 'name'),
            $c('nombre duplicado con la principal', ['name' => 'Clínica Centro'], 'name'),
            $c('nombre vacio', ['name' => ''], 'name'),
            $c('nombre 1 char', ['name' => 'N'], 'name'),
            $c('nombre 151', ['name' => str_repeat('n', 151)], 'name'),
            $c('telefono malo', ['name' => 'Otra', 'phone' => '123'], 'phone'),
            $c('gerente uuid inexistente', ['name' => 'Otra2', 'manager_user_id' => (string) \Illuminate\Support\Str::uuid()], 'manager_user_id'),
            $c('gerente no uuid', ['name' => 'Otra3', 'manager_user_id' => 'abc'], 'manager_user_id'),
        ]);

        // reglas de estado de la principal
        $this->actingAsAdmin();
        Organization::flushCurrent();
        $r = $this->from('/previa')->patch("/configuracion/sucursales/{$main}/estado");
        $this->assertTrue(in_array($r->getStatusCode(), [302, 422, 403], true));
        $this->assertSame('active', DB::table('branches')->where('id', $main)->value('status'), 'No se debe poder desactivar la sucursal principal');
        $this->assertSame(1, DB::table('branches')->where('organization_id', $this->organizationId())->where('is_main', 1)->where('status', 'active')->count());
    }

    public function test_notificaciones_form(): void
    {
        $role = $this->roleId('recepcion');
        $u = '/configuracion/notificaciones';
        $withLead = null;
        $withRole = null;
        foreach (\App\Services\Configuracion\ConfiguracionCatalog::notificationEvents() as $ev => $meta) {
            $withLead ??= $meta['lead'] ? $ev : null;
            $withRole ??= $meta['role'] ? $ev : null;
        }
        $this->assertNotNull($withLead);
        $this->assertNotNull($withRole);

        $this->runCases([
            ['lead negativo', 'PUT', $u, ['events' => [$withLead => ['enabled' => 1, 'lead' => -5]]], 'events'],
            ['lead exagerado', 'PUT', $u, ['events' => [$withLead => ['enabled' => 1, 'lead' => 99999999]]], 'events'],
            ['lead texto', 'PUT', $u, ['events' => [$withLead => ['enabled' => 1, 'lead' => 'abc']]], 'events'],
            ['lead decimal', 'PUT', $u, ['events' => [$withLead => ['enabled' => 1, 'lead' => '10.5']]], 'events'],
            ['rol inexistente', 'PUT', $u, ['events' => [$withRole => ['enabled' => 1, 'role' => (string) \Illuminate\Support\Str::uuid()]]], 'events'],
            ['rol valido', 'PUT', $u, ['events' => [$withRole => ['enabled' => 1, 'role' => $role]]], null],
        ]);
    }

    public function test_perfil_and_password_forms(): void
    {
        $doctorEmail = 'doctor@dental.test';
        $u = '/configuracion/perfil';
        $ok = ['full_name' => 'Admin Demo', 'email' => 'admin@dental.test'];
        $c = fn (string $l, array $o, ?string $e) => [$l, 'PUT', $u, $o + $ok, $e];

        $this->runCases([
            $c('valido', [], null),
            $c('nombre vacio', ['full_name' => ''], 'full_name'),
            $c('nombre corto', ['full_name' => 'Al'], 'full_name'),
            $c('nombre 201', ['full_name' => str_repeat('n', 201)], 'full_name'),
            $c('email vacio', ['email' => ''], 'email'),
            $c('email malo', ['email' => 'x@'], 'email'),
            $c('email de otro usuario', ['email' => $doctorEmail], 'email'),
            $c('email de otro en MAYUSCULAS', ['email' => strtoupper($doctorEmail)], 'email'),
            $c('licencia 5 digitos', ['professional_license' => '12345'], 'professional_license'),
            $c('licencia letras', ['professional_license' => 'ABC12345'], 'professional_license'),
            $c('especialidad 121', ['specialty' => str_repeat('e', 121)], 'specialty'),
        ]);

        $pw = '/configuracion/perfil/password';
        $this->runCases([
            ['pass actual incorrecta', 'PUT', $pw, ['current_password' => 'mal', 'password' => 'Nuevo1234', 'password_confirmation' => 'Nuevo1234'], 'current_password'],
            ['pass sin confirmar', 'PUT', $pw, ['current_password' => 'password', 'password' => 'Nuevo1234', 'password_confirmation' => 'otra'], 'password'],
            ['pass corta', 'PUT', $pw, ['current_password' => 'password', 'password' => 'a1', 'password_confirmation' => 'a1'], 'password'],
            ['pass sin numeros', 'PUT', $pw, ['current_password' => 'password', 'password' => 'SoloLetras', 'password_confirmation' => 'SoloLetras'], 'password'],
            ['pass igual a la actual', 'PUT', $pw, ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'], 'password'],
            ['pass vacia', 'PUT', $pw, ['current_password' => 'password'], 'password'],
        ]);

        // Ruta Breeze alterna /password: no debe ser mas debil
        $this->runCases([
            ['breeze password debil (sin numeros ni mayus)', 'PUT', '/password', ['current_password' => 'password', 'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'], 'password'],
        ]);
    }

    public function test_usuarios_form(): void
    {
        $doctor = $this->roleId('doctor');
        $branch = $this->mainBranchId();
        $u = '/usuarios';
        $ok = ['full_name' => 'Laura Pérez Núñez', 'email' => 'laura@dental.test', 'role_id' => $doctor, 'branch_ids' => [$branch], 'is_doctor' => 0];
        $c = fn (string $l, array $o, ?string $e) => [$l, 'POST', $u, $o + $ok, $e];

        $this->runCases([
            $c('valido', [], null),
            $c('email duplicado', ['email' => 'laura@dental.test', 'full_name' => 'Otra Persona'], 'email'),
            $c('email duplicado en MAYUSCULAS', ['email' => 'LAURA@DENTAL.TEST', 'full_name' => 'Otra Persona'], 'email'),
            $c('email de admin existente', ['email' => 'admin@dental.test'], 'email'),
            $c('email malo', ['email' => 'laura@'], 'email'),
            $c('email 255', ['email' => str_repeat('a', 250).'@x.mx'], 'email'),
            $c('nombre vacio', ['full_name' => '', 'email' => 'v1@dental.test'], 'full_name'),
            $c('nombre con digitos', ['full_name' => 'Juan123', 'email' => 'v2@dental.test'], 'full_name'),
            $c('nombre con simbolos', ['full_name' => 'Juan <script>', 'email' => 'v3@dental.test'], 'full_name'),
            $c('nombre 201', ['full_name' => str_repeat('a', 201), 'email' => 'v4@dental.test'], 'full_name'),
            $c('rol inexistente', ['role_id' => (string) \Illuminate\Support\Str::uuid(), 'email' => 'v5@dental.test'], 'role_id'),
            $c('rol no uuid', ['role_id' => 'admin', 'email' => 'v6@dental.test'], 'role_id'),
            $c('sin sucursales', ['branch_ids' => [], 'email' => 'v7@dental.test'], 'branch_ids'),
            $c('sucursal inexistente', ['branch_ids' => [(string) \Illuminate\Support\Str::uuid()], 'email' => 'v8@dental.test'], 'branch_ids'),
            $c('sucursal repetida', ['branch_ids' => [$branch, $branch], 'email' => 'v9@dental.test'], 'branch_ids'),
            $c('doctor sin cedula', ['is_doctor' => 1, 'email' => 'v10@dental.test'], 'professional_license'),
            $c('doctor cedula 5 digitos', ['is_doctor' => 1, 'professional_license' => '12345', 'email' => 'v11@dental.test'], 'professional_license'),
            $c('doctor cedula valida', ['is_doctor' => 1, 'professional_license' => '1234567', 'email' => 'v12@dental.test'], null),
            $c('especialidad 121', ['specialty' => str_repeat('e', 121), 'email' => 'v13@dental.test'], 'specialty'),
        ]);
    }

    public function test_roles_form(): void
    {
        $u = '/roles';
        $this->runCases([
            ['rol valido', 'POST', $u, ['name' => 'Asistente de laboratorio', 'description' => 'Apoyo'], null],
            ['rol nombre duplicado', 'POST', $u, ['name' => 'Asistente de laboratorio'], 'name'],
            ['rol nombre duplicado minusculas', 'POST', $u, ['name' => 'asistente de laboratorio'], 'name'],
            ['rol nombre vacio', 'POST', $u, ['name' => ''], 'name'],
            ['rol nombre corto', 'POST', $u, ['name' => 'ab'], 'name'],
            ['rol nombre 101', 'POST', $u, ['name' => str_repeat('r', 101)], 'name'],
            ['rol descripcion 256', 'POST', $u, ['name' => 'Otro rol', 'description' => str_repeat('d', 256)], 'description'],
        ]);
    }
}
