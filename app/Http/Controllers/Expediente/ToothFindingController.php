<?php

namespace App\Http\Controllers\Expediente;

use App\Http\Requests\Expediente\StoreToothFindingRequest;
use App\Models\Patient;
use App\Services\Expediente\DbErrors;
use App\Services\Expediente\ExpedienteException;
use App\Services\Expediente\OdontogramService;
use Illuminate\Database\QueryException;

/** Odontograma: cambia el estado de una pieza conservando el historial. */
class ToothFindingController extends BaseController
{
    public function __construct(private OdontogramService $odontogram)
    {
    }

    public function store(StoreToothFindingRequest $request, Patient $patient)
    {
        $data = $request->validated();

        try {
            $finding = $this->odontogram->record(
                $patient,
                (int) $data['tooth_fdi'],
                $data['condition'],
                $data['surfaces'] ?? [],
                $this->memberId($request),
                $data['encounter_id'] ?? null,
                $data['patient_treatment_id'] ?? null,
            );
        } catch (ExpedienteException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return back()->withInput()->with('error', DbErrors::message($e));
        }

        $tab = $this->toPatient($patient, 'odontograma', ['denticion' => \App\Enums\DentitionType::fromTooth((int) $data['tooth_fdi'])?->value]);

        return redirect($tab)->with(
            $finding ? 'success' : 'info',
            $finding ? 'Odontograma actualizado: '.OdontogramService::describe($finding).'.' : 'La pieza ya tenía ese estado; no hubo cambios.'
        );
    }
}
