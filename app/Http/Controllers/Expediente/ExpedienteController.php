<?php

namespace App\Http\Controllers\Expediente;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicalFileType;
use App\Enums\DentitionType;
use App\Enums\FiscalStatus;
use App\Enums\PlannedTreatmentStatus;
use App\Http\Requests\Expediente\StoreConsentRequest;
use App\Http\Requests\Expediente\UpdateMedicalHistoryRequest;
use App\Http\Requests\Expediente\UpdatePatientBasicRequest;
use App\Models\Appointment;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalFile;
use App\Models\ConsentAcceptance;
use App\Models\Invoice;
use App\Models\OrganizationMember;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\Treatment;
use App\Rules\MxPhone;
use App\Services\Expediente\FileService;
use App\Services\Expediente\NoteService;
use App\Services\Expediente\OdontogramService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Expediente clínico del paciente (expediente.show) con sus pestañas. */
class ExpedienteController extends BaseController
{
    public const TABS = [
        'resumen' => 'Resumen',
        'historial' => 'Historial',
        'notas' => 'Notas',
        'archivos' => 'Archivos',
        'odontograma' => 'Odontograma',
        'tratamientos' => 'Tratamientos',
        'consentimientos' => 'Consentimientos',
    ];

    public function __construct(
        private NoteService $notes,
        private OdontogramService $odontogram,
        private FileService $files,
    ) {
    }

    public function show(Request $request, Patient $patient)
    {
        $tab = $request->query('tab', 'resumen');
        if (! array_key_exists($tab, self::TABS)) {
            $tab = 'resumen';
        }

        $record = $this->notes->medicalRecordFor($patient);
        $patient->setRelation('medicalRecord', $record);

        $data = [
            'patient' => $patient,
            'record' => $record,
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $this->counts($patient),
            'canWrite' => $request->user()->hasPermission('expediente', 'editar'),
            'canSign' => $request->user()->hasPermission('expediente', 'firmar_notas'),
            'canCreate' => $request->user()->hasPermission('expediente', 'crear'),
            'memberId' => $this->memberId($request),
        ];

        $data += match ($tab) {
            'resumen' => $this->resumen($patient) + $this->noteForm($patient, $data['memberId']),
            'historial' => $this->historial($patient, $record),
            'notas' => $this->notasTab($request, $patient) + $this->noteForm($patient, $data['memberId']),
            'archivos' => $this->archivos($request, $patient),
            'odontograma' => $this->odontograma($request, $patient),
            'tratamientos' => $this->tratamientos($patient),
            'consentimientos' => $this->consentimientos($patient),
        };

        return view('expediente.show', $data);
    }

    // ---------------------------------------------------------------- acciones

    public function updateHistory(UpdateMedicalHistoryRequest $request, Patient $patient)
    {
        $record = $this->notes->medicalRecordFor($patient);
        $record->update($request->validated());

        return redirect($this->toPatient($patient, 'historial'))->with('success', 'Historial médico actualizado.');
    }

    public function updatePatient(UpdatePatientBasicRequest $request, Patient $patient)
    {
        if ($patient->anonymized_at) {
            return back()->with('error', 'Este paciente fue anonimizado y sus datos ya no se pueden editar.');
        }

        $data = $request->validated();
        $digits = MxPhone::normalize($data['phone']);

        $patient->update([
            'full_name' => $data['full_name'],
            'birth_date' => $data['birth_date'],
            'phone' => $digits ? substr($digits, 0, 2).' '.substr($digits, 2, 4).' '.substr($digits, 6) : $data['phone'],
            'email' => $data['email'] ?? null,
        ]);

        return back()->with('success', 'Datos del paciente actualizados.');
    }

