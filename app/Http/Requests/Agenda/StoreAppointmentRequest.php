<?php

namespace App\Http\Requests\Agenda;

use App\Http\Requests\Agenda\Concerns\ValidatesAppointmentTime;
use App\Models\Organization;
use App\Services\Agenda\AgendaLookup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    use ValidatesAppointmentTime;

    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('agenda', 'crear');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'notes' => is_string($this->notes) ? trim($this->notes) : $this->notes,
            'branch_id' => $this->filled('branch_id') ? $this->branch_id : null,
        ]);
    }

    public function rules(): array
    {
        $org = Organization::currentId();
        $min = (int) config('agenda.min_duration', 15);
        $max = (int) config('agenda.max_duration', 480);

        return [
            'patient_id' => ['required', 'uuid', Rule::exists('patients', 'id')
                ->where(fn ($q) => $q->where('organization_id', $org)->where('status', 'active')->whereNull('deleted_at'))],
            'doctor_member_id' => ['required', 'uuid', Rule::exists('organization_members', 'id')
                ->where(fn ($q) => $q->where('organization_id', $org)->where('is_active', 1))],
            'branch_id' => ['nullable', 'uuid', Rule::exists('branches', 'id')
                ->where(fn ($q) => $q->where('organization_id', $org)->where('status', 'active'))],
            'treatment_id' => ['required', 'uuid', Rule::exists('treatments', 'id')
                ->where(fn ($q) => $q->where('organization_id', $org)->where('status', 'active'))],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', "min:$min", "max:$max", 'multiple_of:5'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'patient_id.required' => 'Selecciona un paciente de la lista.',
            'patient_id.exists' => 'El paciente seleccionado no existe o está inactivo.',
            'patient_id.uuid' => 'Selecciona un paciente de la lista.',
            'doctor_member_id.required' => 'Selecciona un doctor.',
            'doctor_member_id.exists' => 'El doctor seleccionado no existe o está inactivo.',
            'branch_id.exists' => 'La sucursal seleccionada no existe o está inactiva.',
            'treatment_id.required' => 'Selecciona un tratamiento.',
            'treatment_id.exists' => 'El tratamiento seleccionado no existe o está inactivo.',
            'date.required' => 'La fecha es obligatoria.',
            'date.date_format' => 'La fecha no es válida (usa el formato aaaa-mm-dd).',
            'start_time.required' => 'La hora de inicio es obligatoria.',
            'start_time.date_format' => 'La hora de inicio no es válida (usa HH:MM).',
            'duration_minutes.required' => 'La duración es obligatoria.',
            'duration_minutes.integer' => 'La duración debe ser un número entero de minutos.',
            'duration_minutes.min' => 'La duración mínima es de :min minutos.',
            'duration_minutes.max' => 'La duración máxima es de :max minutos.',
            'duration_minutes.multiple_of' => 'La duración debe ser múltiplo de 5 minutos.',
            'notes.max' => 'Las notas no pueden superar :max caracteres.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $lookup = app(AgendaLookup::class);
            $branchId = $this->input('branch_id') ?: $lookup->mainBranch()?->id;

            if (! $v->errors()->has('doctor_member_id') && $this->filled('doctor_member_id')) {
                if (! $lookup->doctorExists($this->input('doctor_member_id'))) {
                    $v->errors()->add('doctor_member_id', 'El usuario seleccionado no es un doctor activo.');
                } elseif ($branchId && ! $lookup->doctorServesBranch($this->input('doctor_member_id'), $branchId)) {
                    $v->errors()->add('doctor_member_id', 'El doctor no está asignado a la sucursal seleccionada.');
                }
            }

            $minutes = $v->errors()->has('duration_minutes') ? null : (int) $this->input('duration_minutes');
            $this->validateTimeWindow($v, 'date', 'start_time', $minutes, 'start_time');
        });
    }

    public function attributes(): array
    {
        return [
            'patient_id' => 'paciente',
            'doctor_member_id' => 'doctor',
            'branch_id' => 'sucursal',
            'treatment_id' => 'tratamiento',
            'date' => 'fecha',
            'start_time' => 'hora de inicio',
            'duration_minutes' => 'duración',
            'notes' => 'notas',
        ];
    }
}
