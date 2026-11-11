<?php

namespace Tests\Feature\Presupuestos;

use App\Models\Quote;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Validación de formularios y de dominio: nada inválido llega a la base de datos.
 */
class QuoteValidationTest extends TestCase
{
    use BuildsQuotes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    private function assertRejected(array $payload, string|array $errorKeys): void
    {
        $this->post(route('presupuestos.store'), $payload)->assertSessionHasErrors($errorKeys);
        $this->assertSame(0, Quote::query()->count(), 'No debe crearse ningún presupuesto con datos inválidos.');
    }

    public function test_required_header_fields(): void
    {
        $this->assertRejected(['items' => []], ['patient_id', 'doctor_member_id', 'branch_id', 'quote_date', 'items']);
    }

    public function test_patient_must_exist_and_be_active(): void
    {
        $this->assertRejected($this->quotePayload(null, null, ['patient_id' => (string) Str::uuid()]), 'patient_id');
        $this->assertRejected($this->quotePayload(null, null, ['patient_id' => 'no-es-uuid']), 'patient_id');
        $this->assertRejected($this->quotePayload($this->makePatient(['status' => 'inactive'])), 'patient_id');
        $this->assertRejected($this->quotePayload($this->makePatient(['anonymized_at' => now()])), 'patient_id');

        $deleted = $this->makePatient();
        $deleted->delete();
        $this->assertRejected($this->quotePayload($deleted), 'patient_id');
    }

    public function test_doctor_must_be_an_active_doctor_of_the_clinic(): void
    {
        $this->assertRejected($this->quotePayload(null, null, ['doctor_member_id' => (string) Str::uuid()]), 'doctor_member_id');
        // La recepcionista es miembro activo pero no doctor.
        $receptionist = (string) \DB::table('organization_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('u.email', 'recepcion@dental.test')->value('m.id');
        $this->assertRejected($this->quotePayload(null, null, ['doctor_member_id' => $receptionist]), 'doctor_member_id');
    }

    public function test_branch_must_exist_and_be_active(): void
    {
        $this->assertRejected($this->quotePayload(null, null, ['branch_id' => (string) Str::uuid()]), 'branch_id');

        $inactive = (string) Str::uuid();
        \DB::table('branches')->insert(['id' => $inactive, 'organization_id' => $this->organizationId(), 'name' => 'Cerrada', 'status' => 'inactive']);
        $this->assertRejected($this->quotePayload(null, null, ['branch_id' => $inactive]), 'branch_id');
    }

    public function test_date_must_be_valid_and_reasonable(): void
    {
        $this->assertRejected($this->quotePayload(null, null, ['quote_date' => '31/12/2026']), 'quote_date');
        $this->assertRejected($this->quotePayload(null, null, ['quote_date' => '2026-02-30']), 'quote_date');
        $this->assertRejected($this->quotePayload(null, null, ['quote_date' => now()->addYears(2)->toDateString()]), 'quote_date');
        $this->assertRejected($this->quotePayload(null, null, ['quote_date' => '1990-01-01']), 'quote_date');
    }

    public function test_treatment_must_exist_and_be_active(): void
    {
        $this->assertRejected($this->quotePayload(null, null, ['items' => [['treatment_id' => (string) Str::uuid()]]]), 'items.0.treatment_id');

        $inactive = $this->makeTreatment(['status' => 'inactive']);
        $this->assertRejected($this->quotePayload(null, $inactive), 'items.0.treatment_id');
    }

    /** @dataProvider invalidAmounts */
    public function test_invalid_line_amounts_are_rejected(string $field, string $value): void
    {
        $this->assertRejected($this->quotePayload(null, null, ['items' => [[$field => $value]]]), "items.0.{$field}");
    }

    public static function invalidAmounts(): array
    {
        return [
            'cantidad cero' => ['quantity', '0'],
            'cantidad negativa' => ['quantity', '-1'],
            'cantidad texto' => ['quantity', 'abc'],
            'cantidad con coma' => ['quantity', '1,5'],
            'cantidad 5 decimales' => ['quantity', '1.12345'],
            'cantidad enorme' => ['quantity', '100001'],
            'precio negativo' => ['unit_price', '-5'],
            'precio texto' => ['unit_price', 'gratis'],
            'precio 5 decimales' => ['unit_price', '10.12345'],
            'precio enorme' => ['unit_price', '100000000'],
            'descuento mayor a 100' => ['discount_pct', '100.01'],
            'descuento negativo' => ['discount_pct', '-1'],
            'descuento 3 decimales' => ['discount_pct', '10.123'],
        ];
    }

    /** @dataProvider invalidTeeth */
    public function test_tooth_must_follow_fdi_numbering(string $tooth): void
    {
        $this->assertRejected($this->quotePayload(null, null, ['items' => [['tooth_fdi' => $tooth]]]), 'items.0.tooth_fdi');
    }

    public static function invalidTeeth(): array
    {
        return [['0'], ['9'], ['19'], ['29'], ['56'], ['66'], ['86'], ['99'], ['4a'], ['1.5']];
    }

    public function test_valid_permanent_and_deciduous_teeth_are_accepted(): void
    {
        foreach (['11', '48', '55', '85'] as $tooth) {
            $this->post(route('presupuestos.store'), $this->quotePayload(null, null, ['items' => [['tooth_fdi' => $tooth]]]))
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(4, Quote::query()->count());
    }

    public function test_dollar_sign_and_spaces_are_tolerated_in_amounts(): void
    {
        $treatment = $this->makeTreatment();
        $this->post(route('presupuestos.store'), $this->quotePayload(null, $treatment, [
            'items' => [['unit_price' => ' $1200.50 ', 'quantity' => ' 2 ']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('1200.5000', (string) Quote::query()->firstOrFail()->items->first()->unit_price);
    }

    public function test_too_many_lines_are_rejected(): void
    {
        $treatment = $this->makeTreatment();
        $items = array_fill(0, 101, ['treatment_id' => $treatment->id, 'quantity' => '1', 'unit_price' => '1', 'discount_pct' => '0']);

        $this->assertRejected($this->quotePayload(null, $treatment, ['items' => $items]), 'items');
    }

    public function test_validation_failure_keeps_user_input(): void
    {
        $payload = $this->quotePayload(null, null, ['items' => [['quantity' => '0']]]);

        $this->from(route('presupuestos.create'))->post(route('presupuestos.store'), $payload)
            ->assertRedirect(route('presupuestos.create'))
            ->assertSessionHasInput('patient_id', $payload['patient_id']);
    }
}
