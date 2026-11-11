<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orquesta los seeders de datos demo que aporta cada módulo.
 * Cada agente/módulo crea Database\Seeders\Demo<Modulo>Seeder; aquí se
 * invocan en orden de dependencias solo si la clase existe.
 */
class DemoSeeder extends Seeder
{
    public const ORDER = [
        'Configuracion',
        'Tratamientos',
        'Pacientes',
        'Agenda',
        'Expediente',
        'Recetas',
        'Presupuestos',
        'Facturacion',
        'Finanzas',
        'Inventario',
    ];

    public function run(): void
    {
        foreach (self::ORDER as $module) {
            $class = __NAMESPACE__ . "\\Demo{$module}Seeder";

            if (class_exists($class)) {
                $this->call($class);
            }
        }
    }
}
