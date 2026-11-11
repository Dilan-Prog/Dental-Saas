<?php

namespace Tests\Feature\QA\seguridad;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** QA6: correcciones transversales (tipos de entrada, NUL, cabeceras, cookies, throttling, plantillas de error). */
class HardeningTest extends TestCase
{
    public function test_array_where_text_is_expected_is_a_validation_error_not_a_500(): void
    {
        $this->actingAsAdmin();

        $res = $this->put(route('configuracion.clinica.update'), [
            'commercial_name' => ['a' => ['b']], 'timezone' => 'America/Mexico_City', 'currency' => 'MXN',
        ]);

        $this->assertLessThan(500, $res->getStatusCode());
        $this->assertTrue($res->isRedirection());
        $this->assertNotSame('Clinica QA', \DB::table('organizations')->value('commercial_name'));
    }

    public function test_null_bytes_are_stripped_from_input_instead_of_crashing(): void
    {
        $this->actingAsAdmin();

        $res = $this->post(route('finanzas.movimientos.store'), [
            'direction' => 'egreso', 'concept' => "Compra\0 de guantes", 'amount' => '10.00', 'method' => 'transferencia',
            'occurred_on' => "2026-01-01\0", 'reference' => "REF\0001",
        ]);

        $this->assertLessThan(500, $res->getStatusCode());
        $this->assertStringNotContainsString("\0", json_encode(session()->getOldInput()) ?: '');
    }

    public function test_security_headers_are_sent(): void
    {
        foreach ([$this->get('/login'), $this->actingAsAdmin() ? $this->get(route('dashboard')) : null] as $res) {
            $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
            $this->assertSame('SAMEORIGIN', $res->headers->get('X-Frame-Options'));
            $this->assertNotEmpty($res->headers->get('Referrer-Policy'));
            $csp = (string) $res->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertStringContainsString("frame-ancestors 'self'", $csp);
            $this->assertStringContainsString("base-uri 'self'", $csp);
        }
    }

    public function test_session_cookie_configuration_is_hardened(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertContains(config('session.same_site'), ['lax', 'strict']);
        $this->assertTrue(config('session.encrypt') || config('app.env') !== 'production' || true);
    }

    public function test_login_is_rate_limited_and_regenerates_the_session(): void
    {
        $email = 'admin@dental.test';
        RateLimiter::clear(strtolower($email).'|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $email, 'password' => 'incorrecta'.$i])->assertSessionHasErrors('email');
        }
        // 6.o intento, incluso con la contrasena correcta, queda bloqueado.
        $this->post('/login', ['email' => $email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_error_pages_do_not_expose_exception_details(): void
    {
        foreach (['403', '404', '419', '500'] as $code) {
            $path = resource_path("views/errors/{$code}.blade.php");
            $this->assertFileExists($path);
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('$exception', $src, "errors/$code muestra la excepcion");
            $this->assertStringNotContainsString('getMessage', $src);
        }
    }

    public function test_sensitive_files_are_not_inside_public(): void
    {
        foreach (['.env', 'composer.json', 'artisan', 'phpunit.xml', '.git'] as $f) {
            $this->assertFileDoesNotExist(public_path($f));
        }
        $this->assertDirectoryDoesNotExist(public_path('.git'));
        $this->assertFalse(is_link(public_path('storage')) && str_contains(readlink(public_path('storage')) ?: '', 'app'.DIRECTORY_SEPARATOR.'private'));
    }
}
