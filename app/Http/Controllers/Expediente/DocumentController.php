<?php

namespace App\Http\Controllers\Expediente;

use App\Http\Requests\Expediente\StoreDocumentRequest;
use App\Models\ClinicalFile;
use App\Models\Patient;
use App\Services\Expediente\DocumentService;
use App\Services\Expediente\FileService;
use Illuminate\Http\Request;

/** Generación de documentos clínicos (documentos.*). */
class DocumentController extends BaseController
{
    public function __construct(private DocumentService $documents, private FileService $files)
    {
    }

    public function index(Request $request)
    {
        $generated = ClinicalFile::query()->where('mime_type', DocumentService::MIME)
            ->with(['patient:id,full_name,folio', 'uploader.user:id,full_name'])
            ->orderByDesc('created_at')->paginate(10);

        return view('documentos.index', [
            'templates' => DocumentService::templates(),
            'generated' => $generated,
            'canCreate' => $request->user()->hasPermission('documentos', 'crear'),
            'documents' => $this->documents,
        ]);
    }

    public function create(Request $request, string $template)
    {
        abort_unless(DocumentService::exists($template), 404);

        $patient = null;
        if ($request->filled('patient')) {
            $patient = Patient::query()->with('medicalRecord')->find($request->query('patient'));
        }

        return view('documentos.create', [
            'template' => $template,
            'meta' => DocumentService::templates()[$template],
            'patients' => Patient::query()->active()->orderBy('full_name')->get(['id', 'full_name', 'folio']),
            'patient' => $patient,
            'defaults' => $patient ? $this->documents->defaults($patient, $template) : [],
            'conditions' => DocumentService::CONDITIONS,
            'complications' => DocumentService::EXTRACTION_COMPLICATIONS,
            'doctors' => app(\App\Services\Expediente\NoteService::class)->doctors(),
        ]);
    }

    /** Vista previa: la plantilla en blanco lista para imprimir. */
    public function preview(string $template)
    {
        abort_unless(DocumentService::exists($template), 404);

        return view('documentos.print', [
            'template' => $template,
            'meta' => DocumentService::templates()[$template],
            'payload' => null,
            'data' => [],
            'patientInfo' => null,
            'conditions' => DocumentService::CONDITIONS,
            'complications' => DocumentService::EXTRACTION_COMPLICATIONS,
            'blank' => true,
            'file' => null,
        ]);
    }

    public function store(StoreDocumentRequest $request, string $template)
    {
        abort_unless(DocumentService::exists($template), 404);

        $patient = Patient::query()->findOrFail($request->input('patient_id'));
        $data = $request->validated();

        return $this->attempt(
            function () use (&$file, $patient, $template, $data, $request) {
                $file = $this->documents->store($patient, $template, $data, $this->memberId($request));
            },
            'Documento guardado en el expediente del paciente.',
            route('documentos.index')
        );
    }

    public function show(ClinicalFile $file)
    {
        $payload = $this->documents->payload($file);
        abort_unless($payload && DocumentService::exists($payload['template']), 404);

        return view('documentos.print', [
            'template' => $payload['template'],
            'meta' => DocumentService::templates()[$payload['template']],
            'payload' => $payload,
            'data' => $payload['data'] ?? [],
            'patientInfo' => $payload['patient'] ?? null,
            'conditions' => DocumentService::CONDITIONS,
            'complications' => DocumentService::EXTRACTION_COMPLICATIONS,
            'blank' => false,
            'file' => $file,
            'integrityOk' => $this->files->verifyIntegrity($file),
        ]);
    }
}
