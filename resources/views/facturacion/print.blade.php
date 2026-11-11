<!DOCTYPE html>
@php
    use App\Enums\FiscalStatus as F;
    use App\Support\Money;
    $issuer = (array) $invoice->issuer_snapshot;
    $receiver = (array) $invoice->receiver_snapshot;
    $isComplement = $invoice->cfdi_type === \App\Enums\CfdiType::Pago;
@endphp
<html lang="es" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->internal_folio }} · Factura</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/modules/facturacion.css') }}?v={{ @filemtime(public_path('css/modules/facturacion.css')) }}">
</head>
<body style="background:#f1f5f9">
<div class="fact-print">
    <div class="toolbar">
        <a class="btn btn--secondary" href="{{ route('facturacion.show', $invoice) }}">Volver</a>
        <button class="btn btn--primary" type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>

    <div class="row row--between row--wrap" style="align-items:flex-start">
        <div>
            <h1>{{ $isComplement ? 'Complemento de pago' : 'Factura' }} {{ $invoice->internal_folio }}</h1>
            <div class="fact-doc__meta">
                {{ $invoice->created_at->translatedFormat('j \d\e F \d\e Y') }} · {{ $invoice->branch?->name }}<br>
                @if ($invoice->uuid_fiscal) Folio fiscal: <span class="fact-doc__uuid">{{ strtoupper($invoice->uuid_fiscal) }}</span>
                @else <span class="watermark">SIN TIMBRAR — NO TIENE VALIDEZ FISCAL</span> @endif
                @if ($invoice->fiscal_status === F::Cancelada)<br><span class="watermark">CANCELADA</span>@endif
            </div>
        </div>
        <div style="text-align:right" class="fact-doc__meta">
            <strong style="font-size:15px;color:#111">{{ $issuer['legal_name'] ?? $issuer['commercial_name'] ?? '' }}</strong><br>
            RFC {{ $issuer['rfc'] ?? '—' }}<br>
            @if (! empty($issuer['tax_regime_code'])) Régimen {{ $issuer['tax_regime_code'] }}<br>@endif
            @if (! empty($issuer['fiscal_postal_code'])) C.P. {{ $issuer['fiscal_postal_code'] }}@endif
        </div>
    </div>

    <div class="fact-doc__block">
        <div class="fact-doc__label">Receptor</div>
        <div class="fact-doc__party">{{ $receiver['legal_name'] ?? $invoice->patient?->full_name }}</div>
        <div class="fact-doc__meta">
            RFC {{ $receiver['rfc'] ?? '—' }} · Régimen {{ $receiver['tax_regime_code'] ?? '—' }} · C.P. {{ $receiver['fiscal_postal_code'] ?? '—' }}<br>
            Uso CFDI {{ $invoice->cfdi_use }} · Método {{ $invoice->cfdi_payment_method->value }} · Forma de pago {{ $invoice->cfdi_payment_form }}
            @if ($invoice->doctor?->user)<br>Atención: {{ $invoice->doctor->user->full_name }}@endif
        </div>
    </div>

    <table class="fact-items">
        <thead><tr><th>Clave SAT</th><th>Descripción</th><th class="num">Cant.</th><th class="num">P. unitario</th><th class="num">Importe</th></tr></thead>
        <tbody>
        @foreach ($invoice->items as $item)
            <tr>
                <td class="mono">{{ $item->sat_product_key }}</td>
                <td>{{ $item->description }}<span class="fact-sub">{{ $item->sat_unit_key }}</span></td>
                <td class="num">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</td>
                <td class="num">{{ Money::format($item->unit_price) }}</td>
                <td class="num">{{ Money::format($item->amount) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="fact-totals">
        <div><span>Subtotal</span><span>{{ Money::format($invoice->subtotal) }}</span></div>
        @if (Money::cmp($invoice->discount_total, '0') > 0)<div><span>Descuento</span><span>-{{ Money::format($invoice->discount_total) }}</span></div>@endif
        @foreach ($taxSummary as $t)<div><span>{{ $t['label'] }}</span><span>{{ $t['amount'] !== null ? Money::format($t['amount']) : '—' }}</span></div>@endforeach
        <div class="is-total"><span>Total {{ $invoice->currency }}</span><span>{{ Money::format($invoice->total) }}</span></div>
        @unless ($isComplement)
            <div><span>Pagado</span><span>{{ Money::format($applied) }}</span></div>
            <div><span>Saldo</span><span>{{ $invoice->fiscal_status === F::Cancelada ? '$0.00' : Money::format($balance) }}</span></div>
        @endunless
    </div>

    <p class="fact-note" style="margin-top:28px">Representación impresa. Para validez fiscal se requiere el CFDI timbrado (XML) emitido por un PAC autorizado.</p>
</div>
</body>
</html>
