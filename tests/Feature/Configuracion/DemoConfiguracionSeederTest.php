<?php

namespace Tests\Feature\Configuracion;

use App\Services\Configuracion\ConfiguracionCatalog;
use Database\Seeders\DemoConfiguracionSeeder;
use Illuminate\Support\Facades\DB;

class DemoConfiguracionSeederTest extends ConfiguracionTestCase
{
    public function test_seeder_is_idempotent_and_coherent(): void
    {
        $this->seed(DemoConfiguracionSeeder::class);
        $snapshot = $this->snapshot();
        $this->seed(DemoConfiguracionSeeder::class);

        $this->assertSame($snapshot, $this->snapshot());
        $this->assertSame(1, DB::table('branches')->where('is_main', 1)->count());
        $this->assertSame(2, DB::table('branches')->count());
        $this->assertSame(count(ConfiguracionCatalog::notificationEvents()), DB::table('notification_preferences')->count());

        $this->assertDatabaseHas('organizations', ['commercial_name' => 'Clínica Dental Pro', 'rfc' => 'CUS200115AB3']);
        // Nunca se siembran referencias a secretos.
        $this->assertDatabaseHas('organization_fiscal_settings', ['csd_certificate_secret_ref' => null, 'csd_key_secret_ref' => null, 'pac_credentials_secret_ref' => null]);
        $this->assertDatabaseHas('folio_sequences', ['document_type' => 'invoice', 'series' => 'A', 'last_value' => 227]);
    }

    public function test_seeder_does_not_overwrite_user_edited_clinic_data(): void
    {
        DB::table('organizations')->update(['commercial_name' => 'Mi Consultorio Real']);

        $this->seed(DemoConfiguracionSeeder::class);

        $this->assertDatabaseHas('organizations', ['commercial_name' => 'Mi Consultorio Real']);
    }

    public function test_demo_doctors_are_assigned_to_branches_with_valid_profiles(): void
    {
        $this->seed(DemoConfiguracionSeeder::class);

        $perBranch = DB::table('member_branches as mb')
            ->join('organization_members as om', 'om.id', '=', 'mb.member_id')
            ->join('roles as r', 'r.id', '=', 'om.role_id')
            ->where('r.code', 'doctor')
            ->groupBy('mb.branch_id')->pluck(DB::raw('COUNT(*)'), 'mb.branch_id');

        $this->assertCount(2, $perBranch);
        $this->assertSame(0, DB::table('organization_members as om')
            ->join('roles as r', 'r.id', '=', 'om.role_id')->where('r.code', 'doctor')
            ->leftJoin('doctor_profiles as dp', 'dp.member_id', '=', 'om.id')->whereNull('dp.member_id')
            ->count());
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'members' => DB::table('organization_members')->count(),
            'profiles' => DB::table('doctor_profiles')->count(),
            'member_branches' => DB::table('member_branches')->count(),
            'prefs' => DB::table('notification_preferences')->count(),
            'sequences' => DB::table('folio_sequences')->count(),
        ];
    }
}
