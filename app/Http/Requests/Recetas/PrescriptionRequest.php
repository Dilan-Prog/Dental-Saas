<?php

namespace App\Http\Requests\Recetas;

use App\Models\Medication;
use App\Models\Organization;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de recetas (borrador) y "Firmar y emitir".
 *
 * Campo `action`: borrador | emitir | vista (guarda borrador y abre la vista de impresión).
 */
class PrescriptionRequest extends FormRequest
{
    public const ACTIONS = ['borrador', 'emitir', 'vista'];

    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }
        if ($this->input('action') === 'emitir') {
            return $user->hasPermission('recetas', 'emitir_recetas');
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = collect((array) $this->input('items', []))
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row) {
                $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);

                // Medicamento del catálogo sin nombre: toma los datos del catálogo.
                if (filled($row['medication_id'] ?? null) && blank($row['medication_name'] ?? null)) {
                    $med = Medication::query()->find($row['medication_id']);
                    if ($med) {
                        $row['medication_name'] = $med->name;
                        $row['strength'] = $row['strength'] ?? $med->strength;
                        $row['presentation'] = filled($row['presentation'] ?? null) ? $row['presentation'] : $med->presentation;
                    }
                }

                return $row;
            })
            // Descarta renglones completamente vacíos (el renglón en blanco del formulario).
            ->filter(fn (array $row) => collect($row)->except('medication_id', 'strength')->filter(fn ($v) => filled($v))->isNotEmpty())
            ->values()
            ->all();

        $this->merge([
            'items' => $items,
            'diagnosis' => is_string($this->input('diagnosis')) ? trim($this->input('diagnosis')) : $this->input('diagnosis'),
            'general_instructions' => is_string($this->input('general_instructions')) ? trim($this->input('general_instructions')) : $this->input('general_instructions'),
            'action' => $this->input('action', 'borrador'),
        ]);
    }

    public function rules(): array
    {
        $org = Organization::currentId();

        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'patient_id' => [
                'required', 'uuid',
                Rule::exists('patients', 'id')
                    ->where('organization_id', $org)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->whereNull('anonymized_at'),
            ],
            'doctor_member_id' => [
                'required', 'uuid',
                Rule::exists('organization_members', 'id')->where('organization_id', $org)->where('is_active', 1),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! DB::table('doctor_profiles')->where('member_id', $value)->exists()) {
                        $fail('El usuario seleccionado no es un doctor.');
                    }
                },
            ],
            'issued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.now()->subYear()->toDateString()],
            'diagnosis' => ['nullable', 'string', 'max:1000', Rule::requiredIf(fn () => $this->input('action') === 'emitir')],
            'general_instructions' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.medication_id' => [
                'nullable', 'uuid',
                Rule::exists('medications', 'id')
                    ->where('status', 'active')
                    ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $org)),
            ],
            'items.*.medication_name' => ['required', 'string', 'max:200'],
            'items.*.strength' => ['nullable', 'string', 'max:80'],
            'items.*.presentation' => ['nullable', 'string', 'max:120'],
            'items.*.dose' => ['required', 'string', 'max:120'],
            'items.*.route' => ['nullable', 'string', 'max:60'],
            'items.*.frequency' => ['required', 'string', 'max:120'],
            'items.*.duration' => ['nullable', 'string', 'max:120'],
            'items.*.instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty() || $this->input('action') !== 'emitir') {
                return;
            }

            $user = $this->user();
            $memberId = $user?->member?->id;
            if (! $memberId || $memberId !== $this->input('doctor_member_id')) {
                $v->errors()->add('doctor_member_id', 'Solo el doctor que prescribe puede firmar y emitir la receta.');

                return;
            }

            $license = DB::table('doctor_profiles')->where('member_id', $memberId)->value('professional_license');
            if (blank($license)) {
                $v->errors()->add('doctor_member_id', 'El doctor no tiene cédula profesional registrada; no es posible emitir la receta.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'action.in' => 'La acción solicitada no es válida.',
            'patient_id.required' => 'Seleccione un paciente.',
            'patient_id.exists' => 'El paciente seleccionado no existe o no está activo.',
            'doctor_member_id.required' => 'Seleccione el doctor que prescribe.',
            'doctor_member_id.exists' => 'El doctor seleccionado no existe o no está activo.',
            'issued_on.required' => 'La fecha de la receta es obligatoria.',
            'issued_on.date_format' => 'La fecha de la receta no tiene un formato válido.',
            'issued_on.before_or_equal' => 'La fecha de la receta no puede ser futura.',
            'issued_on.after_or_equal' => 'La fecha de la receta no puede tener más de un año de antigüedad.',
            'diagnosis.required' => 'El diagnóstico es obligatorio para emitir la receta.',
            'diagnosis.max' => 'El diagnóstico no puede superar los 1000 caracteres.',
            'general_instructions.max' => 'Las indicaciones generales no pueden superar los 2000 caracteres.',
            'items.required' => 'Agregue al menos un medicamento.',
            'items.min' => 'Agregue al menos un medicamento.',
            'items.max' => 'Una receta admite como máximo 20 medicamentos.',
            'items.*.medication_id.exists' => 'El medicamento del catálogo no existe o está inactivo.',
            'items.*.medication_name.required' => 'Indique el nombre del medicamento :position.',
            'items.*.dose.required' => 'Indique la dosis del medicamento :position.',
            'items.*.frequency.required' => 'Indique la frecuencia del medicamento :position.',
            'items.*.medication_name.max' => 'El nombre del medicamento :position no puede superar los 200 caracteres.',
            'items.*.dose.max' => 'La dosis del medicamento :position no puede superar los 120 caracteres.',
            'items.*.frequency.max' => 'La frecuencia del medicamento :position no puede superar los 120 caracteres.',
            'items.*.duration.max' => 'La duración del medicamento :position no puede superar los 120 caracteres.',
            'items.*.presentation.max' => 'La presentación del medicamento :position no puede superar los 120 caracteres.',
            'items.*.strength.max' => 'La concentración del medicamento :position no puede superar los 80 caracteres.',
            'items.*.route.max' => 'La vía del medicamento :position no puede superar los 60 caracteres.',
            'items.*.instructions.max' => 'Las indicaciones del medicamento :position no pueden superar los 1000 caracteres.',
        ];
    }

    public function attributes(): array
    {
        return [
            'patient_id' => 'paciente',
            'doctor_member_id' => 'doctor',
            'issued_on' => 'fecha',
            'diagnosis' => 'diagnóstico',
            'general_instructions' => 'indicaciones generales',
        ];
    }

    /** Datos listos para PrescriptionService::save(). */
    public function payload(): array
    {
        $data = $this->validated();
        unset($data['action']);

        return $data;
    }
}
