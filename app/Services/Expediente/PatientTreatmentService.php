<?php

namespace App\Services\Expediente;

use App\Enums\PlannedTreatmentStatus as S;
use App\Models\Patient;
use App\Models\PatientTreatment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tratamientos del paciente (plan): planeado -> en progreso -> realizado | cancelado.
 * "Aprobado" (presupuesto) != "realizado" (clínico) != "cobrado" (facturación): cada hecho en su tabla.
 */
class PatientTreatmentService
{
    /** @var array<string, string[]> */
    public const TRANSITIONS = [
        'planeado' => ['en_progreso', 'realizado', 'cancelado'],
        'en_progreso' => ['realizado', 'cancelado'],
        'cancelado' => ['planeado'],
        'realizado' => [],
    ];

    public function create(Patient $patient, array $data): PatientTreatment
    {
        $status = $data['status'] ?? S::Planeado->value;
        $now = Carbon::now();

        $started = $data['started_at'] ?? null;
        $completed = $data['completed_at'] ?? null;

        if ($status === S::EnProgreso->value) {
            $started ??= $now;
        } elseif ($status === S::Realizado->value) {
            $started ??= $completed ?? $now;
            $completed ??= $now;
        } elseif ($status === S::Planeado->value || $status === S::Cancelado->value) {
            $completed = null;
        }

        return PatientTreatment::query()->create([
            'patient_id' => $patient->id,
            'treatment_id' => $data['treatment_id'],
            'tooth_fdi' => $data['tooth_fdi'] ?? null,
            'status' => $status,
            'started_at' => $started,
            'completed_at' => $completed,
        ]);
    }

    public function changeStatus(PatientTreatment $item, string $to): PatientTreatment
    {
        return DB::transaction(function () use ($item, $to) {
            $locked = PatientTreatment::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status->value;

            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new ExpedienteException(sprintf(
                    'No se puede pasar el tratamiento de "%s" a "%s".',
                    S::from($from)->label(),
                    S::tryFrom($to)?->label() ?? $to
                ));
            }

            $now = Carbon::now();
            $locked->status = $to;

            if ($to === S::EnProgreso->value) {
                $locked->started_at ??= $now;
                $locked->completed_at = null;
            } elseif ($to === S::Realizado->value) {
                $locked->started_at ??= $now;
                $locked->completed_at = $now;
            } elseif ($to === S::Planeado->value) {
                $locked->started_at = null;
                $locked->completed_at = null;
            }

            $locked->save();

            return $locked;
        });
    }

    /** Sólo se elimina un tratamiento planeado sin origen en presupuesto ni hallazgos ligados. */
    public function delete(PatientTreatment $item): void
    {
        if ($item->status !== S::Planeado) {
            throw new ExpedienteException('Sólo se pueden eliminar tratamientos en estado planeado; cancela el tratamiento en su lugar.');
        }
        if ($item->quote_item_id) {
            throw new ExpedienteException('Este tratamiento proviene de un presupuesto; cancélalo en lugar de eliminarlo.');
        }
        if ($item->toothFindings()->exists()) {
            throw new ExpedienteException('Este tratamiento tiene hallazgos del odontograma ligados; cancélalo en lugar de eliminarlo.');
        }

        $item->delete();
    }
}
