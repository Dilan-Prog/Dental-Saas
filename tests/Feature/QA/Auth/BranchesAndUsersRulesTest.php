<?php

namespace Tests\Feature\QA\Auth;

use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QA: sucursales (principal unica, desactivacion con citas) y usuarios/doctores (firma, cedula, sucursales).
 */
class BranchesAndUsersRulesTest extends TestCase
{
    private function admin(): static
    {
        Organization::flushCurrent();
        $this->flushSession();

        return $this->actingAsAdmin() ? $this : $this;
    }

    private function newBranch(string $name = 'Sucursal Norte'): string
    {
        $this->admin()->post('/configuracion/sucursales', ['name' => $name, 'phone' => '5511223344'])->assertSessionHasNoErrors();

        return (string) DB::table('branches')->where('name', $name)->value('id');
    }

    public function test_only_one_main_branch_and_main_switch_is_atomic(): void
    {
        $second = $this->newBranch();
        $main = $this->mainBranchId();

        $this->admin()->patch("/configuracion/sucursales/{$second}/principal")->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('branches')->where('is_main', 1)->where('status', 'active')->count());
        $this->assertSame(1, (int) DB::table('branches')->where('id', $second)->value('is_main'));
        $this->assertSame(0, (int) DB::table('branches')->where('id', $main)->value('is_main'));
    }

    public function test_cannot_create_a_second_main_branch_via_store(): void
    {
        $this->admin()->post('/configuracion/sucursales', ['name' => 'Otra Principal', 'is_main' => 1]);

        $this->assertSame(1, DB::table('branches')->where('organization_id', $this->organizationId())->where('is_main', 1)->where('status', 'active')->count());
    }

    public function test_cannot_deactivate_main_branch_or_one_with_future_appointments(): void
    {
        $second = $this->newBranch('Sucursal Sur');
        $main = $this->mainBranchId();

        $this->admin()->patch("/configuracion/sucursales/{$main}/estado");
        $this->assertSame('active', DB::table('branches')->where('id', $main)->value('status'));

        // cita futura en la sucursal secundaria
        $org = $this->organizationId();
        $doctor = $this->memberId($this->userByEmail('doctor@dental.test'));
        $patient = (string) Str::uuid();
        DB::table('patients')->insert(['id' => $patient, 'organization_id' => $org, 'folio' => 'PAC-9001', 'full_name' => 'Paciente QA', 'sex' => 'unspecified', 'status' => 'active']);
        DB::table('appointments')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $org, 'branch_id' => $second, 'patient_id' => $patient,
            'doctor_member_id' => $doctor, 'starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(11, 0), 'status' => 'pendiente',
        ]);

        $this->admin()->patch("/configuracion/sucursales/{$second}/estado");
        $this->assertSame('active', DB::table('branches')->where('id', $second)->value('status'), 'No se debe desactivar una sucursal con citas proximas');
    }

    public function test_doctor_signature_rules_and_private_storage(): void
    {
        Storage::fake('local');
        $doctorRole = (string) DB::table('roles')->where('code', 'doctor')->value('id');
        $branch = $this->mainBranchId();
        $base = ['full_name' => 'Doctor Nuevo', 'email' => 'drnuevo@dental.test', 'role_id' => $doctorRole, 'branch_ids' => [$branch], 'is_doctor' => 1, 'professional_license' => '1234567'];

        foreach ([
            'php' => UploadedFile::fake()->createWithContent('f.png', '<?php echo 1;'),
            'pdf' => UploadedFile::fake()->create('f.pdf', 10, 'application/pdf'),
            '2MB' => UploadedFile::fake()->image('f.png', 400, 200)->size(2000),
            'enorme' => UploadedFile::fake()->image('f.png', 3000, 1500),
        ] as $label => $file) {
            $this->admin()->from('/usuarios')->post('/usuarios', ['signature' => $file] + $base)->assertSessionHasErrors();
            $this->assertSame(0, DB::table('users')->where('email', 'drnuevo@dental.test')->count(), "Firma invalida aceptada: {$label}");
        }

        $this->admin()->post('/usuarios', ['signature' => UploadedFile::fake()->image('f.png', 400, 200)] + $base)->assertSessionHasNoErrors();
        $memberId = DB::table('organization_members')->join('users', 'users.id', '=', 'organization_members.user_id')->where('users.email', 'drnuevo@dental.test')->value('organization_members.id');
        $profile = DB::table('doctor_profiles')->where('member_id', $memberId)->first();
        $this->assertNotNull($profile);
        $this->assertSame('1234567', $profile->professional_license);
        $this->assertNotEmpty($profile->signature_image_storage_key);
        $this->assertStringNotContainsString('f.png', $profile->signature_image_storage_key);
    }

    public function test_non_doctor_toggle_and_branch_assignment_are_persisted(): void
    {
        $recepRole = (string) DB::table('roles')->where('code', 'recepcion')->value('id');
        $second = $this->newBranch('Sucursal Este');
        $main = $this->mainBranchId();

        $this->admin()->post('/usuarios', [
            'full_name' => 'Rosa Sánchez', 'email' => 'rosa@dental.test', 'role_id' => $recepRole,
            'branch_ids' => [$main, $second], 'is_doctor' => 0,
        ])->assertSessionHasNoErrors();

        $member = (string) DB::table('organization_members')->join('users', 'users.id', '=', 'organization_members.user_id')->where('users.email', 'rosa@dental.test')->value('organization_members.id');
        $this->assertSame(2, DB::table('member_branches')->where('member_id', $member)->count());
        $this->assertSame(0, DB::table('doctor_profiles')->where('member_id', $member)->count());

        // quitar una sucursal en la edicion
        $this->admin()->put("/usuarios/{$member}", [
            'full_name' => 'Rosa Sánchez', 'email' => 'rosa@dental.test', 'role_id' => $recepRole, 'branch_ids' => [$main], 'is_doctor' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('member_branches')->where('member_id', $member)->count());

        // el usuario invitado queda inactivo hasta aceptar
        $this->assertSame(0, (int) DB::table('users')->where('email', 'rosa@dental.test')->value('is_active'));
    }

    public function test_role_change_is_audited_and_validates_target_role(): void
    {
        $this->admin();
        $recep = $this->userByEmail('recepcion@dental.test');
        $member = $this->memberId($recep);
        $doctorRole = (string) DB::table('roles')->where('code', 'doctor')->value('id');
        $main = $this->mainBranchId();

        $this->admin()->put("/usuarios/{$member}", [
            'full_name' => 'Recepcion Demo', 'email' => 'recepcion@dental.test', 'role_id' => $doctorRole, 'branch_ids' => [$main],
            'is_doctor' => 1, 'professional_license' => '7654321',
        ])->assertSessionHasNoErrors();

        $this->assertSame($doctorRole, DB::table('organization_members')->where('id', $member)->value('role_id'));
        $this->assertGreaterThan(0, DB::table('role_change_history')->where('target_member_id', $member)->count(), 'El cambio de rol debe quedar en el historial');
    }
}
