@extends('layouts.app')

@section('title', 'Editar usuario')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/usuarios.css') }}?v={{ @filemtime(public_path('css/modules/usuarios.css')) }}">
@endpush

@section('content')
@php
    $can = fn ($action) => auth()->user()->hasPermission('usuarios', $action);
    $isDoctor = (bool) old('is_doctor', $member->doctorProfile !== null);
    $profile = $member->doctorProfile;
    $selectedBranches = (array) old('branch_ids', $member->branches->pluck('id')->all());
    $roleOptions = $roles->pluck('name', 'id')->all();
    if ($member->role && ! isset($roleOptions[$member->role_id])) {
        $roleOptions = [$member->role_id => $member->role->name.' (inactivo)'] + $roleOptions;
    }
@endphp

<div class="usr-layout">
    <aside>@include('usuarios.partials.submenu')</aside>

    <div class="usr-main">
        <a href="{{ route('usuarios.index') }}" class="usr-back"><x-ui.icon name="arrow-left" size="sm" /> Usuarios</a>

        <x-ui.page-header :title="$member->user->full_name" :subtitle="($member->role?->name ?? 'Sin rol').' · '.$member->user->email">
            <x-slot name="actions">
                @if ($status === 'activo')
                    <x-ui.badge variant="success" dot>Activo</x-ui.badge>
                @elseif ($status === 'pendiente')
                    <x-ui.badge variant="warning" dot>Invitación pendiente</x-ui.badge>
                @else
                    <x-ui.badge variant="neutral" dot>Inactivo</x-ui.badge>
                @endif
            </x-slot>
        </x-ui.page-header>

        @include('usuarios.partials.access-link')

        <x-ui.card title="Datos del usuario" subtitle="Nombre, correo, rol y sucursales">
            <form method="POST" action="{{ route('usuarios.update', $member) }}" enctype="multipart/form-data" id="user-form" class="stack">
                @csrf
                @method('PUT')

                <div class="form-grid">
                    <x-ui.field name="full_name" label="Nombre completo" :value="$member->user->full_name" required maxlength="200" autocomplete="off" />
                    <x-ui.field name="email" type="email" label="Correo electrónico" :value="$member->user->email" required maxlength="254" autocomplete="off" />
                    <x-ui.field name="role_id" label="Rol" as="select" :options="$roleOptions" :selected="$member->role_id" placeholder="Selecciona un rol" required
                                :hint="$isSelf ? 'No puedes cambiar tu propio rol.' : null" />
                </div>

                <div class="field">
                    <label class="field__label">Sucursales<span class="req" aria-hidden="true">*</span></label>
                    <div class="usr-checklist">
                        @foreach ($branches as $b)
                            <label class="check">
                                <input type="checkbox" name="branch_ids[]" value="{{ $b->id }}" @checked(in_array($b->id, $selectedBranches))>
                                <span>{{ $b->name }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if ($errors->has('branch_ids') || $errors->has('branch_ids.*'))
                        <div class="field__error has-error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $errors->first('branch_ids') ?: $errors->first('branch_ids.*') }}</div>
                    @endif
                </div>

                <div class="usr-doctor" data-doctor-block>
                    <x-ui.switch name="is_doctor" label="Es profesional de la salud (aparece como doctor en la agenda)" :checked="$isDoctor" data-doctor-toggle />

                    <div data-doctor-fields @if (! $isDoctor) hidden @endif class="mt-4 stack">
                        <div class="form-grid">
                            <x-ui.field name="professional_license" label="Cédula profesional" :value="$profile?->professional_license" mask="digits" maxlength="8" inputmode="numeric" hint="7 u 8 dígitos. Aparece en las recetas que emita." />
                            <x-ui.field name="specialty" label="Especialidad" :value="$profile?->specialty" maxlength="120" />
                        </div>

                        <div class="field">
                            <label class="field__label" for="signature">Imagen de firma</label>
                            <div class="usr-signature">
                                @if ($profile?->signature_image_storage_key)
                                    <img src="{{ route('usuarios.firma', $member) }}?v={{ md5($profile->signature_image_storage_key) }}" alt="Firma actual de {{ $member->user->full_name }}" class="usr-signature__img" data-signature-preview>
                                @else
                                    <img alt="" class="usr-signature__img" data-signature-preview hidden>
                                @endif
                                <div class="stack stack--sm">
                                    <input type="file" id="signature" name="signature" accept="image/png,image/jpeg" class="input @error('signature') has-error @enderror" data-signature-input>
                                    @error('signature')<div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $message }}</div>@enderror
                                    @if ($profile?->signature_image_storage_key)
                                        <x-ui.checkbox name="remove_signature" label="Quitar la imagen actual" />
                                    @endif
                                </div>
                            </div>
                            <div class="field__hint">PNG o JPG, máx. 1 MB y 2000 × 1000 px. <strong>Es solo una imagen para el documento: no equivale a una firma electrónica legal.</strong></div>
                        </div>
                    </div>
                </div>
            </form>

            <x-slot name="footer">
                <x-ui.button variant="secondary" :href="route('usuarios.index')">Cancelar</x-ui.button>
                @if ($can('editar'))
                    <x-ui.button type="submit" form="user-form">Guardar cambios</x-ui.button>
                @endif
            </x-slot>
        </x-ui.card>

        <x-ui.card title="Acceso" subtitle="Estado de la cuenta e inicio de sesión" class="mt-6">
            <div class="stack">
                @if ($pending)
                    <x-ui.alert variant="warning" title="Invitación pendiente">
                        Vence {{ $pending->expires_at->timezone(config('app.timezone'))->translatedFormat('d \d\e F \d\e Y, H:i') }}. El usuario podrá entrar cuando defina su contraseña con el enlace.
                    </x-ui.alert>
                @endif

                <div class="row row--wrap">
                    @if ($can('editar') && ! $isSelf)
                        <form method="POST" action="{{ route('usuarios.acceso', $member) }}" data-confirm="Se generará un nuevo enlace y el anterior dejará de funcionar. El usuario deberá definir una contraseña nueva con él. ¿Continuar?" data-confirm-title="Generar enlace de acceso" data-confirm-text="Generar enlace" data-confirm-variant="primary">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" icon="refresh">{{ $pending ? 'Reenviar invitación (nuevo enlace)' : 'Restablecer acceso (nuevo enlace)' }}</x-ui.button>
                        </form>
                    @endif

                    @if ($pending && $can('editar'))
                        <x-ui.confirm-form :action="route('usuarios.invitacion.revocar', $member)" method="DELETE" label="Revocar invitación" message="La invitación dejará de funcionar. ¿Revocarla?" title="Revocar invitación" icon="x-circle" variant="danger-outline" size="" />
                    @endif

                    @if ($can('desactivar') && ! $isSelf && $status !== 'pendiente')
                        @if ($status === 'activo')
                            <form method="POST" action="{{ route('usuarios.estado', $member) }}" data-confirm="{{ $member->user->full_name }} no podrá iniciar sesión hasta que lo reactives. Su historial de citas y expedientes se conserva." data-confirm-title="¿Desactivar al usuario?" data-confirm-text="Desactivar" data-confirm-variant="danger">
                                @csrf
                                <input type="hidden" name="active" value="0">
                                <x-ui.button type="submit" variant="danger">Desactivar usuario</x-ui.button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('usuarios.estado', $member) }}">
                                @csrf
                                <input type="hidden" name="active" value="1">
                                <x-ui.button type="submit">Activar usuario</x-ui.button>
                            </form>
                        @endif
                    @endif
                </div>

                @if ($isSelf)
                    <p class="text-muted text-sm">Estás editando tu propia cuenta: no puedes desactivarte ni cambiar tu rol. Tu contraseña se cambia desde tu perfil.</p>
                @endif
            </div>
        </x-ui.card>
    </div>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/usuarios.js') }}?v={{ @filemtime(public_path('js/modules/usuarios.js')) }}"></script>
@endpush
