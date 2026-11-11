<?php

namespace App\Http\Requests\Expediente;

use App\Models\Organization;
use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base de los FormRequest del módulo: autoriza (los permisos los aplica el middleware de ruta),
 * resuelve el paciente de la ruta y ofrece reglas "exists" acotadas a la clínica.
 */
abstract class ExpedienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function orgId(): ?string
    {
        return Organization::currentId();
    }

    protected function routePatient(): ?Patient
    {
        $p = $this->route('patient');

        return $p instanceof Patient ? $p : null;
    }

    /**
     * Menores de edad: quien acepta (consentimiento / autorización) debe ser uno de los tutores
     * registrados del paciente (el módulo Pacientes obliga a registrar tutor a los menores).
     * Devuelve el mensaje de error o null si no aplica / es válido.
     */
    protected function minorAcceptanceError(?Patient $patient, string $name, string $what = 'el consentimiento'): ?string
    {
        if (! $patient || ! $patient->birth_date || $patient->birth_date->age >= 18) {
            return null;
        }

        $norm = fn (string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim(\Illuminate\Support\Str::ascii($s))));
        $guardians = \App\Models\PatientGuardian::query()->where('patient_id', $patient->id)->pluck('full_name');

        if ($guardians->isEmpty()) {
            return 'El paciente es menor de edad y no tiene tutor registrado: registra al tutor en Pacientes antes de aceptar.';
        }
        if (! $guardians->contains(fn ($g) => $norm($g) === $norm($name))) {
            return "El paciente es menor de edad: {$what} debe aceptarlo uno de sus tutores registrados ({$guardians->implode(', ')}).";
        }

        return null;
    }

    /** exists:tabla,id acotado a la organización. */
    protected function existsInOrg(string $table, string $column = 'id'): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, $column)->where('organization_id', $this->orgId());
    }

    /** Convierte cadenas vacías en null para campos opcionales. */
    protected function nullify(array $keys): void
    {
        $merge = [];
        foreach ($keys as $key) {
            if ($this->has($key) && is_string($this->input($key)) && trim($this->input($key)) === '') {
                $merge[$key] = null;
            }
        }
        if ($merge) {
            $this->merge($merge);
        }
    }

    public function attributes(): array
    {
        return [
            'full_name' => 'nombre completo',
            'birth_date' => 'fecha de nacimiento',
            'phone' => 'teléfono',
            'email' => 'correo electrónico',
            'allergies' => 'alergias',
            'medical_history' => 'antecedentes médicos',
            'note_type' => 'tipo de nota',
            'responsible_doctor_member_id' => 'doctor responsable',
            'treatment_id' => 'tratamiento',
            'tooth_fdi' => 'pieza dental',
            'appointment_id' => 'cita relacionada',
            'encounter_id' => 'consulta',
            'subjective' => 'Subjetivo (S)',
            'objective' => 'Objetivo (O)',
            'analysis' => 'Análisis (A)',
            'plan' => 'Plan (P)',
            'prescription_text' => 'indicaciones',
            'content' => 'contenido',
            'reason' => 'motivo',
            'file' => 'archivo',
            'file_type' => 'tipo de archivo',
            'display_name' => 'nombre del archivo',
            'condition' => 'condición',
            'surfaces' => 'superficies',
            'surfaces.*' => 'superficie',
            'status' => 'estado',
            'started_at' => 'fecha de inicio',
            'completed_at' => 'fecha de término',
            'accepted_at' => 'fecha de aceptación',
            'accepted_by_name' => 'nombre de quien acepta',
            'method' => 'método',
            'witness_member_id' => 'testigo',
            'clinical_file_id' => 'documento adjunto',
        ];
    }
}
