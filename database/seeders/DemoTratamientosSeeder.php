<?php

namespace Database\Seeders;

use App\Enums\RecordStatus;
use App\Models\TaxProfile;
use App\Models\Treatment;
use App\Models\TreatmentCategory;
use Illuminate\Database\Seeder;

/**
 * Catálogo demo de tratamientos (los del prototipo de Figma). Idempotente:
 * se identifica por nombre, no duplica ni pisa precios ya editados.
 */
class DemoTratamientosSeeder extends Seeder
{
    /** nombre => [categoría, duración (min), precio sin IVA] */
    public const CATALOG = [
        'Consulta de valoración' => ['Diagnóstico', 30, '450.00'],
        'Limpieza dental' => ['Preventiva', 45, '800.00'],
        'Resina (1 superficie)' => ['Restaurativa', 45, '950.00'],
        'Endodoncia unirradicular' => ['Endodoncia', 90, '3200.00'],
        'Extracción simple' => ['Cirugía', 40, '1200.00'],
        'Corona de porcelana' => ['Prótesis', 60, '6500.00'],
        'Implante dental' => ['Implantología', 90, '18000.00'],
        'Blanqueamiento' => ['Estética', 60, '2800.00'],
        'Brackets metálicos' => ['Ortodoncia', 60, '12000.00'],
        'Ajuste de ortodoncia' => ['Ortodoncia', 30, '600.00'],
    ];

    public function run(): void
    {
        $iva = TaxProfile::query()->where('is_exempt', false)->where('status', RecordStatus::Active->value)->orderBy('name')->first();

        foreach (self::CATALOG as $name => [$categoryName, $duration, $price]) {
            $category = TreatmentCategory::firstOrCreate(['name' => $categoryName]);

            Treatment::firstOrCreate(
                ['name' => $name],
                [
                    'category_id' => $category->id,
                    'duration_minutes' => $duration,
                    'sat_product_key' => '85121800',
                    'sat_unit_key' => 'E48',
                    'price' => $price,
                    'currency' => 'MXN',
                    'tax_profile_id' => $iva?->id,
                    'status' => RecordStatus::Active,
                ]
            );
        }
    }
}
