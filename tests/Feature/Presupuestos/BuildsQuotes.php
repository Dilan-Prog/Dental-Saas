<?php

namespace Tests\Feature\Presupuestos;

use App\Enums\RecordStatus;
use App\Models\Patient;
use App\Models\Quote;
use App\Models\TaxProfile;
use App\Models\Treatment;
use App\Models\TreatmentCategory;
use App\Services\Presupuestos\QuoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ayudas para pruebas de presupuestos y tratamientos (datos mínimos y coherentes).
 */
trait BuildsQuotes
{
    protected function doctorMemberId(): string
    {
        return (string) DB::table('organization_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('u.email', 'doctor@dental.test')
            ->value('m.id');
    }

    protected function adminMemberId(): string
    {
        return (string) DB::table('organization_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('u.email', 'admin@dental.test')
            ->value('m.id');
    }

    protected function makePatient(array $attributes = []): Patient
    {
        return Patient::create($attributes + [
            'folio' => 'PAC-T'.Str::upper(Str::random(6)),
            'full_name' => 'Paciente de Prueba '.Str::random(4),
            'sex' => 'unspecified',
            'status' => RecordStatus::Active,
        ]);
    }

    protected function makeTreatment(array $attributes = []): Treatment
    {
        $iva = TaxProfile::query()->where('name', 'IVA 16%')->firstOrFail();

        return Treatment::create($attributes + [
            'category_id' => TreatmentCategory::firstOrCreate(['name' => 'General'])->id,
            'name' => 'Tratamiento '.Str::random(6),
            'duration_minutes' => 30,
            'sat_product_key' => '85121800',
            'sat_unit_key' => 'E48',
            'price' => '800.00',
            'currency' => 'MXN',
            'tax_profile_id' => $iva->id,
            'status' => RecordStatus::Active,
        ]);
    }

    /** Payload válido de un presupuesto con una línea (sobrescribible por clave). */
    protected function quotePayload(?Patient $patient = null, ?Treatment $treatment = null, array $override = []): array
    {
        $patient ??= $this->makePatient();
        $treatment ??= $this->makeTreatment();

        return array_replace_recursive([
            'patient_id' => $patient->id,
            'doctor_member_id' => $this->doctorMemberId(),
            'branch_id' => $this->mainBranchId(),
            'quote_date' => now()->toDateString(),
            'items' => [[
                'treatment_id' => $treatment->id,
                'tooth_fdi' => '',
                'quantity' => '1',
                'unit_price' => (string) $treatment->price,
                'discount_pct' => '0',
            ]],
        ], $override);
    }

    /** Crea un presupuesto en borrador con el servicio real. */
    protected function makeQuote(?Patient $patient = null, ?array $items = null): Quote
    {
        $payload = $this->quotePayload($patient);
        if ($items !== null) {
            $payload['items'] = $items;
        }

        return app(QuoteService::class)->create($payload, null);
    }
}
