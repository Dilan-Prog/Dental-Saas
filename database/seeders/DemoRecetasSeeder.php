<?php

namespace Database\Seeders;

use App\Enums\RecordStatus;
use App\Enums\SexType;
use App\Models\MedicalRecord;
use App\Models\Medication;
use App\Models\OrganizationMember;
use App\Models\Patient;
use App\Models\Prescription;
use App\Services\Recetas\PrescriptionService;
use App\Support\Folio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Datos demo del módulo Recetas (idempotente):
 *  - Vademécum global (organization_id NULL) con medicamentos de uso odontológico.
 *  - 4 recetas como en el Figma: 3 firmadas y 1 borrador, del mes en curso.
 * Reutiliza los pacientes demo por nombre; si no existen los crea (con su expediente).
 */
class DemoRecetasSeeder extends Seeder
{
    public function run(PrescriptionService $service): void
    {
        $this->seedMedications();

        $doctors = OrganizationMember::query()->active()->whereHas('doctorProfile')->with('user', 'doctorProfile')->get()
            ->filter(fn ($m) => filled($m->doctorProfile?->professional_license))->values();

        if ($doctors->isEmpty()) {
            $this->command?->warn('DemoRecetasSeeder: no hay doctores con cédula; se omiten las recetas.');

            return;
        }

        $defs = [
            [
                'patient' => ['Renata Aguilar Vega', 'female', '1991-04-18'],
                'dx' => 'Pericoronitis en 48',
                'days_ago' => 8, 'signed' => false,
                'general' => 'Higiene cuidadosa de la zona. Acudir a valoración para la extracción del tercer molar.',
                'items' => [
                    ['Amoxicilina 500 mg', '500 mg', 'Cápsulas', '1 cápsula', 'cada 8 horas', '7 días', 'Oral', 'Tomar con abundante agua.'],
                    ['Ibuprofeno 400 mg', '400 mg', 'Tabletas', '1 tableta', 'cada 8 horas', '3 días', 'Oral', 'Tomar después de los alimentos.'],
                ],
            ],
            [
                'patient' => ['Daniela Vargas Pineda', 'female', '1998-09-02'],
                'dx' => 'Gingivitis leve generalizada',
                'days_ago' => 6, 'signed' => true,
                'general' => 'Cepillado suave tres veces al día. Uso de hilo dental diario.',
                'items' => [
                    ['Clorhexidina 0.12%', '0.12%', 'Enjuague', '15 ml', 'cada 12 horas', '7 días', 'Enjuague bucal', 'Enjuagar 30 segundos sin diluir. No comer ni beber 30 min después.'],
                ],
            ],
            [
                'patient' => ['Patricio Guerra Méndez', 'male', '1985-11-23'],
                'dx' => 'Absceso periapical en 36',
                'days_ago' => 3, 'signed' => true,
                'general' => 'Dieta blanda. Acudir de inmediato si hay fiebre o inflamación facial.',
                'items' => [
                    ['Amoxicilina con ácido clavulánico 875/125 mg', '875/125 mg', 'Tabletas', '1 tableta', 'cada 12 horas', '7 días', 'Oral', 'Tomar con alimentos.'],
                    ['Ketorolaco 10 mg', '10 mg', 'Tabletas', '1 tableta', 'cada 8 horas', '3 días', 'Oral', 'Solo si hay dolor intenso. Máximo 5 días.'],
                ],
            ],
            [
                'patient' => ['Valeria Domínguez Soto', 'female', '1992-02-14'],
                'dx' => 'Caries dental en 26 sin compromiso pulpar',
                'days_ago' => 1, 'signed' => true,
                'general' => 'Acudir a control en 30 días. Evitar alimentos muy fríos las primeras 48 h.',
                'items' => [
                    ['Ibuprofeno 400 mg', '400 mg', 'Tabletas', '1 tableta', 'cada 8 horas', '3 días', 'Oral', 'Tomar después de los alimentos. Suspender si hay molestia gástrica.'],
                    ['Clorhexidina 0.12%', '0.12%', 'Enjuague', '15 ml', 'cada 12 horas', '7 días', 'Enjuague bucal', 'Enjuagar 30 segundos sin diluir. No comer ni beber 30 min después.'],
                ],
            ],
        ];

        $monthStart = Carbon::now()->startOfMonth();

        foreach ($defs as $n => $def) {
            [$name, $sex, $birth] = $def['patient'];
            $patient = $this->patient($name, $sex, $birth);
            $doctor = $doctors[$n % $doctors->count()];

            $issued = Carbon::now()->subDays($def['days_ago']);
            if ($issued->lessThan($monthStart)) {
                $issued = $monthStart->copy();
            }

            // Idempotencia: misma persona, diagnóstico y fecha de emisión.
            $exists = Prescription::query()
                ->where('patient_id', $patient->id)
                ->where('diagnosis', $def['dx'])
                ->whereDate('issued_on', $issued->toDateString())
                ->exists();
            if ($exists) {
                continue;
            }

            DB::transaction(function () use ($def, $patient, $doctor, $issued, $service) {
                $items = array_map(function (array $i) {
                    $med = Medication::query()->where('name', $i[0])->whereNull('organization_id')->first();

                    return [
                        'medication_id' => $med?->id,
                        'medication_name' => $i[0],
                        'strength' => $i[1],
                        'presentation' => $i[2],
                        'dose' => $i[3],
                        'frequency' => $i[4],
                        'duration' => $i[5],
                        'route' => $i[6],
                        'instructions' => $i[7],
                    ];
                }, $def['items']);

                $rx = $service->save(null, [
                    'patient_id' => $patient->id,
                    'doctor_member_id' => $doctor->id,
                    'issued_on' => $issued->toDateString(),
                    'diagnosis' => $def['dx'],
                    'general_instructions' => $def['general'],
                    'items' => $items,
                ]);

                if ($def['signed']) {
                    $service->assertReadyToSign($rx);
                    $rx = $service->applySignature($rx);
                    // La receta más reciente ya se entregó impresa.
                    if ($def['days_ago'] === 1) {
                        $service->send($rx, 'impresion', null);
                    }
                }
            });
        }
    }

