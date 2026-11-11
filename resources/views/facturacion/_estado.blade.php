{{-- Badge de estado de una factura. Variables: $invoice (+ opcional $showFiscal = true para la linea fiscal). --}}
@php
    use App\Enums\FiscalStatus as F;
    use App\Enums\CollectionStatus as C;
    $f = $invoice->fiscal_status;
    $c = $invoice->collection_status;
    [$variant, $text] = match (true) {
        $f === F::Cancelada => ['danger', 'Cancelada'],
        $f === F::Borrador => ['neutral', 'Borrador'],
        $f === F::CancelacionSolicitada => ['warning', 'Cancelación solicitada'],
        $f === F::Error => ['danger', 'Error de timbrado'],
        $invoice->cfdi_type === \App\Enums\CfdiType::Pago => ['info', 'Complemento'],
        $c === C::Pagada => ['success', 'Pagada'],
        $c === C::PagoParcial => ['warning', 'Pago parcial'],
        default => ['neutral', 'Pendiente'],
    };
@endphp
<x-ui.badge :variant="$variant">{{ $text }}</x-ui.badge>
@if (($showFiscal ?? true) && in_array($f, [F::PendienteTimbrado, F::Timbrada], true))
    <span class="fact-sub">{{ $f->label() }}</span>
@endif
