<?php

namespace App\Console\Commands;

use App\Models\PacRequest;
use App\Services\Facturacion\BillingException;
use App\Services\Facturacion\PacResponseHandler;
use Illuminate\Console\Command;

/**
 * Punto de integracion para aplicar una respuesta REAL del PAC a una solicitud
 * pendiente (timbrado o cancelacion). Pensado para ser invocado por el
 * integrador (job, webhook interno, consulta de estatus).
 *
 *   php artisan facturacion:pac-respuesta {pacRequestId} --status=ok \
 *       --uuid=XXXXXXXX-XXXX-... --xml=cfdi/xml/F-0001.xml
 *   php artisan facturacion:pac-respuesta {pacRequestId} --status=error --message="RFC invalido"
 *   php artisan facturacion:pac-respuesta {pacRequestId} --status=ok --cancel=aceptada
 *
 * NUNCA inventes un UUID: debe venir de la respuesta del PAC.
 */
class FacturacionPacRespuesta extends Command
{
    protected $signature = 'facturacion:pac-respuesta
                            {pac_request : ID de la solicitud (pac_requests.id)}
                            {--status=ok : ok|error}
                            {--uuid= : UUID fiscal devuelto por el PAC (timbrado)}
                            {--folio= : Folio fiscal devuelto por el PAC}
                            {--xml= : Clave de almacenamiento del XML}
                            {--pdf= : Clave de almacenamiento del PDF}
                            {--cancel= : Estado de cancelacion: aceptada|rechazada|en_proceso}
                            {--code= : Codigo de respuesta del PAC}
                            {--message= : Mensaje de respuesta del PAC}';

    protected $description = 'Aplica la respuesta del PAC (timbrado/cancelacion) a una solicitud pendiente';

    public function handle(PacResponseHandler $handler): int
    {
        $request = PacRequest::withoutGlobalScopes()->find($this->argument('pac_request'));
        if (! $request) {
            $this->error('No existe la solicitud indicada.');

            return self::FAILURE;
        }

        $ok = $this->option('status') === 'ok';

        try {
            $handler->apply($request, array_filter([
                'success' => $ok,
                'code' => $this->option('code'),
                'message' => $this->option('message'),
                'uuid_fiscal' => $this->option('uuid'),
                'fiscal_folio' => $this->option('folio') !== null ? (int) $this->option('folio') : null,
                'xml_storage_key' => $this->option('xml'),
                'pdf_storage_key' => $this->option('pdf'),
                'cancellation_status' => $this->option('cancel'),
            ], fn ($v) => $v !== null) + ['success' => $ok]);
        } catch (BillingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Respuesta del PAC aplicada.');

        return self::SUCCESS;
    }
}
