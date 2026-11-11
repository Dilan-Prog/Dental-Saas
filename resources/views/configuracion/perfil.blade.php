@extends('configuracion.layout')

@section('cfg')
    <x-ui.page-header title="Mi perfil" />

    <form method="POST" action="{{ route('configuracion.perfil.update') }}" enctype="multipart/form-data" novalidate>
        @csrf
        @method('PUT')

        <x-ui.card>
            <div class="cfg-profile-head">
                @if ($user->avatar_storage_key)
                    <img class="cfg-avatar" src="{{ route('configuracion.perfil.avatar') }}?v={{ $user->updated_at?->timestamp }}" alt="Foto de {{ $user->full_name }}" data-avatar-preview>
                @else
                    <x-ui.avatar :name="$user->full_name" size="lg" data-avatar-fallback />
                    <img class="cfg-avatar" alt="" data-avatar-preview hidden>
                @endif
                <div>
                    <div class="cfg-profile-name">{{ $user->full_name }}</div>
                    <div class="text-sm text-muted">{{ $roleName ?: 'Sin rol asignado' }}</div>
                    <div class="row mt-2">
                        <label class="btn btn--secondary btn--sm" for="avatar">Cambiar foto</label>
                        <input type="file" id="avatar" name="avatar" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" class="sr-only" data-avatar-input>
                        @if ($user->avatar_storage_key)
                            <x-ui.checkbox name="remove_avatar" label="Quitar foto" :checked="false" />
                        @endif
                    </div>
                    @error('avatar')<div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-grid mt-4">
                <x-ui.field name="full_name" label="Nombre" required :value="$user->full_name" maxlength="200" autocomplete="name" />
                <x-ui.field name="email" label="Correo" type="email" required :value="$user->email" maxlength="254" autocomplete="email" />

                @if ($isDoctor)
                    <x-ui.field name="professional_license" label="Cédula profesional" :value="$doctor?->professional_license" mask="digits" maxlength="8" inputmode="numeric" hint="7 u 8 dígitos. Aparece en tus recetas." />
                    <x-ui.field name="specialty" label="Especialidad" :value="$doctor?->specialty" maxlength="120" placeholder="Ej. Endodoncia" />

                    <div class="field col-span-2">
                        <span class="field__label">Firma digital</span>
                        <label class="cfg-signature {{ $errors->has('signature') ? 'has-error' : '' }}" for="signature">
                            @if ($doctor?->signature_image_storage_key)
                                <img src="{{ route('configuracion.perfil.firma') }}?v={{ time() }}" alt="Firma de {{ $user->full_name }}" data-signature-preview>
                            @else
                                <img alt="" data-signature-preview hidden>
                                <span class="cfg-logo__hint" data-signature-hint>Firma de la doctora (PNG)</span>
                            @endif
                        </label>
                        <input type="file" id="signature" name="signature" accept=".png,image/png" class="sr-only" data-signature-input>
                        @error('signature')<div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $message }}</div>@enderror
                        <div class="text-xs text-muted">Imagen PNG de hasta 1 MB. No equivale a una firma electrónica avanzada.</div>
                        @if ($doctor?->signature_image_storage_key)
                            <x-ui.checkbox name="remove_signature" label="Quitar firma actual" :checked="false" />
                        @endif
                    </div>
                @endif
            </div>

            <x-slot name="footer">
                <x-ui.button type="submit">Guardar</x-ui.button>
            </x-slot>
        </x-ui.card>
    </form>

    <x-ui.page-header class="mt-6" title="Cambiar contraseña" subtitle="Usa al menos 8 caracteres con letras y números" />

    <form method="POST" action="{{ route('configuracion.perfil.password') }}" novalidate>
        @csrf
        @method('PUT')

        <x-ui.card>
            <div class="form-grid">
                <x-ui.field name="current_password" bag="password" type="password" label="Contraseña actual" required autocomplete="current-password" class="col-span-2" />
                <x-ui.field name="password" bag="password" type="password" label="Nueva contraseña" required autocomplete="new-password" />
                <x-ui.field name="password_confirmation" bag="password" type="password" label="Confirmar nueva contraseña" required autocomplete="new-password" />
            </div>
            <x-slot name="footer">
                <x-ui.button type="submit" variant="secondary">Actualizar contraseña</x-ui.button>
            </x-slot>
        </x-ui.card>
    </form>
@endsection
