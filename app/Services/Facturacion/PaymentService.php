<?php

namespace App\Services\Facturacion;

use App\Enums\CfdiPaymentMethod;
use App\Enums\CfdiType;
use App\Enums\CollectionStatus;
use App\Enums\FiscalStatus;
use App\Enums\MovementDirection;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashSession;
use App\Models\FinancialCategory;
use App\Models\FinancialMovement;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\PaymentComplement;
use App\Models\PaymentReversal;
use App\Models\User;
use App\Support\Folio;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Cobranza: pagos, aplicaciones a facturas, movimiento financiero, complementos
 * de pago (PPD) y reversiones. Todo en una transaccion con bloqueo de facturas.
 */
class PaymentService
{
    public const INCOME_CATEGORY = 'Tratamientos';

    /**
     * Registra un pago y lo aplica a una o varias facturas del mismo paciente.
     *
     * @param  array{patient_id:string, branch_id?:?string, paid_at?:mixed, method:string, reference?:?string,
     *               idempotency_key:string, applications: array<string,string|float|int>}  $data  applications: invoice_id => monto
     */
    public function register(array $data, ?User $actor = null): Payment
    {
        try {
            return DB::transaction(fn () => $this->doRegister($data, $actor));
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    protected function doRegister(array $data, ?User $actor): Payment
    {
        // Idempotencia: el mismo token no duplica el pago.
        $existing = Payment::where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return $existing;
        }

        $method = PaymentMethod::from($data['method']);
        $apps = collect($data['applications'])
            ->map(fn ($amount) => Money::round($amount))
            ->filter(fn ($amount) => Money::cmp($amount, '0') > 0);
        if ($apps->isEmpty()) {
            throw new BillingException('Indica el monto a aplicar.');
        }

        // Bloqueo en orden estable para evitar interbloqueos.
        $invoices = Invoice::whereIn('id', $apps->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($invoices->count() !== $apps->count()) {
            throw new BillingException('Alguna factura seleccionada no existe.');
        }

        $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
        if ($paidAt->gt(now()->addMinutes(5))) {
            throw new BillingException('La fecha del pago no puede ser futura.');
        }

        foreach ($apps as $invoiceId => $amount) {
            /** @var Invoice $invoice */
            $invoice = $invoices[$invoiceId];

            if ($invoice->patient_id !== $data['patient_id']) {
                throw new BillingException("La factura {$invoice->internal_folio} no pertenece al paciente del pago.");
            }
            if (! $invoice->acceptsPayments()) {
                throw new BillingException("La factura {$invoice->internal_folio} no admite cobros (cancelada, en borrador, complemento o ya pagada).");
            }
            if ($paidAt->lt($invoice->created_at->copy()->startOfDay())) {
                throw new BillingException("La fecha del pago es anterior a la creacion de la factura {$invoice->internal_folio}.");
            }
            $balance = $invoice->balance();
            if (Money::cmp($amount, $balance) > 0) {
                throw new BillingException("El monto excede el saldo de la factura {$invoice->internal_folio} (saldo " . Money::format($balance) . ').');
            }
        }

        $reference = isset($data['reference']) ? trim((string) $data['reference']) : null;
        $reference = $reference === '' ? null : $reference;
        if ($method === PaymentMethod::Transferencia) {
            if ($reference === null) {
                throw new BillingException('La referencia es obligatoria en transferencias.');
            }
            if (Payment::where('method', PaymentMethod::Transferencia->value)->where('reference', $reference)->exists()) {
                throw new BillingException('La referencia de transferencia ya fue registrada en otro pago.');
            }
        }

        $branchId = $data['branch_id'] ?? $invoices->first()->branch_id;
        $session = null;
        if ($method === PaymentMethod::Efectivo) {
            $session = $this->openSessionFor($branchId);
            if (! $session) {
                throw new BillingException('No hay una caja abierta en esta sucursal. Abre caja en Finanzas para registrar pagos en efectivo.');
            }
        }

        $total = Money::sum($apps->values());

        $payment = new Payment([
            'branch_id' => $branchId,
            'patient_id' => $data['patient_id'],
            'paid_at' => $paidAt,
            'amount' => $total,
            'currency' => 'MXN',
            'method' => $method,
            'reference' => $reference,
            'status' => PaymentStatus::Registrado,
            'idempotency_key' => $data['idempotency_key'],
            'cash_session_id' => $session?->id,
            'created_by' => $actor?->id,
        ]);
        $payment->save();

        $ppd = [];
        foreach ($apps as $invoiceId => $amount) {
            $invoice = $invoices[$invoiceId];
            $previous = $invoice->balance();

            (new PaymentApplication([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'applied_amount' => $amount,
                'applied_at' => $paidAt,
            ]))->save();

            $this->recalculateCollection($invoice);

            if ($invoice->cfdi_payment_method === CfdiPaymentMethod::Ppd) {
                $ppd[] = ['invoice' => $invoice, 'previous' => $previous, 'paid' => $amount];
            }
        }

        $this->recordIncome($payment, $invoices->values(), $session, $actor);

        if ($ppd) {
            $this->createComplement($payment, $ppd);
        }

        return $payment->refresh();
    }

    /** Revierte un pago: libera sus aplicaciones, recalcula cobranza y registra la reversion contable. */
    public function reverse(Payment $payment, string $reason, ?User $actor = null): Payment
    {
        try {
            return DB::transaction(fn () => $this->doReverse($payment, $reason, $actor));
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    protected function doReverse(Payment $payment, string $reason, ?User $actor): Payment
    {
        $payment = Payment::lockForUpdate()->findOrFail($payment->id);
        if ($payment->status !== PaymentStatus::Registrado) {
            throw new BillingException('El pago ya fue revertido.');
        }

        $apps = PaymentApplication::where('payment_id', $payment->id)->whereNull('reversed_at')->get();
        $invoices = Invoice::whereIn('id', $apps->pluck('invoice_id'))->orderBy('id')->lockForUpdate()->get();

        // Complementos de pago de este pago: si alguno ya esta timbrado no se puede revertir.
        $complementIds = PaymentComplement::where('payment_id', $payment->id)->pluck('complement_invoice_id')->unique();
        $complements = Invoice::whereIn('id', $complementIds)->get();
        $stamped = $complements->first(fn ($c) => ! empty($c->uuid_fiscal) && $c->fiscal_status !== FiscalStatus::Cancelada);
        if ($stamped) {
            throw new BillingException("El complemento de pago {$stamped->internal_folio} ya esta timbrado. Cancelalo antes de revertir el pago.");
        }

        $session = null;
        if ($payment->method === PaymentMethod::Efectivo) {
            $session = $payment->cash_session_id ? CashSession::open()->where('id', $payment->cash_session_id)->first() : null;
            $session ??= $this->openSessionFor($payment->branch_id);
            if (! $session) {
                throw new BillingException('No hay una caja abierta en esta sucursal. Abre caja en Finanzas para revertir un pago en efectivo.');
            }
        }

        PaymentApplication::where('payment_id', $payment->id)->whereNull('reversed_at')->update(['reversed_at' => now()]);

        $payment->status = PaymentStatus::Revertido;
        $payment->save();

        (new PaymentReversal([
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
            'reason' => $reason,
            'reversed_by' => $actor?->id,
            'reversed_at' => now(),
        ]))->save();

        foreach ($invoices as $invoice) {
            $this->recalculateCollection($invoice);
        }

        // Los complementos sin timbrar dejan de tener sentido.
        foreach ($complements as $c) {
            $c->fiscal_status = FiscalStatus::Cancelada;
            $c->save();
        }

        $original = FinancialMovement::where('payment_id', $payment->id)->first();
        if ($original) {
            (new FinancialMovement([
                'branch_id' => $payment->branch_id,
                'direction' => MovementDirection::Egreso,
                'category_id' => $original->category_id,
                'concept' => 'Reversion de pago: ' . $original->concept,
                'occurred_on' => now()->toDateString(),
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'payment_id' => null,
                'cash_session_id' => $session?->id,
                'created_by' => $actor?->id,
                'reversed_of_id' => $original->id,
            ]))->save();
        }

        return $payment->refresh();
    }

    /** collection_status = f(aplicaciones vigentes / total). */
    public function recalculateCollection(Invoice $invoice): void
    {
        $invoice->refresh();
        $applied = $invoice->appliedAmount();

        $status = Money::cmp($applied, '0') <= 0
            ? CollectionStatus::Pendiente
            : (Money::cmp($applied, $invoice->total) >= 0 ? CollectionStatus::Pagada : CollectionStatus::PagoParcial);

        if ($invoice->collection_status !== $status) {
            $invoice->collection_status = $status;
            $invoice->save();
        }
    }

    public function openSessionFor(?string $branchId): ?CashSession
    {
        return CashSession::query()->open()
            ->whereHas('cashRegister', fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('opened_at')->first();
    }

    // ------------------------------------------------------------------ internos

    protected function recordIncome(Payment $payment, $invoices, ?CashSession $session, ?User $actor): void
    {
        $category = FinancialCategory::firstOrCreate(
            ['name' => self::INCOME_CATEGORY, 'direction' => MovementDirection::Ingreso->value],
            ['status' => 'active']
        );

        $folios = $invoices->pluck('internal_folio')->implode(', ');
        $patient = $payment->patient()->first();

        (new FinancialMovement([
            'branch_id' => $payment->branch_id,
            'direction' => MovementDirection::Ingreso,
            'category_id' => $category->id,
            'concept' => "Pago de factura {$folios} - " . ($patient?->full_name ?? 'Paciente'),
            'occurred_on' => $payment->paid_at->toDateString(),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'method' => $payment->method,
            'payment_id' => $payment->id,
            'cash_session_id' => $session?->id,
            'created_by' => $actor?->id,
        ]))->save();
    }

    /**
     * Complemento de pago (CFDI tipo P) para pagos de facturas PPD. Queda en
     * borrador: se emite cuando la factura relacionada ya esta timbrada.
     *
     * @param  array<int, array{invoice: Invoice, previous: string, paid: string}>  $ppd
     */
    protected function createComplement(Payment $payment, array $ppd): void
    {
        $first = $ppd[0]['invoice'];
        $settings = Organization::current()?->fiscalSettings;
        $receiver = (array) $first->receiver_snapshot;
        $form = config('sat.forma_pago_por_metodo.' . $payment->method->value, '99');

        $complement = new Invoice([
            'internal_folio' => Folio::next(Folio::INVOICE),
            'series' => $settings?->default_series ?: null,
            'cfdi_type' => CfdiType::Pago,
            'patient_id' => $payment->patient_id,
            'branch_id' => $payment->branch_id,
            'issuer_snapshot' => (new InvoiceService())->issuerSnapshot(),
            'receiver_snapshot' => $receiver,
            'cfdi_use' => 'CP01',
            'cfdi_payment_method' => CfdiPaymentMethod::Pue,
            'cfdi_payment_form' => $form,
            'currency' => 'MXN',
            'exchange_rate' => 1,
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 0,
            'fiscal_status' => FiscalStatus::Borrador,
            'collection_status' => CollectionStatus::Pagada,
        ]);
        $complement->save();

        (new InvoiceItem([
            'invoice_id' => $complement->id,
            'line_number' => 1,
            'description' => 'Pago',
            'sat_product_key' => '84111506',
            'sat_unit_key' => 'ACT',
            'quantity' => 1,
            'unit_price' => 0,
            'discount' => 0,
            'amount' => 0,
            'tax_object_code' => '01',
        ]))->save();

        foreach ($ppd as $row) {
            $installment = PaymentComplement::where('related_invoice_id', $row['invoice']->id)
                ->whereIn('complement_invoice_id', Invoice::where('fiscal_status', '!=', FiscalStatus::Cancelada->value)
                    ->where('cfdi_type', CfdiType::Pago->value)->select('id'))
                ->count() + 1;

            (new PaymentComplement([
                'complement_invoice_id' => $complement->id,
                'payment_id' => $payment->id,
                'related_invoice_id' => $row['invoice']->id,
                'installment_number' => $installment,
                'previous_balance' => $row['previous'],
                'paid_amount' => $row['paid'],
                'remaining_balance' => Money::sub($row['previous'], $row['paid']),
            ]))->save();
        }
    }

    /** Convierte los mensajes de los triggers/CHECK de la BD en mensajes claros. */
    protected function translate(QueryException $e): \Throwable
    {
        $msg = $e->getMessage();

        return match (true) {
            str_contains($msg, 'exceden el total de la factura') => new BillingException('Las aplicaciones exceden el saldo de la factura.'),
            str_contains($msg, 'exceden el monto del pago') => new BillingException('Las aplicaciones exceden el monto del pago.'),
            str_contains($msg, 'uq_payments_transfer_ref') => new BillingException('La referencia de transferencia ya fue registrada en otro pago.'),
            str_contains($msg, 'uq_payments_idem') => new BillingException('Este pago ya fue registrado.'),
            default => $e,
        };
    }
}
