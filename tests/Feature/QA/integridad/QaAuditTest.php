<?php

namespace Tests\Feature\QA\integridad;

use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El comando qa:audit es la red de seguridad de coherencia de datos:
 * debe pasar con el demo completo y debe fallar cuando se corrompe un dato.
 */
class QaAuditTest extends TestCase
{
    public function test_audit_passes_on_the_complete_demo_data(): void
    {
        $this->seed(DemoSeeder::class);

        $this->artisan('qa:audit')->assertExitCode(0);
    }

    public function test_audit_detects_a_tampered_quote_total(): void
    {
        $this->seed(DemoSeeder::class);

        // El CHECK chk_quotes_total ya impide alterar solo la cabecera; se altera una linea
        // para que la cabecera deje de coincidir con la suma de sus conceptos.
        $id = DB::table('quote_items')->value('id');
        DB::table('quote_items')->where('id', $id)->update(['line_total' => DB::raw('line_total + 50')]);

        $this->artisan('qa:audit', ['--only' => 'presupuestos'])->assertExitCode(1);
    }

    public function test_audit_detects_a_payment_exceeding_the_invoice(): void
    {
        $this->seed(DemoSeeder::class);

        $app = DB::table('payment_applications')->whereNull('reversed_at')->first();
        $this->assertNotNull($app, 'El demo debe traer al menos un pago aplicado.');

        // El trigger impide el exceso por la via normal; reducimos el total de la factura para simularlo.
        DB::table('invoices')->where('id', $app->invoice_id)->update(['total' => '0.01']);

        $this->artisan('qa:audit', ['--only' => 'pagos,facturas'])->assertExitCode(1);
    }

    public function test_audit_detects_a_broken_signed_note_hash(): void
    {
        $this->seed(DemoSeeder::class);

        $note = (array) DB::table('clinical_notes')->where('status', 'firmada')->first();
        $this->assertNotEmpty($note, 'El demo debe traer al menos una nota firmada.');

        // Los triggers bloquean UPDATE/DELETE de notas firmadas, pero no el INSERT:
        // se inserta una nota "firmada" cuyo hash no corresponde a su contenido.
        $note['id'] = (string) \Illuminate\Support\Str::uuid();
        $note['subjective'] = 'Contenido distinto al que se firmo';
        DB::table('clinical_notes')->insert($note);

        $this->artisan('qa:audit', ['--only' => 'firmas'])->assertExitCode(1);
    }
}
