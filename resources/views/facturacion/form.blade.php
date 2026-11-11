@php
    use App\Support\Money;

    $isEdit = $invoice !== null;
    $val = fn (string $k, $d = null) => old($k, $values[$k] ?? $d);
    $lines = old('items', $values['items'] ?? [$blankLine]);
    if (! is_array($lines) || ! count($lines)) { $lines = [$blankLine]; }
    $profileSelected = $val('fiscal_profile_id');
    $mode = $val('receiver_mode', 'patient');
    $usos = collect(config('sat.usos_cfdi'))->except(['CP01', 'CN01'])->map(fn ($l, $k) => $k . ' — ' . $l)->all();
    $metodos = collect(config('sat.metodos_pago'))->map(fn ($l, $k) => $k . ' — ' . $l)->all();
    $formas = collect(config('sat.formas_pago'))->map(fn ($l, $k) => $k . ' — ' . $l)->all();
    $unidades = collect(config('sat.unidades'))->map(fn ($l, $k) => $k . ' — ' . $l)->all();
    $objetos = collect(config('sat.objeto_impuesto'))->map(fn ($l, $k) => $k . ' — ' . $l)->all();
    $taxMap = $taxProfiles->mapWithKeys(fn ($t) => [$t->id => ['rate' => (string) $t->rate, 'is_exempt' => (bool) $t->is_exempt, 'name' => $t->name]]);
    $title = $isEdit ? 'Editar ' . $invoice->internal_folio : ($quote ? 'Facturar presupuesto ' . $quote->folio : 'Nueva factura');
@endphp
<x-app-layout :title="$title" :breadcrumbs="['Facturación' => route('facturacion.index'), $title]">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/modules/facturacion.css') }}?v={{ @filemtime(public_path('css/modules/facturacion.css')) }}">
    @endpush

    <x-ui.page-header :title="$title" subtitle="CFDI 4.0 · Se guarda como borrador; al emitir se valida y se solicita el timbrado al PAC">
        <x-slot name="actions">
            <x-ui.button variant="ghost" icon="arrow-left" :href="$isEdit ? route('facturacion.show', $invoice) : route('facturacion.index')">Volver</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert variant="danger" title="Revisa el formulario" class="fact-errors">
            <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </x-ui.alert>
    @endif

    <form method="POST" class="fact-form" id="invoice-form" data-invoice-form
          action="{{ $isEdit ? route('facturacion.update', $invoice) : route('facturacion.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif
        <input type="hidden" name="quote_id" value="{{ $val('quote_id') }}">

        {{-- ================= Receptor ================= --}}
        <x-ui.card title="Receptor" subtitle="Paciente y datos fiscales con los que se factura">
            <div class="fact-form__cols">
                <x-ui.field name="patient_id" label="Paciente" as="select" required placeholder="Selecciona un paciente"
                    :options="$patients->mapWithKeys(fn ($p) => [$p->id => $p->full_name . ($p->folio ? ' · ' . $p->folio : '')])->all()"
                    :selected="$val('patient_id')" />
                <div class="field">
                    <span class="field__label">Facturar a</span>
                    <div class="row row--wrap" style="gap:16px">
                        <label class="radio"><input type="radio" name="receiver_mode" value="patient" @checked($mode === 'patient')> Datos fiscales del paciente</label>
                        <label class="radio"><input type="radio" name="receiver_mode" value="publico_general" @checked($mode === 'publico_general')> Público en general</label>
                    </div>
                    @error('receiver_mode')<div class="field__error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="field__label" for="fiscal_profile_id">Perfil fiscal</label>
                    <select class="select @error('fiscal_profile_id') has-error @enderror" id="fiscal_profile_id" name="fiscal_profile_id" data-selected="{{ $profileSelected }}"></select>
                    @error('fiscal_profile_id')<div class="field__error">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="fact-receiver-box" data-receiver-box>Selecciona un paciente.</div>
        </x-ui.card>

        {{-- ================= Datos del CFDI ================= --}}
        <x-ui.card title="Datos del CFDI">
            <div class="fact-form__cols">
                <x-ui.field name="cfdi_use" label="Uso de CFDI" as="select" required :options="$usos" :selected="$val('cfdi_use', 'D01')" />
                <x-ui.field name="cfdi_payment_method" label="Método de pago" as="select" required :options="$metodos" :selected="$val('cfdi_payment_method', 'PUE')"
                            hint="PUE: pago en una exhibición · PPD: parcialidades (genera complementos de pago)." />
                <x-ui.field name="cfdi_payment_form" label="Forma de pago" as="select" required :options="$formas" :selected="$val('cfdi_payment_form', '04')" />
                <x-ui.field name="branch_id" label="Sucursal" as="select" required
                    :options="$branches->pluck('name', 'id')->all()"
                    :selected="$val('branch_id', optional($branches->firstWhere('is_main', true))->id)" />
                <x-ui.field name="doctor_member_id" label="Doctor / Atención" as="select" placeholder="Sin doctor"
                    :options="$doctors->mapWithKeys(fn ($d) => [$d->id => $d->user?->full_name])->all()"
                    :selected="$val('doctor_member_id')" />
            </div>
        </x-ui.card>

        {{-- ================= Conceptos ================= --}}
        <x-ui.card title="Conceptos" subtitle="Cantidad × precio, descuento por porcentaje e impuestos por concepto">
            <div class="fact-lines" id="fact-lines">
                @foreach ($lines as $i => $line)
                    @include('facturacion._line', ['i' => $i, 'line' => $line, 'unidades' => $unidades, 'objetos' => $objetos, 'taxProfiles' => $taxProfiles])
                @endforeach
            </div>
            @error('items')<div class="field__error" style="margin-top:8px">{{ $message }}</div>@enderror

            <div class="row row--between row--wrap" style="margin-top:14px; align-items:flex-start">
                <x-ui.button variant="secondary" icon="plus" data-add-line>Agregar concepto</x-ui.button>
                <div class="fact-sum">
                    <div><span>Subtotal</span><span data-sum="subtotal">$0.00</span></div>
                    <div><span>Descuento</span><span data-sum="discount">-$0.00</span></div>
                    <div><span>Impuestos trasladados</span><span data-sum="tax">$0.00</span></div>
                    <div class="is-total"><span>Total</span><span data-sum="total">$0.00</span></div>
                    <div class="fact-note" style="display:block;margin-top:4px">Referencia: el cálculo definitivo lo hace el servidor (redondeo por concepto a 2 decimales).</div>
                </div>
            </div>
        </x-ui.card>

        <div class="fact-form__footer">
            <x-ui.button variant="ghost" :href="$isEdit ? route('facturacion.show', $invoice) : route('facturacion.index')">Cancelar</x-ui.button>
            <x-ui.button type="submit" variant="secondary" name="action" value="draft">Guardar borrador</x-ui.button>
            @if (auth()->user()->hasPermission('facturacion', 'crear'))
                <x-ui.button type="submit" name="action" value="emit" icon="send">Guardar y emitir</x-ui.button>
            @endif
        </div>
    </form>

    <template id="fact-line-tpl">
        @include('facturacion._line', ['i' => '__i__', 'line' => $blankLine, 'unidades' => $unidades, 'objetos' => $objetos, 'taxProfiles' => $taxProfiles, 'template' => true])
    </template>

    <script type="application/json" id="fact-data">@json(['profiles' => $profiles, 'taxProfiles' => $taxMap])</script>

    @push('scripts')
        <script src="{{ asset('js/modules/facturacion.js') }}?v={{ @filemtime(public_path('js/modules/facturacion.js')) }}"></script>
    @endpush
</x-app-layout>
