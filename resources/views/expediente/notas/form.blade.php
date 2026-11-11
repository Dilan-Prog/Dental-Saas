@php
    $patientsUrl = Route::has('pacientes.index') ? route('pacientes.index') : null;
    $crumbs = ['Pacientes' => $patientsUrl, $patient->full_name => route('expediente.show', [$patient->id, 'tab' => 'notas']), 'Nueva nota'];
    $doctorName = $doctors->firstWhere('id', $defaultDoctorId)?->user?->full_name;
@endphp
<x-app-layout title="Nueva nota clínica" :breadcrumbs="$crumbs">
    @push('styles')<link rel="stylesheet" href="{{ asset('css/modules/expediente.css') }}">@endpush
    @push('scripts')<script src="{{ asset('js/modules/expediente.js') }}" defer></script>@endpush

    <div class="stack exp">
        @include('expediente.partials.header')

        <form method="POST" action="{{ route('expediente.notas.store', $patient->id) }}" class="stack note-form" data-no-lock>
            @csrf
            <x-ui.page-header title="Nueva nota clínica"
                :subtitle="'Formato '.($noteType === 'soap' ? 'SOAP' : 'simple').($doctorName ? ' · '.$doctorName : '')">
                <x-slot name="actions">
                    <x-ui.button variant="secondary" :href="route('expediente.show', [$patient->id, 'tab' => 'notas'])">Cerrar</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('expediente.notas.create', [$patient->id, 'tipo' => $noteType === 'soap' ? 'simple' : 'soap'])">
                        Cambiar a {{ $noteType === 'soap' ? 'nota simple' : 'formato SOAP' }}
                    </x-ui.button>
                    <x-ui.button variant="secondary" type="submit" name="action" value="guardar">Guardar borrador</x-ui.button>
                    @if ($canSign)
                        <x-ui.button type="submit" name="action" value="firmar" data-confirm="Al firmar, la nota queda inmutable (sólo se podrán agregar adendas). ¿Firmar ahora?">Firmar nota</x-ui.button>
                    @endif
                </x-slot>
            </x-ui.page-header>

            <x-ui.card>
                @include('expediente.notas._fields', ['creating' => true])
            </x-ui.card>
        </form>
    </div>

    @include('expediente.partials.modal-editar-datos')
</x-app-layout>
