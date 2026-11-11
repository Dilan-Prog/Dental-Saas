<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\DoctorProfile;
use App\Models\FolioSequence;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\OrganizationFiscalSetting;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Services\Configuracion\ConfiguracionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos demo de Configuración: datos de la clínica, datos fiscales (sin
 * secretos), segunda sucursal, doctores asignados a sucursales y preferencias
 * de notificación. Idempotente y respetuoso con lo que el usuario ya editó.
 */
class DemoConfiguracionSeeder extends Seeder
{
    public function run(): void
    {
        Organization::flushCurrent();
        $org = Organization::current();

        if (! $org) {
            $this->command?->warn('Sin organización: ejecuta BaseSeeder primero.');

            return;
        }

        DB::transaction(function () use ($org) {
            $this->clinic($org);
            $branches = $this->branches();
            $this->doctors($branches);
            $this->notificationPreferences();
        });
    }

    private function clinic(Organization $org): void
    {
        $fiscal = OrganizationFiscalSetting::query()->firstOrNew(['organization_id' => $org->id]);

        // Solo se completa si sigue con los valores por defecto del BaseSeeder:
        // nunca se pisan los datos que el usuario ya capturó.
        if ($org->commercial_name === 'Clínica Dental') {
            $org->forceFill([
                'commercial_name' => 'Clínica Dental Pro',
                'legal_name' => 'Clínica Dental Pro S.A. de C.V.',
                'rfc' => 'CUS200115AB3',
                'phone' => '5512345678',
                'email' => 'contacto@clinicadentalpro.mx',
                'address_line' => 'Av. Reforma 222, CDMX',
                'address_postal_code' => '06600',
            ])->save();

            // RFC de persona moral => régimen 601 (coherente con el RFC anterior).
            $fiscal->fill([
                'tax_regime_code' => '601',
                'fiscal_postal_code' => '06600',
                'pac_provider' => 'Finkok',
                'default_series' => 'A',
                // Sin referencias a secretos: el timbrado queda "pendiente" hasta configurarlas.
            ])->save();
        } elseif (! $fiscal->exists) {
            $fiscal->fill(['default_series' => 'A'])->save();
        }

        // Folio inicial 228 para la serie A de facturas (Figma): último emitido = 227.
        FolioSequence::query()->firstOrCreate(
            ['document_type' => 'invoice', 'series' => 'A'],
            ['prefix' => 'F-', 'last_value' => 227],
        );
    }

    /** @return array{centro: Branch, sur: Branch} */
    private function branches(): array
    {
        $centro = Branch::query()->where('is_main', true)->first() ?? Branch::query()->orderBy('created_at')->firstOrFail();

        if (! $centro->address_line) {
            $centro->address_line = 'Av. Reforma 222, CDMX';
            $centro->phone ??= '5512345678';
            $centro->save();
        }

        $sur = Branch::query()->firstOrCreate(
            ['name' => 'Sucursal Sur'],
            ['address_line' => 'Insurgentes Sur 1800, CDMX', 'phone' => '5598765432', 'status' => 'active', 'is_main' => false],
        );

        return ['centro' => $centro, 'sur' => $sur];
    }

    /** @param array{centro: Branch, sur: Branch} $branches */
    private function doctors(array $branches): void
    {
        $doctorRole = Role::query()->where('code', 'doctor')->first();

        if (! $doctorRole) {
            return;
        }

        $demo = [
            ['email' => 'lucia.fonseca@dental.test', 'name' => 'Dra. Lucía Fonseca', 'license' => '8123456', 'specialty' => 'Odontología general', 'branches' => ['centro']],
            ['email' => 'carlos.mendez@dental.test', 'name' => 'Dr. Carlos Méndez', 'license' => '9234567', 'specialty' => 'Endodoncia', 'branches' => ['sur']],
        ];

        foreach ($demo as $row) {
            $user = User::query()->firstOrCreate(
                ['email' => $row['email']],
                ['full_name' => $row['name'], 'password' => 'password', 'is_active' => true],
            );

            $member = OrganizationMember::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['role_id' => $doctorRole->id, 'is_active' => true],
            );

            DoctorProfile::query()->firstOrCreate(
                ['member_id' => $member->id],
                ['organization_id' => $member->organization_id, 'professional_license' => $row['license'], 'specialty' => $row['specialty']],
            );

            foreach ($row['branches'] as $key) {
                $this->assign($member, $branches[$key]);
            }
        }

        // El doctor base atiende en ambas sucursales.
        $baseDoctor = User::query()->where('email', 'doctor@dental.test')->first();
        $baseMember = $baseDoctor ? OrganizationMember::query()->where('user_id', $baseDoctor->id)->first() : null;

        if ($baseMember) {
            $this->assign($baseMember, $branches['centro']);
            $this->assign($baseMember, $branches['sur']);
        }
    }

    private function assign(OrganizationMember $member, Branch $branch): void
    {
        DB::table('member_branches')->insertOrIgnore([
            'organization_id' => $member->organization_id,
            'member_id' => $member->id,
            'branch_id' => $branch->id,
        ]);
    }

    private function notificationPreferences(): void
    {
        $roles = Role::query()->pluck('id', 'code');

        foreach (ConfiguracionCatalog::notificationEvents() as $event => $meta) {
            NotificationPreference::query()->firstOrCreate(
                ['event_type' => $event, 'channel' => $meta['channel']],
                [
                    // WhatsApp requiere una integración externa: queda apagado de inicio.
                    'is_enabled' => $meta['channel'] !== 'whatsapp',
                    'lead_time_minutes' => $meta['lead']['default'] ?? null,
                    'recipient_role_id' => ! empty($meta['default_role']) ? ($roles[$meta['default_role']] ?? null) : null,
                ],
            );
        }
    }
}
