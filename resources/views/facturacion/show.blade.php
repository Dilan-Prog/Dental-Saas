@php
    use App\Enums\FiscalStatus as F;
    use App\Enums\PaymentStatus;
    use App\Support\Money;

    $u = auth()->user();
    $issuer = (array) $invoice->issuer_snapshot;
    $receiver = (array) $invoice->receiver_snapshot;
    $f = $invoice->fiscal_status;
    $canPay = $u->hasPermission('facturacion', 'registrar_pagos') && $invoice->acceptsPayments();
    $canCancel = $u->hasPermission('facturacion', 'cancelar_cfdi') && $invoice->isCancelable();
    $canEdit = $u->hasPermission('facturacion', 'editar') && $invoice->isEditable();
    $canDelete = $u->hasPermission('facturacion', 'desactivar') && $invoice->isEditable();
    $canIssue = $u->hasPermission('facturacion', 'crear') && in_array($f, [F::Borrador, F::Error], true);
    $subtitle = ($f === F::Cancelada ? 'Cancelada' : ($f === F::Borrador ? 'Borrador' : ($isComplement ? 'Complemento de pago' : $invoice->collection_status->label())))
              . ' · CFDI 4.0 · ' . $f->label();
    $balanceDue = Money::cmp($balance, '0') > 0;
