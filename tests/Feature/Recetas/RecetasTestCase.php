<?php

namespace Tests\Feature\Recetas;

use App\Enums\RecordStatus;
use App\Enums\SexType;
use App\Models\MedicalRecord;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Recetas\PrescriptionService;
use App\Support\Folio;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base de las pruebas del módulo Recetas: fixtures reutilizables.
 * Cada prueba corre en transacción y con BaseSeeder (organización, roles, doctor, folios).
 */
abstract class RecetasTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // La organización cambia de id en cada prueba: se olvida la caché en memoria.
        Organization::flushCurrent();
    }

    protected function service(): PrescriptionService
    {
        return app(PrescriptionService::class);
    }

    protected function makePatient(array $attrs = [], ?string $allergies = null): Patient
    {
        $patient = Patient::create($attrs + [
            'folio' => Folio::next(Folio::PATIENT),
            'full_name' => 'Valeria Domínguez Soto',
            'birth_date' => '1992-02-14',
            'sex' => SexType::Female,
            'phone' => '5512345678',
            'email' => 'valeria@example.com',
            'status' => RecordStatus::Active,
        ]);
        MedicalRecord::create(['patient_id' => $patient->id, 'allergies' => $allergies]);

        return $patient;
    }

    protected function doctorUser(): User
    {
        return $this->userByEmail('doctor@dental.test');
    }

    protected function doctorMemberId(): string
    {
        return $this->memberId($this->doctorUser());
    }

    /** Renglón de medicamento válido. */
    protected function item(array $over = []): array
    {
        return $over + [
            'medication_name' => 'Ibuprofeno 400 mg',
            'presentation' => 'Tabletas',
            'dose' => '1 tableta',
            'frequency' => 'cada 8 horas',
            'duration' => '3 días',
            'route' => 'Oral',
            'instructions' => 'Tomar después de los alimentos.',
        ];
    }

    /** Datos del formulario (válidos por omisión). */
    protected function payload(Patient $patient, array $over = []): array
    {
        return $over + [
            'action' => 'borrador',
            'patient_id' => $patient->id,
            'doctor_member_id' => $this->doctorMemberId(),
            'issued_on' => now()->toDateString(),
            'diagnosis' => 'Caries dental en 26 sin compromiso pulpar',
            'general_instructions' => 'Acudir a control en 30 días.',
            'items' => [$this->item()],
        ];
    }

    protected function makeDraft(?Patient $patient = null, array $over = []): Prescription
    {
        $patient ??= $this->makePatient();
        $data = $this->payload($patient, $over);
        unset($data['action']);

        return $this->service()->save(null, $data);
    }

    protected function makeSigned(?Patient $patient = null, array $over = []): Prescription
    {
        $rx = $this->makeDraft($patient, $over);

        return $this->service()->sign($rx, $this->doctorUser());
    }

    protected function makeMedication(array $over = []): Medication
    {
        return Medication::create($over + [
            'organization_id' => Organization::currentId(),
            'name' => 'Paracetamol 500 mg',
            'active_ingredient' => 'Paracetamol',
            'strength' => '500 mg',
            'presentation' => 'Tabletas',
            'status' => RecordStatus::Active,
        ]);
    }

    protected function makeGlobalMedication(array $over = []): Medication
    {
        return Medication::withoutGlobalScopes()->create($over + [
            'organization_id' => null,
            'name' => 'Amoxicilina 500 mg',
            'active_ingredient' => 'Amoxicilina',
            'strength' => '500 mg',
            'presentation' => 'Cápsulas',
            'status' => RecordStatus::Active,
        ]);
    }

    protected function rxCount(): int
    {
        return (int) DB::table('prescriptions')->count();
    }
}
