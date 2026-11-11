<?php

namespace Database\Seeders;

use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Quote;
use App\Models\Treatment;
use App\Services\Presupuestos\QuoteLookups;
use App\Services\Presupuestos\QuoteService;
use App\Support\Folio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Presupuestos demo en distintos estados, creados con el mismo servicio que usa la
 * aplicación (totales, folios, historial y plan de tratamiento coherentes).
 * Idempotente: no hace nada si ya existen presupuestos.
 */
class DemoPresupuestosSeeder extends Seeder
{
    public function run(): void
    {
        if (Quote::query()->exists()) {
            return;
        }

        $this->call(DemoTratamientosSeeder::class);

        $lookups = app(QuoteLookups::class);
        $service = app(QuoteService::class);

        $doctors = $lookups->doctors()->keys()->values();
        $branchId = Branch::query()->where('is_main', true)->value('id') ?? $lookups->branches()->keys()->first();

        if ($doctors->isEmpty() || ! $branchId) {
            return; // Sin doctor o sucursal no hay a quién asignar los presupuestos.
        }

        $treatments = Treatment::query()->pluck('id', 'name');

        // [paciente, líneas [tratamiento, diente, cantidad, descuento %], estado final, días atrás]
        $plans = [
            ['Jorge Salinas Cano', [['Corona de porcelana', 46, 2, 0], ['Consulta de valoración', null, 1, 0]], 'enviado', 7],
            ['Valeria Domínguez Soto', [['Brackets metálicos', null, 1, 0], ['Limpieza dental', null, 2, 10]], 'aprobado', 9],
            ['Patricio Guerra Méndez', [['Endodoncia unirradicular', 36, 1, 0], ['Resina (1 superficie)', 26, 4, 5]], 'borrador', 12],
            ['Sergio Naranjo Téllez', [['Blanqueamiento', null, 1, 15]], 'rechazado', 16],
            ['Fernanda Lozano Cruz', [['Extracción simple', 38, 1, 0], ['Ajuste de ortodoncia', null, 2, 0]], 'aprobado', 19],
        ];

        foreach ($plans as $i => [$patientName, $lines, $finalStatus, $daysAgo]) {
            $patient = $this->patient($patientName);

            $items = [];
            foreach ($lines as [$treatmentName, $tooth, $qty, $discount]) {
                $treatmentId = $treatments[$treatmentName] ?? null;
                if (! $treatmentId) {
                    continue 2;
                }
                $price = (string) Treatment::query()->find($treatmentId)->price;
                $items[] = [
                    'treatment_id' => $treatmentId,
                    'tooth_fdi' => $tooth,
                    'quantity' => (string) $qty,
                    'unit_price' => $price,
                    'discount_pct' => (string) $discount,
                ];
            }

            $quote = $service->create([
                'patient_id' => $patient->id,
                'doctor_member_id' => $doctors[$i % $doctors->count()],
                'branch_id' => $branchId,
                'quote_date' => now()->subDays($daysAgo)->toDateString(),
                'items' => $items,
            ], null);

            if (in_array($finalStatus, ['enviado', 'aprobado', 'rechazado'], true)) {
                $service->send($quote, null);
            }
            if ($finalStatus === 'aprobado') {
                $service->approve($quote, null);
            }
            if ($finalStatus === 'rechazado') {
                $service->reject($quote, null);
            }
        }
    }

    /** Reutiliza al paciente si ya existe (lo siembra el módulo Pacientes) o lo crea mínimo. */
    private function patient(string $name): Patient
    {
        $existing = Patient::query()->where('full_name', $name)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($name) {
            $patient = Patient::create([
                'folio' => Folio::next(Folio::PATIENT),
                'full_name' => $name,
                'sex' => 'unspecified',
                'status' => RecordStatus::Active,
            ]);

            MedicalRecord::firstOrCreate(['patient_id' => $patient->id]);

            return $patient;
        });
    }
}
