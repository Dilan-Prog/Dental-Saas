@extends('configuracion.layout')

@section('cfg')
    <x-ui.page-header title="Datos fiscales del emisor" subtitle="Información con la que se emiten tus facturas (CFDI 4.0)">
        <x-slot name="actions">
            @if ($secretsConfigured)
                <x-ui.badge variant="success" dot>Timbrado configurado</x-ui.badge>
            @else
                <x-ui.badge variant="warning" dot>Timbrado pendiente</x-ui.badge>
            @endif
        </x-slot>
    </x-ui.page-header>

    <form method="POST" action="{{ route('configuracion.facturacion.update') }}" novalidate>
        @csrf
        @method('PUT')

        <x-ui.card>
            <div class="form-grid">
                <x-ui.field name="legal_name" label="Razón social" :value="$org->legal_name" maxlength="300" placeholder="Clínica Dental Pro S.A. de C.V." />
                <x-ui.field name="rfc" label="RFC" :value="$org->rfc" mask="rfc" maxlength="13" placeholder="12 o 13 caracteres" />

                <x-ui.field name="tax_regime_code" label="Régimen fiscal" as="select" placeholder="Selecciona el régimen"
                    :options="collect($regimenes)->map(fn ($d, $c) => $c.' - '.$d)->all()" :selected="$fiscal?->tax_regime_code" />
                <x-ui.field name="fiscal_postal_code" label="Código postal fiscal" :value="$fiscal?->fiscal_postal_code" mask="postal" maxlength="5" inputmode="numeric" hint="El del domicilio fiscal registrado ante el SAT." />

                <x-ui.field name="pac_provider" label="Proveedor de timbrado" as="select" placeholder="Selecciona un proveedor" :options="$pacProviders" :selected="$fiscal?->pac_provider" />
                <x-ui.field name="default_series" label="Serie" :value="$fiscal?->default_series" mask="upper" maxlength="10" placeholder="Ej. A" hint="Serie que se usa por defecto en tus facturas." />
            </div>

            <div class="cfg-secrets">
                <div class="cfg-secrets__title"><x-ui.icon name="lock" size="sm" /> Certificado de sello digital (CSD) y credenciales del PAC</div>
                <x-ui.alert variant="warning" title="Nunca captures certificados, llaves ni contraseñas aquí.">
                    Los archivos .cer/.key y las contraseñas se guardan en el gestor de secretos de la clínica. En este sistema solo se registra la <strong>referencia</strong> (por ejemplo <code>vault://clinica/csd-cer</code>).
                </x-ui.alert>
                <div class="form-grid">
                    <x-ui.field name="csd_certificate_secret_ref" label="Referencia del certificado (.cer)" :value="$fiscal?->csd_certificate_secret_ref" maxlength="255" autocomplete="off" spellcheck="false" placeholder="vault://clinica/csd-cer" />
                    <x-ui.field name="csd_key_secret_ref" label="Referencia de la llave (.key)" :value="$fiscal?->csd_key_secret_ref" maxlength="255" autocomplete="off" spellcheck="false" placeholder="vault://clinica/csd-key" />
                    <x-ui.field name="pac_credentials_secret_ref" label="Referencia de credenciales del PAC" :value="$fiscal?->pac_credentials_secret_ref" maxlength="255" autocomplete="off" spellcheck="false" placeholder="vault://clinica/pac" class="col-span-2" />
                </div>
            </div>

            <x-slot name="footer">
                <x-ui.button type="submit">Guardar</x-ui.button>
            </x-slot>
        </x-ui.card>
    </form>

    <x-ui.page-header class="mt-6" title="Folios y consecutivos" subtitle="Prefijo y próximo folio de cada documento" />

    <form method="POST" action="{{ route('configuracion.facturacion.folios') }}" novalidate>
        @csrf
        @method('PUT')

        <x-ui.card flush>
            <x-ui.table>
                <x-slot name="head">
                    <tr>
                        <th>Documento</th>
                        <th>Serie</th>
                        <th>Prefijo</th>
                        <th>Último emitido</th>
                        <th>Próximo folio</th>
                    </tr>
                </x-slot>
                @forelse ($sequences as $i => $seq)
                    <tr>
                        <td>
                            {{ $documentLabels[$seq->document_type] ?? $seq->document_type }}
                            <input type="hidden" name="folios[{{ $i }}][id]" value="{{ $seq->id }}">
                        </td>
                        <td>{{ $seq->series !== '' ? $seq->series : '—' }}</td>
                        <td style="min-width:120px">
                            <x-ui.input :name="'folios['.$i.'][prefix]'" :value="$seq->prefix" mask="upper" maxlength="10" aria-label="Prefijo" />
                            @error('folios.'.$i.'.prefix')<div class="field__error">{{ $message }}</div>@enderror
                        </td>
                        <td class="text-muted">{{ $seq->last_value > 0 ? $seq->prefix.str_pad((string) $seq->last_value, 4, '0', STR_PAD_LEFT) : 'Sin emitir' }}</td>
                        <td style="min-width:140px">
                            <x-ui.input :name="'folios['.$i.'][next]'" type="number" :value="$seq->last_value + 1" min="{{ $seq->last_value + 1 }}" step="1" aria-label="Próximo folio" />
                            @error('folios.'.$i.'.next')<div class="field__error">{{ $message }}</div>@enderror
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state title="Sin consecutivos configurados" icon="receipt" /></td></tr>
                @endforelse
            </x-ui.table>
            <x-slot name="footer">
                <span class="text-sm text-muted">El próximo folio solo puede aumentar. Un cambio de prefijo aplica a documentos nuevos.</span>
                <x-ui.button type="submit" variant="secondary">Guardar folios</x-ui.button>
            </x-slot>
        </x-ui.card>
    </form>
@endsection
