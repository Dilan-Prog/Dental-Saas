<?php

namespace Tests\Feature\Configuracion;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SucursalesTest extends ConfiguracionTestCase
{
    private function branchData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sucursal Sur',
            'address_line' => 'Insurgentes Sur 1800, CDMX',
            'phone' => '55 9876 5432',
        ], $overrides);
    }

    private function createBranch(array $attributes = []): Branch
    {
        return Branch::query()->create(array_merge(['name' => 'Sucursal Norte', 'status' => 'active', 'is_main' => false], $attributes));
    }

    private function mainBranch(): Branch
    {
        return Branch::query()->where('is_main', true)->firstOrFail();
    }

    private function futureAppointment(Branch $branch, string $status = 'pendiente'): void
    {
        $org = $this->organizationId();
        $patientId = (string) Str::uuid();

        DB::table('patients')->insert([
            'id' => $patientId, 'organization_id' => $org, 'folio' => 'PAC-'.random_int(1000, 9999), 'full_name' => 'Paciente de prueba',
        ]);

        $doctor = $this->memberId($this->userByEmail('doctor@dental.test'));

        DB::table('appointments')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $org, 'branch_id' => $branch->id, 'patient_id' => $patientId,
            'doctor_member_id' => $doctor, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => $status,
        ]);
    }

    public function test_only_the_owner_can_manage_branches(): void
    {
        $this->get(route('configuracion.sucursales'))->assertRedirect(route('login'));

        $this->actingAsReceptionist();
        $this->get(route('configuracion.sucursales'))->assertForbidden();

        $this->actingAsDoctor();
        $this->post(route('configuracion.sucursales.store'), $this->branchData())->assertForbidden();

        $this->actingAsAdmin();
        $this->get(route('configuracion.sucursales'))->assertOk()->assertSee('Sucursales')->assertSee('Principal')->assertSee('Nueva sucursal');
    }

    public function test_a_branch_can_be_created_with_normalized_phone(): void
    {
        $this->actingAsAdmin();

        $this->post(route('configuracion.sucursales.store'), $this->branchData(['name' => '  Sucursal Sur  ']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.sucursales'));

        $this->assertDatabaseHas('branches', ['name' => 'Sucursal Sur', 'phone' => '5598765432', 'status' => 'active', 'is_main' => 0]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'branch', 'action' => 'insert']);
    }

    /** @dataProvider invalidBranches */
    public function test_invalid_branch_data_is_rejected(array $override, string $field): void
    {
        $this->actingAsAdmin();

        $this->post(route('configuracion.sucursales.store'), $this->branchData($override))->assertSessionHasErrors($field);
        $this->assertSame(1, Branch::query()->count());
    }

    public static function invalidBranches(): array
    {
        return [
            'nombre vacío' => [['name' => ''], 'name'],
            'nombre duplicado (case-insensitive)' => [['name' => 'clínica centro'], 'name'],
            'nombre largo' => [['name' => str_repeat('a', 151)], 'name'],
            'teléfono inválido' => [['phone' => '123'], 'phone'],
            'dirección larga' => [['address_line' => str_repeat('x', 301)], 'address_line'],
            'responsable inexistente' => [['manager_user_id' => '00000000-0000-4000-8000-000000000000'], 'manager_user_id'],
            'responsable no uuid' => [['manager_user_id' => 'abc'], 'manager_user_id'],
        ];
    }

    public function test_manager_must_be_an_active_member(): void
    {
        $this->actingAsAdmin();
        $doctor = $this->userByEmail('doctor@dental.test');

        $this->post(route('configuracion.sucursales.store'), $this->branchData(['manager_user_id' => $doctor->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('branches', ['name' => 'Sucursal Sur', 'manager_user_id' => $doctor->id]);

        DB::table('organization_members')->where('user_id', $doctor->id)->update(['is_active' => 0]);
        $this->post(route('configuracion.sucursales.store'), $this->branchData(['name' => 'Otra']))->assertSessionHasNoErrors();
        $this->post(route('configuracion.sucursales.store'), $this->branchData(['name' => 'Tercera', 'manager_user_id' => $doctor->id]))
            ->assertSessionHasErrors('manager_user_id');
    }

    public function test_creating_a_main_branch_demotes_the_previous_one(): void
    {
        $this->actingAsAdmin();
        $previous = $this->mainBranch();

        $this->post(route('configuracion.sucursales.store'), $this->branchData(['is_main' => 1]))->assertSessionHasNoErrors();

        $this->assertSame(1, Branch::query()->where('is_main', true)->count());
        $this->assertFalse($previous->fresh()->is_main);
        $this->assertSame('Sucursal Sur', $this->mainBranch()->name);
    }

    public function test_branch_can_be_updated_and_name_uniqueness_ignores_itself(): void
    {
        $this->actingAsAdmin();
        $branch = $this->createBranch();

        $this->put(route('configuracion.sucursales.update', $branch), $this->branchData(['name' => 'Sucursal Norte', 'address_line' => 'Nueva dirección 1']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'address_line' => 'Nueva dirección 1']);

        $this->put(route('configuracion.sucursales.update', $branch), $this->branchData(['name' => 'Clínica Centro']))
            ->assertSessionHasErrors('name');
    }

    public function test_the_main_branch_cannot_lose_its_flag_or_be_deactivated(): void
    {
        $this->actingAsAdmin();
        $main = $this->mainBranch();

        $this->put(route('configuracion.sucursales.update', $main), ['name' => $main->name])->assertSessionHasErrors('is_main');
        $this->assertTrue($main->fresh()->is_main);

        $this->patch(route('configuracion.sucursales.status', $main), ['status' => 'inactive'])->assertSessionHasErrors('status');
        $this->assertSame('active', $main->fresh()->status->value);
    }

    public function test_editing_the_main_branch_keeps_it_main(): void
    {
        $this->actingAsAdmin();
        $main = $this->mainBranch();

        $this->put(route('configuracion.sucursales.update', $main), ['name' => $main->name, 'address_line' => 'Otra', 'is_main' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($main->fresh()->is_main);
    }

    public function test_main_branch_can_be_reassigned(): void
    {
        $this->actingAsAdmin();
        $old = $this->mainBranch();
        $new = $this->createBranch();

        $this->patch(route('configuracion.sucursales.main', $new))->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertTrue($new->fresh()->is_main);
        $this->assertFalse($old->fresh()->is_main);
        $this->assertSame(1, Branch::query()->where('is_main', true)->count());
    }

    public function test_inactive_branch_cannot_become_main(): void
    {
        $this->actingAsAdmin();
        $inactive = $this->createBranch(['status' => 'inactive']);

        $this->patch(route('configuracion.sucursales.main', $inactive))->assertSessionHasErrors('status');
        $this->put(route('configuracion.sucursales.update', $inactive), $this->branchData(['name' => 'Sucursal Norte', 'is_main' => 1]))->assertSessionHasErrors('is_main');
        $this->assertFalse($inactive->fresh()->is_main);
    }

    public function test_branch_with_upcoming_appointments_cannot_be_deactivated(): void
    {
        $this->actingAsAdmin();
        $branch = $this->createBranch();
        $this->futureAppointment($branch, 'confirmada');

        $this->patch(route('configuracion.sucursales.status', $branch), ['status' => 'inactive'])->assertSessionHasErrors('status');
        $this->assertSame('active', $branch->fresh()->status->value);
    }

    public function test_cancelled_appointments_do_not_block_deactivation_and_branch_can_be_reactivated(): void
    {
        $this->actingAsAdmin();
        $branch = $this->createBranch();
        $this->futureAppointment($branch, 'cancelada');

        $this->patch(route('configuracion.sucursales.status', $branch), ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame('inactive', $branch->fresh()->status->value);

        $this->patch(route('configuracion.sucursales.status', $branch), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('active', $branch->fresh()->status->value);
    }

    public function test_invalid_status_value_is_rejected(): void
    {
        $this->actingAsAdmin();
        $branch = $this->createBranch();

        $this->patch(route('configuracion.sucursales.status', $branch), ['status' => 'borrada'])->assertSessionHasErrors('status');
        $this->patch(route('configuracion.sucursales.status', $branch), [])->assertSessionHasErrors('status');
    }

    public function test_unknown_branch_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->put(route('configuracion.sucursales.update', (string) Str::uuid()), $this->branchData())->assertNotFound();
    }

    public function test_listing_shows_doctor_counts_per_branch(): void
    {
        $this->actingAsAdmin();
        $branch = $this->createBranch();
        $doctor = $this->memberId($this->userByEmail('doctor@dental.test'));
        DB::table('member_branches')->insert(['organization_id' => $this->organizationId(), 'member_id' => $doctor, 'branch_id' => $branch->id]);

        $this->get(route('configuracion.sucursales'))->assertOk()->assertSee('Sucursal Norte');
        $this->assertSame(1, DB::table('member_branches')->where('branch_id', $branch->id)->count());
    }
}
