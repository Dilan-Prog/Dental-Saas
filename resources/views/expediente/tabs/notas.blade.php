@php $base = fn (array $q = []) => route('expediente.show', ['patient' => $patient->id, 'tab' => 'notas'] + $q); @endphp
<x-ui.card title="Notas clínicas" :subtitle="$noteList->total().' nota(s)'">
    <x-slot name="actions">
        <div class="segmented">
            <a href="{{ $base() }}" class="{{ ! $statusFilter ? 'is-active' : '' }}">Todas</a>
            <a href="{{ $base(['estado' => 'borrador']) }}" class="{{ $statusFilter === 'borrador' ? 'is-active' : '' }}">Borradores</a>
            <a href="{{ $base(['estado' => 'firmada']) }}" class="{{ $statusFilter === 'firmada' ? 'is-active' : '' }}">Firmadas</a>
        </div>
        @if ($canCreate)
            <x-ui.button variant="secondary" icon="file-text" :href="route('expediente.notas.create', $patient->id)">Nota SOAP</x-ui.button>
            <x-ui.button icon="plus" data-modal-open="nueva-nota">Nueva nota</x-ui.button>
        @endif
    </x-slot>
    <div class="note-list">
        @include('expediente.partials.notes-list', ['notes' => $noteList])
    </div>
    @if ($noteList->hasPages())
        <div class="pagination-wrap">{{ $noteList->links() }}</div>
    @endif
</x-ui.card>

@if ($canCreate)
    @include('expediente.partials.modal-nueva-nota')
@endif