    private function patient(string $name, string $sex, string $birth): Patient
    {
        $patient = Patient::withTrashed()->where('full_name', $name)->first();
        if ($patient) {
            return $patient;
        }

        $patient = Patient::create([
            'folio' => Folio::next(Folio::PATIENT),
            'full_name' => $name,
            'birth_date' => $birth,
            'phone' => '55'.str_pad((string) (crc32($name) % 100000000), 8, '0', STR_PAD_LEFT),
            'sex' => SexType::from($sex),
            'status' => RecordStatus::Active,
        ]);
        MedicalRecord::firstOrCreate(['patient_id' => $patient->id]);

        return $patient;
    }

    private function seedMedications(): void
    {
        $catalog = [
            ['Ibuprofeno 400 mg', 'Ibuprofeno', '400 mg', 'Tabletas'],
            ['Paracetamol 500 mg', 'Paracetamol', '500 mg', 'Tabletas'],
            ['Naproxeno 550 mg', 'Naproxeno sódico', '550 mg', 'Tabletas'],
            ['Ketorolaco 10 mg', 'Ketorolaco trometamina', '10 mg', 'Tabletas'],
            ['Diclofenaco 50 mg', 'Diclofenaco sódico', '50 mg', 'Tabletas'],
            ['Amoxicilina 500 mg', 'Amoxicilina', '500 mg', 'Cápsulas'],
            ['Amoxicilina con ácido clavulánico 875/125 mg', 'Amoxicilina / ácido clavulánico', '875/125 mg', 'Tabletas'],
            ['Clindamicina 300 mg', 'Clindamicina', '300 mg', 'Cápsulas'],
            ['Azitromicina 500 mg', 'Azitromicina', '500 mg', 'Tabletas'],
            ['Metronidazol 500 mg', 'Metronidazol', '500 mg', 'Tabletas'],
            ['Clorhexidina 0.12%', 'Digluconato de clorhexidina', '0.12%', 'Enjuague'],
            ['Dexametasona 8 mg', 'Dexametasona', '8 mg', 'Solución inyectable'],
        ];

        foreach ($catalog as [$name, $ingredient, $strength, $presentation]) {
            $exists = Medication::withoutGlobalScopes()
                ->whereNull('organization_id')->where('name', $name)->exists();
            if ($exists) {
                continue;
            }
            Medication::withoutGlobalScopes()->create([
                'organization_id' => null,
                'name' => $name,
                'active_ingredient' => $ingredient,
                'strength' => $strength,
                'presentation' => $presentation,
                'status' => RecordStatus::Active,
            ]);
        }
    }
}
