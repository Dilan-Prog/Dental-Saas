@php
    use App\Enums\QuoteStatus;
    use App\Support\Money;

    $badge = ['borrador' => 'neutral', 'enviado' => 'info', 'aprobado' => 'success', 'rechazado' => 'danger'];
    $status = $quote->status;
    $can = fn (string $action) => auth()->user()->hasPermission('presupuestos', $action);
    $patientUrl = Route::has('pacientes.show') && $quote->patient ? route('pacientes.show', $quote->patient) : null;
    $planUrl = Route::has('expediente.show') && $quote->patient ? route('expediente.show', $quote->patient) : null;
    $taxes = $quote->items->where('tax_is_exempt', false)->filter(fn ($i) => (float) $i->line_tax > 0)->groupBy(fn ($i) => (string) $i->tax_rate_snapshot);
    $planned = $quote->items->sum(fn ($i) => $i->patientTreatments->count());
@endphp
<x-app-layout :title="'Presupuesto '.$quote->folio" :breadcrumbs="['Presupuestos' => route('presupuestos.index'), $quote->folio]">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/modules/presupuestos.css') }}">
    @endpush

    <x-ui.page-header :title="'Presupuesto '.$quote->folio"
        :subtitle="'Paciente: '.($quote->patient?->full_name ?? '—').' · Doctor: '.($quote->doctor?->name ?? '—')">
        <x-slot name="actions">
            <x-ui.badge :variant="$badge[$status->value]" dot>{{ $status->label() }}</x-ui.badge>
            <x-ui.button variant="secondary" icon="printer" :href="route('presupuestos.print', $quote)" target="_blank" rel="noopener">Imprimir</x-ui.button>

            @if ($status === QuoteStatus::Borrador)
                @if ($can('editar'))
                    <x-ui.button variant="secondary" icon="edit" :href="route('presupuestos.edit', $quote)">Editar</x-ui.button>
                    <x-ui.confirm-form :action="route('presupuestos.send', $quote)" method="POST" variant="primary" size="md" icon="send"
                        title="Enviar presupuesto" message="¿Marcar el presupuesto {{ $quote->folio }} como enviado al paciente? Ya no podrá editarse."
                        label="Marcar como enviado" confirmText="Enviar" />
                @endif
                @if ($can('desactivar'))
                    <x-ui.confirm-form :action="route('presupuestos.destroy', $quote)" method="DELETE" variant="danger-outline" size="md" icon="trash"
                        title="Eliminar presupuesto" message="¿Eliminar el borrador {{ $quote->folio }}? Esta acción no se puede deshacer." label="Eliminar" />
                @endif
            @elseif ($status === QuoteStatus::Enviado && $can('editar'))
                <x-ui.confirm-form :action="route('presupuestos.reopen', $quote)" method="POST" variant="secondary" size="md" icon="refresh"
                    title="Reabrir como borrador" message="El presupuesto volverá a borrador para poder editarlo." label="Reabrir" confirmText="Reabrir" />
                <x-ui.confirm-form :action="route('presupuestos.reject', $quote)" method="POST" variant="danger-outline" size="md" icon="x-circle"
                    title="Rechazar presupuesto" message="¿Registrar que el paciente rechazó el presupuesto {{ $quote->folio }}?" label="Rechazar" confirmText="Rechazar" />
                <x-ui.confirm-form :action="route('presupuestos.approve', $quote)" method="POST" variant="primary" size="md" icon="check-circle"
                    title="Aprobar presupuesto" message="¿Aprobar el presupuesto {{ $quote->folio }}? Sus conceptos pasarán al plan de tratamiento del paciente."
                    label="Aprobar" confirmText="Aprobar" />
            @elseif ($status === QuoteStatus::Aprobado && Route::has('facturacion.fromQuote'))
                <x-ui.button icon="receipt" :href="route('facturacion.fromQuote', $quote)">Facturar</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-sidebar">
        <div class="stack">
            <x-ui.card title="Conceptos" flush>
                <x-ui.table>
                    <x-slot name="head">
                        <tr><th>Tratamiento</th><th>Diente</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Desc. %</th><th class="text-right">Importe</th></tr>
                    </x-slot>
                    @foreach ($quote->items as $item)
                        <tr>
                            <td>
                                <div class="fw-500">{{ $item->description_snapshot }}</div>
                                @if ($item->sat_product_key_snapshot)<div class="text-xs text-subtle mono">SAT {{ $item->sat_product_key_snapshot }}</div>@endif
                            </td>
                            <td>{{ $item->tooth_fdi ?? '—' }}</td>
                            <td class="text-right mono">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') ?: '0' }}</td>
                            <td class="text-right mono">{{ Money::format($item->unit_price) }}</td>
                            <td class="text-right mono">{{ rtrim(rtrim((string) $item->discount_pct, '0'), '.') ?: '0' }}%</td>
                            <td class="text-right fw-500 mono">{{ Money::format($item->line_subtotal - $item->line_discount) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <div class="quote-totals-wrap">
                <div class="quote-totals card">
                    <div class="quote-totals__row"><span>Subtotal</span><span class="mono">{{ Money::format($quote->subtotal) }}</span></div>
                    @if ((float) $quote->discount_total > 0)
                        <div class="quote-totals__row"><span>Descuento</span><span class="mono">-{{ Money::format($quote->discount_total) }}</span></div>
                    @endif
                    @foreach ($taxes as $rate => $items)
                        <div class="quote-totals__row"><span>IVA {{ rtrim(rtrim(number_format((float) $rate * 100, 4), '0'), '.') }}%</span><span class="mono">{{ Money::format($items->sum('line_tax')) }}</span></div>
                    @endforeach
                    <div class="quote-totals__row quote-totals__row--total"><span>Total</span><span class="mono">{{ Money::format($quote->total) }}</span></div>
                </div>
            </div>
        </div>

        <div class="stack">
            <x-ui.card title="Detalle">
                <dl class="dl">
                    <dt>Paciente</dt>
                    <dd>@if ($patientUrl)<a href="{{ $patientUrl }}">{{ $quote->patient->full_name }}</a>@else{{ $quote->patient?->full_name ?? '—' }}@endif
                        @if ($quote->patient)<span class="text-xs text-subtle"> · {{ $quote->patient->folio }}</span>@endif</dd>
                    <dt>Doctor</dt><dd>{{ $quote->doctor?->name ?? '—' }}</dd>
                    <dt>Sucursal</dt><dd>{{ $quote->branch?->name ?? '—' }}</dd>
                    <dt>Fecha</dt><dd>{{ $quote->quote_date->translatedFormat('j \d\e F \d\e Y') }}</dd>
                    @if ($quote->sent_at)<dt>Enviado</dt><dd>{{ $quote->sent_at->translatedFormat('j M Y, H:i') }}</dd>@endif
                    <dt>Moneda</dt><dd>{{ $quote->currency }}</dd>
                </dl>
                @if ($status === QuoteStatus::Aprobado && $planned > 0)
                    <p class="text-sm text-muted mt-1">{{ $planned }} {{ $planned === 1 ? 'tratamiento agregado' : 'tratamientos agregados' }} al plan del paciente.
                        @if ($planUrl)<a href="{{ $planUrl }}">Ver expediente</a>@endif</p>
                @endif
                @if ($quote->invoices->isNotEmpty())
                    <p class="text-sm text-muted mt-1">Facturas:
                        @foreach ($quote->invoices as $invoice)
                            @if (Route::has('facturacion.show'))<a href="{{ route('facturacion.show', $invoice) }}">{{ $invoice->internal_folio }}</a>@else{{ $invoice->internal_folio }}@endif{{ ! $loop->last ? ', ' : '' }}
                        @endforeach
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card title="Historial">
                <ul class="quote-history">
                    @foreach ($quote->statusHistory->sortByDesc('created_at') as $entry)
                        <li>
                            <x-ui.badge :variant="$badge[$entry->to_status->value]">{{ $entry->to_status->label() }}</x-ui.badge>
                            <span class="text-sm">{{ $entry->from_status ? 'desde '.mb_strtolower($entry->from_status->label()) : 'creado' }}</span>
                            <span class="text-xs text-subtle">
                                {{ $entry->created_at->translatedFormat('j M Y, H:i') }}@if ($entry->actor) · {{ $entry->actor->full_name }}@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </div>
    </div>
</x-app-layout>
