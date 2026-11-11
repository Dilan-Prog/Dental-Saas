@php use App\Support\Money; @endphp
<x-app-layout title="Registrar pago" :breadcrumbs="['Facturación' => route('facturacion.index'), 'Registrar pago']">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/modules/facturacion.css') }}?v={{ @filemtime(public_path('css/modules/facturacion.css')) }}">
    @endpush

    <x-ui.page-header title="Registrar pago" subtitle="Reparte un pago entre una o varias facturas con saldo del mismo paciente">
        <x-slot name="actions">
            <x-ui.button variant="ghost" icon="arrow-left" :href="route('facturacion.index')">Volver</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <x-ui.card title="Paciente">
        <form method="GET" action="{{ route('pagos.create') }}" class="row row--wrap" style="align-items:flex-end;gap:12px" data-no-lock>
            <div class="field flex-1" style="min-width:260px;margin:0">
                <label class="field__label" for="patient_pick">Paciente</label>
                <select class="select" id="patient_pick" name="patient" onchange="this.form.submit()">
                    <option value="">Selecciona un paciente…</option>
                    @foreach ($patients as $p)<option value="{{ $p->id }}" @selected($patient?->id === $p->id)>{{ $p->full_name }}@if($p->folio) · {{ $p->folio }}@endif</option>@endforeach
                </select>
            </div>
            <x-ui.button type="submit" variant="secondary">Ver facturas con saldo</x-ui.button>
        </form>
    </x-ui.card>

    @if ($patient)
        <form method="POST" action="{{ route('pagos.storeMulti') }}" class="mt-1" data-multi-payment>
            @csrf
            <input type="hidden" name="patient_id" value="{{ $patient->id }}">
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <x-ui.card flush title="Facturas con saldo" :subtitle="$patient->full_name">
                <x-ui.table class="fact-pay-table">
                    <x-slot name="head">
                        <tr><th>Folio</th><th>Fecha</th><th class="text-right">Total</th><th class="text-right">Saldo</th><th class="text-right">Monto a pagar</th><th></th></tr>
                    </x-slot>
                    @forelse ($invoices as $inv)
                        <tr>
                            <td><a class="fact-folio" href="{{ route('facturacion.show', $inv) }}">{{ $inv->internal_folio }}</a><span class="fact-sub">{{ $inv->cfdi_payment_method->value }}</span></td>
                            <td class="nowrap">{{ $inv->created_at->translatedFormat('j M Y') }}</td>
                            <td class="text-right mono">{{ Money::format($inv->total) }}</td>
                            <td class="text-right mono fw-500">{{ Money::format($inv->balance_due) }}</td>
                            <td class="text-right">
                                <input class="input @error('applications.' . $inv->id) has-error @enderror" name="applications[{{ $inv->id }}]" data-app data-mask="money" inputmode="decimal"
                                       data-max="{{ $inv->balance_due }}" value="{{ old('applications.' . $inv->id) }}" placeholder="0.00">
                                @error('applications.' . $inv->id)<div class="field__error">{{ $message }}</div>@enderror
                            </td>
                            <td class="text-right"><x-ui.button variant="ghost" size="sm" data-fill>Saldo</x-ui.button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state icon="receipt" title="Sin facturas con saldo" text="Este paciente no tiene facturas pendientes de cobro." /></td></tr>
                    @endforelse
                </x-ui.table>
                @error('applications')<div class="field__error" style="padding:0 20px 12px">{{ $message }}</div>@enderror
            </x-ui.card>

            @if ($invoices->isNotEmpty())
                <x-ui.card class="mt-1" title="Datos del pago">
                    <div class="fact-form__cols">
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
                            @error('method')<div class="field__error">{{ $message }}</div>@enderror
                        </div>
                        <div data-ref-field><x-ui.field name="reference" label="Referencia" maxlength="120" hint="Obligatoria en transferencias; debe ser única." /></div>
                        <x-ui.field name="paid_at" type="date" label="Fecha del pago" :value="now()->toDateString()" max="{{ now()->toDateString() }}" />
                    </div>
                    <div class="row row--between" style="margin-top:16px">
                        <div><span class="text-muted text-sm">Total del pago</span><div class="fact-pay-total" data-multi-total>$0.00</div></div>
                        <x-ui.button type="submit" icon="check">Registrar pago</x-ui.button>
                    </div>
                </x-ui.card>
            @endif
        </form>
    @endif

    @push('scripts')
        <script src="{{ asset('js/modules/facturacion.js') }}?v={{ @filemtime(public_path('js/modules/facturacion.js')) }}"></script>
    @endpush
</x-app-layout>
