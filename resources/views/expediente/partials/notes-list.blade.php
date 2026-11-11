{{-- Lista de notas. Requiere: $patient, $notes (colección de ClinicalNote con responsibleDoctor.user). --}}
@php use App\Services\Expediente\Fmt; use App\Services\Expediente\NoteService; @endphp
@forelse ($notes as $n)
    <a href="{{ route('expediente.notas.show', [$patient->id, $n->id]) }}" class="note-item">
        <div class="note-item__meta">
            <span>{{ Fmt::date($n->signed_at ?? $n->created_at) }} · {{ $n->responsibleDoctor?->user?->full_name ?? '—' }}</span>
            <span class="note-item__badges">
                @if ($n->note_type->value === 'soap')<x-ui.badge variant="info">SOAP</x-ui.badge>@endif
                @if ($n->isSigned())
                    <x-ui.badge variant="success" dot>Firmada</x-ui.badge>
                @else
                    <x-ui.badge variant="warning" dot>Borrador</x-ui.badge>
                @endif
                @if (($n->addenda_count ?? 0) > 0)<x-ui.badge variant="neutral">{{ $n->addenda_count }} adenda(s)</x-ui.badge>@endif
            </span>
        </div>
        <div class="note-item__text">{{ NoteService::summary($n) ?: 'Sin contenido' }}</div>
        @if ($n->tooth_fdi || $n->treatment)
            <div class="note-item__tags text-xs text-muted">
                @if ($n->treatment){{ $n->treatment->name }}@endif
                @if ($n->tooth_fdi) · Pieza {{ $n->tooth_fdi }} @endif
            </div>
        @endif
    </a>
@empty
    <x-ui.empty-state icon="file-text" title="Aún no hay notas clínicas" text="Las notas firmadas quedan como registro inmutable del expediente." />
@endforelse
