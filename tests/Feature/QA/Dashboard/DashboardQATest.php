<?php

namespace Tests\Feature\QA\Dashboard;

use App\Models\Appointment;
use App\Models\FinancialCategory;
use App\Models\FinancialMovement;
use App\Models\Invoice;
use App\Models\NotificationPreference;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA4: coherencia del Dashboard, notificaciones y buscador global. */
class DashboardQATest extends TestCase
{
    protected function setUp(): void
    {
        Organization::flushCurrent();
        parent::setUp();
        Organization::flushCurrent();
        Cache::flush();
        NotificationService::markAllRead($this->userByEmail('admin@dental.test'));
    }

    protected function tearDown(): void
    {
        Organization::flushCurrent();
        parent::tearDown();
    }

    private function patient(string $name = 'Renata Aguilar', string $folio = 'PAC-9001'): Patient
    {
        return Patient::create(['folio' => $folio, 'full_name' => $name, 'phone' => '5512345678', 'email' => strtolower(Str::slug($name)).'@x.mx']);
    }

    private function income(string $amount, ?string $date = null, array $over = []): FinancialMovement
    {
        $cat = FinancialCategory::query()->firstOrCreate(['name' => 'Tratamientos', 'direction' => 'ingreso'], ['status' => 'active']);

        return FinancialMovement::create($over + [
            'branch_id' => $this->mainBranchId(), 'direction' => 'ingreso', 'category_id' => $cat->id,
            'concept' => 'Ingreso de prueba', 'occurred_on' => $date ?? now()->toDateString(), 'amount' => $amount,
            'currency' => 'MXN', 'method' => 'tarjeta',
        ]);
    }

    private function kpi(string $label): ?array
    {
        $kpis = DashboardService::for(auth()->user())->kpis();

        return collect($kpis)->firstWhere('label', $label);
    }