    public function storeConsent(StoreConsentRequest $request, Patient $patient)
    {
        $data = $request->validated();
        $file = ! empty($data['clinical_file_id']) ? ClinicalFile::query()->find($data['clinical_file_id']) : null;
        $at = ! empty($data['accepted_at']) ? Carbon::parse($data['accepted_at']) : Carbon::now();

        ConsentAcceptance::query()->create([
            'patient_id' => $patient->id,
            'clinical_file_id' => $file?->id,
            'accepted_at' => $at,
            'accepted_by_name' => $data['accepted_by_name'],
            'method' => $data['method'],
            'witness_member_id' => $data['witness_member_id'] ?? null,
            'content_hash' => hash('sha256', implode('|', [
                $patient->id, $file?->sha256, $data['accepted_by_name'], $data['method'], $at->toIso8601String(),
            ])),
        ]);

        return redirect($this->toPatient($patient, 'consentimientos'))->with('success', 'Aceptación del consentimiento registrada.');
    }

    // ---------------------------------------------------------------- datos por pestaña

    private function counts(Patient $patient): array
    {
        return [
            'notas' => $this->notes->notesFor($patient)->count(),
            'archivos' => ClinicalFile::query()->where('patient_id', $patient->id)->count(),
            'tratamientos' => PatientTreatment::query()->where('patient_id', $patient->id)
                ->whereIn('status', [PlannedTreatmentStatus::Planeado->value, PlannedTreatmentStatus::EnProgreso->value])->count(),
            'consentimientos' => ConsentAcceptance::query()->where('patient_id', $patient->id)->count(),
        ];
    }

    private function resumen(Patient $patient): array
    {
        $pendingInvoices = Invoice::query()->where('patient_id', $patient->id)
            ->whereNotIn('fiscal_status', [FiscalStatus::Borrador->value, FiscalStatus::Cancelada->value])
            ->where('collection_status', '!=', 'pagada')->count();

        return [
            'nextAppointment' => Appointment::query()->where('patient_id', $patient->id)
                ->whereIn('status', [AppointmentStatus::Pendiente->value, AppointmentStatus::Confirmada->value])
                ->where('starts_at', '>=', Carbon::now())->orderBy('starts_at')
                ->with(['treatment:id,name', 'doctor.user:id,full_name'])->first(),
            'lastVisit' => $patient->lastVisit(),
            'balance' => $patient->pendingBalance(),
            'pendingInvoices' => $pendingInvoices,
            'activeTreatments' => PatientTreatment::query()->where('patient_id', $patient->id)
                ->whereIn('status', [PlannedTreatmentStatus::Planeado->value, PlannedTreatmentStatus::EnProgreso->value])
                ->with('treatment:id,name')->get(),
            'recentNotes' => $this->notes->notesFor($patient)->with(['responsibleDoctor.user:id,full_name'])
                ->orderByDesc('created_at')->limit(5)->get(),
        ];
    }

    private function historial(Patient $patient, $record): array
    {
        return [
            'encounters' => ClinicalEncounter::query()->where('medical_record_id', $record->id)
                ->with(['doctor.user:id,full_name', 'branch:id,name', 'notes'])
                ->orderByDesc('occurred_at')->limit(60)->get(),
            'upcoming' => Appointment::query()->where('patient_id', $patient->id)
                ->whereIn('status', [AppointmentStatus::Pendiente->value, AppointmentStatus::Confirmada->value])
                ->where('starts_at', '>=', Carbon::now())->orderBy('starts_at')->limit(5)
                ->with(['treatment:id,name', 'doctor.user:id,full_name'])->get(),
        ];
    }

    private function notasTab(Request $request, Patient $patient): array
    {
        $status = $request->query('estado');

        return [
            'noteList' => $this->notes->notesFor($patient)
                ->when(in_array($status, ['borrador', 'firmada'], true), fn ($q) => $q->where('status', $status))
                ->with(['responsibleDoctor.user:id,full_name', 'treatment:id,name'])
                ->withCount('addenda')
                ->orderByDesc('created_at')->paginate(12)->withQueryString(),
            'statusFilter' => $status,
        ];
    }

