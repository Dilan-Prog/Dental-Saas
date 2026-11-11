<?php

namespace App\Http\Controllers\Facturacion;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facturacion\PaymentRequest;
use App\Http\Requests\Facturacion\ReversePaymentRequest;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Services\Facturacion\BillingException;
use App\Services\Facturacion\PaymentService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $service)
    {
    }

    /** Pago multiple: elige paciente y reparte el monto entre sus facturas con saldo. */
    public function create(Request $request)
    {
        $patients = Patient::active()->orderBy('full_name')->limit(2000)->get(['id', 'full_name', 'folio']);
        $patient = $request->filled('patient') ? Patient::find($request->input('patient')) : null;

        $invoices = collect();
        if ($patient) {
            $invoices = Invoice::receivable()
                ->where('patient_id', $patient->id)
                ->where('cfdi_type', 'I')
                ->withSum(['applications as applied_sum' => fn ($q) => $q->whereNull('reversed_at')], 'applied_amount')
                ->orderBy('created_at')->get()
                ->each(fn ($i) => $i->balance_due = Money::sub($i->total, $i->applied_sum ?? 0));
        }

        return view('facturacion.pagos.create', [
            'patients' => $patients,
            'patient' => $patient,
            'invoices' => $invoices,
            'methods' => PaymentMethod::options(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    /** Pago desde el detalle de una factura (modal "Registrar pago"). */
    public function store(PaymentRequest $request, Invoice $invoice)
    {
        $data = $request->validated();
        $data['patient_id'] = $invoice->patient_id;
        $data['branch_id'] = $invoice->branch_id;
        $data['applications'] = [$invoice->id => $data['amount']];

        return $this->register($data, $request, route('facturacion.show', $invoice));
    }

    public function storeMulti(PaymentRequest $request)
    {
        $data = $request->validated();

        return $this->register($data, $request, route('facturacion.index', ['patient' => $data['patient_id']]));
    }

    public function reverse(ReversePaymentRequest $request, Payment $payment)
    {
        try {
            $this->service->reverse($payment, $request->validated('reason'), $request->user());
        } catch (BillingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pago revertido. El saldo de las facturas se recalculo.');
    }

    protected function register(array $data, PaymentRequest $request, string $redirect)
    {
        try {
            $payment = $this->service->register($data, $request->user());
        } catch (BillingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect($redirect)->with(
            $payment->wasRecentlyCreated ? 'success' : 'info',
            $payment->wasRecentlyCreated
                ? 'Pago de ' . Money::format($payment->amount) . ' registrado.'
                : 'Este pago ya estaba registrado (no se duplico).'
        );
    }
}
