<?php

namespace App\Http\Requests\Expediente;

use App\Enums\SexType;
use App\Models\Patient;
use App\Rules\MxPhone;
use App\Services\Expediente\DocumentService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Generación de documentos clínicos: valida según la plantilla de la ruta. */
class StoreDocumentRequest extends ExpedienteRequest
{
    protected function template(): string
    {
        return (string) $this->route('template');
    }

    protected function prepareForValidation(): void
    {
        $this->nullify([
            'birth_date', 'sex', 'address', 'phone', 'occupation', 'chief_complaint', 'bad_experience', 'bad_experience_reason',
            'hygiene', 'brushing_times', 'under_treatment', 'allergies_detail', 'blood_pressure', 'pulse', 'respiration',
            'temperature', 'observations',
        ]);

        // Filas dinámicas: se descartan las que vienen totalmente vacías.
        foreach (['fluoride', 'diagnoses', 'activities'] as $key) {
            $rows = collect($this->input($key, []))
                ->filter(fn ($r) => is_array($r) && collect($r)->contains(fn ($v) => filled($v)))
                ->values()->all();
            $this->merge([$key => $rows]);
        }

        $this->merge([
            'sync_record' => $this->boolean('sync_record'),
            'register_acceptance' => $this->boolean('register_acceptance'),
        ]);
    }

    public function rules(): array
    {
        $base = [
            'patient_id' => ['required', $this->existsInOrg('patients')],
        ];

        return $base + match ($this->template()) {
            DocumentService::HISTORIA => $this->historiaRules(),
            DocumentService::AUTORIZACION => $this->autorizacionRules(),
            default => [],
        };
    }

    private function historiaRules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:250'],
            'birth_date' => ['nullable', 'date', 'after:1900-01-01', 'before_or_equal:today'],
            'sex' => ['nullable', Rule::in(SexType::values())],
            'address' => ['nullable', 'string', 'max:300'],
            'phone' => ['nullable', 'string', 'max:30', new MxPhone()],
            'occupation' => ['nullable', 'string', 'max:120'],
            'chief_complaint' => ['nullable', 'string', 'max:1000'],
            'bad_experience' => ['nullable', Rule::in(['si', 'no'])],
            'bad_experience_reason' => ['nullable', 'required_if:bad_experience,si', 'string', 'max:500'],
            'hygiene' => ['nullable', Rule::in(['cepillo', 'no_utiliza'])],
            'brushing_times' => ['nullable', 'integer', 'between:0,10'],
            'extraction_complications' => ['nullable', 'array'],
            'extraction_complications.*' => [Rule::in(array_keys(DocumentService::EXTRACTION_COMPLICATIONS))],
            'under_treatment' => ['nullable', 'string', 'max:1000'],
            'conditions' => ['nullable', 'array'],
            'conditions.*' => [Rule::in(array_keys(DocumentService::CONDITIONS))],
            'allergies_detail' => ['nullable', 'string', 'max:1000'],
            'blood_pressure' => ['nullable', 'regex:/^\d{2,3}\/\d{2,3}$/'],
            'pulse' => ['nullable', 'integer', 'between:20,250'],
            'respiration' => ['nullable', 'integer', 'between:5,60'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'sync_record' => ['boolean'],
        ];
    }

    private function autorizacionRules(): array
    {
        return [
            'fluoride' => ['nullable', 'array', 'max:20'],
            'fluoride.*.year' => ['required', 'integer', 'between:1990,'.now()->year],
            'fluoride.*.details' => ['nullable', 'string', 'max:300'],
            'diagnoses' => ['nullable', 'array', 'max:30'],
            'diagnoses.*.diagnosis' => ['required', 'string', 'max:300'],
            'diagnoses.*.date' => ['nullable', 'date', 'after:1990-01-01', 'before_or_equal:today'],
            'activities' => ['nullable', 'array', 'max:50'],
            'activities.*.date' => ['required', 'date', 'after:1990-01-01', 'before_or_equal:today'],
            'activities.*.activity' => ['required', 'string', 'max:500'],
            'activities.*.doctor' => ['nullable', 'string', 'max:200'],
            'register_acceptance' => ['boolean'],
            'accepted_by_name' => [Rule::requiredIf(fn () => $this->boolean('register_acceptance')), 'nullable', 'string', 'min:3', 'max:250'],
            'acceptance_method' => [Rule::requiredIf(fn () => $this->boolean('register_acceptance')), 'nullable', Rule::in(['firma_digital', 'verbal_registrada'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty() || $this->template() !== DocumentService::AUTORIZACION) {
                return;
            }
            if (empty($this->input('diagnoses')) && empty($this->input('activities'))) {
                $v->errors()->add('diagnoses', 'Registra al menos un diagnóstico o una actividad del plan de tratamiento.');
            }

            $patient = Patient::query()->find($this->input('patient_id'));
            if ($patient && $this->boolean('register_acceptance') && $patient->birth_date && $patient->birth_date->age < 18
                && mb_strtolower(trim((string) $this->input('accepted_by_name'))) === mb_strtolower($patient->full_name)) {
                $v->errors()->add('accepted_by_name', 'El paciente es menor de edad: la autorización debe aceptarla su tutor.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'patient_id.required' => 'Selecciona al paciente.',
            'patient_id.exists' => 'El paciente seleccionado no existe.',
            'bad_experience_reason.required_if' => 'Explica por qué fue desagradable la experiencia anterior.',
            'blood_pressure.regex' => 'La presión arterial debe tener el formato 120/80.',
            'pulse.between' => 'El pulso debe estar entre 20 y 250 lpm.',
            'respiration.between' => 'La respiración debe estar entre 5 y 60 por minuto.',
            'temperature.between' => 'La temperatura debe estar entre 30 y 45 °C.',
            'fluoride.*.year.between' => 'El año de aplicación de flúor no es válido.',
            'activities.*.date.required' => 'Cada actividad necesita una fecha.',
            'activities.*.activity.required' => 'Describe la actividad realizada.',
            'diagnoses.*.diagnosis.required' => 'Escribe el diagnóstico.',
            'accepted_by_name.required_if' => 'Indica quién acepta (paciente o tutor).',
            'acceptance_method.required_if' => 'Selecciona el método de aceptación.',
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + [
            'blood_pressure' => 'presión arterial',
            'pulse' => 'pulso',
            'respiration' => 'respiración',
            'temperature' => 'temperatura',
            'brushing_times' => 'veces de cepillado',
            'address' => 'domicilio',
            'occupation' => 'ocupación',
            'chief_complaint' => 'motivo de la consulta',
            'sex' => 'sexo',
        ];
    }
}
