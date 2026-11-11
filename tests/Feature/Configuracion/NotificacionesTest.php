<?php

namespace Tests\Feature\Configuracion;

use App\Services\Configuracion\ConfiguracionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificacionesTest extends ConfiguracionTestCase
{
    private function roleId(string $code): string
    {
        return (string) DB::table('roles')->where('code', $code)->value('id');
    }

    /** Payload válido con todos los eventos del catálogo. */
    private function payload(array $overrides = []): array
    {
        $events = [];

        foreach (ConfiguracionCatalog::notificationEvents() as $event => $meta) {
            $events[$event] = ['enabled' => 1];

            if ($meta['lead']) {
                $events[$event]['lead'] = $meta['lead']['default'];
            }

            if ($meta['role']) {
                $events[$event]['role'] = $this->roleId($meta['default_role']);
            }
        }

        foreach ($overrides as $event => $values) {
            $events[$event] = array_merge($events[$event], $values);
        }

        return ['events' => $events];
    }

    public function test_page_lists_all_notification_events(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('configuracion.notificaciones'))->assertOk()->assertSee('Recordatorios automáticos');

        foreach (ConfiguracionCatalog::notificationEvents() as $meta) {
            $response->assertSee($meta['label']);
        }
    }

    public function test_only_admin_can_manage_notifications(): void
    {
        $this->get(route('configuracion.notificaciones'))->assertRedirect(route('login'));

        $this->actingAsReceptionist();
        $this->get(route('configuracion.notificaciones'))->assertForbidden();
        $this->put(route('configuracion.notificaciones.update'), $this->payload())->assertForbidden();
    }

    public function test_preferences_are_saved_one_row_per_event_and_channel(): void
    {
        $this->actingAsAdmin();

        $payload = $this->payload([
            'reminder_24h_email' => ['enabled' => 1, 'lead' => 720],
            'reminder_2h_whatsapp' => ['enabled' => 0, 'lead' => ''],
        ]);

        $this->put(route('configuracion.notificaciones.update'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.notificaciones'));

        $this->assertSame(count(ConfiguracionCatalog::notificationEvents()), DB::table('notification_preferences')->count());
        $this->assertDatabaseHas('notification_preferences', ['event_type' => 'reminder_24h_email', 'channel' => 'email', 'is_enabled' => 1, 'lead_time_minutes' => 720]);
        // Al apagarlo conserva la anticipación predeterminada.
        $this->assertDatabaseHas('notification_preferences', ['event_type' => 'reminder_2h_whatsapp', 'channel' => 'whatsapp', 'is_enabled' => 0, 'lead_time_minutes' => 120]);
        $this->assertDatabaseHas('notification_preferences', ['event_type' => 'low_stock', 'channel' => 'in_app', 'recipient_role_id' => $this->roleId('recepcion')]);

        // Guardar de nuevo no duplica filas.
        $this->put(route('configuracion.notificaciones.update'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(count(ConfiguracionCatalog::notificationEvents()), DB::table('notification_preferences')->count());
    }

    public function test_changed_values_are_shown_again_on_the_form(): void
    {
        $this->actingAsAdmin();
        $this->put(route('configuracion.notificaciones.update'), $this->payload(['reminder_24h_email' => ['lead' => 600]]))->assertSessionHasNoErrors();

        $this->get(route('configuracion.notificaciones'))->assertOk()->assertSee('value="600"', false);
    }

    /** @dataProvider invalidEvents */
    public function test_invalid_values_are_rejected(array $override, string $field): void
    {
        $this->actingAsAdmin();

        $this->put(route('configuracion.notificaciones.update'), $this->payload($override))->assertSessionHasErrors($field);
        $this->assertSame(0, DB::table('notification_preferences')->count());
    }

    public static function invalidEvents(): array
    {
        return [
            'anticipación menor al mínimo' => [['reminder_24h_email' => ['lead' => 10]], 'events.reminder_24h_email.lead'],
            'anticipación mayor al máximo' => [['reminder_2h_whatsapp' => ['lead' => 5000]], 'events.reminder_2h_whatsapp.lead'],
            'anticipación no numérica' => [['reminder_24h_email' => ['lead' => 'mañana']], 'events.reminder_24h_email.lead'],
            'anticipación decimal' => [['reminder_24h_email' => ['lead' => 90.5]], 'events.reminder_24h_email.lead'],
            'activo sin anticipación' => [['reminder_24h_email' => ['enabled' => 1, 'lead' => '']], 'events.reminder_24h_email.lead'],
            'activo sin rol destinatario' => [['low_stock' => ['enabled' => 1, 'role' => '']], 'events.low_stock.role'],
            'rol inexistente' => [['payment_received' => ['role' => '00000000-0000-4000-8000-000000000000']], 'events.payment_received.role'],
        ];
    }

    public function test_disabled_event_does_not_require_lead_or_role(): void
    {
        $this->actingAsAdmin();

        $this->put(route('configuracion.notificaciones.update'), $this->payload([
            'reminder_24h_email' => ['enabled' => 0, 'lead' => ''],
            'low_stock' => ['enabled' => 0, 'role' => ''],
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('notification_preferences', ['event_type' => 'reminder_24h_email', 'is_enabled' => 0]);
    }

    public function test_unknown_events_in_the_payload_are_ignored(): void
    {
        $this->actingAsAdmin();
        $payload = $this->payload();
        $payload['events']['evento_inventado'] = ['enabled' => 1];

        $this->put(route('configuracion.notificaciones.update'), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('notification_preferences', ['event_type' => 'evento_inventado']);
        $this->assertNotSame('', (string) Str::uuid());
    }
}
