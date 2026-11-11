@php
    use App\Services\Expediente\Fmt;
    $patientsUrl = Route::has('pacientes.index') ? route('pacientes.index') : null;
    $patientUrl = Route::has('pacientes.show') ? route('pacientes.show', $patient->id) : null;
    $crumbs = ['Pacientes' => $patientsUrl, $patient->full_name => $patientUrl, 'Expediente clínico'];
    $tabItems = [];
    foreach ($tabs as $key => $label) {
        $tabItems[$key] = [
            'label' => $label,
            'href' => route('expediente.show', ['patient' => $patient->id, 'tab' => $key]),
            'count' => match ($key) {
                'notas' => $counts['notas'] ?: null,
                'archivos' => $counts['archivos'] ?: null,
                'tratamientos' => $counts['tratamientos'] ?: null,
                'consentimientos' => $counts['consentimientos'] ?: null,
                default => null,
            },
        ];
    }
@endphp
<x-app-layout title="Expediente clínico" :breadcrumbs="$crumbs">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/modules/expediente.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/modules/expediente.js') }}" defer></script>
    @endpush

    <div class="stack exp">
        @include('expediente.partials.header')

        @if ($record->allergies)
            <x-ui.alert variant="warning" title="Alergias registradas">{{ $record->allergies }}</x-ui.alert>
        @endif

        <x-ui.tabs :items="$tabItems" :active="$tab" />

        @include('expediente.tabs.'.$tab)
    </div>

    @include('expediente.partials.modal-editar-datos')
</x-app-layout>
