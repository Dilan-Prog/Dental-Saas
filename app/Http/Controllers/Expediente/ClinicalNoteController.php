<?php

namespace App\Http\Controllers\Expediente;

use App\Enums\AppointmentStatus;
use App\Http\Requests\Expediente\StoreAddendumRequest;
use App\Http\Requests\Expediente\StoreClinicalNoteRequest;
use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\Expediente\DbErrors;
use App\Services\Expediente\ExpedienteException;
use App\Services\Expediente\NoteService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Notas clínicas (simple y SOAP): borrador, firma y adendas. */
class ClinicalNoteController extends BaseController
{
    public function __construct(private NoteService $notes)
    {
    }

    public function create(Request $request, Patient $patient)
    {
        $type = $request->query('tipo') === 'simple' ? 'simple' : 'soap';

        return view('expediente.notas.form', $this->formData($request, $patient) + [
            'note' => null,
            'noteType' => $type,
        ]);
    }

    public function store(StoreClinicalNoteRequest $request, Patient $patient)
    {
        $data = $request->validated();

        try {
            $note = $this->notes->create($patient, $data, $this->memberId($request));
        } catch (ExpedienteException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return back()->withInput()->with('error', DbErrors::message($e));
        }

        if (($data['action'] ?? 'guardar') === 'firmar') {
            return $this->signAndRedirect($request, $patient, $note);
        }

        return redirect($this->toPatient($patient, 'notas'))->with('success', 'Nota guardada como borrador.');
    }

    public function show(Request $request, Patient $patient, ClinicalNote $note)
    {
        $this->ensureBelongs($patient, $note);
        $note->load(['responsibleDoctor.user:id,full_name', 'author.user:id,full_name', 'signedBy.user:id,full_name', 'treatment:id,name', 'addenda.author.user:id,full_name', 'encounter']);

        $editable = ! $note->isSigned() && ($this->isAdmin($request)
            || in_array($this->memberId($request), [$note->author_member_id, $note->responsible_doctor_member_id], true));

        return view('expediente.notas.show', $this->formData($request, $patient) + [
            'note' => $note,
            'noteType' => $note->note_type->value,
            'editable' => $editable,
            'integrityOk' => $note->isSigned() ? $this->notes->verifyIntegrity($note) : null,
            'canSignThis' => ! $note->isSigned() && $note->responsible_doctor_member_id === $this->memberId($request)
                && $request->user()->hasPermission('expediente', 'firmar_notas'),
            'canAddendum' => $note->isSigned() && $request->user()->hasPermission('expediente', 'firmar_notas'),
        ]);
    }

    public function update(StoreClinicalNoteRequest $request, Patient $patient, ClinicalNote $note)
    {
        $this->ensureBelongs($patient, $note);
        $data = $request->validated();

        try {
            $this->notes->update($note, $data, $this->memberId($request), $this->isAdmin($request));
        } catch (ExpedienteException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return back()->withInput()->with('error', DbErrors::message($e));
        }

        if (($data['action'] ?? 'guardar') === 'firmar') {
            return $this->signAndRedirect($request, $patient, $note);
        }

        return redirect(route('expediente.notas.show', [$patient->id, $note->id]))->with('success', 'Borrador actualizado.');
    }

    public function sign(Request $request, Patient $patient, ClinicalNote $note)
    {
        $this->ensureBelongs($patient, $note);

        return $this->signAndRedirect($request, $patient, $note);
    }

    public function destroy(Request $request, Patient $patient, ClinicalNote $note)
    {
        $this->ensureBelongs($patient, $note);

        return $this->attempt(
            fn () => $this->notes->delete($note, $this->memberId($request), $this->isAdmin($request)),
            'Borrador eliminado.',
            $this->toPatient($patient, 'notas')
        );
    }

    public function addendum(StoreAddendumRequest $request, Patient $patient, ClinicalNote $note)
    {
        $this->ensureBelongs($patient, $note);
        $data = $request->validated();

        return $this->attempt(
            fn () => $this->notes->addAddendum($note, $data['content'], $data['reason'], $this->memberId($request)),
            'Adenda registrada.',
            route('expediente.notas.show', [$patient->id, $note->id])
        );
    }

    // ------------------------------------------------------------------

    private function signAndRedirect(Request $request, Patient $patient, ClinicalNote $note)
    {
        try {
            $this->notes->sign($note, $this->memberId($request));
        } catch (ExpedienteException $e) {
            return redirect(route('expediente.notas.show', [$patient->id, $note->id]))
                ->with('error', $e->getMessage().' La nota quedó como borrador.');
        } catch (QueryException $e) {
            report($e);

            return redirect(route('expediente.notas.show', [$patient->id, $note->id]))
                ->with('error', DbErrors::message($e).' La nota quedó como borrador.');
        }

        return redirect($this->toPatient($patient, 'notas'))->with('success', 'Nota firmada. Ya no se puede editar; usa una adenda para correcciones.');
    }

    private function ensureBelongs(Patient $patient, ClinicalNote $note): void
    {
        abort_unless($this->notes->notesFor($patient)->whereKey($note->id)->exists(), 404);
    }

    private function formData(Request $request, Patient $patient): array
    {
        $record = $this->notes->medicalRecordFor($patient);

        return [
            'patient' => $patient,
            'doctors' => $this->notes->doctors(),
            'defaultDoctorId' => $this->notes->defaultDoctorId($patient, $this->memberId($request)),
            'treatments' => Treatment::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'appointments' => Appointment::query()->where('patient_id', $patient->id)
                ->whereIn('status', [AppointmentStatus::EnCurso->value, AppointmentStatus::Completada->value, AppointmentStatus::Confirmada->value, AppointmentStatus::Pendiente->value])
                ->where('starts_at', '>=', Carbon::now()->subDays(45))->where('starts_at', '<=', Carbon::now()->addDay())
                ->with('treatment:id,name')->orderByDesc('starts_at')->limit(15)->get(),
            'encounters' => $record->encounters()->reorder('occurred_at', 'desc')->limit(15)->get(['id', 'occurred_at']),
            'canSign' => $request->user()->hasPermission('expediente', 'firmar_notas'),
            'memberId' => $this->memberId($request),
            'record' => $record,
        ];
    }
}
