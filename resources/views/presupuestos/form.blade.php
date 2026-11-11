@php
    $editing = $quote->exists;
    $title = $editing ? 'Editar presupuesto '.$quote->folio : 'Nuevo presupuesto';
    $itemErrors = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'items'))->flatten()->unique()->values();
@endphp
<x-app-layout :title="$title" :breadcrumbs="['Presupuestos' => route('presupuestos.index'), $editing ? $quote->folio : 'Nuevo']">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/modules/presupuestos.css') }}">
    @endpush

    <x-ui.page-header :title="$title"
        :subtitle="$editing ? 'Borrador · '.($quote->patient?->full_name ?? 'Sin paciente') : 'Selecciona al paciente y agrega los conceptos del plan de tratamiento'">
        <x-slot name="actions">
            @if ($editing)
                <x-ui.button variant="secondary" :href="route('presupuestos.print', $quote)" target="_blank" rel="noopener">Vista de impresión</x-ui.button>
            @endif
            <x-ui.button variant="secondary" :href="$editing ? route('presupuestos.show', $quote) : route('presupuestos.index')">Volver</x-ui.button>
            <x-ui.button type="submit" form="quote-form">Guardar</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <form id="quote-form" method="POST" novalidate
          action="{{ $editing ? route('presupuestos.update', $quote) : route('presupuestos.store') }}"
          data-treatments='@json($treatmentData)' data-patient-search="{{ route('presupuestos.patients') }}">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-ui.card title="Datos generales" class="mb-2">
            <div class="form-grid">
                <div class="field @error('patient_id') has-error @enderror">
                    <label class="field__label" for="patient-search">Paciente<span class="req" aria-hidden="true">*</span></label>
                    <input type="hidden" name="patient_id" id="patient_id" value="{{ old('patient_id', $quote->patient_id) }}">
                    <div class="patient-picker" data-patient-picker>
                        <div class="input-wrap">
                            <x-ui.icon name="search" size="sm" />
                            <input type="text" id="patient-search" class="input @error('patient_id') has-error @enderror" autocomplete="off"
                                   placeholder="Busca por nombre, folio o teléfono…" maxlength="100"
                                   value="{{ $patient ? $patient->full_name.' · '.$patient->folio : '' }}">
                        </div>
                        <ul class="patient-picker__results" data-patient-results hidden></ul>
                    </div>
                    @error('patient_id')<div class="field__error">{{ $message }}</div>@enderror
                </div>

                <x-ui.field label="Doctor responsable" name="doctor_member_id" as="select" required
                            :options="$doctors" :selected="$quote->doctor_member_id" placeholder="Selecciona al doctor" />

                <x-ui.field label="Sucursal" name="branch_id" as="select" required
                            :options="$branches" :selected="$quote->branch_id" placeholder="Selecciona la sucursal" />

                <x-ui.field label="Fecha del presupuesto" name="quote_date" type="date" required
                            :value="optional($quote->quote_date)->toDateString() ?? now()->toDateString()" />
            </div>
        </x-ui.card>

        <x-ui.card title="Conceptos" flush>
            @if ($itemErrors->isNotEmpty())
                <div class="form-error-summary" role="alert">
                    <ul>@foreach ($itemErrors as $message)<li>{{ $message }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="table-wrap">
                <table class="table quote-table">
                    <thead>
                        <tr>
                            <th>Tratamiento</th><th>Diente</th><th>Cant.</th><th>Precio</th><th>Desc. %</th><th>Importe</th><th class="actions"></th>
                        </tr>
                    </thead>
                    <tbody id="quote-lines">
                        @foreach ($rows as $i => $row)
                            @include('presupuestos._line', ['i' => $i, 'row' => $row, 'treatmentOptions' => $treatmentOptions])
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="quote-lines-foot">
                <x-ui.button variant="secondary" icon="plus" id="add-line">Agregar concepto</x-ui.button>
                <span class="text-sm text-muted">El precio se toma del catálogo y puede ajustarse por concepto. Los importes se recalculan en el servidor al guardar.</span>
            </div>
        </x-ui.card>

        <div class="quote-totals-wrap">
            <div class="quote-totals card" aria-live="polite">
                <div class="quote-totals__row"><span>Subtotal</span><span class="mono" id="total-subtotal">$0.00</span></div>
                <div class="quote-totals__row" id="row-discount" hidden><span>Descuento</span><span class="mono" id="total-discount">-$0.00</span></div>
                <div id="total-taxes"></div>
                <div class="quote-totals__row quote-totals__row--total"><span>Total</span><span class="mono" id="total-total">$0.00</span></div>
            </div>
        </div>
    </form>

    <template id="line-template">
        @include('presupuestos._line', ['i' => '__INDEX__', 'row' => ['quantity' => '1', 'discount_pct' => '0'], 'treatmentOptions' => $treatmentOptions])
    </template>

    @push('scripts')
        <script src="{{ asset('js/modules/presupuestos.js') }}"></script>
    @endpush
</x-app-layout>
