<?php

namespace App\Http\Controllers\Agenda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agenda\CancelAppointmentRequest;
use App\Http\Requests\Agenda\RescheduleAppointmentRequest;
use App\Http\Requests\Agenda\StoreAppointmentRequest;
use App\Http\Requests\Agenda\UpdateAppointmentRequest;
use App\Models\Appointment;
use App\Services\Agenda\AgendaCalendar;
use App\Services\Agenda\AgendaLookup;
use App\Services\Agenda\AppointmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $service)
    {
    }

    public function store(StoreAppointmentRequest $request): RedirectResponse
    {
        $appointment = $this->service->create($request->validated(), $request->user());
        $appointment->load('patient');

        $view = in_array($request->input('_view'), AgendaCalendar::VIEWS, true) ? $request->input('_view') : 'week';

        return redirect()
            ->route('agenda.index', ['view' => $view, 'date' => $appointment->starts_at->toDateString()])
            ->with('success', 'Cita programada para '.$appointment->patient->full_name.' el '
                .$appointment->starts_at->translatedFormat('j \d\e F').' a las '.$appointment->starts_at->format('H:i').'.');
    }

    public function show(Appointment $appointment, AgendaCalendar $calendar, AgendaLookup $lookup, Request $request)
    {
        $appointment->load(['patient', 'treatment', 'doctor.user', 'branch', 'creator', 'encounter', 'statusHistory.actor']);
        $canEdit = $request->user()->hasPermission('agenda', 'editar');
        $payload = $calendar->payloadFor($appointment, $canEdit);

        return view('agenda.show', [
            'appointment' => $appointment,
            'payload' => $payload,
            'canEdit' => $canEdit,
            'editable' => $payload['can']['reschedule'],
            'doctors' => $lookup->doctors($appointment->branch_id),
            'treatments' => $lookup->treatments(),
            'history' => $appointment->statusHistory->sortByDesc('created_at')->values(),
            'patientUrl' => Route::has('pacientes.show') ? route('pacientes.show', $appointment->patient_id) : null,
            'recordUrl' => Route::has('expediente.show') ? route('expediente.show', $appointment->patient_id) : null,
        ]);
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $this->service->update($appointment, $request->validated(), $request->user());

        return back()->with('success', 'Cita actualizada.');
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $data = $request->validated();
        $data['reason'] = $data['change_reason'] ?? null;

        try {
            $a = $this->service->reschedule($appointment, $data, $request->user());
        } catch (ValidationException $e) {
            // Los choques de horario se muestran dentro del modal «Reagendar».
            if (isset($e->errors()['start_time']) || isset($e->errors()['doctor_member_id'])) {
                throw $e->errorBag('reschedule');
            }

            throw $e;
        }

        return back()->with('success', 'Cita reprogramada para el '.$a->starts_at->translatedFormat('j \d\e F').' a las '.$a->starts_at->format('H:i').'.');
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $this->service->cancel($appointment, $request->validated('reason'), $request->user());

        return back()->with('success', 'Cita cancelada.');
    }

    public function confirm(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->service->confirm($appointment, $request->user());

        return back()->with('success', 'Cita confirmada.');
    }

    public function start(Request $request, Appointment $appointment): RedirectResponse
    {
        $encounter = $this->service->start($appointment, $request->user());

        $msg = 'Cita iniciada. Se abrió la consulta en el expediente del paciente.';

        if (Route::has('expediente.show')) {
            return redirect()->route('expediente.show', $encounter->medicalRecord->patient_id)->with('success', $msg);
        }

        return back()->with('success', $msg);
    }

    public function complete(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->service->complete($appointment, $request->user());

        return back()->with('success', 'Cita completada.');
    }

    public function noshow(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->service->markNoShow($appointment, $request->user());

        return back()->with('success', 'Cita marcada como "No asistió".');
    }
}
