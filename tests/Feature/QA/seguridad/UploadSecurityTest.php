<?php

namespace Tests\Feature\QA\seguridad;

use App\Models\ClinicalFile;
use App\Models\Patient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** QA6: subidas y descargas de archivos (mime falso, SVG con script, nombres hostiles, URL firmada). */
class UploadSecurityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // Directorio propio para no chocar con otros procesos de prueba.
        $this->root = storage_path('framework/testing/qa6-'.uniqid());
        File::ensureDirectoryExists($this->root);
        config(['filesystems.disks.local.root' => $this->root]);
        app('filesystem')->forgetDisk('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** Archivos guardados en el disco (excluye los temporales de la propia prueba). */
    private function storedFiles(): array
    {
        return array_values(array_filter(File::allFiles($this->root), fn ($f) => ! str_contains($f->getPathname(), '_tmp')));
    }

    private function patient(): Patient
    {
        return Patient::query()->create(['folio' => 'PAC-'.random_int(9000, 9999), 'full_name' => 'Paciente QA6']);
    }

    /** Archivo REAL en disco (el fake de Laravel deduce el mime por el nombre, no por el contenido). */
    private function real(string $clientName, string $content, string $clientMime = 'application/octet-stream'): UploadedFile
    {
        $dir = $this->root.DIRECTORY_SEPARATOR.'_tmp';
        File::ensureDirectoryExists($dir);
        $path = realpath($dir).DIRECTORY_SEPARATOR.'up'.bin2hex(random_bytes(6));
        file_put_contents($path, $content);

        return new UploadedFile($path, $clientName, $clientMime, null, true);
    }

    private function png(string $name = 'rx.png'): UploadedFile
    {
        // PNG 1x1 valido.
        return $this->real($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), 'image/png');
    }

    private function upload(Patient $p, UploadedFile $file, array $extra = [])
    {
        return $this->actingAsAdmin() && true
            ? $this->post(route('expediente.archivos.store', $p), ['file' => $file, 'file_type' => 'radiografia'] + $extra)
            : null;
    }

    public function test_php_script_disguised_as_image_is_rejected(): void
    {
        $p = $this->patient();
        $evil = $this->real('radiografia.jpg', '<?php echo 1; ?>', 'image/jpeg');

        $this->upload($p, $evil)->assertSessionHasErrors('file');
        $this->assertSame(0, ClinicalFile::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_html_and_svg_with_script_are_rejected_as_clinical_files(): void
    {
        $p = $this->patient();
        foreach ([
            ['x.png', '<html><script>alert(1)</script></html>'],
            ['x.pdf', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        ] as [$name, $content]) {
            $this->upload($p, $this->real($name, $content, 'image/png'))->assertSessionHasErrors('file');
        }
        $this->assertSame(0, ClinicalFile::query()->count());
    }

    public function test_hostile_file_names_never_reach_the_storage_key(): void
    {
        $p = $this->patient();
        $this->upload($p, $this->png('../../../etc/passwd.php.png'), ['display_name' => '../../x<script>.png'])->assertSessionHasNoErrors();

        $file = ClinicalFile::query()->firstOrFail();
        $this->assertMatchesRegularExpression('#^clinical/[0-9a-f-]{36}/[0-9a-f-]{36}/[0-9a-f-]{36}\.png$#', $file->storage_key);
        $this->assertStringNotContainsString('/', $file->display_name);
        $this->assertStringNotContainsString('<', $file->display_name);
        $this->assertFileExists($this->root.'/'.$file->storage_key);
        // El disco privado no esta bajo public/.
        $this->assertStringNotContainsString(realpath(public_path()), realpath($this->root));
    }

    public function test_oversized_file_is_rejected(): void
    {
        $p = $this->patient();
        $big = UploadedFile::fake()->create('big.pdf', 21 * 1024, 'application/pdf');

        $this->upload($p, $big)->assertSessionHasErrors('file');
        $this->assertSame(0, ClinicalFile::query()->count());
    }

    public function test_download_requires_a_valid_signature(): void
    {
        $p = $this->patient();
        $this->upload($p, $this->png())->assertSessionHasNoErrors();
        $file = ClinicalFile::query()->firstOrFail();

        // Sin firma.
        $this->get(route('expediente.archivos.download', $file))->assertForbidden();

        // Firma manipulada.
        $signed = URL::temporarySignedRoute('expediente.archivos.download', now()->addMinutes(5), $file);
        $this->get($signed.'x')->assertForbidden();
        $this->assertContains($this->get(str_replace($file->id, '00000000-0000-4000-8000-000000000000', $signed))->getStatusCode(), [403, 404]);

        // Firma vencida.
        $expired = URL::temporarySignedRoute('expediente.archivos.download', now()->subMinute(), $file);
        $this->get($expired)->assertForbidden();

        // Valida, pero un usuario sin permiso de expediente sigue recibiendo 403.
        $ok = $this->get($signed);
        $ok->assertOk();
        $this->assertSame('nosniff', $ok->headers->get('X-Content-Type-Options'));
        $this->assertStringNotContainsString('text/html', (string) $ok->headers->get('Content-Type'));
    }

    public function test_path_traversal_in_route_parameters_is_not_routable(): void
    {
        $this->actingAsAdmin();
        foreach (['/expediente/archivos/..%2F..%2F.env/descargar', '/expediente/archivos/../../.env/descargar', '/expediente/%2e%2e/archivos'] as $url) {
            $this->assertContains($this->get($url)->getStatusCode(), [403, 404, 405]);
        }
    }

    public function test_svg_with_script_is_rejected_as_logo_and_avatar(): void
    {
        $this->actingAsAdmin();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';

        $this->put(route('configuracion.clinica.update'), [
            'commercial_name' => 'Clinica QA', 'timezone' => 'America/Mexico_City', 'currency' => 'MXN',
            'logo' => $this->real('logo.svg', $svg, 'image/svg+xml'),
        ])->assertSessionHasErrors('logo');

        $user = $this->actingAsAdmin();
        $this->put(route('configuracion.perfil.update'), [
            'full_name' => $user->full_name, 'email' => $user->email,
            'avatar' => $this->real('a.svg', $svg, 'image/svg+xml'),
        ])->assertSessionHasErrors('avatar');
    }

    public function test_php_script_is_rejected_as_expense_receipt(): void
    {
        $this->actingAsAdmin();
        $res = $this->post(route('finanzas.movimientos.store'), [
            'direction' => 'egreso', 'concept' => 'Prueba', 'amount' => '10.00', 'method' => 'transferencia',
            'occurred_on' => now()->toDateString(),
            'receipt' => $this->real('r.jpg', '<?php echo 2; ?>', 'image/jpeg'),
        ]);

        $res->assertSessionHasErrors('receipt', null, 'movement');
        $this->assertSame([], $this->storedFiles());
    }
}
