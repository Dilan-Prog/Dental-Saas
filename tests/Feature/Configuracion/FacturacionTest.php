<?php

namespace Tests\Feature\Configuracion;

use App\Models\FolioSequence;
use Illuminate\Support\Facades\DB;

class FacturacionTest extends ConfiguracionTestCase
{
    public function test_only_authorized_users_can_open_billing_settings(): void
    {
        $this->get(route('configuracion.facturacion'))->assertRedirect(route('login'));

        $this->actingAsDoctor();
        $this->get(route('configuracion.facturacion'))->assertForbidden();

        $this->actingAsAdmin();
        $this->get(route('configuracion.facturacion'))->assertOk()->assertSee('Datos fiscales del emisor')->assertSee('Folios y consecutivos');
    }

    public function test_fiscal_data_is_saved_and_series_sequence_created(): void
    {
        $this->actingAsAdmin();

        $this->put(route('configuracion.facturacion.update'), $this->fiscalData(['rfc' => ' cus200115ab3 ', 'default_series' => 'b']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.facturacion'));

        $this->assertDatabaseHas('organizations', ['legal_name' => 'Clínica Dental Pro S.A. de C.V.', 'rfc' => 'CUS200115AB3']);
        $this->assertDatabaseHas('organization_fiscal_settings', [
            'organization_id' => $this->organizationId(),
            'tax_regime_code' => '601',
            'fiscal_postal_code' => '06600',
            'pac_provider' => 'Finkok',
            'default_series' => 'B',
            'csd_certificate_secret_ref' => 'vault://clinica/csd-cer',
        ]);
        $this->assertDatabaseHas('folio_sequences', ['document_type' => 'invoice', 'series' => 'B', 'last_value' => 0]);
    }

    public function test_saving_twice_is_idempotent(): void
    {
        $this->actingAsAdmin();

        $this->put(route('configuracion.facturacion.update'), $this->fiscalData())->assertSessionHasNoErrors();
        $this->put(route('configuracion.facturacion.update'), $this->fiscalData())->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('organization_fiscal_settings')->count());
        $this->assertSame(1, DB::table('folio_sequences')->where('document_type', 'invoice')->where('series', 'A')->count());
    }

    /** @dataProvider invalidFiscalData */
    public function test_invalid_fiscal_data_is_rejected(array $override, string $field): void
    {
        $this->actingAsAdmin();

        $this->put(route('configuracion.facturacion.update'), $this->fiscalData($override))->assertSessionHasErrors($field);
    }

    public static function invalidFiscalData(): array
    {
        return [
            'rfc inválido' => [['rfc' => '12345'], 'rfc'],
            'rfc genérico' => [['rfc' => 'XEXX010101000'], 'rfc'],
            'régimen fuera de catálogo' => [['tax_regime_code' => '999'], 'tax_regime_code'],
            'régimen físico con rfc moral' => [['tax_regime_code' => '612'], 'tax_regime_code'],
            'régimen moral con rfc físico' => [['rfc' => 'PEGJ800101AB3', 'tax_regime_code' => '601'], 'tax_regime_code'],
            'cp fiscal inválido' => [['fiscal_postal_code' => '123'], 'fiscal_postal_code'],
            'proveedor desconocido' => [['pac_provider' => 'PAC Falso'], 'pac_provider'],
            'serie con espacios' => [['default_series' => 'A B'], 'default_series'],
            'serie demasiado larga' => [['default_series' => 'ABCDEFGHIJK'], 'default_series'],
            'referencia con PEM' => [['csd_key_secret_ref' => '-----BEGIN PRIVATE KEY-----'], 'csd_key_secret_ref'],
            'referencia con espacios (contraseña)' => [['pac_credentials_secret_ref' => 'mi contraseña 123'], 'pac_credentials_secret_ref'],
            'razón social muy corta' => [['legal_name' => 'AB'], 'legal_name'],
            'timbrado sin razón social' => [['legal_name' => ''], 'legal_name'],
            'timbrado sin cp fiscal' => [['fiscal_postal_code' => ''], 'fiscal_postal_code'],
        ];
    }

    public function test_billing_can_be_saved_empty_while_pending(): void
    {
        $this->actingAsAdmin();

        $this->put(route('configuracion.facturacion.update'), [])->assertSessionHasNoErrors();
        $this->get(route('configuracion.facturacion'))->assertOk()->assertSee('Timbrado pendiente');
    }

    public function test_secret_references_are_the_only_secrets_accepted_never_files(): void
    {
        $this->actingAsAdmin();
        $file = \Illuminate\Http\UploadedFile::fake()->create('csd.cer', 5);

        // Aunque llegue un archivo, no se almacena ni se lee: solo existen campos de referencia.
        $this->put(route('configuracion.facturacion.update'), $this->fiscalData() + ['csd_file' => $file])->assertSessionHasNoErrors();
        \Illuminate\Support\Facades\Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_folio_prefix_and_next_value_can_be_updated_forward(): void
    {
        $this->actingAsAdmin();
        $seq = FolioSequence::query()->where('document_type', 'invoice')->firstOrFail();

        $this->put(route('configuracion.facturacion.folios'), ['folios' => [
            ['id' => $seq->id, 'prefix' => 'fa-', 'next' => 228],
        ]])->assertSessionHasNoErrors()->assertRedirect(route('configuracion.facturacion'));

        $this->assertDatabaseHas('folio_sequences', ['id' => $seq->id, 'prefix' => 'FA-', 'last_value' => 227]);
    }

    public function test_folio_cannot_go_backwards(): void
    {
        $this->actingAsAdmin();
        $seq = FolioSequence::query()->where('document_type', 'quote')->firstOrFail();
        DB::table('folio_sequences')->where('id', $seq->id)->update(['last_value' => 50]);

        $this->put(route('configuracion.facturacion.folios'), ['folios' => [
            ['id' => $seq->id, 'prefix' => 'PRE-', 'next' => 10],
        ]])->assertSessionHasErrors('folios.0.next');

        $this->assertDatabaseHas('folio_sequences', ['id' => $seq->id, 'last_value' => 50]);
    }

    public function test_folio_rejects_invalid_prefix_unknown_id_and_non_numeric_next(): void
    {
        $this->actingAsAdmin();
        $seq = FolioSequence::query()->firstOrFail();

        $this->put(route('configuracion.facturacion.folios'), ['folios' => [['id' => $seq->id, 'prefix' => 'A B', 'next' => 5]]])
            ->assertSessionHasErrors('folios.0.prefix');
        $this->put(route('configuracion.facturacion.folios'), ['folios' => [['id' => $seq->id, 'prefix' => '', 'next' => 5]]])
            ->assertSessionHasErrors('folios.0.prefix');
        $this->put(route('configuracion.facturacion.folios'), ['folios' => [['id' => (string) \Illuminate\Support\Str::uuid(), 'prefix' => 'X-', 'next' => 5]]])
            ->assertSessionHasErrors('folios.0.id');
        $this->put(route('configuracion.facturacion.folios'), ['folios' => [['id' => $seq->id, 'prefix' => 'X-', 'next' => 'abc']]])
            ->assertSessionHasErrors('folios.0.next');
        $this->put(route('configuracion.facturacion.folios'), ['folios' => []])->assertSessionHasErrors('folios');
    }
}
