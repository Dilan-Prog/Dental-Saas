<?php

namespace App\Services\Facturacion;

use RuntimeException;

/**
 * Resuelve la implementacion de PacClient.
 *
 * config('services.pac.driver') (por defecto 'null'). Para integrar un PAC real:
 * crear una clase que implemente PacClient y registrarla en $drivers (o con
 * PacManager::extend() desde un ServiceProvider).
 */
class PacManager
{
    /** @var array<string, class-string<PacClient>> */
    protected static array $drivers = [
        'null' => NullPacClient::class,
    ];

    public static function extend(string $driver, string $class): void
    {
        static::$drivers[$driver] = $class;
    }

    public static function client(): PacClient
    {
        $driver = (string) config('services.pac.driver', 'null');
        $class = static::$drivers[$driver] ?? null;

        if ($class === null) {
            throw new RuntimeException("Driver de PAC desconocido: {$driver}");
        }

        return app($class);
    }
}
