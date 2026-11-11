<?php

namespace App\Http\Controllers\Tratamientos;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tratamientos\TaxProfileRequest;
use App\Models\TaxProfile;
use Illuminate\Http\RedirectResponse;

class TaxProfileController extends Controller
{
    public function store(TaxProfileRequest $request): RedirectResponse
    {
        $profile = TaxProfile::create($request->payload() + ['status' => RecordStatus::Active]);

        return redirect()->route('tratamientos.index', ['tab' => 'impuestos'])
            ->with('success', "Perfil de impuestos «{$profile->name}» creado.");
    }

    /**
     * La tasa de un perfil ya usado solo afecta presupuestos nuevos: las partidas
     * guardan su propia tasa (snapshot).
     */
    public function update(TaxProfileRequest $request, TaxProfile $taxProfile): RedirectResponse
    {
        $taxProfile->update($request->payload());

        return redirect()->route('tratamientos.index', ['tab' => 'impuestos'])
            ->with('success', "Perfil de impuestos «{$taxProfile->name}» actualizado.");
    }

    public function toggle(TaxProfile $taxProfile): RedirectResponse
    {
        $taxProfile->status = $taxProfile->status === RecordStatus::Active ? RecordStatus::Inactive : RecordStatus::Active;
        $taxProfile->save();

        return redirect()->route('tratamientos.index', ['tab' => 'impuestos'])
            ->with('success', $taxProfile->status === RecordStatus::Active
                ? "Perfil «{$taxProfile->name}» activado."
                : "Perfil «{$taxProfile->name}» desactivado.");
    }
}
