<?php

namespace App\Http\Requests\Agenda;

use App\Enums\AppointmentStatus;
use App\Models\Organization;
use App\Services\Agenda\AgendaCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Filtros de la agenda por querystring. Los valores inválidos no rompen la
 * página: se ignoran (vista semana, hoy, sin filtros).
 */
class AgendaFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('agenda', 'ver');
    }

    public function rules(): array
    {
        $org = Organization::currentId();

        return [
            'view' => ['nullable', Rule::in(AgendaCalendar::VIEWS)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'doctor' => ['nullable', 'uuid', Rule::exists('organization_members', 'id')->where('organization_id', $org)],
            'branch' => ['nullable', 'uuid', Rule::exists('branches', 'id')->where('organization_id', $org)],
            'status' => ['nullable', Rule::in(array_merge(['activas', 'todas'], AppointmentStatus::values()))],
            'patient' => ['nullable', 'uuid'],
        ];
    }

    /** Descarta (en lugar de fallar) los parámetros de consulta inválidos. */
    protected function failedValidation($validator): void
    {
        $this->bad = array_keys($validator->errors()->messages());
    }

    /** @var list<string> */
    public array $bad = [];

    public function filters(): array
    {
        $get = fn (string $k) => in_array($k, $this->bad, true) ? null : $this->query($k);

        $view = $get('view') ?: 'week';
        $date = $get('date') ? Carbon::createFromFormat('Y-m-d', $get('date'))->startOfDay() : Carbon::today();

        return [
            'view' => $view,
            'date' => $date,
            'doctor' => $get('doctor') ?: null,
            'branch' => $get('branch') ?: null,
            'status' => $get('status') ?: 'activas',
            'patient' => $get('patient') ?: null,
        ];
    }
}
