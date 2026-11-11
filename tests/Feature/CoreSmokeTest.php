<?php

namespace Tests\Feature;

use App\Support\Folio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CoreSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'active.member'])->group(function () {
            Route::get('/_t/pacientes', fn () => 'ok')->middleware('permission:pacientes,ver');
            Route::get('/_t/facturacion', fn () => 'ok')->middleware('permission:facturacion,cancelar_cfdi');
        });
    }

    public function test_base_seeder_is_idempotent_and_complete(): void
    {
        $this->seedBase();

        $this->assertSame(1, DB::table('organizations')->count());
        $this->assertSame(3, DB::table('roles')->count());
        $this->assertSame(75, DB::table('permissions')->count());
        $this->assertSame(4, DB::table('folio_sequences')->count());
        $this->assertSame(1, DB::table('branches')->where('is_main', true)->count());
    }

    public function test_admin_passes_permission_middleware(): void
    {
        $this->actingAsAdmin();

        $this->get('/_t/pacientes')->assertOk();
        $this->get('/_t/facturacion')->assertOk();
    }

    public function test_receptionist_is_limited_by_permissions(): void
    {
        $this->actingAsReceptionist();

        $this->get('/_t/pacientes')->assertOk();
        $this->get('/_t/facturacion')->assertForbidden();
    }

    public function test_inactive_member_is_logged_out(): void
    {
        $user = $this->actingAsReceptionist();
        DB::table('organization_members')->where('user_id', $user->id)->update(['is_active' => false]);

        $this->get('/_t/pacientes')->assertRedirect(route('login'));
    }

    public function test_folio_is_consecutive(): void
    {
        $this->assertSame('PAC-0001', Folio::next(Folio::PATIENT));
        $this->assertSame('PAC-0002', Folio::next(Folio::PATIENT));
        $this->assertSame('RX-0001', Folio::next(Folio::PRESCRIPTION));
    }
}
