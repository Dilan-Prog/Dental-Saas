<?php

namespace Tests\Feature\Expediente;

use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Utilidades comunes de las pruebas del módulo Expediente/Documentos. */
abstract class ExpedienteTestCase extends TestCase
{
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Disco de pruebas PROPIO del módulo (Storage::fake comparte carpeta entre procesos de prueba en paralelo).
        $this->diskRoot = storage_path('framework/testing/expediente-'.uniqid('', true));
        config(['filesystems.disks.local.root' => $this->diskRoot]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    protected function makePatient(array $attrs = []): Patient
    {
        static $n = 0;
        $n++;

        return Patient::query()->create($attrs + [
            'folio' => 'PAC-T'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).substr(uniqid(), -4),
            'full_name' => 'Paciente de Prueba '.$n,
            'birth_date' => '1990-05-10',
            'sex' => 'female',
            'phone' => '55 1234 5678',
            'email' => 'paciente'.$n.'@correo.mx',
        ]);
    }

    protected function makeTreatment(string $name = 'Resina compuesta'): Treatment
    {
        return Treatment::query()->create(['name' => $name, 'duration_minutes' => 30, 'price' => '800.00']);
    }

    protected function doctorUser(): User
    {
        return $this->userByEmail('doctor@dental.test');
    }

    protected function doctorMemberId(): string
    {
        return $this->memberId($this->doctorUser());
    }

    /** Nota simple borrador creada vía HTTP como el doctor. */
    protected function createDraftNote(Patient $patient, array $overrides = []): \App\Models\ClinicalNote
    {
        $this->actingAsDoctor();
        $this->post(route('expediente.notas.store', $patient->id), $overrides + [
            'note_type' => 'simple',
            'responsible_doctor_member_id' => $this->doctorMemberId(),
            'treatment_id' => $this->makeTreatment('Tratamiento '.uniqid())->id,
            'objective' => 'Procedimiento realizado sin complicaciones.',
            'action' => 'guardar',
        ])->assertSessionHasNoErrors();

        return \App\Models\ClinicalNote::query()->latest('created_at')->latest('id')->firstOrFail();
    }
}
