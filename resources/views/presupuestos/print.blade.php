@php
    use App\Support\Money;

    $taxes = $quote->items->where('tax_is_exempt', false)->filter(fn ($i) => (float) $i->line_tax > 0)->groupBy(fn ($i) => (string) $i->tax_rate_snapshot);
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Presupuesto {{ $quote->folio }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/modules/presupuestos.css') }}">
</head>
<body class="print-body">
    <div class="print-toolbar no-print">
        <button type="button" class="btn btn--primary" onclick="window.print()">Imprimir / Guardar PDF</button>
        <a class="btn btn--secondary" href="{{ route('presupuestos.show', $quote) }}">Volver</a>
    </div>

    <article class="print-doc">
        <header class="print-doc__head">
            <div>
                <h1>{{ $organization?->commercial_name ?? 'Clínica Dental' }}</h1>
                @if ($organization?->legal_name)<div class="print-muted">{{ $organization->legal_name }}</div>@endif
                @if ($organization?->rfc)<div class="print-muted">RFC {{ $organization->rfc }}</div>@endif
                @if ($organization?->address_line)<div class="print-muted">{{ $organization->address_line }}@if($organization->address_postal_code), C.P. {{ $organization->address_postal_code }}@endif</div>@endif
                @if ($organization?->phone || $organization?->email)<div class="print-muted">{{ $organization->phone }}@if($organization->phone && $organization->email) · @endif{{ $organization->email }}</div>@endif
            </div>
            <div class="print-doc__folio">
                <div class="print-doc__label">Presupuesto</div>
                <div class="print-doc__number">{{ $quote->folio }}</div>
                <div class="print-muted">{{ $quote->quote_date->translatedFormat('j \d\e F \d\e Y') }}</div>
                <div class="print-muted">Estado: {{ $quote->status->label() }}</div>
            </div>
        </header>

        <section class="print-doc__parties">
            <div><div class="print-doc__label">Paciente</div><strong>{{ $quote->patient?->full_name ?? '—' }}</strong>
                @if ($quote->patient?->folio)<div class="print-muted">{{ $quote->patient->folio }}</div>@endif</div>
            <div><div class="print-doc__label">Doctor</div><strong>{{ $quote->doctor?->name ?? '—' }}</strong>
                @if ($quote->doctor?->doctorProfile?->professional_license)<div class="print-muted">Cédula {{ $quote->doctor->doctorProfile->professional_license }}</div>@endif</div>
            <div><div class="print-doc__label">Sucursal</div><strong>{{ $quote->branch?->name ?? '—' }}</strong></div>
        </section>

        <table class="print-table">
            <thead><tr><th>Tratamiento</th><th>Diente</th><th class="r">Cant.</th><th class="r">Precio</th><th class="r">Desc. %</th><th class="r">Importe</th></tr></thead>
            <tbody>
                @foreach ($quote->items as $item)
                    <tr>
                        <td>{{ $item->description_snapshot }}</td>
                        <td>{{ $item->tooth_fdi ?? '—' }}</td>
                        <td class="r">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') ?: '0' }}</td>
                        <td class="r">{{ Money::format($item->unit_price) }}</td>
                        <td class="r">{{ rtrim(rtrim((string) $item->discount_pct, '0'), '.') ?: '0' }}%</td>
                        <td class="r">{{ Money::format($item->line_subtotal - $item->line_discount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="print-totals">
            <div><span>Subtotal</span><span>{{ Money::format($quote->subtotal) }}</span></div>
            @if ((float) $quote->discount_total > 0)<div><span>Descuento</span><span>-{{ Money::format($quote->discount_total) }}</span></div>@endif
            @foreach ($taxes as $rate => $items)
                <div><span>IVA {{ rtrim(rtrim(number_format((float) $rate * 100, 4), '0'), '.') }}%</span><span>{{ Money::format($items->sum('line_tax')) }}</span></div>
            @endforeach
            <div class="print-totals__total"><span>Total {{ $quote->currency }}</span><span>{{ Money::format($quote->total) }}</span></div>
        </div>

        <footer class="print-doc__foot print-muted">
            Presupuesto informativo sujeto a la valoración clínica. Los precios pueden variar si el plan de tratamiento cambia.
            Este documento no es un comprobante fiscal.
        </footer>
    </article>
</body>
</html>
