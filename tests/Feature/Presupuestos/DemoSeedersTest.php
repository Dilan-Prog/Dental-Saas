<?php

namespace Tests\Feature\Presupuestos;

use App\Models\PatientTreatment;
use App\Models\Quote;
use App\Models\Treatment;
use Database\Seeders\DemoPresupuestosSeeder;
use Database\Seeders\DemoTratamientosSeeder;
use Tests\TestCase;

/**
 * Los datos demo deben ser coherentes (mismos totales que calcula la aplicación).
 */
class DemoSeedersTest extends TestCase
{
    public function test_demo_catalog_is_idempotent(): void
    {
        $this->seed(DemoTratamientosSeeder::class);
        $this->seed(DemoTratamientosSeeder::class);

        $this->assertSame(count(DemoTratamientosSeeder::CATALOG), Treatment::query()->count());
        $this->assertSame(0, Treatment::query()->whereNull('tax_profile_id')->count());
        $this->assertSame(0, Treatment::query()->where('price', '<=', 0)->count());
    }

    public function test_demo_quotes_are_consistent(): void
    {
        $this->seed(DemoPresupuestosSeeder::class);

        $quotes = Quote::query()->with('items')->get();
        $this->assertCount(5, $quotes);
        $this->assertEqualsCanonicalizing(
            ['borrador', 'enviado', 'aprobado', 'aprobado', 'rechazado'],
            $quotes->map(fn ($q) => $q->status->value)->all()
        );

        foreach ($quotes as $quote) {
            $this->assertEqualsWithDelta((float) $quote->total, (float) $quote->subtotal - (float) $quote->discount_total + (float) $quote->tax_total, 0.001, $quote->folio);
            $this->assertEqualsWithDelta((float) $quote->total, $quote->items->sum(fn ($i) => (float) $i->line_total), 0.001, $quote->folio);
            $this->assertEqualsWithDelta((float) $quote->tax_total, $quote->items->sum(fn ($i) => (float) $i->line_tax), 0.001, $quote->folio);
            $this->assertGreaterThan(0, (float) $quote->total);
            $this->assertNotNull($quote->patient_id);
            $this->assertNotNull($quote->doctor_member_id);
        }

        $approvedItems = $quotes->filter(fn ($q) => $q->status->value === 'aprobado')->flatMap->items->count();
        $this->assertSame($approvedItems, PatientTreatment::query()->count());
    }

    public function test_demo_quotes_seeder_is_idempotent(): void
    {
        $this->seed(DemoPresupuestosSeeder::class);
        $this->seed(DemoPresupuestosSeeder::class);

        $this->assertSame(5, Quote::query()->count());
    }
}
