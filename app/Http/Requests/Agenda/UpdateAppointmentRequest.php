<?php

namespace App\Http\Requests\Agenda;

use App\Models\Organization;
use App\Services\Agenda\AgendaLookup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Edita doctor, tratamiento, duración y notas de una cita pendiente/confirmada. */
class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('agenda', 'editar');
    }

    public function rules(): array
    {
        $org = Organization::currentId();
        $min = (int) config('agenda.min_duration', 15);
        $max = (int) config('agenda.max_duration', 480);

        return [
            'doctor_member_id' => ['sometimes', 'required', 'uuid', Rule::exists('organization_members', 'id')
                ->where(fn ($q) => $q->where('organization_id', $org)->where('is_active', 1))],
            'treatment_id' => ['sometimes', 'required', 'uuid', Rule::exists('treatments', 'id')
                ->where(fn ($q) => $q->where('organization_id', $org)->where('status', 'active'))],
            'duration_minutes' => ['sometimes', 'required', 'integer', "min:$min", "max:$max", 'multiple_of:5'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'doctor_member_id.exists' => 'El doctor seleccionado no existe o está inactivo.',
            'treatment_id.exists' => 'El tratamiento seleccionado no existe o está inactivo.',
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
            if ($v->errors()->has('doctor_member_id') || ! $this->filled('doctor_member_id')) {
                return;
            }
            $appointment = $this->route('appointment');
            $lookup = app(AgendaLookup::class);

            if (! $lookup->doctorExists($this->input('doctor_member_id'))) {
                $v->errors()->add('doctor_member_id', 'El usuario seleccionado no es un doctor activo.');
            } elseif ($appointment && ! $lookup->doctorServesBranch($this->input('doctor_member_id'), $appointment->branch_id)) {
                $v->errors()->add('doctor_member_id', 'El doctor no está asignado a la sucursal de la cita.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'doctor_member_id' => 'doctor',
            'treatment_id' => 'tratamiento',
            'duration_minutes' => 'duración',
            'notes' => 'notas',
        ];
    }
}
