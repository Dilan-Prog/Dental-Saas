<?php

namespace App\Http\Controllers\Facturacion;

use App\Enums\CfdiType;
use App\Enums\CollectionStatus;
use App\Enums\FiscalStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facturacion\CancelInvoiceRequest;
use App\Http\Requests\Facturacion\InvoiceRequest;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\OrganizationMember;
use App\Models\Patient;
use App\Models\PatientFiscalProfile;
use App\Models\Quote;
use App\Models\TaxProfile;
use App\Services\Facturacion\BillingException;
use App\Services\Facturacion\BillingMetrics;
use App\Services\Facturacion\InvoiceService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $service)
    {
    }

    // ------------------------------------------------------------------ listado

    public function index(Request $request)
    {
        $invoices = $this->filtered($request)->paginate(15)->withQueryString();

        return view('facturacion.index', [
            'invoices' => $invoices,
            'kpis' => BillingMetrics::summary(),
            'filters' => $request->only(['q', 'fiscal_status', 'collection_status', 'type', 'from', 'to', 'patient']),
            'fiscalOptions' => FiscalStatus::options(),
            'collectionOptions' => CollectionStatus::options(),
            'patientFilter' => $request->filled('patient') ? Patient::find($request->input('patient')) : null,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filtered($request)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
            fputcsv($out, ['Folio', 'Tipo', 'Fecha', 'Paciente', 'RFC receptor', 'Total', 'Pagado', 'Saldo', 'Estado fiscal', 'Cobranza', 'UUID fiscal']);
            foreach ($rows as $i) {
                $applied = Money::round($i->applied_sum ?? 0);
                fputcsv($out, [
                    $i->internal_folio,
                    $i->cfdi_type->value,
                    $i->created_at->format('Y-m-d'),
                    $i->patient?->full_name,
                    $i->receiver_snapshot['rfc'] ?? '',
                    $i->total,
                    $applied,
                    Money::sub($i->total, $applied),
                    $i->fiscal_status->label(),
                    $i->collection_status->label(),
                    $i->uuid_fiscal,
                ]);
            }
            fclose($out);
        }, 'facturas-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------ alta

    public function create(Request $request)
    {
        $prefill = [
            'patient_id' => $request->input('patient'),
            'cfdi_payment_method' => 'PUE',
            'cfdi_payment_form' => '04',
            'receiver_mode' => 'patient',
            'items' => [$this->blankLine()],
        ];

        return view('facturacion.form', $this->formData(null, $prefill));
    }

    public function fromQuote(Quote $quote)
    {
        $quote->loadMissing('patient');

        $blocked = null;
        if ($quote->status->value !== 'aprobado') {
            $blocked = 'Solo se pueden facturar presupuestos aprobados.';
        } elseif (Invoice::where('quote_id', $quote->id)->where('fiscal_status', '!=', FiscalStatus::Cancelada->value)->exists()) {
            $blocked = 'El presupuesto ya tiene una factura vigente.';
        }
        if ($blocked) {
            $back = \Illuminate\Support\Facades\Route::has('presupuestos.show') ? route('presupuestos.show', $quote) : route('facturacion.index');

            return redirect($back)->with('error', $blocked);
        }

        $profile = $quote->patient?->fiscalProfiles()->orderByDesc('is_default')->first();

        $prefill = [
            'patient_id' => $quote->patient_id,
            'quote_id' => $quote->id,
            'branch_id' => $quote->branch_id,
            'doctor_member_id' => $quote->doctor_member_id,
            'cfdi_use' => $profile?->default_cfdi_use ?: 'D01',
            'cfdi_payment_method' => 'PUE',
            'cfdi_payment_form' => '04',
            'receiver_mode' => 'patient',
            'items' => $this->service->linesFromQuote($quote),
        ];

        return view('facturacion.form', $this->formData(null, $prefill, $quote));
    }

    public function store(InvoiceRequest $request)
    {
        try {
            $invoice = $this->service->create($request->validated(), $request->user());
        } catch (BillingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($request->input('action') === 'emit') {
            return $this->emitAndRedirect($invoice, $request);
        }

        return redirect()->route('facturacion.show', $invoice)
            ->with('success', "Factura {$invoice->internal_folio} guardada como borrador.");
    }

    // ------------------------------------------------------------------ detalle

    public function show(Invoice $invoice)
    {
        $invoice->load([
            'patient', 'branch', 'doctor.user', 'quote', 'items.taxes',
            'applications.payment.reversals', 'pacRequests', 'cancellations.substituteInvoice',
            'complementRows.relatedInvoice', 'paymentComplements.complementInvoice', 'relations.relatedInvoice',
        ]);

        $applied = $invoice->appliedAmount();
        $isComplement = $invoice->cfdi_type === CfdiType::Pago;

        $substitutes = Invoice::query()
            ->where('patient_id', $invoice->patient_id)->where('id', '!=', $invoice->id)
            ->where('cfdi_type', CfdiType::Ingreso->value)
            ->whereNotIn('fiscal_status', [FiscalStatus::Cancelada->value, FiscalStatus::Borrador->value])
            ->orderByDesc('created_at')->get(['id', 'internal_folio', 'total']);

        return view('facturacion.show', [
            'invoice' => $invoice,
            'applied' => $applied,
            'balance' => Money::sub($invoice->total, $applied),
            'taxSummary' => $this->taxSummary($invoice),
            'substitutes' => $substitutes,
            'isComplement' => $isComplement,
            'methods' => PaymentMethod::options(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function print(Invoice $invoice)
    {
        $invoice->load(['patient', 'branch', 'doctor.user', 'items.taxes', 'applications.payment']);
        $applied = $invoice->appliedAmount();

        return view('facturacion.print', [
            'invoice' => $invoice,
            'applied' => $applied,
            'balance' => Money::sub($invoice->total, $applied),
            'taxSummary' => $this->taxSummary($invoice),
        ]);
    }

    public function xml(Invoice $invoice)
    {
        $key = $invoice->xml_storage_key;
        if (! $key || ! Storage::disk('local')->exists($key)) {
            return back()->with('error', 'El XML se genera cuando el PAC timbra la factura.');
        }

        return Storage::disk('local')->download($key, ($invoice->uuid_fiscal ?: $invoice->internal_folio) . '.xml');
    }

    // ------------------------------------------------------------------ edicion

    public function edit(Invoice $invoice)
    {
        if (! $invoice->isEditable()) {
            return redirect()->route('facturacion.show', $invoice)->with('error', 'Solo se pueden editar facturas en borrador.');
        }
        $invoice->load('items');

        $receiver = (array) $invoice->receiver_snapshot;
        $prefill = [
            'patient_id' => $invoice->patient_id,
            'branch_id' => $invoice->branch_id,
            'doctor_member_id' => $invoice->doctor_member_id,
            'quote_id' => $invoice->quote_id,
            'cfdi_use' => $invoice->cfdi_use,
            'cfdi_payment_method' => $invoice->cfdi_payment_method->value,
            'cfdi_payment_form' => $invoice->cfdi_payment_form,
            'receiver_mode' => $receiver['mode'] ?? 'patient',
            'fiscal_profile_id' => $receiver['fiscal_profile_id'] ?? null,
            'items' => $invoice->items->map(function ($it) {
                $tax = $it->taxes->first();

                return [
                    'description' => $it->description,
                    'sat_product_key' => $it->sat_product_key,
                    'sat_unit_key' => $it->sat_unit_key,
                    'quantity' => rtrim(rtrim((string) $it->quantity, '0'), '.'),
                    'unit_price' => rtrim(rtrim((string) $it->unit_price, '0'), '.'),
                    'discount_pct' => Money::cmp($it->amount, '0') > 0
                        ? rtrim(rtrim(bcmul(bcdiv((string) $it->discount, (string) $it->amount, 6), '100', 2), '0'), '.')
                        : '0',
                    'tax_object_code' => $it->tax_object_code,
                    'tax_profile_id' => $this->matchTaxProfile($tax),
                    'treatment_id' => $it->treatment_id,
                    'quote_item_id' => $it->quote_item_id,
                ];
            })->all(),
        ];

        return view('facturacion.form', $this->formData($invoice, $prefill, $invoice->quote));
    }

    public function update(InvoiceRequest $request, Invoice $invoice)
    {
        try {
            $invoice = $this->service->updateDraft($invoice, $request->validated());
        } catch (BillingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($request->input('action') === 'emit') {
            return $this->emitAndRedirect($invoice, $request);
        }

        return redirect()->route('facturacion.show', $invoice)->with('success', 'Borrador actualizado.');
    }

    public function destroy(Invoice $invoice)
    {
        try {
            $this->service->deleteDraft($invoice);
        } catch (BillingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('facturacion.index')->with('success', 'Borrador eliminado.');
    }

    // ------------------------------------------------------------------ emision / cancelacion

    public function emit(Request $request, Invoice $invoice)
    {
        return $this->emitAndRedirect($invoice, $request, true);
    }

    public function cancel(CancelInvoiceRequest $request, Invoice $invoice)
    {
        try {
            $invoice = $this->service->cancel($invoice, $request->validated(), $request->user());
        } catch (BillingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $msg = $invoice->fiscal_status === FiscalStatus::Cancelada
            ? "Factura {$invoice->internal_folio} cancelada."
            : "Cancelacion de {$invoice->internal_folio} solicitada. Queda en proceso hasta la respuesta del PAC/SAT.";

        return redirect()->route('facturacion.show', $invoice)->with('success', $msg);
    }

    // ------------------------------------------------------------------ privados

    protected function emitAndRedirect(Invoice $invoice, Request $request, bool $back = false)
    {
        try {
            $this->service->issue($invoice, $request->user());
        } catch (BillingException $e) {
            return redirect()->route('facturacion.show', $invoice)
                ->with('error', $e->getMessage())->with('emit_errors', $e->errors);
        }

        return redirect()->route('facturacion.show', $invoice)
            ->with('success', 'Factura enviada a timbrar. Queda pendiente de timbrado; el UUID fiscal lo asigna el PAC.');
    }

    protected function filtered(Request $request)
    {
        $request->validate([
            'fiscal_status' => ['nullable', 'in:' . implode(',', FiscalStatus::values())],
            'collection_status' => ['nullable', 'in:' . implode(',', CollectionStatus::values())],
            'type' => ['nullable', 'in:I,P'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
            'patient' => ['nullable', 'uuid'],
        ], [
            'to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        $query = Invoice::query()
            ->with('patient:id,full_name,folio')
            ->withSum(['applications as applied_sum' => fn ($q) => $q->whereNull('reversed_at')], 'applied_amount')
            ->orderByDesc('created_at');

        if ($term = trim((string) $request->input('q'))) {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('internal_folio', 'like', $like)
                    ->orWhere('uuid_fiscal', 'like', $like)
                    ->orWhereHas('patient', fn ($p) => $p->where('full_name', 'like', $like)->orWhere('folio', 'like', $like));
            });
        }
        if ($v = $request->input('fiscal_status')) {
            $query->where('fiscal_status', $v);
        }
        if ($v = $request->input('collection_status')) {
            $query->where('collection_status', $v);
        }
        if ($v = $request->input('type')) {
            $query->where('cfdi_type', $v);
        }
        if ($v = $request->input('patient')) {
            $query->where('patient_id', $v);
        }
        if ($v = $request->input('from')) {
            $query->whereDate('created_at', '>=', $v);
        }
        if ($v = $request->input('to')) {
            $query->whereDate('created_at', '<=', $v);
        }

        return $query;
    }

    /** @return array<string,mixed> */
    protected function blankLine(): array
    {
        $default = (string) array_key_first(config('sat.claves_producto_sugeridas'));
        $iva = TaxProfile::active()->where('is_exempt', false)->orderByDesc('rate')->first();

        return [
            'description' => '', 'sat_product_key' => $default, 'sat_unit_key' => 'E48', 'quantity' => '1',
            'unit_price' => '', 'discount_pct' => '0', 'tax_object_code' => '02', 'tax_profile_id' => $iva?->id,
        ];
    }

    protected function matchTaxProfile($tax): ?string
    {
        if (! $tax) {
            return null;
        }
        $profiles = TaxProfile::query()->get();
        $hit = $profiles->first(fn ($p) => $tax->factor_type === 'Exento'
            ? (bool) $p->is_exempt
            : (! $p->is_exempt && Money::cmp((string) $p->rate, (string) $tax->rate, 6) === 0));

        return $hit?->id;
    }

    /** IVA 16% $x / Exento, agrupados por tasa. @return array<int,array{label:string,base:string,amount:?string}> */
    protected function taxSummary(Invoice $invoice): array
    {
        $groups = [];
        foreach ($invoice->items as $item) {
            foreach ($item->taxes as $t) {
                $name = config('sat.impuestos.' . $t->tax_code, $t->tax_code);
                $label = $t->factor_type === 'Exento'
                    ? "{$name} Exento"
                    : "{$name} " . rtrim(rtrim(bcmul((string) $t->rate, '100', 2), '0'), '.') . '%';
                $groups[$label]['label'] = $label;
                $groups[$label]['base'] = Money::add($groups[$label]['base'] ?? '0', $t->base);
                $groups[$label]['amount'] = $t->amount === null ? ($groups[$label]['amount'] ?? null) : Money::add($groups[$label]['amount'] ?? '0', $t->amount);
            }
        }

        return array_values($groups);
    }

    /** @return array<string,mixed> */
    protected function formData(?Invoice $invoice, array $prefill, ?Quote $quote = null): array
    {
        $patients = Patient::active()->orderBy('full_name')->limit(2000)->get(['id', 'full_name', 'folio']);
        if (! empty($prefill['patient_id']) && ! $patients->contains('id', $prefill['patient_id'])) {
            if ($p = Patient::find($prefill['patient_id'])) {
                $patients->push($p);
            }
        }

        $profiles = PatientFiscalProfile::query()->get()->groupBy('patient_id')->map(fn ($g) => $g->map(fn ($p) => [
            'id' => $p->id, 'rfc' => $p->rfc, 'legal_name' => $p->legal_name,
            'regime' => $p->tax_regime_code, 'cp' => $p->fiscal_postal_code,
            'default' => (bool) $p->is_default, 'use' => $p->default_cfdi_use,
        ])->values());

        return [
            'invoice' => $invoice,
            'quote' => $quote,
            'values' => $prefill,
            'patients' => $patients,
            'profiles' => $profiles,
            'branches' => Branch::active()->orderBy('name')->get(['id', 'name', 'is_main']),
            'doctors' => OrganizationMember::active()->whereHas('role', fn ($q) => $q->where('code', 'doctor'))->with('user')->get(),
            'taxProfiles' => TaxProfile::active()->orderByDesc('rate')->get(['id', 'name', 'tax_code', 'rate', 'is_exempt']),
            'blankLine' => $this->blankLine(),
        ];
    }
}
