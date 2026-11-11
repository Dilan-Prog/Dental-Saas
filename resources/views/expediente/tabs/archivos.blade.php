@php
    use App\Services\Expediente\Fmt;
    $filters = ['todos' => 'Todos', 'radiografias' => 'Radiografías', 'fotos' => 'Fotos', 'documentos' => 'Documentos'];
    $badge = fn ($f) => match ($f->file_type->value) { 'radiografia' => 'RX', 'fotografia_intraoral' => 'FOTO', default => 'DOC' };
@endphp
<div class="stack">
    <div class="row row--between row--wrap">
        <div class="segmented">
            @foreach ($filters as $key => $label)
                <a href="{{ route('expediente.show', ['patient' => $patient->id, 'tab' => 'archivos', 'tipo' => $key]) }}"
                   class="{{ $fileFilter === $key ? 'is-active' : '' }}">{{ $label }}</a>
            @endforeach
        </div>
        <span class="text-muted text-sm">{{ $fileList->count() }} archivo(s)</span>
    </div>

    @if ($canCreate)
        <div class="dropzone" data-dropzone="subir-archivo" tabindex="0" role="button" aria-label="Subir archivo">
            <div class="dropzone__icon"><x-ui.icon name="upload" size="lg" /></div>
            <div class="dropzone__title">Arrastra archivos aquí o haz clic para subir</div>
            <div class="dropzone__hint">Radiografías, fotos clínicas, consentimientos · JPG, PNG, PDF hasta 20 MB</div>
        </div>
    @endif

    @if ($fileList->isEmpty())
        <x-ui.empty-state icon="image" title="Sin archivos" text="Aún no se han cargado radiografías, fotografías ni documentos para este paciente." />
    @else
        <div class="file-grid">
            @foreach ($fileList as $f)
                @php
                    $isDoc = $fileService->isGeneratedDocument($f);
                    $viewUrl = $isDoc ? route('documentos.show', $f->id) : ($fileUrls[$f->id] ?? null);
                    $typeClass = match ($f->file_type->value) { 'radiografia' => 'rx', 'fotografia_intraoral' => 'foto', default => 'doc' };
                @endphp
                <div class="file-card">
                    <a class="file-card__thumb file-card__thumb--{{ $typeClass }}" href="{{ $viewUrl }}" @if(! $isDoc) target="_blank" rel="noopener" @endif>
                        <span class="file-card__tag">{{ $badge($f) }}</span>
                        @if ($fileService->isImage($f) && ! empty($fileUrls[$f->id]))
                            <img src="{{ $fileUrls[$f->id] }}" alt="{{ $f->display_name }}" loading="lazy">
                        @else
                            <x-ui.icon :name="$f->mime_type === 'application/pdf' || $isDoc ? 'file-text' : 'image'" size="lg" />
                        @endif
                    </a>
                    <div class="file-card__body">
                        <div class="file-card__name" title="{{ $f->display_name }}">{{ $f->display_name }}</div>
                        <div class="file-card__meta">
                            {{ $f->file_type->label() }} · {{ Fmt::date($f->created_at, 'j M') }} · {{ $fileService->extensionLabel($f) }} · {{ Fmt::bytes($f->size_bytes) }}
                        </div>
                        <div class="file-card__meta">{{ Fmt::doctor($f->uploader) }}</div>
                        <div class="file-card__actions">
                            <a class="icon-btn icon-btn--flat" href="{{ $viewUrl }}" @if(! $isDoc) target="_blank" rel="noopener" @endif title="Ver" aria-label="Ver"><x-ui.icon name="eye" /></a>
                            @if (! $isDoc)
                                <a class="icon-btn icon-btn--flat" href="{{ $fileDownloadUrls[$f->id] }}" title="Descargar" aria-label="Descargar"><x-ui.icon name="download" /></a>
                            @endif
                            @if (auth()->user()->hasPermission('expediente', 'desactivar'))
                                <x-ui.confirm-form :action="route('expediente.archivos.destroy', [$patient->id, $f->id])" method="DELETE"
                                                   message="¿Dar de baja este archivo? Dejará de mostrarse en el expediente (se conserva el respaldo)."
                                                   label="Dar de baja" icon="trash" icon-only variant="ghost" />
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@if ($canCreate)
    <x-ui.modal id="subir-archivo" title="Subir archivo" :subtitle="$patient->full_name.' · '.$patient->folio" open-on-error
                :action="route('expediente.archivos.store', $patient->id)" enctype="multipart/form-data">
        <div class="stack">
            <x-ui.field label="Archivo" name="file" required hint="JPG, PNG o PDF · máximo 20 MB.">
                <input type="file" id="file" name="file" class="input @error('file') has-error @enderror" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required data-file-input>
            </x-ui.field>
            <x-ui.field name="file_type" label="Tipo de archivo" as="select" required :options="$fileTypes" selected="radiografia" />
            <x-ui.field name="display_name" label="Nombre" maxlength="250" placeholder="Si lo dejas vacío se usa el nombre del archivo" />
            @if ($encounterOptions->isNotEmpty())
                <x-ui.field name="encounter_id" label="Consulta relacionada" as="select" placeholder="Sin consulta"
                            :options="$encounterOptions->mapWithKeys(fn ($e) => [$e->id => Fmt::dateTime($e->occurred_at)])->all()" />
            @endif
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit" icon="upload">Subir</x-ui.button>
        </x-slot>
    </x-ui.modal>
@endif
