<?php

namespace Tests\Feature\Expediente;

use App\Models\ClinicalFile;
use App\Services\Expediente\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ClinicalFileTest extends ExpedienteTestCase
{
    private function upload($patient, $file, array $extra = [])
    {
        return $this->post(route('expediente.archivos.store', $patient->id), $extra + [
            'file' => $file, 'file_type' => 'radiografia',
        ]);
    }

    public function test_valid_image_is_stored_privately_with_hash_and_unique_key(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->upload($patient, UploadedFile::fake()->image('panoramica.jpg', 200, 200), ['display_name' => 'Panorámica'])
            ->assertRedirect()->assertSessionHas('success');

        $file = ClinicalFile::query()->firstOrFail();
        $this->assertSame('Panorámica', $file->display_name);
        $this->assertSame('radiografia', $file->file_type->value);
        $this->assertSame(64, strlen($file->sha256));
        $this->assertSame($this->doctorMemberId(), $file->uploaded_by_member_id);
        $this->assertStringStartsWith('clinical/', $file->storage_key);
        Storage::disk('local')->assertExists($file->storage_key);
        $this->assertTrue(app(FileService::class)->verifyIntegrity($file));
    }

    public function test_pdf_is_accepted(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->upload($patient, UploadedFile::fake()->create('consentimiento.pdf', 120, 'application/pdf'), ['file_type' => 'consentimiento'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, ClinicalFile::query()->count());
    }

    public function test_invalid_types_and_sizes_are_rejected(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->upload($patient, UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'))->assertSessionHasErrors('file');
        $this->upload($patient, UploadedFile::fake()->create('texto.txt', 10, 'text/plain'))->assertSessionHasErrors('file');
        $this->upload($patient, UploadedFile::fake()->create('enorme.pdf', 21000, 'application/pdf'))->assertSessionHasErrors('file');
        $this->upload($patient, UploadedFile::fake()->image('ok.png'), ['file_type' => 'invalido'])->assertSessionHasErrors('file_type');
        $this->post(route('expediente.archivos.store', $patient->id), ['file_type' => 'otro'])->assertSessionHasErrors('file');

        $this->assertSame(0, ClinicalFile::query()->count());
    }

    public function test_file_with_wrong_content_for_its_extension_is_rejected(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        // Archivo real (no el fake de Laravel, que informa el MIME por la extensión): el contenido es PHP.
        $path = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($path, '<?php echo "hola"; ?>');
        $fake = new UploadedFile($path, 'falsa.png', 'image/png', null, true);

        $this->upload($patient, $fake)->assertSessionHasErrors('file');
        @unlink($path);
    }

    public function test_download_requires_a_valid_signed_url(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $this->upload($patient, UploadedFile::fake()->image('foto.png'), ['file_type' => 'fotografia_intraoral']);
        $file = ClinicalFile::query()->firstOrFail();

        $this->get(route('expediente.archivos.download', $file->id))->assertForbidden();

        $url = app(FileService::class)->signedUrl($file);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get(app(FileService::class)->signedUrl($file, true))->assertOk();
    }

    public function test_signed_url_does_not_work_for_guests(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $this->upload($patient, UploadedFile::fake()->image('foto.png'));
        $url = app(FileService::class)->signedUrl(ClinicalFile::query()->firstOrFail());

        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_soft_delete_keeps_the_physical_file_and_hides_it(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();
        $this->upload($patient, UploadedFile::fake()->image('foto.jpg'));
        $file = ClinicalFile::query()->firstOrFail();

        $this->delete(route('expediente.archivos.destroy', [$patient->id, $file->id]))->assertRedirect()->assertSessionHas('success');

        $this->assertSoftDeleted('clinical_files', ['id' => $file->id]);
        Storage::disk('local')->assertExists($file->storage_key);
        $this->assertSame(0, ClinicalFile::query()->count());
    }

    public function test_file_of_another_patient_cannot_be_deleted_through_this_patient(): void
    {
        $a = $this->makePatient();
        $b = $this->makePatient();
        $this->actingAsAdmin();
        $this->upload($a, UploadedFile::fake()->image('foto.jpg'));
        $file = ClinicalFile::query()->firstOrFail();

        $this->delete(route('expediente.archivos.destroy', [$b->id, $file->id]))->assertNotFound();
    }

    public function test_filters_and_gallery_render(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();
        $this->upload($patient, UploadedFile::fake()->image('rx.jpg'), ['display_name' => 'Radiografía A']);
        $this->upload($patient, UploadedFile::fake()->image('foto.jpg'), ['display_name' => 'Foto B', 'file_type' => 'fotografia_intraoral']);

        $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => 'archivos']))->assertOk()->assertSee('Radiografía A')->assertSee('Foto B');
        $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => 'archivos', 'tipo' => 'radiografias']))
            ->assertOk()->assertSee('Radiografía A')->assertDontSee('Foto B');
    }

    public function test_receptionist_cannot_upload(): void
    {
        $patient = $this->makePatient();
        $this->actingAsReceptionist();

        $this->upload($patient, UploadedFile::fake()->image('x.jpg'))->assertForbidden();
    }
}
