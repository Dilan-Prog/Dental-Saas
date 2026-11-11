<?php

namespace Tests\Feature\Dashboard;

use App\Models\Appointment;
use App\Models\AppNotification;
use App\Models\Invoice;
use App\Models\NotificationRecipient;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\Dashboard\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesClinicUser;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesClinicUser;

    protected function setUp(): void
    {
        parent::setUp();
        // Cada prueba siembra una organizacion nueva: olvida el id memorizado.
        \App\Models\Organization::flushCurrent();
    }

    private function patient(string $name = 'Renata Aguilar Vega', string $folio = 'PAC-9001'): Patient
    {
        return Patient::create(['folio' => $folio, 'full_name' => $name, 'phone' => '5512345678']);
    }

    private function appointment(Patient $p, string $doctorMemberId, $start, string $status = 'confirmada', int $minutes = 60): Appointment
    {
        return Appointment::create([
            'branch_id' => $this->mainBranchId(),
            'patient_id' => $p->id,
            'doctor_member_id' => $doctorMemberId,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes($minutes),
            'status' => $status,
        ]);
    }

    private function payment(Patient $p, string $amount, $at, string $key): Payment
    {
        return Payment::create([
            'branch_id' => $this->mainBranchId(),
            'patient_id' => $p->id,
            'paid_at' => $at,
            'amount' => $amount,
            'method' => 'efectivo',
            'status' => 'registrado',
            'idempotency_key' => $key,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/buscar?q=ab')->assertRedirect(route('login'));
        $this->get('/notificaciones')->assertRedirect(route('login'));
    }

    public function test_dashboard_renders_with_greeting_and_empty_states(): void
    {
        $this->actingAsAdmin();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Citas hoy')
            ->assertSee('Agenda de hoy')
            ->assertSee('No hay citas programadas para hoy.')
            ->assertSee('Todo al día')
            ->assertSee('data-notif-panel', false)
            ->assertSee('Administrador');
    }

    public function test_dashboard_requires_dashboard_permission(): void
    {
        $user = $this->createClinicUser([], 'sinpermisos');
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertForbidden();
    }

    public function test_kpis_reflect_real_data(): void
    {
        $admin = $this->actingAsAdmin();
        $doctor = $this->memberId($this->userByEmail('doctor@dental.test'));
        $p = $this->patient();

        $this->appointment($p, $doctor, now()->setTime(10, 0));
        // Pendiente en el futuro (la alerta "confirmar cita" solo mira citas por venir).
        $this->appointment($this->patient('Daniela Vargas', 'PAC-9002'), $doctor, now()->addHours(2), 'pendiente');
        // Una cancelada hoy no cuenta.
        $this->appointment($this->patient('Cancelada Hoy', 'PAC-9003'), $doctor, now()->setTime(15, 0), 'cancelada');

        $this->payment($p, '1500.00', now(), 'k-1');
        $this->payment($p, '850.50', now(), 'k-2');
        $this->payment($p, '999.00', now()->subDay(), 'k-3');

        Invoice::create([
            'internal_folio' => 'F-9001', 'cfdi_type' => 'I', 'patient_id' => $p->id, 'branch_id' => $this->mainBranchId(),
            'issuer_snapshot' => ['rfc' => 'AAA010101AAA'], 'receiver_snapshot' => ['rfc' => 'XAXX010101000'],
            'cfdi_use' => 'D01', 'cfdi_payment_method' => 'PUE', 'cfdi_payment_form' => '01',
            'subtotal' => '2000.00', 'tax_total' => '0.00', 'total' => '2000.00',
            'fiscal_status' => 'pendiente_timbrado', 'collection_status' => 'pendiente', 'issued_at' => now(),
        ]);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Renata Aguilar Vega', $html);
        $this->assertStringContainsString('Daniela Vargas', $html);
        $this->assertStringNotContainsString('Cancelada Hoy', $html);
        $this->assertStringContainsString('$2,350.50', $html);   // ingresos del dia
        $this->assertStringContainsString('$2,000.00', $html);   // por cobrar
        $this->assertStringContainsString('1 factura pendiente', $html);
        $this->assertStringContainsString('Confirmar cita de Daniela Vargas', $html);
        $this->assertStringContainsString('Factura pendiente de cobro', $html);
    }

    public function test_doctor_only_sees_own_appointments(): void
    {
        $doctorUser = $this->userByEmail('doctor@dental.test');
        $adminMember = $this->memberId($this->userByEmail('admin@dental.test'));
        $doctorMember = $this->memberId($doctorUser);

        $this->appointment($this->patient('Paciente Del Doctor', 'PAC-9010'), $doctorMember, now()->setTime(9, 0));
        $this->appointment($this->patient('Paciente Del Admin', 'PAC-9011'), $adminMember, now()->setTime(11, 0));

        $this->actingAs($doctorUser);
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Paciente Del Doctor', $html);
        $this->assertStringNotContainsString('Paciente Del Admin', $html);
    }

    public function test_overlapping_appointments_get_separate_lanes(): void
    {
        $this->actingAsAdmin();
        $d1 = $this->memberId($this->userByEmail('doctor@dental.test'));
        $d2 = $this->memberId($this->userByEmail('admin@dental.test'));

        $this->appointment($this->patient('Uno', 'PAC-9020'), $d1, now()->setTime(10, 0));
        $this->appointment($this->patient('Dos', 'PAC-9021'), $d2, now()->setTime(10, 30));

        $agenda = \App\Services\Dashboard\DashboardService::for(auth()->user())->todayAgenda();

        $this->assertSame(2, $agenda['count']);
        $this->assertSame([0, 1], array_column($agenda['events'], 'lane'));
        $this->assertSame([2, 2], array_column($agenda['events'], 'lanes'));
    }

    private function lowStock(string $name = 'Guantes nitrilo (M)'): string
    {
        $org = $this->organizationId();
        $itemId = (string) Str::uuid();
        DB::table('inventory_items')->insert([
            'id' => $itemId, 'organization_id' => $org, 'name' => $name,
            'sku' => 'SKU-'.Str::random(6), 'unit_of_measure' => 'caja',
        ]);
        DB::table('inventory_stock')->insert([
            'organization_id' => $org, 'branch_id' => $this->mainBranchId(), 'item_id' => $itemId,
            'quantity_on_hand' => '1.0000', 'min_quantity' => '5.0000',
        ]);

        return $itemId;
    }

    public function test_notifications_are_generated_once_and_per_permission(): void
    {
        $this->lowStock();
        $admin = $this->userByEmail('admin@dental.test');
        $doctor = $this->userByEmail('doctor@dental.test');

        NotificationService::sync(true);
        NotificationService::sync(true); // idempotente: misma dedup_key

        $this->assertSame(1, AppNotification::where('event_type', 'low_stock')->count());
        // El admin tiene permiso de inventario; el doctor no.
        $this->assertSame(1, NotificationService::forUser($admin)->count());
        $this->assertSame(0, NotificationService::forUser($doctor)->count());
    }

    public function test_panel_and_bell_show_unread_notifications(): void
    {
        $this->lowStock();
        $this->actingAsAdmin();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Stock bajo: Guantes nitrilo (M)')
            ->assertSee('data-unread="1"', false)
            ->assertSee('Marcar leídas');
    }

    public function test_mark_one_and_all_as_read(): void
    {
        $this->lowStock('Insumo A');
        $this->lowStock('Insumo B');
        $admin = $this->actingAsAdmin();
        NotificationService::sync(true);

        $this->assertSame(2, NotificationService::unreadCount($admin));

        $first = NotificationService::forUser($admin)->first();
        $this->post(route('notifications.read', $first))->assertRedirect();
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertSame(1, NotificationRecipient::where('user_id', $admin->id)->whereNull('read_at')->count());

        $this->post(route('notifications.readAll'))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, NotificationRecipient::where('user_id', $admin->id)->whereNull('read_at')->count());
    }

    public function test_cannot_read_another_users_notification(): void
    {
        $this->lowStock();
        NotificationService::sync(true);
        $other = NotificationRecipient::where('user_id', $this->userByEmail('admin@dental.test')->id)->firstOrFail();

        $this->actingAsReceptionist();
        $this->post(route('notifications.read', $other))->assertNotFound();
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_notifications_index_filters_unread(): void
    {
        $this->lowStock();
        $admin = $this->actingAsAdmin();
        NotificationService::sync(true);

        $this->get(route('notifications.index'))->assertOk()->assertSee('Stock bajo');
        NotificationService::markAllRead($admin);
        $this->get(route('notifications.index', ['filtro' => 'no-leidas']))
            ->assertOk()->assertSee('No tienes notificaciones sin leer.');
    }

    public function test_disabled_preference_skips_event(): void
    {
        $this->lowStock();
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $this->organizationId(),
            'event_type' => 'low_stock', 'channel' => 'in_app', 'is_enabled' => false,
        ]);

        NotificationService::sync(true);

        $this->assertSame(0, AppNotification::where('event_type', 'low_stock')->count());
    }

    public function test_payment_and_unconfirmed_appointment_notifications(): void
    {
        $p = $this->patient();
        $doctor = $this->memberId($this->userByEmail('doctor@dental.test'));
        $this->appointment($p, $doctor, now()->addHours(3), 'pendiente');
        $this->payment($p, '2400.00', now(), 'n-1');

        NotificationService::sync(true);

        $this->assertSame(1, AppNotification::where('event_type', 'payment_received')->count());
        $this->assertSame(1, AppNotification::where('event_type', 'appointment_unconfirmed')->count());
        $this->assertStringContainsString('$2,400.00', AppNotification::where('event_type', 'payment_received')->value('title'));
    }

    public function test_search_returns_patients_as_json(): void
    {
        $this->patient('Roberto Mendoza Lira', 'PAC-9100');
        $this->actingAsAdmin();

        $this->getJson(route('search', ['q' => 'mendoza']))
            ->assertOk()
            ->assertJsonPath('groups.pacientes.items.0.label', 'Roberto Mendoza Lira');
    }

    public function test_search_short_term_and_wildcards_return_nothing(): void
    {
        $this->patient('Roberto Mendoza Lira', 'PAC-9100');
        $this->actingAsAdmin();

        $this->getJson(route('search', ['q' => 'r']))->assertOk()->assertJsonPath('groups', []);
        // "%%" se busca literal, no como comodin.
        $res = $this->getJson(route('search', ['q' => '%%']))->assertOk();
        $this->assertSame([], $res->json('groups.pacientes.items'));
    }

    public function test_search_validates_length(): void
    {
        $this->actingAsAdmin();

        $this->getJson(route('search', ['q' => str_repeat('a', 101)]))
            ->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_search_respects_permissions(): void
    {
        $this->patient('Valeria Dominguez Soto', 'PAC-9200');
        $this->actingAsReceptionist(); // recepcion no tiene permisos de recetas

        $groups = $this->getJson(route('search', ['q' => 'valeria']))->assertOk()->json('groups');

        $this->assertArrayHasKey('pacientes', $groups);
        $this->assertArrayNotHasKey('recetas', $groups);
    }

    public function test_search_page_renders(): void
    {
        $this->patient('Patricio Guerra Mendez', 'PAC-9300');
        $this->actingAsAdmin();

        $this->get(route('search', ['q' => 'patricio']))->assertOk()->assertSee('Patricio Guerra Mendez');
        $this->get(route('search'))->assertOk()->assertSee('Escribe para buscar');
        $this->get(route('search', ['q' => 'x']))->assertOk()->assertSee('Búsqueda muy corta');
    }
}
