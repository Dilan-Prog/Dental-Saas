@extends('configuracion.layout')

@section('cfg')
    <x-ui.page-header title="Datos de la clínica" />

    <form method="POST" action="{{ route('configuracion.clinica.update') }}" enctype="multipart/form-data" novalidate>
        @csrf
        @method('PUT')

        <x-ui.card>
            <div class="cfg-form">
                <div class="cfg-logo-col">
                    <span class="field__label">Logotipo</span>
                    <label class="cfg-logo {{ $errors->has('logo') ? 'has-error' : '' }}" for="logo" data-logo-drop>
                        @if ($org->logo_storage_key)
                            <img src="{{ route('configuracion.clinica.logo') }}?v={{ $org->updated_at?->timestamp }}" alt="Logotipo de {{ $org->commercial_name }}" data-logo-preview>
                        @else
                            <img alt="" data-logo-preview hidden>
                            <span class="cfg-logo__hint" data-logo-hint>PNG o SVG, fondo transparente</span>
                        @endif
                    </label>
                    <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg,image/png,image/jpeg,image/webp,image/svg+xml" class="sr-only" data-logo-input>
                    @error('logo')<div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $message }}</div>@enderror
                    <div class="field__hint text-xs text-muted">Máximo 2 MB. Se muestra en recetas y documentos.</div>
                    @if ($org->logo_storage_key)
                        <x-ui.checkbox name="remove_logo" label="Quitar logotipo actual" :checked="false" />
                    @endif
                </div>

                <div class="cfg-fields form-grid">
                    <x-ui.field name="commercial_name" label="Nombre comercial" required :value="$org->commercial_name" maxlength="200" />
                    <x-ui.field name="rfc" label="RFC" :value="$org->rfc" mask="rfc" maxlength="13" placeholder="Ej. CUS200115AB3" hint="Opcional aquí; se usa para facturar." />
                    <x-ui.field name="phone" label="Teléfono" :value="$phoneFormatted" mask="phone" placeholder="Ej. 55 1234 5678" inputmode="tel" />
                    <x-ui.field name="timezone" label="Zona horaria" as="select" required :options="$timezones" :selected="$org->timezone" />
                    <x-ui.field name="currency" label="Moneda" as="select" required :options="$currencies" :selected="$org->currency" />
                    <x-ui.field name="email" label="Correo de contacto" type="email" :value="$org->email" maxlength="254" placeholder="contacto@clinica.mx" />
                    <x-ui.field name="address_line" label="Dirección" :value="$org->address_line" maxlength="300" class="col-span-2" placeholder="Calle, número, colonia, ciudad" />
                    <x-ui.field name="address_postal_code" label="Código postal" :value="$org->address_postal_code" mask="postal" maxlength="5" inputmode="numeric" />
                </div>
            </div>

            <x-slot name="footer">
                <x-ui.button type="submit">Guardar</x-ui.button>
            </x-slot>
        </x-ui.card>
    </form>
@endsection
