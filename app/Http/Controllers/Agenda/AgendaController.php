<?php

namespace App\Http\Controllers\Agenda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agenda\AgendaFilterRequest;
use App\Models\Appointment;
use App\Models\Patient;
use App\Services\Agenda\AgendaCalendar;
use App\Services\Agenda\AgendaLookup;
use App\Services\Agenda\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use App\Models\Organization;

class AgendaController extends Controller
{
    public function index(AgendaFilterRequest $request, AgendaCalendar $calendar, AgendaLookup $lookup)
    {
        $f = $request->filters();
        $user = $request->user();
        $canEdit = $user->hasPermission('agenda', 'editar');
        $canCreate = $user->hasPermission('agenda', 'crear');

        $data = $calendar->build($f['view'], $f['date'], $f['doctor'], $f['branch'], $f['status'], $canEdit);

        $branches = $lookup->branches();
        $branchForForm = $f['branch'] ?: $lookup->mainBranch()?->id;

        // Paciente preseleccionado (?patient=uuid) -> abre "Nueva cita" ya llenada.
        $preselected = $f['patient'] ? Patient::query()->active()->find($f['patient']) : null;

        // Tras un error de validación en cancelar / reagendar se vuelve a abrir su modal.
        $reopen = null;
        $form = old('_form');
        if (in_array($form, ['cancel', 'reschedule'], true) && old('_appointment') && $this->bagHasErrors($form)) {
            $appt = Appointment::query()->find(old('_appointment'));
            if ($appt) {
                $reopen = ['form' => old('_form'), 'payload' => $calendar->payloadFor($appt, $canEdit)];
            }
        }

        return view('agenda.index', $data + [
            'filters' => $f,
            'doctors' => $lookup->doctors(),
            'formDoctors' => $lookup->doctors($branchForForm),
            'branches' => $branches,
            'treatments' => $lookup->treatments(),
            'preselected' => $preselected,
            'reopen' => $reopen,
            'createFailed' => old('_form') === 'create' && $this->defaultBagHasErrors(),
            'canCreate' => $canCreate,
            'canEdit' => $canEdit,
            'defaultBranchId' => $branchForForm,
            'statusOptions' => ['activas' => 'Activas', 'todas' => 'Todas'] + \App\Enums\AppointmentStatus::options(),
            'config' => config('agenda'),
        ]);
    }

    private function bagHasErrors(string $bag): bool
    {
        $errors = session('errors');

        return $errors && $errors->getBag($bag)->any();
    }

    private function defaultBagHasErrors(): bool
    {
        $errors = session('errors');

        return $errors && $errors->getBag('default')->any();
    }

    /** Buscador de pacientes del formulario de nueva cita (JSON). */
    public function patients(Request $request, AgendaLookup $lookup): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => $lookup->searchPatients($term)->map(fn (Patient $p) => [
                'id' => $p->id,
                'name' => $p->full_name,
                'folio' => $p->folio,
                'phone' => $p->phone,
            ])->values(),
        ]);
    }

    /** Horarios de inicio disponibles para un doctor en una fecha (JSON). */
    public function slots(Request $request, AvailabilityService $availability): JsonResponse
    {
        $org = Organization::currentId();

        $v = Validator::make($request->query(), [
            'doctor_member_id' => ['required', 'uuid', Rule::exists('organization_members', 'id')->where('organization_id', $org)],
            'date' => ['required', 'date_format:Y-m-d'],
            'duration_minutes' => ['nullable', 'integer', 'min:'.config('agenda.min_duration'), 'max:'.config('agenda.max_duration')],
            'patient_id' => ['nullable', 'uuid'],
            'ignore' => ['nullable', 'uuid'],
        ], [
            'doctor_member_id.required' => 'Selecciona un doctor.',
            'date.required' => 'Selecciona una fecha.',
            'date.date_format' => 'La fecha no es válida.',
        ]);

        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first(), 'data' => []], 422);
        }

        $date = Carbon::createFromFormat('Y-m-d', $request->query('date'))->startOfDay();
        $minutes = (int) ($request->query('duration_minutes') ?: config('agenda.default_duration'));

        return response()->json([
            'data' => $availability->slots(
                $request->query('doctor_member_id'),
                $date,
                $minutes,
                $request->query('patient_id') ?: null,
                $request->query('ignore') ?: null
            ),
        ]);
    }
}
