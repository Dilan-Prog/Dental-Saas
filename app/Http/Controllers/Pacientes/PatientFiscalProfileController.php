<?php

namespace App\Http\Controllers\Pacientes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pacientes\FiscalProfileRequest;
use App\Models\Patient;
use App\Models\PatientFiscalProfile;
use App\Services\Pacientes\PatientService;
use DomainException;
use Illuminate\Http\RedirectResponse;

/** Perfiles fiscales del paciente (RFC, razón social, régimen, CP, uso de CFDI). */
class PatientFiscalProfileController extends Controller
{
    public function __construct(private PatientService $patients)
    {
    }

    public function store(FiscalProfileRequest $request, Patient $patient): RedirectResponse
    {
        if ($blocked = $this->blockIfAnonymized($patient)) {
            return $blocked;
        }

        $profile = $request->filled('profile_id')
            ? $patient->fiscalProfiles()->findOrFail($request->input('profile_id'))
            : null;

        $this->patients->saveFiscalProfile($patient, $request->validated(), $profile);

        return $this->back($patient)->with('success', $profile ? 'Datos fiscales actualizados.' : 'Datos fiscales agregados.');
    }

    public function makeDefault(Patient $patient, PatientFiscalProfile $profile): RedirectResponse
    {
        abort_unless($profile->patient_id === $patient->id, 404);

        if ($blocked = $this->blockIfAnonymized($patient)) {
            return $blocked;
        }

        $this->patients->makeDefault($patient, $profile);

        return $this->back($patient)->with('success', 'Perfil fiscal predeterminado actualizado.');
    }

    public function destroy(Patient $patient, PatientFiscalProfile $profile): RedirectResponse
    {
        abort_unless($profile->patient_id === $patient->id, 404);

        if ($blocked = $this->blockIfAnonymized($patient)) {
            return $blocked;
        }

        try {
            $this->patients->deleteFiscalProfile($patient, $profile);
        } catch (DomainException $e) {
            return $this->back($patient)->with('error', $e->getMessage());
        }

        return $this->back($patient)->with('success', 'Perfil fiscal eliminado.');
    }

    private function back(Patient $patient): RedirectResponse
    {
        return redirect(route('pacientes.show', $patient).'#fiscal');
    }

    private function blockIfAnonymized(Patient $patient): ?RedirectResponse
    {
        return $patient->anonymized_at
            ? $this->back($patient)->with('error', 'El paciente fue anonimizado y ya no se puede modificar.')
            : null;
    }
}
