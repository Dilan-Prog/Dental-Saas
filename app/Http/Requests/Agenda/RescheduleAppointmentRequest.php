<?php

namespace App\Http\Requests\Agenda;

use App\Http\Requests\Agenda\Concerns\ValidatesAppointmentTime;
use App\Models\Organization;
use App\Services\Agenda\AgendaLookup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RescheduleAppointmentRequest extends FormRequest
{
    use ValidatesAppointmentTime;

    /** Los errores de este formulario van en su propia bolsa (modal «Reagendar»). */
    protected $errorBag = 'reschedule';

    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('agenda', 'editar');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'doctor_member_id' => $this->filled('doctor_member_id') ? $this->doctor_member_id : null,
            'duration_minutes' => $this->filled('duration_minutes') ? $this->duration_minutes : null,
            'change_reason' => is_string($this->change_reason) ? trim($this->change_reason) : $this->change_reason,
        ]);
    }

    public function rules(): array
    {
        $org = Organization::currentId();
        $min = (int) config('agenda.min_duration', 15);
        $max = (int) config('agenda.max_duration', 480);

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', "min:$min", "max:$max", 'multiple_of:5'],
            'doctor_member_id' => ['nullable', 'uuid', Rule::exists('organization_members', 'id')
                ->where(fn ($q) => $q->where('organization_id', $org)->where('is_active', 1))],
            'change_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'La nueva fecha es obligatoria.',
            'date.date_format' => 'La fecha no es válida (usa el formato aaaa-mm-dd).',
            'start_time.required' => 'La nueva hora de inicio es obligatoria.',
            'start_time.date_format' => 'La hora de inicio no es válida (usa HH:MM).',
            'duration_minutes.integer' => 'La duración debe ser un número entero de minutos.',
            'duration_minutes.min' => 'La duración mínima es de :min minutos.',
            'duration_minutes.max' => 'La duración máxima es de :max minutos.',
            'duration_minutes.multiple_of' => 'La duración debe ser múltiplo de 5 minutos.',
            'doctor_member_id.exists' => 'El doctor seleccionado no existe o está inactivo.',
            'change_reason.max' => 'El motivo no puede superar :max caracteres.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $appointment = $this->route('appointment');
            $lookup = app(AgendaLookup::class);

            if ($this->filled('doctor_member_id') && ! $v->errors()->has('doctor_member_id')) {
                if (! $lookup->doctorExists($this->input('doctor_member_id'))) {
                    $v->errors()->add('doctor_member_id', 'El usuario seleccionado no es un doctor activo.');
                } elseif ($appointment && ! $lookup->doctorServesBranch($this->input('doctor_member_id'), $appointment->branch_id)) {
                    $v->errors()->add('doctor_member_id', 'El doctor no está asignado a la sucursal de la cita.');
                }
            }

            $minutes = $v->errors()->has('duration_minutes')
                ? null
                : (int) ($this->input('duration_minutes') ?: ($appointment?->duration_minutes ?? config('agenda.default_duration', 30)));

            $this->validateTimeWindow($v, 'date', 'start_time', $minutes, 'start_time');
        });
    }

    public function attributes(): array
    {
        return [
            'date' => 'fecha',
            'start_time' => 'hora de inicio',
            'duration_minutes' => 'duración',
            'doctor_member_id' => 'doctor',
            'change_reason' => 'motivo',
        ];
    }
}
