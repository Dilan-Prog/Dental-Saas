<?php

namespace Tests\Feature\Recetas;

use App\Enums\SigningStatus;
use App\Models\Medication;
use App\Models\Prescription;
use Database\Seeders\DemoRecetasSeeder;

class DemoRecetasSeederTest extends RecetasTestCase
{
    public function test_seeder_creates_coherent_demo_data_and_is_idempotent(): void
    {
        $this->seed(DemoRecetasSeeder::class);

        $this->assertSame(12, Medication::withoutGlobalScopes()->whereNull('organization_id')->count());
        $this->assertSame(4, Prescription::query()->count());
        $this->assertSame(3, Prescription::query()->where('status', SigningStatus::Firmada->value)->count());
        $this->assertSame(1, Prescription::query()->where('status', SigningStatus::Borrador->value)->count());

        foreach (Prescription::query()->with('items', 'patient', 'doctor')->get() as $rx) {
            $this->assertNotEmpty($rx->items, "{$rx->folio} sin medicamentos");
            $this->assertNotNull($rx->patient);
            $this->assertTrue($rx->doctor->is_active);
            $this->assertMatchesRegularExpression('/^RX-\d{4}$/', $rx->folio);
            $this->assertSame(now()->format('Y-m'), $rx->issued_on->format('Y-m'));
            if ($rx->isSigned()) {
                $this->assertTrue($this->service()->verifyIntegrity($rx), "{$rx->folio} hash inválido");
                $this->assertNotEmpty($rx->doctor_license_snapshot);
            }
        }
        // Folios únicos y consecutivos
        $folios = Prescription::query()->pluck('folio')->sort()->values()->all();
        $this->assertSame(['RX-0001', 'RX-0002', 'RX-0003', 'RX-0004'], $folios);

        // Segunda corrida: sin duplicados
        $this->seed(DemoRecetasSeeder::class);
        $this->assertSame(4, Prescription::query()->count());
        $this->assertSame(12, Medication::withoutGlobalScopes()->whereNull('organization_id')->count());

        // La pantalla muestra los KPIs coherentes con los datos
        $this->actingAsDoctor();
        $response = $this->get(route('recetas.index'))->assertOk();
        $this->assertSame(4, $response->viewData('kpis')['month']);
        $this->assertSame(3, $response->viewData('kpis')['signed']);
        $this->assertSame(1, $response->viewData('kpis')['drafts']);
    }
}
