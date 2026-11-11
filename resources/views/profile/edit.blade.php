<x-app-layout title="Mi perfil" :breadcrumbs="['Configuración', 'Mi perfil']">
    <x-ui.page-header title="Mi perfil" subtitle="Administra tus datos de acceso y seguridad." />

    <div class="stack content--narrow">
        <x-ui.card title="Información del perfil" subtitle="Actualiza tu nombre y correo electrónico.">
            @include('profile.partials.update-profile-information-form')
        </x-ui.card>

        <x-ui.card title="Cambiar contraseña" subtitle="Usa una contraseña larga y única.">
            @include('profile.partials.update-password-form')
        </x-ui.card>

        <x-ui.card title="Dar de baja mi cuenta" subtitle="Tu usuario se desactiva; los registros clínicos y fiscales se conservan.">
            @include('profile.partials.delete-user-form')
        </x-ui.card>
    </div>
</x-app-layout>
