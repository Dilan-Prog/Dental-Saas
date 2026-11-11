@php use App\Services\Expediente\Fmt; @endphp
<x-ui.card title="Consentimientos informados" subtitle="Un documento adjunto no equivale a aceptación: aquí se registra quién, cuándo y cómo aceptó." flush>
    <x-slot name="actions">
        @if ($canCreate)<x-ui.button icon="plus" data-modal-open="nuevo-consentimiento">Registrar aceptación</x-ui.button>@endif
        @if (Route::has('documentos.create') && auth()->user()->hasPermission('documentos', 'crear'))
            <x-ui.button variant="secondary" icon="file-text" :href="route('documentos.create', ['template' => 'autorizacion-tratamiento', 'patient' => $patient->id])">Generar autorización</x-ui.button>
        @endif
    </x-slot>

    <x-ui.table>
        <x-slot name="head">
            <tr><th>Aceptó</th><th>Fecha</th><th>Método</th><th>Documento</th><th>Testigo</th><th>Huella</th></tr>
        </x-slot>
        @forelse ($consents as $c)
            <tr>
                <td class="fw-500">{{ $c->accepted_by_name }}</td>
                <td>{{ Fmt::dateTime($c->accepted_at) }}</td>
                <td>{{ $methods[$c->method] ?? $c->method }}</td>
                <td>
                    @if ($c->clinicalFile)
                        @if ($c->clinicalFile->mime_type === 'application/json')
                            <a href="{{ route('documentos.show', $c->clinicalFile->id) }}">{{ $c->clinicalFile->display_name }}</a>
                        @else
                            <a href="{{ route('expediente.show', ['patient' => $patient->id, 'tab' => 'archivos']) }}">{{ $c->clinicalFile->display_name }}</a>
                        @endif
                    @else
                        <span class="text-muted">Sin documento</span>
                    @endif
                </td>
                <td>{{ Fmt::doctor($c->witness) }}</td>
                <td class="mono text-xs" title="{{ $c->content_hash }}">{{ $c->content_hash ? substr($c->content_hash, 0, 10).'…' : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6"><x-ui.empty-state icon="shield" title="Sin consentimientos" text="Registra la aceptación del paciente o de su tutor antes de iniciar tratamientos." /></td></tr>
        @endforelse
    </x-ui.table>
</x-ui.card>

@if ($canCreate)
    <x-ui.modal id="nuevo-consentimiento" title="Registrar aceptación" :subtitle="$patient->full_name.' · '.$patient->folio" open-on-error
                :action="route('expediente.consentimientos.store', $patient->id)">
        <div class="stack">
            <x-ui.field name="accepted_by_name" label="Quién acepta (paciente o tutor)" required maxlength="250" list="accept-names" :value="old('accepted_by_name', $patient->full_name)" />
            <datalist id="accept-names">
                <option value="{{ $patient->full_name }}">
                @foreach ($guardians as $g)<option value="{{ $g->full_name }}">@endforeach
            </datalist>
            <x-ui.field name="method" label="Método" as="select" required :options="$methods" selected="verbal_registrada" />
            <x-ui.field name="accepted_at" label="Fecha y hora" type="datetime-local" max="{{ now()->format('Y-m-d\TH:i') }}" :value="now()->format('Y-m-d\TH:i')" />
            <x-ui.field name="clinical_file_id" label="Documento adjunto" as="select" placeholder="Sin documento"
                        :options="$consentFiles->mapWithKeys(fn ($f) => [$f->id => $f->display_name.' · '.Fmt::date($f->created_at)])->all()"
                        hint="Sube primero el escaneo en Archivos (tipo Consentimiento). Obligatorio para firma autógrafa escaneada." />
            <x-ui.field name="witness_member_id" label="Testigo (personal)" as="select" placeholder="Sin testigo"
                        :options="$members->mapWithKeys(fn ($m) => [$m->id => $m->user?->full_name])->all()" />
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Registrar</x-ui.button>
        </x-slot>
    </x-ui.modal>
@endif
