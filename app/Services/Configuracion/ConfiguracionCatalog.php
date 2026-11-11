<?php

namespace App\Services\Configuracion;

use Illuminate\Support\Facades\Route;

/**
 * Catálogos y metadatos del módulo Configuración (zonas horarias, proveedores
 * de timbrado, eventos de notificación y secciones del submenú).
 */
class ConfiguracionCatalog
{
    /** Zonas horarias de México (clave IANA => etiqueta). */
    public const TIMEZONES = [
        'America/Mexico_City' => 'América/Ciudad de México (GMT-6)',
        'America/Monterrey' => 'América/Monterrey (GMT-6)',
        'America/Merida' => 'América/Mérida (GMT-6)',
        'America/Cancun' => 'América/Cancún (GMT-5)',
        'America/Chihuahua' => 'América/Chihuahua (GMT-6)',
        'America/Mazatlan' => 'América/Mazatlán (GMT-7)',
        'America/Hermosillo' => 'América/Hermosillo (GMT-7)',
        'America/Tijuana' => 'América/Tijuana (GMT-8)',
    ];

    public const CURRENCIES = [
        'MXN' => 'MXN — Peso mexicano',
    ];

    /** Proveedores de timbrado (PAC) soportados en la configuración. */
    public const PAC_PROVIDERS = [
        'Finkok' => 'Finkok',
        'Facturama' => 'Facturama',
        'SW Sapien' => 'SW Sapien',
        'Solución Factible' => 'Solución Factible',
        'Otro' => 'Otro proveedor',
    ];

    /** Regímenes fiscales que solo aplican a personas morales (RFC de 12). */
    public const REGIMENES_MORALES = ['601', '603', '620', '623', '624'];

    /** Regímenes fiscales que solo aplican a personas físicas (RFC de 13). */
    public const REGIMENES_FISICAS = ['605', '606', '608', '611', '612', '614', '615', '616', '621', '625', '626'];

    /**
     * Eventos de notificación configurables.
     * channel fijo por evento; lead = minutos de anticipación (editable) o null;
     * role = el evento se dirige a un rol de la clínica (editable).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function notificationEvents(): array
    {
        return [
            'reminder_24h_email' => [
                'label' => 'Recordatorio por email 24 h antes',
                'hint' => 'Se envía al correo del paciente antes de su cita.',
                'channel' => 'email',
                'lead' => ['default' => 1440, 'min' => 60, 'max' => 10080],
                'role' => false,
            ],
            'reminder_2h_whatsapp' => [
                'label' => 'Recordatorio por WhatsApp 2 h antes',
                'hint' => 'Requiere una integración de WhatsApp activa para entregarse.',
                'channel' => 'whatsapp',
                'lead' => ['default' => 120, 'min' => 15, 'max' => 1440],
                'role' => false,
            ],
            'appointment_confirmed' => [
                'label' => 'Confirmación de cita al paciente',
                'hint' => 'Se envía al agendar o confirmar una cita.',
                'channel' => 'email',
                'lead' => null,
                'role' => false,
            ],
            'appointment_cancelled' => [
                'label' => 'Aviso de cancelación de cita',
                'hint' => 'Se envía al paciente cuando se cancela su cita.',
                'channel' => 'email',
                'lead' => null,
                'role' => false,
            ],
            'low_stock' => [
                'label' => 'Aviso de stock mínimo a recepción',
                'hint' => 'Notificación interna cuando un insumo baja de su mínimo.',
                'channel' => 'in_app',
                'lead' => null,
                'role' => true,
                'default_role' => 'recepcion',
            ],
            'payment_received' => [
                'label' => 'Aviso de pago recibido',
                'hint' => 'Notificación interna al registrar un pago.',
                'channel' => 'in_app',
                'lead' => null,
                'role' => true,
                'default_role' => 'admin_sistema',
            ],
        ];
    }

    /** Secciones del submenú lateral de Configuración. */
    public static function sections(): array
    {
        return [
            ['key' => 'clinica', 'label' => 'Clínica', 'route' => 'configuracion.index'],
            ['key' => 'usuarios', 'label' => 'Usuarios', 'route' => self::routeOrNull('usuarios.index')],
            ['key' => 'roles', 'label' => 'Roles y permisos', 'route' => self::routeOrNull('roles.index')],
            ['key' => 'tratamientos', 'label' => 'Tratamientos', 'route' => self::routeOrNull('tratamientos.index')],
            ['key' => 'facturacion', 'label' => 'Facturación', 'route' => 'configuracion.facturacion'],
            ['key' => 'notificaciones', 'label' => 'Notificaciones', 'route' => 'configuracion.notificaciones'],
            ['key' => 'sucursales', 'label' => 'Sucursales', 'route' => 'configuracion.sucursales', 'owner' => true],
            ['key' => 'perfil', 'label' => 'Mi perfil', 'route' => 'configuracion.perfil'],
        ];
    }

    private static function routeOrNull(string $name): ?string
    {
        return Route::has($name) ? $name : null;
    }

    /** Valida coherencia entre el tipo de RFC (12/13) y el régimen fiscal. */
    public static function regimeMatchesRfc(?string $rfc, ?string $regime): bool
    {
        if (! $rfc || ! $regime) {
            return true;
        }

        $len = strlen(trim($rfc));

        if ($len === 12 && in_array($regime, self::REGIMENES_FISICAS, true)) {
            return false;
        }

        if ($len === 13 && in_array($regime, self::REGIMENES_MORALES, true)) {
            return false;
        }

        return true;
    }

    /** Formatea 10 dígitos como "55 1234 5678". */
    public static function formatPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) === 10
            ? substr($digits, 0, 2).' '.substr($digits, 2, 4).' '.substr($digits, 6)
            : ($phone ?: null);
    }
}