@endphp
<x-app-layout :title="'Factura ' . $invoice->internal_folio"
              :breadcrumbs="['Facturación' => route('facturacion.index'), $invoice->internal_folio]">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/modules/facturacion.css') }}?v={{ @filemtime(public_path('css/modules/facturacion.css')) }}">
    @endpush

    <x-ui.page-header :title="($isComplement ? 'Complemento de pago ' : 'Factura ') . $invoice->internal_folio" :subtitle="$subtitle">
        <x-slot name="actions">
            <div class="fact-actions">
                <x-ui.button variant="ghost" icon="arrow-left" :href="route('facturacion.index')">Volver</x-ui.button>
                @if ($canIssue)
                    <x-ui.confirm-form :action="route('facturacion.emit', $invoice)" method="POST" variant="primary" icon="send" size=""
                        :label="$f === F::Error ? 'Reintentar timbrado' : 'Emitir (timbrar)'"
                        message="Se validarán los datos fiscales y se enviará la solicitud de timbrado al PAC. Ya no podrás editar la factura. ¿Continuar?" />
                @endif
                @if ($canPay)
                    <x-ui.button icon="credit-card" data-modal-open="registrar-pago">Registrar pago</x-ui.button>
                @endif
                @if ($canEdit)
                    <x-ui.button variant="secondary" icon="edit" :href="route('facturacion.edit', $invoice)">Editar</x-ui.button>
                @endif
                @if ($invoice->xml_storage_key)
                    <x-ui.button variant="secondary" icon="download" :href="route('facturacion.xml', $invoice)">XML</x-ui.button>
                @else
                    <x-ui.button variant="secondary" icon="download" disabled title="El XML se genera cuando el PAC timbra la factura">XML</x-ui.button>
                @endif
                <x-ui.button variant="secondary" icon="printer" :href="route('facturacion.print', $invoice)" target="_blank" rel="noopener">PDF</x-ui.button>
                @if ($canCancel)
                    <x-ui.button variant="danger-outline" data-modal-open="cancelar-factura">Cancelar CFDI</x-ui.button>
                @endif
                @if ($canDelete)
                    <x-ui.confirm-form :action="route('facturacion.destroy', $invoice)" label="Eliminar borrador" icon="trash"
                        message="El borrador se eliminará definitivamente. ¿Continuar?" />
                @endif
            </div>
        </x-slot>
    </x-ui.page-header>

    @if (session('emit_errors'))
        <x-ui.alert variant="danger" title="No se puede emitir la factura" class="fact-errors">
            <ul>@foreach (session('emit_errors') as $err)<li>{{ $err }}</li>@endforeach</ul>
        </x-ui.alert>
    @endif
    @if ($f === F::PendienteTimbrado)
        <x-ui.alert variant="warning" class="fact-errors">
            La solicitud de timbrado está en cola con el PAC. El <strong>UUID fiscal</strong>, el XML y el sello se asignan únicamente cuando el PAC responde.
        </x-ui.alert>
    @endif

    <div class="fact-layout">
        <x-ui.card>
            <div class="fact-doc" style="padding:0">
                <h2>{{ $issuer['legal_name'] ?? $issuer['commercial_name'] ?? 'Emisor sin configurar' }}</h2>
                <div class="fact-doc__meta">
                    RFC: {{ $issuer['rfc'] ?? '—' }}
                    @if (! empty($issuer['address_line'])) · {{ $issuer['address_line'] }}@endif
                    @if (! empty($issuer['tax_regime_code'])) · Régimen {{ $issuer['tax_regime_code'] }}@endif
                    <br>
                    Folio fiscal (UUID):
                    @if ($invoice->uuid_fiscal)
                        <span class="fact-doc__uuid">{{ strtoupper($invoice->uuid_fiscal) }}</span>
                    @else
                        <span class="text-subtle">se asigna al timbrar</span>
                    @endif
                    @if ($invoice->series || $invoice->fiscal_folio)
                        · Serie/folio fiscal: {{ $invoice->series }}{{ $invoice->fiscal_folio }}
                    @endif
                    <br>
                    {{ $invoice->branch?->name }} · {{ $invoice->created_at->translatedFormat('j M Y') }}
                    @if ($invoice->stamped_at) · Timbrada {{ $invoice->stamped_at->translatedFormat('j M Y H:i') }}@endif
                </div>

                <div class="fact-doc__block">
                    <div class="fact-doc__label">Receptor</div>
                    <div class="fact-doc__party">
                        {{ $receiver['legal_name'] ?? $invoice->patient?->full_name }}
                        @if (Route::has('pacientes.show') && $invoice->patient)
                            <a href="{{ route('pacientes.show', $invoice->patient) }}" class="fact-sub" style="display:inline;margin-left:8px">Ver paciente</a>
                        @endif
                    </div>
                    <div class="fact-doc__meta">
                        RFC: {{ $receiver['rfc'] ?? '—' }}
                        @if (! empty($receiver['tax_regime_code'])) · Régimen {{ $receiver['tax_regime_code'] }}@endif
                        @if (! empty($receiver['fiscal_postal_code'])) · C.P. {{ $receiver['fiscal_postal_code'] }}@endif
                        · Uso CFDI {{ $invoice->cfdi_use }}
                        <br>
                        Método {{ $invoice->cfdi_payment_method->value }} · Forma de pago {{ $invoice->cfdi_payment_form }} — {{ config('sat.formas_pago.' . $invoice->cfdi_payment_form, '') }}
                        @if ($invoice->doctor?->user)<br>Doctor / Atención: {{ $invoice->doctor->user->full_name }}@endif
                        @if ($invoice->quote && Route::has('presupuestos.show'))
                            <br>Presupuesto: <a href="{{ route('presupuestos.show', $invoice->quote) }}">{{ $invoice->quote->folio }}</a>
                        @endif
                    </div>
                </div>

                <table class="fact-items">
                    <thead><tr><th>Clave SAT</th><th>Descripción</th><th class="num">Cant.</th><th class="num">P. unitario</th><th class="num">Importe</th></tr></thead>
                    <tbody>
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="mono">{{ $item->sat_product_key }}</td>
                            <td>{{ $item->description }}<span class="fact-sub">{{ $item->sat_unit_key }} · Obj. imp. {{ $item->tax_object_code }}@if (Money::cmp($item->discount, '0') > 0) · Desc. {{ Money::format($item->discount) }}@endif</span></td>
                            <td class="num">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</td>
                            <td class="num">{{ Money::format($item->unit_price) }}</td>
                            <td class="num">{{ Money::format($item->amount) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                <div class="fact-totals">
                    <div><span>Subtotal</span><span>{{ Money::format($invoice->subtotal) }}</span></div>
                    @if (Money::cmp($invoice->discount_total, '0') > 0)
                        <div><span>Descuento</span><span>-{{ Money::format($invoice->discount_total) }}</span></div>
                    @endif
                    @foreach ($taxSummary as $t)
                        <div><span>{{ $t['label'] }}</span><span>{{ $t['amount'] !== null ? Money::format($t['amount']) : '—' }}</span></div>
                    @endforeach
                    <div class="is-total"><span>Total</span><span>{{ Money::format($invoice->total) }}</span></div>
                </div>

                {{-- Complementos de pago emitidos por esta factura PPD --}}
                @if ($invoice->paymentComplements->isNotEmpty())
                    <div class="fact-section">
                        <h3>Complementos de pago</h3>
                        <table class="fact-table">
                            <thead><tr><th>Complemento</th><th>Parcialidad</th><th class="num">Saldo anterior</th><th class="num">Pagado</th><th class="num">Saldo insoluto</th><th>Estado</th></tr></thead>
                            @foreach ($invoice->paymentComplements as $pc)
                                <tr>
                                    <td><a href="{{ route('facturacion.show', $pc->complement_invoice_id) }}">{{ $pc->complementInvoice?->internal_folio }}</a></td>
                                    <td>{{ $pc->installment_number }}</td>
                                    <td class="num">{{ Money::format($pc->previous_balance) }}</td>
                                    <td class="num">{{ Money::format($pc->paid_amount) }}</td>
                                    <td class="num">{{ Money::format($pc->remaining_balance) }}</td>
                                    <td>{{ $pc->complementInvoice?->fiscal_status->label() }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endif

                {{-- Si esta factura ES un complemento de pago --}}
                @if ($isComplement && $invoice->complementRows->isNotEmpty())
                    <div class="fact-section">
                        <h3>Documentos relacionados</h3>
                        <table class="fact-table">
                            <thead><tr><th>Factura</th><th>Parcialidad</th><th class="num">Saldo anterior</th><th class="num">Pagado</th><th class="num">Saldo insoluto</th></tr></thead>
                            @foreach ($invoice->complementRows as $row)
                                <tr>
                                    <td><a href="{{ route('facturacion.show', $row->related_invoice_id) }}">{{ $row->relatedInvoice?->internal_folio }}</a></td>
                                    <td>{{ $row->installment_number }}</td>
                                    <td class="num">{{ Money::format($row->previous_balance) }}</td>
                                    <td class="num">{{ Money::format($row->paid_amount) }}</td>
                                    <td class="num">{{ Money::format($row->remaining_balance) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endif

                @unless ($isComplement)
                <div class="fact-section">
                    <h3>Historial de pagos</h3>
                    @if ($invoice->applications->isEmpty())
                        <p class="fact-note">Sin pagos registrados.</p>
                    @else
                        <table class="fact-table">
                            <thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th class="num">Monto</th><th></th></tr></thead>
                            @foreach ($invoice->applications->sortBy('applied_at') as $app)
                                @php $pay = $app->payment; $reversed = $app->reversed_at !== null; @endphp
                                <tr class="{{ $reversed ? 'fact-reversed' : '' }}">
                                    <td>{{ $pay?->paid_at?->translatedFormat('j M Y') }}</td>
                                    <td>{{ $pay?->method->label() }}</td>
                                    <td class="mono">{{ $pay?->reference ?: '—' }}</td>
                                    <td class="num">{{ Money::format($app->applied_amount) }}</td>
                                    <td class="text-right nowrap">
                                        @if ($reversed)
                                            <x-ui.badge variant="neutral">Revertido</x-ui.badge>
                                        @elseif ($u->hasPermission('facturacion', 'registrar_pagos') && $pay)
                                            <x-ui.button variant="ghost" size="sm" :data-modal-open="'revertir-' . $pay->id">Revertir</x-ui.button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </div>
                @endunless

                @if ($invoice->pacRequests->isNotEmpty() || $invoice->cancellations->isNotEmpty())
                    <div class="fact-section">
                        <h3>Bitácora fiscal</h3>
                        <table class="fact-table">
                            <thead><tr><th>Fecha</th><th>Evento</th><th>Estado</th><th>Detalle</th></tr></thead>
                            @foreach ($invoice->pacRequests as $r)
                                <tr>
                                    <td class="nowrap">{{ $r->requested_at?->translatedFormat('j M Y H:i') }}</td>
                                    <td>{{ $r->kind->label() }} (intento {{ $r->attempt_number }})</td>
                                    <td>{{ $r->status->label() }}</td>
                                    <td class="text-muted">{{ $r->error_detail ?: $r->response_message ?: '—' }}</td>
                                </tr>
                            @endforeach
                            @foreach ($invoice->cancellations as $c)
                                <tr>
                                    <td class="nowrap">{{ $c->requested_at?->translatedFormat('j M Y H:i') }}</td>
                                    <td>Cancelación · motivo {{ $c->reason_code }} ({{ config('sat.motivos_cancelacion.' . $c->reason_code) }})
                                        @if ($c->substituteInvoice)<span class="fact-sub">Sustituida por {{ $c->substituteInvoice->internal_folio }}</span>@endif</td>
                                    <td>{{ $c->status->label() }}</td>
                                    <td class="text-muted">{{ $c->response_detail ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card>
            @if ($isComplement)
                <div class="fact-side__label">Tipo</div>
                <div class="fact-side__value" style="font-size:18px">Complemento de pago</div>
                @include('facturacion._estado', ['invoice' => $invoice, 'showFiscal' => false])
            @else
                <div class="fact-side__label">Pagado</div>
                <div class="fact-side__value">{{ Money::format($applied) }}</div>
                <div class="fact-side__label">Saldo</div>
                <div class="fact-side__value {{ $balanceDue && $f !== F::Cancelada ? 'fact-side__value--due' : 'fact-side__value--ok' }}">
                    {{ $f === F::Cancelada ? '$0.00' : Money::format($balance) }}
                </div>
                @include('facturacion._estado', ['invoice' => $invoice, 'showFiscal' => false])
            @endif
        </x-ui.card>
    </div>

    {{-- ===== Modal: registrar pago ===== --}}
    @if ($canPay)
        <x-ui.modal id="registrar-pago" title="Registrar pago" :subtitle="'Factura ' . $invoice->internal_folio . ' · Saldo ' . Money::format($balance)"
                    :action="route('pagos.store', $invoice)" method="POST" size="md" open-on-error>
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
            <div class="stack">
                <x-ui.field name="amount" label="Monto" required bag="pago" mask="money" inputmode="decimal"
                            :value="number_format((float) $balance, 2, '.', '')" />
                <div class="field">
                    <label class="field__label">Método de pago</label>
                    <div class="fact-methods">
                        <input type="hidden" name="method" value="{{ old('method', 'tarjeta') }}">
                        <div class="segmented" data-method-group>
                            @foreach (['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'domiciliado' => 'Domiciliado'] as $k => $label)
                                <button type="button" data-method="{{ $k }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    @if ($errors->getBag('pago')->has('method'))<div class="field__error">{{ $errors->getBag('pago')->first('method') }}</div>@endif
                </div>
                <div data-ref-field>
                    <x-ui.field name="reference" label="Referencia" bag="pago" maxlength="120" placeholder="AUTH-"
                                hint="Obligatoria en transferencias; debe ser única." />
                </div>
                <x-ui.field name="paid_at" type="date" label="Fecha del pago" bag="pago" :value="now()->toDateString()" max="{{ now()->toDateString() }}" />
                @if ($u->hasPermission('finanzas', 'ver') && Route::has('finanzas.index'))
                    <p class="fact-note">Los pagos en efectivo requieren una caja abierta en la sucursal (<a href="{{ route('finanzas.index') }}">Finanzas</a>).</p>
                @endif
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Registrar pago</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif

    {{-- ===== Modal: cancelar factura ===== --}}
    @if ($canCancel)
        <x-ui.modal id="cancelar-factura" title="Cancelar CFDI" :subtitle="'Factura ' . $invoice->internal_folio"
                    :action="route('facturacion.cancel', $invoice)" method="POST" size="md" open-on-error>
            <div class="stack">
                @if (empty($invoice->uuid_fiscal))
                    <x-ui.alert variant="info">Aún no se timbra: se cancelará de inmediato, sin trámite ante el SAT.</x-ui.alert>
                @else
                    <x-ui.alert variant="warning">La cancelación se solicita al PAC/SAT y queda en proceso hasta su respuesta.</x-ui.alert>
                @endif
                @if (Money::cmp($applied, '0') > 0)
                    <x-ui.alert variant="danger">Esta factura tiene pagos aplicados: revierte los pagos antes de cancelarla.</x-ui.alert>
                @endif
                <div class="field">
                    <label class="field__label" for="cancel_reason_code">Motivo de cancelación <span class="req">*</span></label>
                    <x-ui.select name="reason_code" id="cancel_reason_code" bag="cancelacion" placeholder="Selecciona"
                        :options="collect(config('sat.motivos_cancelacion'))->map(fn ($l, $k) => $k . ' — ' . $l)->all()" required />
                    @if ($errors->getBag('cancelacion')->has('reason_code'))<div class="field__error">{{ $errors->getBag('cancelacion')->first('reason_code') }}</div>@endif
                </div>
                <div class="field" data-substitute-field hidden>
                    <label class="field__label" for="substitute_invoice_id">Factura que la sustituye <span class="req">*</span></label>
                    <x-ui.select name="substitute_invoice_id" id="substitute_invoice_id" bag="cancelacion" placeholder="Selecciona la factura sustituta"
                        :options="$substitutes->mapWithKeys(fn ($s) => [$s->id => $s->internal_folio . ' · ' . Money::format($s->total)])->all()" />
                    @if ($errors->getBag('cancelacion')->has('substitute_invoice_id'))<div class="field__error">{{ $errors->getBag('cancelacion')->first('substitute_invoice_id') }}</div>@endif
                </div>
                <x-ui.field name="detail" as="textarea" rows="3" label="Comentario (opcional)" bag="cancelacion" maxlength="500" />
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Volver</x-ui.button>
                <x-ui.button type="submit" variant="danger">Cancelar factura</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif

    {{-- ===== Modales: revertir pago (uno por pago vigente) ===== --}}
    @unless ($isComplement)
        @foreach ($invoice->applications->whereNull('reversed_at') as $app)
            @if ($app->payment && $u->hasPermission('facturacion', 'registrar_pagos'))
                @php $bag = 'reversion_' . $app->payment->id; @endphp
                <x-ui.modal :id="'revertir-' . $app->payment->id" title="Revertir pago"
                            :subtitle="Money::format($app->payment->amount) . ' · ' . $app->payment->method->label()"
                            :action="route('pagos.reverse', $app->payment)" method="POST" size="sm" open-on-error>
                    <x-ui.alert variant="warning">El pago deja de contar, se recalcula el saldo y se registra un movimiento inverso en Finanzas.</x-ui.alert>
                    <div class="mt-1">
                        <x-ui.field name="reason" as="textarea" rows="3" label="Motivo de la reversión" required :bag="$bag" maxlength="500" />
                    </div>
                    <x-slot name="footer">
                        <x-ui.button variant="secondary" data-modal-close>Volver</x-ui.button>
                        <x-ui.button type="submit" variant="danger">Revertir pago</x-ui.button>
                    </x-slot>
                </x-ui.modal>
            @endif
        @endforeach
    @endunless

    @push('scripts')
        <script src="{{ asset('js/modules/facturacion.js') }}?v={{ @filemtime(public_path('js/modules/facturacion.js')) }}"></script>
    @endpush
</x-app-layout>
