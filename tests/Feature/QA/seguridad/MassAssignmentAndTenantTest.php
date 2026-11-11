<?php

namespace Tests\Feature\QA\seguridad;

use App\Models\BaseModel;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\FinancialCategory;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA6: asignacion masiva (los modelos usan $guarded = []) y aislamiento de organizacion. */
class MassAssignmentAndTenantTest extends TestCase
{
    public function test_patient_store_ignores_privileged_extra_fields(): void
    {
        $this->actingAsAdmin();
        $foreignOrg = (string) Str::uuid();
        $fixedId = (string) Str::uuid();

        $this->post(route('pacientes.store'), [
            'full_name' => 'Maria Lopez Hernandez', 'birth_date' => '1990-05-05', 'sex' => 'female', 'phone' => '5512345678',
            'id' => $fixedId, 'organization_id' => $foreignOrg, 'folio' => 'HACK-1', 'status' => 'inactive',
            'deleted_at' => now()->toDateTimeString(), 'anonymized_at' => now()->toDateTimeString(),
            'created_at' => '2001-01-01 00:00:00',
        ])->assertSessionHasNoErrors();

        $p = Patient::withoutGlobalScopes()->where('full_name', 'Maria Lopez Hernandez')->firstOrFail();
        $this->assertNotSame($fixedId, $p->id);
        $this->assertSame($this->organizationId(), $p->organization_id);
        $this->assertMatchesRegularExpression('/^PAC-\d+$/', $p->folio);
        $this->assertSame('active', $p->status->value);
        $this->assertNull($p->deleted_at);
        $this->assertNull($p->anonymized_at);
        $this->assertStringStartsNotWith('2001', (string) $p->created_at);
    }

    public function test_role_store_cannot_create_a_system_admin_role(): void
    {
        $this->actingAsAdmin();

        $this->post(route('roles.store'), [
            'name' => 'Rol Pirata', 'description' => 'x',
            'is_system_admin' => 1, 'code' => 'admin_sistema', 'status' => 'inactive', 'organization_id' => (string) Str::uuid(),
        ]);

        $role = Role::withoutGlobalScopes()->where('name', 'Rol Pirata')->first();
        $this->assertNotNull($role);
        $this->assertFalse((bool) $role->is_system_admin);
        $this->assertSame($this->organizationId(), $role->organization_id);
        $this->assertNotSame('admin_sistema', $role->code);
        $this->assertSame('active', $role->status->value);
    }

    public function test_supplier_and_category_store_ignore_organization_and_status(): void
    {
        $this->actingAsAdmin();
        $this->post(route('inventario.proveedores.store'), [
            'name' => 'Proveedor Fantasma', 'organization_id' => (string) Str::uuid(), 'status' => 'inactive',
        ]);
        $s = Supplier::withoutGlobalScopes()->where('name', 'Proveedor Fantasma')->first();
        $this->assertNotNull($s);
        $this->assertSame($this->organizationId(), $s->organization_id);
        $this->assertSame('active', $s->status->value);

        $this->post(route('finanzas.categorias.store'), [
            'name' => 'Categoria Fantasma', 'direction' => 'egreso', 'organization_id' => (string) Str::uuid(), 'status' => 'inactive',
        ]);
        $c = FinancialCategory::withoutGlobalScopes()->where('name', 'Categoria Fantasma')->first();
        $this->assertNotNull($c);
        $this->assertSame($this->organizationId(), $c->organization_id);
        $this->assertSame('active', $c->status->value);
    }

    public function test_profile_update_cannot_escalate_role_or_flip_flags(): void
    {
        $user = $this->actingAsReceptionist();
        $roleBefore = DB::table('organization_members')->where('user_id', $user->id)->value('role_id');
        $adminRole = DB::table('roles')->where('code', 'admin_sistema')->value('id');

        $this->put(route('configuracion.perfil.update'), [
            'full_name' => 'Recepcion Renombrada', 'email' => $user->email,
            'role_id' => $adminRole, 'is_active' => 0, 'password' => 'Hacked#12345', 'is_system_admin' => 1,
        ]);

        $user->refresh();
        $this->assertSame($roleBefore, DB::table('organization_members')->where('user_id', $user->id)->value('role_id'));
        $this->assertTrue((bool) $user->is_active);
        $this->assertFalse(\Hash::check('Hacked#12345', (string) $user->password));
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = $this->actingAsReceptionist();

        $this->put(route('configuracion.perfil.password'), [
            'current_password' => 'incorrecta', 'password' => 'NuevaClave#2026x', 'password_confirmation' => 'NuevaClave#2026x',
        ])->assertSessionHasErrors();

        $this->assertTrue(\Hash::check('password', (string) $user->fresh()->password));
    }

    public function test_non_admin_cannot_touch_roles_or_users(): void
    {
        $this->actingAsReceptionist();
        $role = Role::query()->where('code', 'doctor')->firstOrFail();
        $adminRole = Role::query()->where('code', 'admin_sistema')->firstOrFail();

        $this->put(route('roles.permisos', $role), ['permissions' => []])->assertForbidden();
        $this->put(route('roles.update', $adminRole), ['name' => 'X'])->assertForbidden();
        $this->post(route('usuarios.store'), ['full_name' => 'Intruso', 'email' => 'intruso@x.com'])->assertForbidden();
    }

    /** Todo modelo con organization_id debe usar el trait que aplica el scope y fija el valor al crear. */
    public function test_every_model_with_an_organization_column_is_tenant_scoped(): void
    {
        // Excepciones documentadas: Organization es el propio tenant; Permission es catalogo global;
        // UserSession/DeliveryAttempt no pertenecen a una sola organizacion; Medication mezcla catalogo global + propio.
        $exempt = [Organization::class, \App\Models\Permission::class, \App\Models\UserSession::class, \App\Models\DeliveryAttempt::class, \App\Models\Medication::class];
        // Medication tiene su propio scope (catalogo global + el de la organizacion).
        $this->assertArrayHasKey('organization_or_global', (new \App\Models\Medication)->getGlobalScopes(), 'Medication debe declarar su propio scope');
        $missing = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, BaseModel::class) || in_array($class, $exempt, true)) {
                continue;
            }
            $table = (new $class)->getTable();
            if (Schema::hasColumn($table, 'organization_id') && ! in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'Modelos con organization_id sin BelongsToOrganization: '.implode(', ', $missing));
    }

    public function test_records_of_another_organization_are_invisible(): void
    {
        $this->actingAsAdmin();
        $foreignOrg = (string) Str::uuid();
        DB::table('organizations')->insert(['id' => $foreignOrg, 'commercial_name' => 'Otra clinica', 'created_at' => now()->addDay(), 'updated_at' => now()->addDay()]);
        $foreignPatient = (string) Str::uuid();
        DB::table('patients')->insert(['id' => $foreignPatient, 'organization_id' => $foreignOrg, 'folio' => 'PAC-ZZZ1', 'full_name' => 'Paciente Ajeno']);
        Organization::flushCurrent();

        $this->get(route('pacientes.show', $foreignPatient))->assertNotFound();
        $this->put(route('pacientes.update', $foreignPatient), ['full_name' => 'Cambiado Por Error'])->assertNotFound();
        $this->delete(route('pacientes.destroy', $foreignPatient))->assertNotFound();
        $this->get(route('expediente.show', $foreignPatient))->assertNotFound();
        $this->get(route('pacientes.index'))->assertDontSee('PAC-ZZZ1')->assertDontSee('Paciente Ajeno');

        $this->assertSame('Paciente Ajeno', DB::table('patients')->where('id', $foreignPatient)->value('full_name'));
    }
}
