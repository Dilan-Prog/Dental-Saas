<?php

namespace App\Http\Controllers\Expediente;

use App\Http\Requests\Expediente\StorePatientTreatmentRequest;
use App\Http\Requests\Expediente\UpdatePatientTreatmentStatusRequest;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Services\Expediente\PatientTreatmentService;

/** Tratamientos del paciente (plan clínico). */
class PatientTreatmentController extends BaseController
{
    public function __construct(private PatientTreatmentService $service)
    {
    }

    public function store(StorePatientTreatmentRequest $request, Patient $patient)
    {
        $data = $request->validated();

        return $this->attempt(
            fn () => $this->service->create($patient, $data),
            'Tratamiento agregado al plan del paciente.',
            $this->toPatient($patient, 'tratamientos')
        );
    }

    public function status(UpdatePatientTreatmentStatusRequest $request, Patient $patient, PatientTreatment $treatment)
    {
        abort_unless($treatment->patient_id === $patient->id, 404);
        $to = $request->validated()['status'];

        return $this->attempt(
            fn () => $this->service->changeStatus($treatment, $to),
            'Estado del tratamiento actualizado.',
            $this->toPatient($patient, 'tratamientos')
        );
    }

    public function destroy(Patient $patient, PatientTreatment $treatment)
    {
        abort_unless($treatment->patient_id === $patient->id, 404);

        return $this->attempt(
            fn () => $this->service->delete($treatment),
            'Tratamiento eliminado del plan.',
            $this->toPatient($patient, 'tratamientos')
        );
    }
}
