<?php

namespace Tests\Feature\Pacientes;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Patient;
use App\Services\Pacientes\PatientService;
use Tests\Concerns\CreatesClinicUser;
use Tests\TestCase;

/** Utilidades comunes de las pruebas del módulo Pacientes. */
abstract class PacientesTestCase extends TestCase
{
    use CreatesClinicUser;

    protected function setUp(): void
    {
        parent::setUp(); // siembra BaseSeeder: cada prueba tiene una organización distinta

        // Organization::currentId() cachea el id en memoria: hay que olvidarlo por prueba.
        Organization::flushCurrent();
    }

    /** Datos válidos de alta; se pueden sobrescribir campos. */
    protected function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'full_name' => 'María Fernanda López Ortega',
            'birth_date' => now()->subYears(30)->toDateString(),
            'sex' => 'female',
            'phone' => '55 1234 5678',
            'email' => 'maria.lopez@correo.mx',
        ], $overrides);
    }

    protected function makePatient(array $overrides = [], ?array $fiscal = null, ?array $guardian = null): Patient
    {
        $data = $this->payload($overrides);
        $data['phone'] = preg_replace('/\D+/', '', $data['phone']);

        return app(PatientService::class)->create($data, $fiscal, $guardian);
    }

    protected function doctorMemberId(): string
    {
        return $this->memberId($this->userByEmail('doctor@dental.test'));
    }

    protected function makeAppointment(Patient $patient, string $status = 'pendiente', string $startsAt = '+2 days'): Appointment
    {
        $start = now()->modify($startsAt)->setTime(10, 0);

        return Appointment::create([
            'branch_id' => $this->mainBranchId(),
            'patient_id' => $patient->id,
            'doctor_member_id' => $this->doctorMemberId(),
            'starts_at' => $start,
            'ends_at' => (clone $start)->addHour(),
            'status' => $status,
        ]);
    }

    protected function makeInvoice(Patient $patient, string $total = '1000.00', string $fiscalStatus = 'timbrada'): Invoice
    {
        return Invoice::create([
            'internal_folio' => 'F-T'.random_int(1000, 9999),
            'patient_id' => $patient->id,
            'branch_id' => $this->mainBranchId(),
            'issuer_snapshot' => ['rfc' => 'XAXX010101000'],
            'receiver_snapshot' => ['rfc' => 'XAXX010101000'],
            'cfdi_use' => 'D01',
            'cfdi_payment_method' => 'PPD',
            'cfdi_payment_form' => '99',
            'subtotal' => $total,
            'total' => $total,
            'fiscal_status' => $fiscalStatus,
        ]);
    }
}
