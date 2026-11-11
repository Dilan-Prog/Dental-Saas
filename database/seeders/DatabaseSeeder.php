<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * BaseSeeder: datos obligatorios (clínica, roles, permisos, folios, usuarios).
     * DemoSeeder: datos de ejemplo por módulo (solo si existen las clases).
     */
    public function run(): void
    {
        $this->call(BaseSeeder::class);

        if (class_exists(DemoSeeder::class)) {
            $this->call(DemoSeeder::class);
        }
    }
}
