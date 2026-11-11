@php use App\Services\Expediente\Fmt; @endphp
<x-app-layout title="Documentos" :breadcrumbs="['Documentos' => null, 'Generar nuevo']">
    @push('styles')<link rel="stylesheet" href="{{ asset('css/modules/expediente.css') }}">@endpush
    @push('scripts')<script src="{{ asset('js/modules/expediente.js') }}" defer></script>@endpush

    <div class="stack docs">
        <x-ui.page-header title="Generación de Documentos" subtitle="Selecciona una plantilla para iniciar el registro o autorización del paciente." />

        <div class="grid grid-2 template-grid">
            @foreach ($templates as $key => $t)
                <x-ui.card class="template-card">
                    <div class="template-card__icon"><x-ui.icon :name="$t['icon']" size="lg" /></div>
                    <h2 class="template-card__title">{{ $t['title'] }}</h2>
                    <p class="template-card__text">{{ $t['description'] }}</p>
                    <x-slot name="footer">
                        <div class="row row--between">
                            <a href="{{ route('documentos.preview', $key) }}" target="_blank" rel="noopener" class="text-muted">Vista previa</a>
                            @if ($canCreate)
                                <x-ui.button :href="route('documentos.create', $key)">Crear documento <x-ui.icon name="chevron-right" size="sm" /></x-ui.button>
                            @endif
                        </div>
                    </x-slot>
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.card title="Documentos generados" subtitle="Quedan guardados en el expediente de cada paciente" flush>
            <x-ui.table>
                <x-slot name="head">
                    <tr><th>Documento</th><th>Paciente</th><th>Fecha</th><th>Creado por</th><th class="actions"></th></tr>
                </x-slot>
                @forelse ($generated as $f)
                    <tr>
                        <td class="fw-500">{{ $f->display_name }}</td>
                        <td>
                            @if ($f->patient)
                                <a href="{{ route('expediente.show', $f->patient->id) }}">{{ $f->patient->full_name }}</a>
                                <span class="text-muted text-xs mono">{{ $f->patient->folio }}</span>
                            @else — @endif
                        </td>
                        <td>{{ Fmt::dateTime($f->created_at) }}</td>
                        <td>{{ Fmt::doctor($f->uploader) }}</td>
                        <td class="actions">
                            <x-ui.button size="sm" variant="secondary" icon="eye" :href="route('documentos.show', $f->id)">Ver / imprimir</x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state icon="file-text" title="Aún no hay documentos generados" text="Crea una historia clínica o una autorización de tratamiento." /></td></tr>
                @endforelse
            </x-ui.table>
            @if ($generated->hasPages())<div class="pagination-wrap">{{ $generated->links() }}</div>@endif
        </x-ui.card>
    </div>
</x-app-layout>
