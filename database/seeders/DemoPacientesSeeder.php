<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Services\Pacientes\PatientService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Pacientes de demostración. Idempotente: un paciente ya existente (mismo
 * teléfono, incluso dado de baja) no se vuelve a crear.
 *
 * Cubre los casos del módulo: adultos con y sin doctor, un menor con tutor,
 * perfiles fiscales (persona física y público en general), un paciente
 * inactivo y alergias en el expediente.
 */
class DemoPacientesSeeder extends Seeder
{
    public function run(?PatientService $service = null): void
    {
        $service ??= app(PatientService::class);

        if (DB::table('organizations')->doesntExist()) {
            $this->command?->warn('DemoPacientesSeeder: ejecuta BaseSeeder primero.');

            return;
        }

        $doctors = $service->doctors()->pluck('id')->all();
        $pick = fn (int $i) => $doctors ? $doctors[$i % count($doctors)] : null;

        $rows = [
            // nombre, nacimiento, sexo, teléfono, correo, [fiscal], [tutor], alergias, inactivo
            ['Renata Aguilar Vega', '-26 years', 'female', '5599043367', 'renata.aguilar@correo.mx', ['AUVR990415AB3', 'Renata Aguilar Vega', '612', '06600', 'D01'], null, null, false],
            ['Sergio Naranjo Téllez', '-63 years', 'male', '5522456690', 'sergio.naranjo@correo.mx', null, null, 'Penicilina', false],
            ['Itzel Romero Fuentes', '-31 years', 'female', '5566789012', null, ['ROFI940820KT5', 'Itzel Romero Fuentes', '605', '03100', 'D01'], null, null, false],
            ['Marco Antonio Ibarra Salas', '-48 years', 'male', '5533417788', 'marco.ibarra@correo.mx', null, null, null, true],
            ['Valeria Domínguez Soto', '-34 years', 'female', '5544120981', 'valeria.dominguez@correo.mx', null, null, 'Látex', false],
            ['Roberto Mendoza Lira', '-41 years', 'male', '5587654321', 'roberto.mendoza@correo.mx', null, null, null, false],
            ['Patricio Guerra Méndez', '-29 years', 'male', '5512348765', 'patricio.guerra@correo.mx', ['XAXX010101000', 'PUBLICO EN GENERAL', '616', '06700', 'S01'], null, null, false],
            ['Daniela Vargas Pineda', '-22 years', 'female', '5578901234', 'daniela.vargas@correo.mx', null, null, null, false],
            ['Emilio Cárdenas Ruiz', '-37 years', 'male', '5590123456', null, null, null, null, false],
            ['Sofía Lozano Bernal', '-9 years', 'female', '5560001122', null, null, ['Mariana Bernal Ortiz', 'Madre', '5560001123', 'mariana.bernal@correo.mx'], null, false],
            ['Hugo Escobedo Prieto', '-55 years', 'male', '5543219876', 'hugo.escobedo@correo.mx', null, null, 'Ibuprofeno', false],
            ['Lucía Ferrer Montes', '-19 years', 'female', '5531122334', 'lucia.ferrer@correo.mx', null, null, null, false],
        ];

        foreach ($rows as $i => [$name, $age, $sex, $phone, $email, $fiscal, $guardian, $allergies, $inactive]) {
            if (Patient::withTrashed()->where('phone', $phone)->exists()) {
                continue;
            }

            $patient = $service->create(
                [
                    'full_name' => $name,
                    'birth_date' => now()->modify($age)->toDateString(),
                    'sex' => $sex,
                    'phone' => $phone,
                    'email' => $email,
                    // El último queda sin doctor para ejercitar el estado "Sin asignar".
                    'assigned_doctor_member_id' => $i === count($rows) - 1 ? null : $pick($i),
                ],
                $fiscal ? [
                    'rfc' => $fiscal[0], 'legal_name' => $fiscal[1], 'tax_regime_code' => $fiscal[2],
                    'fiscal_postal_code' => $fiscal[3], 'default_cfdi_use' => $fiscal[4],
                ] : null,
                $guardian ? [
                    'full_name' => $guardian[0], 'relationship' => $guardian[1], 'phone' => $guardian[2], 'email' => $guardian[3],
                ] : null,
            );

            if ($allergies) {
                $patient->medicalRecord?->update(['allergies' => $allergies]);
            }

            if ($inactive) {
                $patient->forceFill(['status' => 'inactive'])->save();
            }
        }
    }
}
