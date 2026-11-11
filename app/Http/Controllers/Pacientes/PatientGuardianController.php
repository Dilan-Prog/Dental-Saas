<?php

namespace App\Http\Controllers\Pacientes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pacientes\GuardianRequest;
use App\Models\Patient;
use App\Models\PatientGuardian;
use App\Services\Pacientes\PatientService;
use DomainException;
use Illuminate\Http\RedirectResponse;

/** Tutores de pacientes menores de edad. */
class PatientGuardianController extends Controller
{
    public function __construct(private PatientService $patients)
    {
    }

    public function store(GuardianRequest $request, Patient $patient): RedirectResponse
    {
        if ($patient->anonymized_at) {
            return $this->back($patient)->with('error', 'El paciente fue anonimizado y ya no se puede modificar.');
        }

        $guardian = $request->filled('guardian_id')
            ? $patient->guardians()->findOrFail($request->input('guardian_id'))
            : null;

        $this->patients->saveGuardian($patient, $request->validated(), $guardian);

        return $this->back($patient)->with('success', $guardian ? 'Tutor actualizado.' : 'Tutor agregado.');
    }

    public function destroy(Patient $patient, PatientGuardian $guardian): RedirectResponse
    {
        abort_unless($guardian->patient_id === $patient->id, 404);

        if ($patient->anonymized_at) {
            return $this->back($patient)->with('error', 'El paciente fue anonimizado y ya no se puede modificar.');
        }

        try {
            $this->patients->deleteGuardian($patient, $guardian);
        } catch (DomainException $e) {
            return $this->back($patient)->with('error', $e->getMessage());
        }

        return $this->back($patient)->with('success', 'Tutor eliminado.');
    }

    private function back(Patient $patient): RedirectResponse
    {
        return redirect(route('pacientes.show', $patient).'#tutores');
    }
}
