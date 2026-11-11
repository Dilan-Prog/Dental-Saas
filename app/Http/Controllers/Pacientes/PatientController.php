<?php

namespace App\Http\Controllers\Pacientes;

use App\Enums\AppointmentStatus;
use App\Enums\RecordStatus;
use App\Enums\SexType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pacientes\AnonymizePatientRequest;
use App\Http\Requests\Pacientes\StorePatientRequest;
use App\Http\Requests\Pacientes\UpdatePatientRequest;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quote;
use App\Services\Pacientes\PatientService;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(private PatientService $patients)
    {
    }

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $paginator = $this->filteredQuery($filters)->paginate(self::PER_PAGE)->withQueryString();

        return view('pacientes.index', [
            'patients' => $paginator,
            'filters' => $filters,
            'total' => Patient::query()->count(),
            'doctors' => $this->patients->doctorOptions(),
            'sexOptions' => SexType::options(),
            'regimenes' => config('sat.regimenes'),
            'usosCfdi' => config('sat.usos_cfdi'),
            'relationships' => PatientService::RELATIONSHIPS,
            'canSeeDeleted' => $request->user()->hasPermission('pacientes', 'desactivar'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->filteredQuery($filters);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea UTF-8
            fputcsv($out, ['Folio', 'Nombre', 'Edad', 'Sexo', 'Teléfono', 'Correo', 'Doctor', 'Última visita', 'Saldo', 'Estado']);

            foreach ($query->lazyById(200, 'patients.id', 'id') as $p) {
                fputcsv($out, array_map([$this, 'csvSafe'], [
                    $p->folio,
                    $p->full_name,
                    $p->age ?? '',
                    $p->sex->label(),
                    PatientService::formatPhone($p->phone),
                    $p->email,
                    $p->assignedDoctor?->user?->full_name,
                    $p->last_visit ? \Illuminate\Support\Carbon::parse($p->last_visit)->format('d/m/Y') : '',
                    PatientService::balanceOf($p),
                    $p->trashed() ? 'Baja' : $p->status->label(),
                ]));
            }
            fclose($out);
        }, 'pacientes-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(StorePatientRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $patient = $this->patients->create($data, $data['fiscal'] ?? null, $data['guardian'] ?? null);
        } catch (RuntimeException|QueryException $e) {
            Log::error('Alta de paciente fallida: '.$e->getMessage());

            return back()->withInput()->with('error', 'No se pudo registrar al paciente. Intenta de nuevo.');
        }

        return redirect()->route('pacientes.show', $patient)
            ->with('success', "Paciente {$patient->folio} registrado correctamente.");
    }

    public function show(Request $request, Patient $patient): View
    {
        $patient->load(['assignedDoctor.user:id,full_name', 'fiscalProfiles', 'guardians', 'medicalRecord']);

        $activeStatuses = [AppointmentStatus::Pendiente->value, AppointmentStatus::Confirmada->value, AppointmentStatus::EnCurso->value];

        $upcoming = Appointment::query()
            ->with(['treatment:id,name', 'doctor.user:id,full_name'])
            ->where('patient_id', $patient->id)
            ->whereIn('status', $activeStatuses)
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(3)
            ->get();

        $lastVisit = Appointment::query()
            ->where('patient_id', $patient->id)
            ->where('status', AppointmentStatus::Completada->value)
            ->max('starts_at');

        $balance = $patient->pendingBalance();

        return view('pacientes.show', [
            'patient' => $patient,
            'upcoming' => $upcoming,
            'lastVisit' => $lastVisit ? \Illuminate\Support\Carbon::parse($lastVisit) : null,
            'balance' => $balance,
            'hasBalance' => Money::cmp($balance, 0) > 0,
            'counts' => [
                'appointments' => Appointment::query()->where('patient_id', $patient->id)->count(),
                'prescriptions' => Prescription::query()->where('patient_id', $patient->id)->count(),
                'quotes' => Quote::query()->where('patient_id', $patient->id)->count(),
                'invoices' => Invoice::query()->where('patient_id', $patient->id)->count(),
            ],
            'isMinor' => $this->patients->isMinor($patient->birth_date),
            'doctors' => $this->patients->doctorOptions(),
            'sexOptions' => SexType::options(),
            'statusOptions' => RecordStatus::options(),
            'regimenes' => config('sat.regimenes'),
            'usosCfdi' => config('sat.usos_cfdi'),
            'relationships' => PatientService::RELATIONSHIPS,
            'editable' => $patient->anonymized_at === null && ! $patient->trashed(),
        ]);
    }

    public function update(UpdatePatientRequest $request, Patient $patient): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->patients->update($patient, $data, $data['guardian'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            Log::error('Edición de paciente fallida: '.$e->getMessage());

            return back()->withInput()->with('error', 'No se pudo guardar el paciente. Intenta de nuevo.');
        }

        return redirect()->route('pacientes.show', $patient)->with('success', 'Datos del paciente actualizados.');
    }

    public function destroy(Patient $patient): RedirectResponse
    {
        try {
            $this->patients->archive($patient);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pacientes.index')
            ->with('success', "Paciente {$patient->folio} dado de baja. Su expediente se conserva.");
    }

    public function restore(Patient $patient): RedirectResponse
    {
        try {
            $this->patients->restore($patient);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pacientes.show', $patient)->with('success', "Paciente {$patient->folio} reactivado.");
    }

    public function anonymize(AnonymizePatientRequest $request, Patient $patient): RedirectResponse
    {
        try {
            $this->patients->anonymize($patient);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pacientes.show', $patient)
            ->with('success', 'Paciente anonimizado. Se eliminaron sus datos de identificación.');
    }

    // ------------------------------------------------------------------ privados

    /** @return array{q: string, estado: string, doctor: string} */
    private function filters(Request $request): array
    {
        $estado = (string) $request->query('estado', '');
        $allowed = ['active', 'inactive'];
        if ($request->user()->hasPermission('pacientes', 'desactivar')) {
            $allowed[] = 'deleted';
        }

        $doctor = (string) $request->query('doctor', '');

        return [
            'q' => trim((string) $request->query('q', '')),
            'estado' => in_array($estado, $allowed, true) ? $estado : '',
            'doctor' => preg_match('/^[0-9a-f-]{36}$/i', $doctor) ? $doctor : '',
        ];
    }

    /** @param  array{q: string, estado: string, doctor: string}  $f */
    private function filteredQuery(array $f): Builder
    {
        $query = $this->patients->listingQuery();

        if ($f['estado'] === 'deleted') {
            $query->onlyTrashed();
        } elseif ($f['estado'] !== '') {
            $query->where('patients.status', $f['estado']);
        }

        if ($f['doctor'] !== '') {
            $query->where('patients.assigned_doctor_member_id', $f['doctor']);
        }

        if ($f['q'] !== '') {
            $digits = preg_replace('/\D+/', '', $f['q']) ?? '';
            // Si parece un teléfono (solo dígitos y separadores) se busca por dígitos.
            if (strlen($digits) >= 4 && preg_match('/^[\d\s\-+().]+$/', $f['q'])) {
                $query->where('patients.phone', 'like', '%'.$digits.'%');
            } else {
                $query->search($f['q']);
            }
        }

        return $query->orderBy('patients.full_name')->orderBy('patients.id');
    }

    /** Evita inyección de fórmulas al abrir el CSV en Excel. */
    private function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
