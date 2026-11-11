<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\ClinicaRequest;
use App\Models\Organization;
use App\Models\OrganizationFiscalSetting;
use App\Services\Configuracion\Auditor;
use App\Services\Configuracion\ConfiguracionCatalog;
use App\Services\Configuracion\StoredImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClinicaController extends Controller
{
    public function show(): View
    {
        $org = Organization::current();
        abort_if(! $org, 404, 'No hay un consultorio configurado.');

        return view('configuracion.clinica', [
            'section' => 'clinica',
            'org' => $org,
            'phoneFormatted' => ConfiguracionCatalog::formatPhone($org->phone),
            'timezones' => ConfiguracionCatalog::TIMEZONES,
            'currencies' => ConfiguracionCatalog::CURRENCIES,
        ]);
    }

    public function update(ClinicaRequest $request): RedirectResponse
    {
        $org = Organization::current();
        $data = $request->safe()->only([
            'commercial_name', 'rfc', 'phone', 'email', 'address_line', 'address_postal_code', 'timezone', 'currency',
        ]);

        $oldLogo = $org->logo_storage_key;
        $newLogo = null;

        try {
            DB::transaction(function () use ($request, $org, $data, &$newLogo) {
                $org->fill($data);

                if ($request->hasFile('logo')) {
                    $newLogo = StoredImage::store($request->file('logo'), 'configuracion/logo');
                    $org->logo_storage_key = $newLogo;
                } elseif ($request->boolean('remove_logo')) {
                    $org->logo_storage_key = null;
                }

                $changed = array_keys($org->getDirty());
                $org->save();

                if ($changed) {
                    Auditor::log('organization', $org->id, 'update', $changed);
                }
            });
        } catch (\Throwable $e) {
            StoredImage::delete($newLogo);
            throw $e;
        }

        if ($oldLogo && $oldLogo !== $org->logo_storage_key) {
            StoredImage::delete($oldLogo);
        }

        $redirect = redirect()->route('configuracion.index')->with('success', 'Los datos de la clínica se guardaron correctamente.');

        // El RFC se comparte con Facturación: si ya no concuerda con el régimen fiscal, se avisa (sin bloquear).
        $regime = OrganizationFiscalSetting::query()->where('organization_id', $org->id)->value('tax_regime_code');

        if (! ConfiguracionCatalog::regimeMatchesRfc($org->rfc, $regime)) {
            $redirect->with('warning', "El RFC guardado no corresponde con el régimen fiscal {$regime}. Revísalo en Configuración › Facturación.");
        }

        return $redirect;
    }

    /** Sirve el logotipo desde el disco privado (solo usuarios autenticados). */
    public function logo(): StreamedResponse
    {
        return StoredImage::response(Organization::current()?->logo_storage_key);
    }
}
