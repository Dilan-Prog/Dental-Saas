<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\InteractsWithClinic;

/**
 * Base de pruebas: MySQL dental_saas_testing (ver phpunit.xml), migraciones
 * recreadas una vez por ejecución, transacción por prueba y BaseSeeder sembrado.
 * Para una prueba sin datos base: `protected bool $seedBase = false;`.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use RefreshDatabase;
    use InteractsWithClinic;

    protected bool $seedBase = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->seedBase) {
            $this->seedBase();
        }
    }
}