    /** Datos para el modal "Nueva nota clínica". */
    private function noteForm(Patient $patient, string $memberId): array
    {
        return [
            'doctors' => $this->notes->doctors(),
            'defaultDoctorId' => $this->notes->defaultDoctorId($patient, $memberId),
            'treatments' => Treatment::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function archivos(Request $request, Patient $patient): array
    {
        $filter = $request->query('tipo', 'todos');
        $query = ClinicalFile::query()->where('patient_id', $patient->id)->with('uploader.user:id,full_name');

        match ($filter) {
            'radiografias' => $query->where('file_type', ClinicalFileType::Radiografia->value),
            'fotos' => $query->where('file_type', ClinicalFileType::FotografiaIntraoral->value),
            'documentos' => $query->whereIn('file_type', [ClinicalFileType::Consentimiento->value, ClinicalFileType::Otro->value]),
            default => $filter = 'todos',
        };

        $list = $query->orderByDesc('created_at')->get();

        return [
            'fileFilter' => $filter,
            'fileList' => $list,
            'fileUrls' => $list->mapWithKeys(fn ($f) => [$f->id => $this->files->isGeneratedDocument($f) ? null : $this->files->signedUrl($f, true)]),
            'fileDownloadUrls' => $list->mapWithKeys(fn ($f) => [$f->id => $this->files->isGeneratedDocument($f) ? null : $this->files->signedUrl($f)]),
            'fileTypes' => ClinicalFileType::options(),
            'encounterOptions' => $this->encounterOptions($patient),
            'fileService' => $this->files,
        ];
    }

    private function odontograma(Request $request, Patient $patient): array
    {
        $dentition = DentitionType::tryFrom((string) $request->query('denticion', '')) ?? DentitionType::Permanente;

        return [
            'dentition' => $dentition,
            'rows' => OdontogramService::rows($dentition),
            'current' => $this->odontogram->current($patient),
            'toothHistory' => $this->odontogram->history($patient),
            'patientTreatments' => PatientTreatment::query()->where('patient_id', $patient->id)
                ->whereIn('status', [PlannedTreatmentStatus::Planeado->value, PlannedTreatmentStatus::EnProgreso->value])
                ->with('treatment:id,name')->get(),
            'encounterOptions' => $this->encounterOptions($patient),
        ];
    }

    private function tratamientos(Patient $patient): array
    {
        return [
            'planItems' => PatientTreatment::query()->where('patient_id', $patient->id)
                ->with(['treatment:id,name,price', 'quoteItem.quote:id,folio'])
                ->orderByRaw("FIELD(status, 'en_progreso', 'planeado', 'realizado', 'cancelado')")
                ->orderByDesc('started_at')->get(),
            'catalog' => Treatment::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'transitions' => \App\Services\Expediente\PatientTreatmentService::TRANSITIONS,
        ];
    }

    private function consentimientos(Patient $patient): array
    {
        return [
            'consents' => ConsentAcceptance::query()->where('patient_id', $patient->id)
                ->with(['clinicalFile:id,display_name,mime_type', 'witness.user:id,full_name'])
                ->orderByDesc('accepted_at')->get(),
            'consentFiles' => ClinicalFile::query()->where('patient_id', $patient->id)
                ->where('file_type', ClinicalFileType::Consentimiento->value)->orderByDesc('created_at')->get(['id', 'display_name', 'created_at']),
            'members' => OrganizationMember::query()->where('is_active', true)->with('user:id,full_name')->get()
                ->sortBy(fn ($m) => $m->user?->full_name)->values(),
            'guardians' => $patient->guardians()->get(['id', 'full_name']),
            'methods' => StoreConsentRequest::METHODS,
        ];
    }

    private function encounterOptions(Patient $patient)
    {
        $record = $patient->medicalRecord;

        return $record
            ? ClinicalEncounter::query()->where('medical_record_id', $record->id)->orderByDesc('occurred_at')->limit(20)->get(['id', 'occurred_at'])
            : collect();
    }
}
