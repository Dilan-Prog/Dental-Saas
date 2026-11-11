<?php

namespace App\Http\Controllers\Presupuestos;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Presupuestos\QuoteRequest;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Quote;
use App\Services\Presupuestos\QuoteLookups;
use App\Services\Presupuestos\QuoteService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use RuntimeException;

class QuoteController extends Controller
{
    public function __construct(private QuoteService $quotes, private QuoteLookups $lookups)
    {
    }

    public function index(Request $request): View
    {
        $estado = in_array($request->query('estado'), QuoteStatus::values(), true) ? $request->query('estado') : null;
        $term = trim((string) $request->query('q', ''));
        $from = $this->validDate($request->query('desde'));
        $to = $this->validDate($request->query('hasta'));
        $patientId = Validator::make(['p' => $request->query('paciente')], ['p' => 'uuid'])->passes()
            ? $request->query('paciente') : null;

        $quotes = Quote::query()
            ->with(['patient', 'doctor.user'])
            ->when($estado, fn ($q) => $q->where('status', $estado))
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $w->where('folio', 'like', "%{$term}%")
                    ->orWhereHas('patient', fn ($p) => $p->where('full_name', 'like', "%{$term}%")->orWhere('folio', 'like', "%{$term}%"));
            }))
            ->when($patientId, fn ($q) => $q->where('patient_id', $patientId))
            ->when($from, fn ($q) => $q->whereDate('quote_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('quote_date', '<=', $to))
            ->orderByDesc('quote_date')
            ->orderByDesc('folio')
            ->paginate(15)
            ->withQueryString();

        $counts = Quote::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return view('presupuestos.index', [
            'quotes' => $quotes,
            'estado' => $estado,
            'counts' => $counts,
            'filters' => ['q' => $term, 'desde' => $from, 'hasta' => $to, 'paciente' => $patientId],
            'filterPatient' => $patientId ? Patient::query()->find($patientId) : null,
        ]);
    }

    public function create(Request $request): View
    {
        $quote = new Quote([
            'quote_date' => now(),
            'branch_id' => Branch::query()->where('is_main', true)->value('id'),
            'doctor_member_id' => $this->defaultDoctorId($request),
        ]);

        $patientId = Validator::make(['p' => $request->query('paciente')], ['p' => 'uuid'])->passes()
            ? $request->query('paciente') : null;
        if ($patientId) {
            $quote->patient_id = Patient::query()->active()->whereNull('deleted_at')
                ->whereNull('anonymized_at')->where('id', $patientId)->value('id');
        }

        return $this->form($quote);
    }

    public function store(QuoteRequest $request): RedirectResponse
    {
        try {
            $quote = $this->quotes->create($request->validated(), $request->user()->id);
        } catch (QueryException $e) {
            report($e);

            return back()->withInput()->with('error', 'No se pudo guardar el presupuesto. Revisa los datos e inténtalo de nuevo.');
        }

        return redirect()->route('presupuestos.show', $quote)
            ->with('success', "Presupuesto {$quote->folio} creado como borrador.");
    }

    public function show(Quote $quote): View
    {
        $quote->load(['patient', 'doctor.user', 'branch', 'items.patientTreatments', 'statusHistory.actor', 'invoices']);

        return view('presupuestos.show', ['quote' => $quote]);
    }

    public function edit(Quote $quote): View|RedirectResponse
    {
        if ($quote->status !== QuoteStatus::Borrador) {
            return redirect()->route('presupuestos.show', $quote)
                ->with('error', 'Solo se puede editar un presupuesto en borrador. Reábrelo si aún no tiene respuesta.');
        }

        $quote->load(['patient', 'items']);

        return $this->form($quote);
    }

    public function update(QuoteRequest $request, Quote $quote): RedirectResponse
    {
        try {
            $this->quotes->update($quote, $request->validated());
        } catch (RuntimeException $e) {
            return redirect()->route('presupuestos.show', $quote)->with('error', $e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return back()->withInput()->with('error', 'No se pudo guardar el presupuesto. Revisa los datos e inténtalo de nuevo.');
        }

        return redirect()->route('presupuestos.show', $quote)
            ->with('success', "Presupuesto {$quote->folio} actualizado.");
    }

    public function print(Quote $quote): View
    {
        $quote->load(['patient', 'doctor.user', 'branch', 'items']);

        return view('presupuestos.print', [
            'quote' => $quote,
            'organization' => Organization::query()->find(Organization::currentId()),
        ]);
    }

    public function send(Request $request, Quote $quote): RedirectResponse
    {
        return $this->transition($quote, fn () => $this->quotes->send($quote, $request->user()->id),
            "Presupuesto {$quote->folio} marcado como enviado.");
    }

    public function approve(Request $request, Quote $quote): RedirectResponse
    {
        return $this->transition($quote, fn () => $this->quotes->approve($quote, $request->user()->id),
            "Presupuesto {$quote->folio} aprobado: sus conceptos pasaron al plan de tratamiento del paciente.");
    }

    public function reject(Request $request, Quote $quote): RedirectResponse
    {
        return $this->transition($quote, fn () => $this->quotes->reject($quote, $request->user()->id),
            "Presupuesto {$quote->folio} marcado como rechazado.");
    }

    public function reopen(Request $request, Quote $quote): RedirectResponse
    {
        return $this->transition($quote, fn () => $this->quotes->reopen($quote, $request->user()->id),
            "Presupuesto {$quote->folio} regresó a borrador.");
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        try {
            $this->quotes->delete($quote);
        } catch (RuntimeException $e) {
            return redirect()->route('presupuestos.show', $quote)->with('error', $e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return redirect()->route('presupuestos.show', $quote)->with('error', 'No se pudo eliminar el presupuesto.');
        }

        return redirect()->route('presupuestos.index')->with('success', "Presupuesto {$quote->folio} eliminado.");
    }

    /** Búsqueda de pacientes para el selector del formulario. */
    public function patients(Request $request): JsonResponse
    {
        return response()->json(
            $this->lookups->searchPatients($request->query('q'))->map(fn (Patient $p) => [
                'id' => $p->id,
                'folio' => $p->folio,
                'name' => $p->full_name,
                'phone' => $p->phone,
            ])->values()
        );
    }

    // ---------------------------------------------------------------- privado

    private function form(Quote $quote): View
    {
        $treatments = $this->lookups->treatments();

        $rows = old('items');
        if ($rows === null) {
            $rows = $quote->exists
                ? $quote->items->map(fn ($i) => [
                    'treatment_id' => $i->treatment_id,
                    'tooth_fdi' => $i->tooth_fdi,
                    'quantity' => rtrim(rtrim((string) $i->quantity, '0'), '.') ?: '1',
                    'unit_price' => rtrim(rtrim((string) $i->unit_price, '0'), '.') ?: '0',
                    'discount_pct' => rtrim(rtrim((string) $i->discount_pct, '0'), '.') ?: '0',
                ])->all()
                : [['treatment_id' => '', 'tooth_fdi' => '', 'quantity' => '1', 'unit_price' => '', 'discount_pct' => '0']];
        }

        $patientId = old('patient_id', $quote->patient_id);
        $patient = $patientId ? Patient::query()->find($patientId) : null;

        return view('presupuestos.form', [
            'quote' => $quote,
            'rows' => array_values($rows),
            'patient' => $patient,
            'doctors' => $this->lookups->doctors(),
            'branches' => $this->lookups->branches(),
            'treatmentOptions' => $treatments->pluck('name', 'id'),
            'treatmentData' => $treatments->mapWithKeys(fn ($t) => [$t->id => [
                'price' => (string) $t->price,
                'rate' => $t->taxProfile && ! $t->taxProfile->is_exempt ? (string) $t->taxProfile->rate : '0',
                'exempt' => $t->taxProfile ? (bool) $t->taxProfile->is_exempt : true,
                'tax' => $t->taxProfile?->name ?? 'Exento',
            ]]),
        ]);
    }

    private function transition(Quote $quote, callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return redirect()->route('presupuestos.show', $quote)->with('error', $e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return redirect()->route('presupuestos.show', $quote)->with('error', 'No se pudo completar la acción. Inténtalo de nuevo.');
        }

        return redirect()->route('presupuestos.show', $quote)->with('success', $success);
    }

    private function defaultDoctorId(Request $request): ?string
    {
        $memberId = $request->attributes->get('member_id');

        return $memberId && $this->lookups->doctors()->has($memberId) ? $memberId : null;
    }

    private function validDate(mixed $value): ?string
    {
        return is_string($value) && Validator::make(['d' => $value], ['d' => 'date_format:Y-m-d'])->passes() ? $value : null;
    }
}
