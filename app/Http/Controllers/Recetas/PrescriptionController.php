<?php

namespace App\Http\Controllers\Recetas;

use App\Enums\RecordStatus;
use App\Enums\SigningStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recetas\PrescriptionRequest;
use App\Http\Requests\Recetas\SendPrescriptionRequest;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Patient;
use App\Models\Prescription;
use App\Services\Recetas\PrescriptionService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class PrescriptionController extends Controller
{
    public function __construct(private readonly PrescriptionService $service)
    {
    }

    // ------------------------------------------------------------------
    // Pantallas
    // ------------------------------------------------------------------

    /** Listado + documento de la receta seleccionada. */
    public function index(Request $request): View
    {
        return $this->screen($request);
    }

    /** Misma pantalla con la receta seleccionada a la derecha. */
    public function show(Request $request, Prescription $prescription): View
    {
        return $this->screen($request, $prescription);
    }

    /** Pantalla con el modal "Crear receta" abierto. */
    public function create(Request $request): View
    {
        return $this->screen($request, null, form: ['mode' => 'create']);
    }

    /** Pantalla con el modal abierto para editar un borrador. */
    public function edit(Request $request, Prescription $prescription): View|RedirectResponse
    {
        if ($prescription->isSigned()) {
            return redirect()->route('recetas.show', $prescription)
                ->with('error', 'La receta ya fue emitida y no puede editarse. Use "Repetir receta" para crear una nueva.');
        }

        return $this->screen($request, $prescription, form: ['mode' => 'edit', 'rx' => $prescription]);
    }

    // ------------------------------------------------------------------
    // Acciones
    // ------------------------------------------------------------------

    public function store(PrescriptionRequest $request): RedirectResponse
    {
        try {
            $rx = $this->service->save(null, $request->payload());

            return $this->afterSave($request, $rx);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return $this->databaseError($e, back()->withInput());
        }
    }

    public function update(PrescriptionRequest $request, Prescription $prescription): RedirectResponse
    {
        if ($prescription->isSigned()) {
            return redirect()->route('recetas.show', $prescription)
                ->with('error', 'La receta ya fue emitida y no puede modificarse. Use "Repetir receta" para crear una nueva.');
        }

        try {
            $rx = $this->service->save($prescription, $request->payload());

            return $this->afterSave($request, $rx);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return $this->databaseError($e, back()->withInput());
        }
    }

    public function destroy(Prescription $prescription): RedirectResponse
    {
        try {
            $this->service->deleteDraft($prescription);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return $this->databaseError($e, back());
        }

        return redirect()->route('recetas.index')->with('success', 'Borrador eliminado.');
    }

    /** Firma y emite un borrador ya guardado. */
    public function emit(Request $request, Prescription $prescription): RedirectResponse
    {
        try {
            $rx = $this->service->sign($prescription, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return $this->databaseError($e, back());
        }

        return redirect()->route('recetas.show', $rx)->with('success', "Receta {$rx->folio} firmada y emitida.");
    }

    public function repeat(Request $request, Prescription $prescription): RedirectResponse
    {
        try {
            $new = $this->service->repeat($prescription, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return $this->databaseError($e, back());
        }

        return redirect()->route('recetas.edit', $new)
            ->with('success', "Se creó el borrador {$new->folio} a partir de {$prescription->folio}. Revise y emita.");
    }

    public function send(SendPrescriptionRequest $request, Prescription $prescription): RedirectResponse
    {
        try {
            $delivery = $this->service->send($prescription, $request->validated('channel'), $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return $this->databaseError($e, back());
        }

        $msg = match ($delivery->channel) {
            'email' => 'La receta quedó en cola para enviarse por correo electrónico.',
            'whatsapp' => 'La receta quedó en cola para enviarse por WhatsApp.',
            default => 'Se registró la impresión de la receta.',
        };

        return back()->with('success', $msg);
    }

    /** Vista imprimible (membrete de la clínica). */
    public function print(Request $request, Prescription $prescription): View
    {
        $prescription->load(['patient.medicalRecord', 'doctor.user', 'doctor.doctorProfile', 'items']);

        return view('recetas.print', [
            'rx' => $prescription,
            'org' => Organization::current(),
            'autoPrint' => $request->boolean('auto'),
            'integrity' => $prescription->isSigned() ? $this->service->verifyIntegrity($prescription) : null,
        ]);
    }

    /** "PDF": la misma vista imprimible que abre el diálogo de impresión (Guardar como PDF). */
    public function pdf(Request $request, Prescription $prescription): RedirectResponse
    {
        return redirect()->route('recetas.print', ['prescription' => $prescription, 'auto' => 1]);
    }

    // ------------------------------------------------------------------
    // JSON (typeahead del formulario)
    // ------------------------------------------------------------------

    public function searchPatients(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $patients = Patient::query()
            ->active()
            ->whereNull('anonymized_at')
            ->with('medicalRecord')
            ->search($q)
            ->orderBy('full_name')
            ->limit(8)
            ->get();

        return response()->json($patients->map(fn (Patient $p) => $this->patientPayload($p))->values());
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function afterSave(PrescriptionRequest $request, Prescription $rx): RedirectResponse
    {
        $action = $request->input('action', 'borrador');

        if ($action === 'emitir') {
            try {
                $rx = $this->service->sign($rx, $request->user());
            } catch (DomainException $e) {
                // El borrador ya quedó guardado: se informa y se deja editable.
                return redirect()->route('recetas.edit', $rx)->with('error', 'Se guardó el borrador '.$rx->folio.', pero no se pudo emitir: '.$e->getMessage());
            }

            return redirect()->route('recetas.show', $rx)->with('success', "Receta {$rx->folio} firmada y emitida.");
        }

        if ($action === 'vista') {
            return redirect()->route('recetas.print', $rx)->with('success', "Borrador {$rx->folio} guardado.");
        }

        return redirect()->route('recetas.show', $rx)->with('success', "Borrador {$rx->folio} guardado.");
    }

    /** Pantalla principal (listado, KPIs, documento y modal opcional). */
    private function screen(Request $request, ?Prescription $selected = null, ?array $form = null): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('estado');
        $status = in_array($status, ['firmada', 'borrador'], true) ? $status : null;

        $query = Prescription::query()
            ->with(['patient', 'doctor.user'])
            ->withCount('items')
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.$q.'%';
                $w->where(function ($x) use ($like) {
                    $x->where('folio', 'like', $like)
                        ->orWhere('diagnosis', 'like', $like)
                        ->orWhereHas('patient', fn ($p) => $p->where('full_name', 'like', $like)->orWhere('folio', 'like', $like));
                });
            })
            ->orderByDesc('issued_on')
            ->orderByDesc('created_at');

        $prescriptions = $query->paginate(10)->withQueryString();

        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $monthEnd = Carbon::now()->endOfMonth()->toDateString();
        $kpis = [
            'month' => Prescription::query()->whereBetween('issued_on', [$monthStart, $monthEnd])->count(),
            'signed' => Prescription::query()->whereBetween('issued_on', [$monthStart, $monthEnd])->where('status', SigningStatus::Firmada->value)->count(),
            'drafts' => Prescription::query()->where('status', SigningStatus::Borrador->value)->count(),
        ];

        if ($selected === null && $form === null) {
            $selected = $prescriptions->first();
        }
        $doc = $selected;
        $doc?->load(['patient.medicalRecord', 'doctor.user', 'doctor.doctorProfile', 'items', 'deliveries']);

        $formData = null;
        if ($form !== null) {
            $formData = $this->formData($request, $form['rx'] ?? null);
            $formData['mode'] = $form['mode'];
        }

        return view('recetas.index', [
            'prescriptions' => $prescriptions,
            'selected' => $doc,
            'kpis' => $kpis,
            'org' => Organization::current(),
            'filters' => ['q' => $q, 'estado' => $status],
            'form' => $formData,
        ]);
    }

    /** Datos para el modal de crear/editar. */
    private function formData(Request $request, ?Prescription $rx): array
    {
        $doctors = OrganizationMember::query()
            ->active()
            ->whereHas('doctorProfile')
            ->with(['user', 'doctorProfile'])
            ->get()
            ->sortBy(fn ($m) => $m->user?->full_name)
            ->values();

        $patient = null;
        $oldPatientId = old('patient_id');
        if ($oldPatientId) {
            $patient = Patient::query()->active()->with('medicalRecord')->find($oldPatientId);
        } elseif ($rx) {
            $patient = $rx->patient()->with('medicalRecord')->first();
        } elseif ($request->filled('patient')) {
            $patient = Patient::query()->active()->whereNull('anonymized_at')->with('medicalRecord')->find($request->query('patient'));
        }

        $myMemberId = $request->user()?->member?->id;
        $defaultDoctor = $rx?->doctor_member_id
            ?? ($doctors->firstWhere('id', $myMemberId)?->id)
            ?? $doctors->first()?->id;

        $items = old('items');
        if ($items === null) {
            $items = $rx
                ? $rx->items->map(fn ($i) => $i->only(['medication_id', 'medication_name', 'strength', 'presentation', 'dose', 'route', 'frequency', 'duration', 'instructions']))->all()
                : [];
        }
        if (empty($items)) {
            $items = [[]];
        }

        return [
            'rx' => $rx,
            'doctors' => $doctors,
            'defaultDoctor' => $defaultDoctor,
            'patient' => $patient ? $this->patientPayload($patient) : null,
            'items' => array_values($items),
            'canEmit' => (bool) $request->user()?->hasPermission('recetas', 'emitir_recetas'),
        ];
    }

    /** @return array<string, mixed> */
    private function patientPayload(Patient $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->full_name,
            'folio' => $p->folio,
            'label' => $p->full_name.' · '.$p->folio,
            'age' => $p->birth_date ? (int) $p->birth_date->diffInYears(now()) : null,
            'sex' => $p->sex?->label(),
            'allergies' => $p->medicalRecord?->allergies,
            'has_email' => filled($p->email),
            'has_phone' => filled($p->phone),
        ];
    }

    private function databaseError(QueryException $e, RedirectResponse $redirect): RedirectResponse
    {
        // SQLSTATE 45000 = reglas de negocio de la BD (triggers): mensaje legible.
        if (($e->errorInfo[0] ?? null) === '45000') {
            return $redirect->with('error', (string) ($e->errorInfo[2] ?? 'La operación no está permitida.'));
        }
        if (($e->errorInfo[1] ?? null) === 1062) {
            return $redirect->with('error', 'Ya existe un registro con esos datos. Intente de nuevo.');
        }

        report($e);

        return $redirect->with('error', 'No se pudo completar la operación. Intente de nuevo.');
    }
}
