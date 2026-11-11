<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\FacturacionRequest;
use App\Http\Requests\Configuracion\FoliosRequest;
use App\Models\FolioSequence;
use App\Models\Organization;
use App\Models\OrganizationFiscalSetting;
use App\Services\Configuracion\Auditor;
use App\Services\Configuracion\ConfiguracionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FacturacionController extends Controller
{
    public const DOCUMENT_LABELS = [
        'patient' => 'Pacientes',
        'prescription' => 'Recetas',
        'quote' => 'Presupuestos',
        'invoice' => 'Facturas',
    ];

    public function show(): View
    {
        $org = Organization::current();
        abort_if(! $org, 404, 'No hay un consultorio configurado.');

        $fiscal = OrganizationFiscalSetting::query()->where('organization_id', $org->id)->first();

        $sequences = FolioSequence::query()
            ->orderByRaw("FIELD(document_type, 'patient', 'prescription', 'quote', 'invoice')")
            ->orderBy('series')
            ->get();

        return view('configuracion.facturacion', [
            'section' => 'facturacion',
            'org' => $org,
            'fiscal' => $fiscal,
            'sequences' => $sequences,
            'documentLabels' => self::DOCUMENT_LABELS,
            'regimenes' => config('sat.regimenes', []),
            'pacProviders' => ConfiguracionCatalog::PAC_PROVIDERS,
            'secretsConfigured' => $fiscal
                && $fiscal->csd_certificate_secret_ref
                && $fiscal->csd_key_secret_ref
                && $fiscal->pac_credentials_secret_ref,
        ]);
    }

    public function update(FacturacionRequest $request): RedirectResponse
    {
        $org = Organization::current();
        $data = $request->validated();

        DB::transaction(function () use ($org, $data) {
            $org->fill(['legal_name' => $data['legal_name'] ?? null, 'rfc' => $data['rfc'] ?? null]);
            $changed = array_keys($org->getDirty());
            $org->save();

            $fiscal = OrganizationFiscalSetting::query()->firstOrNew(['organization_id' => $org->id]);
            $fiscal->fill([
                'tax_regime_code' => $data['tax_regime_code'] ?? null,
                'fiscal_postal_code' => $data['fiscal_postal_code'] ?? null,
                'pac_provider' => $data['pac_provider'] ?? null,
                'default_series' => $data['default_series'] ?? null,
                'csd_certificate_secret_ref' => $data['csd_certificate_secret_ref'] ?? null,
                'csd_key_secret_ref' => $data['csd_key_secret_ref'] ?? null,
                'pac_credentials_secret_ref' => $data['pac_credentials_secret_ref'] ?? null,
            ]);
            $changed = array_merge($changed, array_keys($fiscal->getDirty()));
            $fiscal->save();

            // La serie por defecto debe tener su consecutivo de facturas.
            if (! empty($data['default_series'])) {
                FolioSequence::query()->firstOrCreate(
                    ['document_type' => 'invoice', 'series' => $data['default_series']],
                    ['prefix' => 'F-', 'last_value' => 0],
                );
            }

            if ($changed) {
                Auditor::log('organization_fiscal_settings', $org->id, 'update', $changed);
            }
        });

        return redirect()->route('configuracion.facturacion')->with('success', 'Los datos fiscales se guardaron correctamente.');
    }

    /** Edición controlada de folios: prefijo y próximo folio (solo hacia adelante). */
    public function folios(FoliosRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            foreach ($request->validated('folios') as $row) {
                $seq = FolioSequence::query()->lockForUpdate()->find($row['id']);

                if (! $seq) {
                    continue;
                }

                $next = (int) $row['next'] - 1;
                // Revalida bajo bloqueo: nunca retroceder un consecutivo ya emitido.
                abort_if($next < $seq->last_value, 422, 'El próximo folio no puede ser menor al último emitido.');

                $seq->prefix = $row['prefix'];
                $seq->last_value = $next;

                if ($seq->isDirty()) {
                    $changed = array_keys($seq->getDirty());
                    $seq->save();
                    Auditor::log('folio_sequences', $seq->id, 'update', $changed);
                }
            }
        });

        return redirect()->route('configuracion.facturacion')->with('success', 'Los folios se actualizaron correctamente.');
    }
}
