<?php

namespace Tests\Feature\Finanzas;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\FinancialCategory;
use App\Models\FinancialMovement;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finanzas\CashSessionService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Base de las pruebas de Finanzas: limpia la cache de organizacion y autentica como admin. */
abstract class FinanzasTestCase extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        Organization::flushCurrent();
        parent::setUp();
        Organization::flushCurrent();
        Storage::fake('local');
        $this->admin = $this->actingAsAdmin();
    }

    protected function tearDown(): void
    {
        Organization::flushCurrent();
        parent::tearDown();
    }

    protected function category(string $name = 'Insumos', string $direction = 'egreso', string $status = 'active'): FinancialCategory
    {
        return FinancialCategory::create(['name' => $name, 'direction' => $direction, 'status' => $status]);
    }

    protected function cashRegister(string $name = 'Caja principal'): CashRegister
    {
        return CashRegister::create(['branch_id' => $this->mainBranchId(), 'name' => $name, 'status' => 'active']);
    }

    protected function openCash(string $opening = '1000.00'): CashSession
    {
        return app(CashSessionService::class)->open($this->cashRegister(), $opening, $this->admin);
    }

    /** Movimiento manual directo en BD (sin pasar por el formulario). */
    protected function makeMovement(FinancialCategory $category, array $over = []): FinancialMovement
    {
        return FinancialMovement::create($over + [
            'branch_id' => $this->mainBranchId(),
            'direction' => $category->direction->value,
            'category_id' => $category->id,
            'concept' => 'Movimiento de prueba',
            'occurred_on' => now()->toDateString(),
            'amount' => '100.00',
            'currency' => 'MXN',
            'method' => 'tarjeta',
            'created_by' => $this->admin->id,
        ]);
    }

    /** Pago de paciente + su movimiento (solo lectura en Finanzas). */
    protected function makePaymentMovement(FinancialCategory $incomeCategory): FinancialMovement
    {
        $patient = Patient::create(['folio' => 'PAC-9001', 'full_name' => 'Paciente de Prueba']);
        $payment = Payment::create([
            'branch_id' => $this->mainBranchId(),
            'patient_id' => $patient->id,
            'paid_at' => now(),
            'amount' => '500.00',
            'method' => 'tarjeta',
            'idempotency_key' => 'test-'.uniqid(),
            'created_by' => $this->admin->id,
        ]);

        return $this->makeMovement($incomeCategory, ['concept' => 'Pago · Paciente de Prueba', 'amount' => '500.00', 'payment_id' => $payment->id]);
    }

    /** Datos validos de "Nueva transaccion". */
    protected function payload(FinancialCategory $category, array $over = []): array
    {
        return $over + [
            'submission_key' => (string) \Illuminate\Support\Str::uuid(),
            'direction' => $category->direction->value,
            'category_id' => $category->id,
            'method' => 'tarjeta',
            'concept' => 'Compra de material',
            'amount' => '1250.50',
            'occurred_on' => now()->toDateString(),
            'branch_id' => $this->mainBranchId(),
        ];
    }
}