    private function payment(Patient $p, string $amount, ?string $key = null): Payment
    {
        return Payment::create([
            'branch_id' => $this->mainBranchId(), 'patient_id' => $p->id, 'paid_at' => now(), 'amount' => $amount,
            'method' => 'tarjeta', 'status' => 'registrado', 'idempotency_key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function invoice(Patient $p, string $total, string $folio = 'F-9001', string $fiscal = 'pendiente_timbrado'): Invoice
    {
        return Invoice::create([
            'internal_folio' => $folio, 'cfdi_type' => 'I', 'patient_id' => $p->id, 'branch_id' => $this->mainBranchId(),
            'issuer_snapshot' => ['rfc' => 'AAA010101AAA'], 'receiver_snapshot' => ['rfc' => 'XAXX010101000'],
            'cfdi_use' => 'D01', 'cfdi_payment_method' => 'PUE', 'cfdi_payment_form' => '01',
            'subtotal' => $total, 'tax_total' => '0.00', 'total' => $total,
            'fiscal_status' => $fiscal, 'collection_status' => 'pendiente', 'issued_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- Dashboard

    public function test_income_kpi_equals_effective_income_movements_of_the_day(): void
    {
        $this->actingAsAdmin();
        $p = $this->patient();
        $pay = $this->payment($p, '500.00');
        $this->income('500.00', null, ['payment_id' => $pay->id, 'concept' => 'Pago']);
        $this->income('200.25');                                   // ingreso manual
        $orig = $this->income('300.00');                           // revertido: par excluido
        $this->income('300.00', null, ['direction' => 'egreso', 'reversed_of_id' => $orig->id,
            'category_id' => FinancialCategory::create(['name' => 'Reversión de ingreso', 'direction' => 'egreso'])->id]);
        $this->income('50.00', now()->subDay()->toDateString());   // ayer
        FinancialMovement::create([                                  // egreso: no es ingreso
            'branch_id' => $this->mainBranchId(), 'direction' => 'egreso', 'concept' => 'Renta', 'amount' => '999.00',
            'occurred_on' => now()->toDateString(), 'currency' => 'MXN', 'method' => 'tarjeta',
            'category_id' => FinancialCategory::create(['name' => 'Renta', 'direction' => 'egreso'])->id,
        ]);

        $sql = (string) DB::table('financial_movements')->where('direction', 'ingreso')->whereNull('reversed_of_id')
            ->where('occurred_on', now()->toDateString())
            ->whereNotIn('id', DB::table('financial_movements')->whereNotNull('reversed_of_id')->pluck('reversed_of_id'))
            ->sum('amount');

        $kpi = $this->kpi('Ingresos del día');
        $this->assertSame('$700.25', $kpi['value']);
        $this->assertSame('700.25', number_format((float) $sql, 2, '.', ''));
        $this->assertStringContainsString('vs. ayer', (string) $kpi['delta']);
    }

    public function test_income_series_total_matches_sql_and_excludes_reversals(): void
    {
        $this->actingAsAdmin();
        $this->income('100.00', now()->subDays(2)->toDateString());
        $this->income('40.00', now()->toDateString());
        $orig = $this->income('70.00', now()->subDays(5)->toDateString());
        $this->income('70.00', now()->toDateString(), ['direction' => 'egreso', 'reversed_of_id' => $orig->id,
            'category_id' => FinancialCategory::create(['name' => 'Reversión de ingreso', 'direction' => 'egreso'])->id]);
        $this->income('999.00', now()->subDays(45)->toDateString());   // fuera de la ventana de 30 dias

        $series = DashboardService::for(auth()->user())->incomeSeries(30);

        $this->assertSame('140.00', $series['total']);
        $this->assertCount(30, $series['days']);
    }

    public function test_receivable_kpi_equals_sql_balance(): void
    {
        $this->actingAsAdmin();
        $p = $this->patient();
        $inv = $this->invoice($p, '1000.00');
        $this->invoice($p, '500.00', 'F-9002', 'borrador');     // borrador: no cuenta
        $this->invoice($p, '300.00', 'F-9003', 'cancelada');    // cancelada: no cuenta
        $pay = $this->payment($p, '400.00');
        PaymentApplication::create(['payment_id' => $pay->id, 'invoice_id' => $inv->id, 'applied_amount' => '400.00', 'applied_at' => now()]);
        $pay2 = $this->payment($p, '100.00');
        PaymentApplication::create(['payment_id' => $pay2->id, 'invoice_id' => $inv->id, 'applied_amount' => '100.00', 'applied_at' => now(), 'reversed_at' => now()]);

        $kpi = $this->kpi('Facturas por cobrar');

        $this->assertSame('$600.00', $kpi['value']);
        $this->assertSame('1 factura pendiente', $kpi['delta']);
    }

    public function test_cash_kpi_visibility_follows_permissions(): void
    {
        $this->actingAsDoctor();   // sin finanzas ni facturacion
        $labels = collect(DashboardService::for(auth()->user())->kpis())->pluck('label')->all();

        $this->assertNotContains('Ingresos del día', $labels);
        $this->assertNotContains('Facturas por cobrar', $labels);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Ingresos del día');
    }

    public function test_today_appointments_kpi_excludes_cancelled_and_other_days(): void
    {
        $admin = $this->actingAsAdmin();
        $member = $this->memberId($admin);
        $p = $this->patient();
        $mk = fn ($start, $status) => Appointment::create([
            'branch_id' => $this->mainBranchId(), 'patient_id' => $p->id, 'doctor_member_id' => $member,
            'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'status' => $status,
        ]);
        $mk(now()->setTime(9, 0), 'confirmada');
        $mk(now()->setTime(10, 0), 'pendiente');
        $mk(now()->setTime(11, 0), 'cancelada');
        $mk(now()->addDay()->setTime(9, 0), 'confirmada');
        $mk(now()->subDay()->setTime(9, 0), 'completada');

        $kpi = $this->kpi('Citas hoy');
        $this->assertSame('2', $kpi['value']);
        $this->assertStringContainsString('vs. ayer (1)', $kpi['delta']);
    }

    // ------------------------------------------------------------ Buscador

    public function test_search_treats_like_wildcards_literally(): void
    {
        $this->actingAsAdmin();
        $this->patient('Pedro 100% Real', 'PAC-9101');
        $this->patient('Pedro Otro', 'PAC-9102');
        $this->patient('Pedro_Guion', 'PAC-9103');

        $names = fn (string $q) => collect($this->getJson(route('search', ['q' => $q]))->assertOk()->json('groups.pacientes.items'))->pluck('label')->all();

        $this->assertSame(['Pedro 100% Real'], $names('100%'));
        $this->assertSame([], $names('%%'));
        $this->assertSame(['Pedro_Guion'], $names('o_G'));
        $this->assertSame([], $names('__'));
        $this->assertSame([], $names('x'));       // menos de 2 caracteres
    }

    public function test_search_hostile_inputs_never_error(): void
    {
        $this->actingAsAdmin();
        $this->patient();
        foreach (["'; DROP TABLE patients;--", "' OR '1'='1", '\\', '\\\\', '"', '<script>alert(1)</script>', str_repeat('a', 100), '%_%', 'ñandú', '漢字'] as $q) {
            $r = $this->getJson(route('search', ['q' => $q]));
            $this->assertSame(200, $r->getStatusCode(), 'estado '.$r->getStatusCode().' con '.$q);
        }
        $this->assertSame(1, Patient::query()->count());

        foreach ([str_repeat('a', 101), ['a', 'b']] as $bad) {
            $r = $this->getJson(route('search', ['q' => $bad]));
            $this->assertContains($r->getStatusCode(), [422, 200], 'estado '.$r->getStatusCode().' con '.json_encode($bad).' '.substr((string) ($r->exception?->getMessage()), 0, 200));
        }
    }

    public function test_search_html_page_escapes_results(): void
    {
        $this->actingAsAdmin();
        $this->patient('<img src=x onerror=alert(1)> Maria', 'PAC-9201');

        $html = $this->get(route('search', ['q' => 'Maria', 'format' => 'html']))->assertOk()->getContent();
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);

        $html = $this->get(route('search', ['q' => '<script>alert(1)</script>', 'format' => 'html']))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_search_groups_follow_permissions(): void
    {
        $this->patient('Laura Perez', 'PAC-9301');

        $this->actingAsDoctor();    // sin facturacion
        $groups = array_keys($this->getJson(route('search', ['q' => 'Laura']))->json('groups'));
        $this->assertContains('pacientes', $groups);
        $this->assertContains('recetas', $groups);
        $this->assertNotContains('facturas', $groups);

        $this->actingAsReceptionist();   // sin recetas
        $groups = array_keys($this->getJson(route('search', ['q' => 'Laura']))->json('groups'));
        $this->assertContains('facturas', $groups);
        $this->assertNotContains('recetas', $groups);
    }

    // ------------------------------------------------------ Notificaciones

    public function test_notifications_are_deduplicated_and_sent_only_to_permitted_users(): void
    {
        $this->actingAsAdmin();
        $p = $this->patient();
        $this->payment($p, '500.00');

        NotificationService::sync(true);
        NotificationService::sync(true);
        NotificationService::sync(true);

        $this->assertSame(1, DB::table('notifications')->where('event_type', 'payment_received')->count());

        $recipients = DB::table('notification_recipients')->join('users', 'users.id', '=', 'notification_recipients.user_id')
            ->pluck('users.email')->all();
        $this->assertContains('admin@dental.test', $recipients);
        $this->assertContains('recepcion@dental.test', $recipients);       // facturacion.ver
        $this->assertNotContains('doctor@dental.test', $recipients);       // sin facturacion
        $this->assertCount(count(array_unique($recipients)), $recipients, 'un destinatario por usuario');
    }

    public function test_notification_preferences_disable_generation(): void
    {
        $this->actingAsAdmin();
        NotificationPreference::create(['event_type' => 'payment_received', 'channel' => 'in_app', 'is_enabled' => false]);
        $this->payment($this->patient(), '500.00');

        NotificationService::sync(true);

        $this->assertSame(0, DB::table('notifications')->where('event_type', 'payment_received')->count());
    }

    public function test_users_cannot_read_notifications_of_others_and_mark_all_is_scoped(): void
    {
        $admin = $this->actingAsAdmin();
        $this->payment($this->patient(), '500.00');
        NotificationService::sync(true);

        $mine = NotificationRecipient::query()->where('user_id', $admin->id)->firstOrFail();
        $others = NotificationRecipient::query()->where('user_id', '!=', $admin->id)->firstOrFail();

        $this->post(route('notifications.read', $others))->assertNotFound();
        $this->assertNull($others->fresh()->read_at);

        $this->postJson(route('notifications.readAll'))->assertOk();
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($others->fresh()->read_at, 'marcar todas no debe tocar las de otros usuarios');

        $this->post(route('notifications.read', (string) Str::uuid()))->assertNotFound();
    }

    public function test_unread_count_matches_rows(): void
    {
        $admin = $this->actingAsAdmin();
        $p = $this->patient();
        $this->payment($p, '10.00');
        $this->payment($p, '20.00');
        NotificationService::sync(true);

        $unread = DB::table('notification_recipients')->where('user_id', $admin->id)->whereNull('read_at')->count();
        $this->assertSame($unread, NotificationService::unreadCount($admin));
        $this->get(route('notifications.index'))->assertOk();
    }

    public function test_notification_and_dashboard_pages_survive_garbage_query_params(): void
    {
        $this->actingAsAdmin();
        foreach (['abc', '-1', '9999999999999999999', ['x'], '<script>', str_repeat('a', 300)] as $v) {
            foreach ([route('notifications.index', ['filtro' => $v, 'page' => $v]), route('dashboard', ['date' => $v, 'range' => $v, 'branch' => $v])] as $url) {
                $r = $this->get($url);
                $this->assertLessThan(500, $r->getStatusCode(), $url.' devolvio '.$r->getStatusCode());
            }
        }
    }
}
