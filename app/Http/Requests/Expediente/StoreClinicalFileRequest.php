<?php

namespace App\Http\Requests\Expediente;

use App\Enums\ClinicalFileType;
use App\Models\MedicalRecord;
use App\Services\Expediente\FileService;
use Illuminate\Validation\Rule;

/** Carga de un archivo clínico (JPG, PNG o PDF hasta 20 MB). */
class StoreClinicalFileRequest extends ExpedienteRequest
{
    protected function prepareForValidation(): void
    {
        $this->nullify(['display_name', 'encounter_id']);
    }

    public function rules(): array
    {
        $patientId = $this->routePatient()?->id;

        return [
            'file' => [
                'required', 'file',
                'mimes:'.implode(',', FileService::ALLOWED_EXTENSIONS),
                'mimetypes:'.implode(',', FileService::ALLOWED_MIMES),
                'max:'.FileService::MAX_KB,
            ],
            'file_type' => ['required', Rule::in(ClinicalFileType::values())],
            'display_name' => ['nullable', 'string', 'max:250'],
            'encounter_id' => ['nullable', function ($attr, $value, $fail) use ($patientId) {
                $ok = $value && MedicalRecord::query()->where('patient_id', $patientId)
                    ->whereHas('encounters', fn ($q) => $q->where('id', $value))->exists();
                if (! $ok) {
                    $fail('La consulta seleccionada no pertenece a este paciente.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Selecciona un archivo.',
            'file.mimes' => 'Sólo se permiten archivos JPG, PNG o PDF.',
            'file.mimetypes' => 'El contenido del archivo no es JPG, PNG ni PDF.',
            'file.max' => 'El archivo no puede pesar más de 20 MB.',
            'file.uploaded' => 'No se pudo subir el archivo (¿excede el límite del servidor?).',
        ];
    }
}
