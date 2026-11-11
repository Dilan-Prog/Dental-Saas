<?php

namespace Tests\Feature\Configuracion;

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base de las pruebas de Configuración: limpia la caché estática de la
 * organización (el id cambia en cada prueba porque se resiembra) y usa un
 * disco falso para las imágenes.
 */
abstract class ConfiguracionTestCase extends TestCase
{
    protected function setUp(): void
    {
        Organization::flushCurrent();
        parent::setUp();
        Organization::flushCurrent();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Organization::flushCurrent();
        parent::tearDown();
    }

    /** Datos válidos de la pestaña Clínica. */
    protected function clinicData(array $overrides = []): array
    {
        return array_merge([
            'commercial_name' => 'Clínica Dental Pro',
            'rfc' => 'CUS200115AB3',
            'phone' => '55 1234 5678',
            'email' => 'contacto@clinica.mx',
            'address_line' => 'Av. Reforma 222, CDMX',
            'address_postal_code' => '06600',
            'timezone' => 'America/Mexico_City',
            'currency' => 'MXN',
        ], $overrides);
    }

    /** Datos válidos de la pestaña Facturación. */
    protected function fiscalData(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'Clínica Dental Pro S.A. de C.V.',
            'rfc' => 'CUS200115AB3',
            'tax_regime_code' => '601',
            'fiscal_postal_code' => '06600',
            'pac_provider' => 'Finkok',
            'default_series' => 'A',
            'csd_certificate_secret_ref' => 'vault://clinica/csd-cer',
            'csd_key_secret_ref' => 'vault://clinica/csd-key',
            'pac_credentials_secret_ref' => 'vault://clinica/pac',
        ], $overrides);
    }
}
