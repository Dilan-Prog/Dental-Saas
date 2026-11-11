<?php

namespace App\Services\Expediente;

use App\Enums\DentitionType;
use App\Enums\ToothCondition;
use App\Models\Patient;
use App\Models\ToothFinding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Odontograma por pieza FDI. Un hallazgo nunca se borra ni se edita:
 * al cambiar el estado se cierra el vigente (superseded_at) y se crea uno nuevo.
 */
class OdontogramService
{
    /** Superficies: M mesial, D distal, O oclusal/incisal, V vestibular, L lingual, P palatina. */
    public const SURFACES = ['M', 'D', 'O', 'V', 'L', 'P'];

    /** Condiciones que admiten superficies. */
    public const WITH_SURFACES = ['caries', 'restauracion', 'endodoncia'];

    /** Filas del odontograma (orden visual de izquierda a derecha). */
    public static function rows(DentitionType $dentition): array
    {
        return $dentition === DentitionType::Permanente
            ? [
                'upper' => array_merge(range(18, 11), range(21, 28)),
                'lower' => array_merge(range(48, 41), range(31, 38)),
            ]
            : [
                'upper' => array_merge(range(55, 51), range(61, 65)),
                'lower' => array_merge(range(85, 81), range(71, 75)),
            ];
    }

    /** Estado vigente por pieza (colección keyBy tooth_fdi). */
    public function current(Patient $patient): Collection
    {
        return ToothFinding::query()
            ->where('patient_id', $patient->id)
            ->current()
            ->get()
            ->keyBy('tooth_fdi');
    }

    /** Historial de cambios (más reciente primero). */
    public function history(Patient $patient, int $limit = 30): Collection
    {
        return ToothFinding::query()
            ->where('patient_id', $patient->id)
            ->with('author.user:id,full_name')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Registra el estado de una pieza. Devuelve null si no hay cambios respecto al vigente.
     *
     * @param  string[]  $surfaces
     */
    public function record(
        Patient $patient,
        int $tooth,
        string $condition,
        array $surfaces,
        string $authorMemberId,
        ?string $encounterId = null,
        ?string $patientTreatmentId = null,
        ?Carbon $at = null,
    ): ?ToothFinding {
        $dentition = DentitionType::fromTooth($tooth);
        if (! $dentition) {
            throw new ExpedienteException("La pieza {$tooth} no es una pieza FDI válida.");
        }
        if (! in_array($condition, ToothCondition::values(), true)) {
            throw new ExpedienteException('La condición dental no es válida.');
        }

        $surfaces = $this->normalizeSurfaces($condition, $surfaces);
        $at ??= Carbon::now();

        return DB::transaction(function () use ($patient, $tooth, $condition, $surfaces, $authorMemberId, $encounterId, $patientTreatmentId, $at, $dentition) {
            $current = ToothFinding::query()
                ->where('patient_id', $patient->id)
                ->where('tooth_fdi', $tooth)
                ->current()
                ->lockForUpdate()
                ->first();

            if ($current
                && $current->condition->value === $condition
                && $this->sameSurfaces((array) $current->surfaces, $surfaces)
                && ($current->patient_treatment_id ?? null) === $patientTreatmentId) {
                return null;
            }

            if ($current) {
                $current->superseded_at = $at->greaterThanOrEqualTo($current->recorded_at) ? $at : $current->recorded_at;
                $current->save();
            }

            return ToothFinding::query()->create([
                'patient_id' => $patient->id,
                'tooth_fdi' => $tooth,
                'dentition' => $dentition->value,
                'condition' => $condition,
                'surfaces' => $surfaces ?: null,
                'encounter_id' => $encounterId,
                'patient_treatment_id' => $patientTreatmentId,
                'author_member_id' => $authorMemberId,
                'recorded_at' => $at,
            ]);
        });
    }

    /** @return string[] */
    public function normalizeSurfaces(string $condition, array $surfaces): array
    {
        if (! in_array($condition, self::WITH_SURFACES, true)) {
            return [];
        }

        $set = array_values(array_unique(array_map('strtoupper', array_filter($surfaces))));

        return array_values(array_filter(self::SURFACES, fn ($s) => in_array($s, $set, true)));
    }

    private function sameSurfaces(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    /** Resumen "Resina oclusal en 16" para la línea de tiempo. */
    public static function describe(ToothFinding $finding): string
    {
        $surfaces = (array) $finding->surfaces;
        $names = ['M' => 'mesial', 'D' => 'distal', 'O' => 'oclusal', 'V' => 'vestibular', 'L' => 'lingual', 'P' => 'palatina'];
        $detail = $surfaces ? ' ('.implode(', ', array_map(fn ($s) => $names[$s] ?? $s, $surfaces)).')' : '';

        return $finding->condition->label().$detail.' en '.$finding->tooth_fdi;
    }
}
