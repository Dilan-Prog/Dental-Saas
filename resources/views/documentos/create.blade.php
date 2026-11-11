@php
    use App\Services\Expediente\DocumentService;
    $crumbs = ['Documentos' => route('documentos.index'), $meta['title']];
@endphp
<x-app-layout :title="$meta['title']" :breadcrumbs="$crumbs">
    @push('styles')<link rel="stylesheet" href="{{ asset('css/modules/expediente.css') }}">@endpush
    @push('scripts')<script src="{{ asset('js/modules/expediente.js') }}" defer></script>@endpush

    <div class="stack docs">
        <x-ui.page-header :title="$meta['title']"
            :subtitle="$template === 'historia-clinica' ? 'Complete los datos de identificación e historia médica del paciente.' : 'Plan de tratamiento, control y registro de actividades clínicas.'">
            <x-slot name="actions">
                <x-ui.button variant="secondary" :href="route('documentos.index')">Cancelar</x-ui.button>
                <x-ui.button variant="secondary" :href="route('documentos.preview', $template)" target="_blank" icon="printer">Plantilla en blanco</x-ui.button>
                @if ($patient)<x-ui.button type="submit" form="doc-form" icon="check">Guardar documento</x-ui.button>@endif
            </x-slot>
        </x-ui.page-header>

        {{-- Paso 1: elegir paciente (precarga datos conocidos) --}}
        <x-ui.card title="Paciente" subtitle="El documento se guarda en el expediente del paciente seleccionado.">
            <form method="GET" action="{{ route('documentos.create', $template) }}" class="row row--wrap" data-no-lock>
                <div style="min-width:280px;flex:1">
                    <x-ui.select name="patient" id="patient-picker" :options="$patients->mapWithKeys(fn ($p) => [$p->id => $p->folio.' · '.$p->full_name])->all()"
                                 :selected="$patient?->id" placeholder="Selecciona un paciente…" data-autosubmit />
                </div>
                <x-ui.button type="submit" variant="secondary">Cargar paciente</x-ui.button>
            </form>
        </x-ui.card>

        @if ($patient)
            <form id="doc-form" method="POST" action="{{ route('documentos.store', $template) }}" class="stack" novalidate>
                @csrf
                <input type="hidden" name="patient_id" value="{{ $patient->id }}">
                @if ($template === DocumentService::HISTORIA)
                    @include('documentos.forms.historia-clinica')
                @else
                    @include('documentos.forms.autorizacion-tratamiento')
                @endif
                <div class="form-actions no-print">
                    <x-ui.button variant="secondary" :href="route('documentos.index')">Cancelar</x-ui.button>
                    <x-ui.button type="submit" icon="check">Guardar documento</x-ui.button>
                </div>
            </form>
        @else
            <x-ui.empty-state icon="user" title="Selecciona un paciente" text="Elige al paciente para precargar sus datos y generar el documento." />
        @endif
    </div>
</x-app-layout>
