@php
    use App\Services\Expediente\Fmt;
    $patientsUrl = Route::has('pacientes.index') ? route('pacientes.index') : null;
    $crumbs = ['Pacientes' => $patientsUrl, $patient->full_name => route('expediente.show', [$patient->id, 'tab' => 'notas']), 'Nota clínica'];
    $signed = $note->isSigned();
@endphp
<x-app-layout title="Nota clínica" :breadcrumbs="$crumbs">
    @push('styles')<link rel="stylesheet" href="{{ asset('css/modules/expediente.css') }}">@endpush
    @push('scripts')<script src="{{ asset('js/modules/expediente.js') }}" defer></script>@endpush

    <div class="stack exp">
        @include('expediente.partials.header')

        <x-ui.page-header :title="'Nota clínica '.($noteType === 'soap' ? 'SOAP' : 'simple')"
                          :subtitle="Fmt::dateTime($note->created_at).' · '.($note->treatment?->name ?? 'Sin tratamiento').($note->tooth_fdi ? ' · Pieza '.$note->tooth_fdi : '')">
            <x-slot name="actions">
                <x-ui.button variant="secondary" :href="route('expediente.show', [$patient->id, 'tab' => 'notas'])">Volver a notas</x-ui.button>
                @if ($signed)<x-ui.button variant="secondary" icon="printer" onclick="window.print()">Imprimir</x-ui.button>@endif
            </x-slot>
        </x-ui.page-header>

        @if ($signed)
            <x-ui.alert :variant="$integrityOk ? 'success' : 'danger'" :title="$integrityOk ? 'Nota firmada · integridad verificada' : 'Atención: el contenido no coincide con la huella firmada'">
                Firmada por <strong>{{ Fmt::doctor($note->signedBy) }}</strong> el {{ Fmt::dateTime($note->signed_at) }}
                (mecanismo: {{ $note->signature_mechanism }}). Huella SHA-256:
                <span class="mono text-xs" style="word-break:break-all">{{ $note->content_hash }}</span>.
                La nota es inmutable; las correcciones se registran como adendas.
            </x-ui.alert>

            <x-ui.card title="Contenido">
                <dl class="dl">
                    <dt>Doctor responsable</dt><dd>{{ Fmt::doctor($note->responsibleDoctor) }}</dd>
                    <dt>Autor</dt><dd>{{ Fmt::doctor($note->author) }}</dd>
                    @if ($noteType === 'soap')
                        <dt>Subjetivo (S)</dt><dd>{!! nl2br(e($note->subjective ?: '—')) !!}</dd>
                        <dt>Objetivo (O)</dt><dd>{!! nl2br(e($note->objective ?: '—')) !!}</dd>
                        <dt>Análisis (A)</dt><dd>{!! nl2br(e($note->analysis ?: '—')) !!}</dd>
                        <dt>Plan (P)</dt><dd>{!! nl2br(e($note->plan ?: '—')) !!}</dd>
                    @else
                        <dt>Nota</dt><dd>{!! nl2br(e($note->objective ?: '—')) !!}</dd>
                    @endif
                    @if ($note->prescription_text)
                        <dt>Indicaciones</dt><dd>{!! nl2br(e($note->prescription_text)) !!}</dd>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card title="Adendas" subtitle="Correcciones y aclaraciones posteriores a la firma">
                <div class="exp-timeline">
                    @forelse ($note->addenda as $a)
                        <div class="exp-timeline__item">
                            <div class="exp-timeline__title">Versión {{ $a->version_number }} · {{ Fmt::doctor($a->author) }} · {{ Fmt::dateTime($a->created_at) }}</div>
                            <div class="exp-timeline__text"><strong>Motivo:</strong> {{ $a->reason }}</div>
                            <div class="exp-timeline__text">{!! nl2br(e($a->content)) !!}</div>
                            <div class="mono text-xs text-muted" title="{{ $a->content_hash }}">SHA-256 {{ substr($a->content_hash, 0, 16) }}…</div>
                        </div>
                    @empty
                        <div class="text-muted text-sm">Sin adendas.</div>
                    @endforelse
                </div>

                @if ($canAddendum)
                    <form method="POST" action="{{ route('expediente.notas.addendum', [$patient->id, $note->id]) }}" class="stack mt-1" data-no-lock>
                        @csrf
                        <x-ui.field name="reason" label="Motivo de la corrección" required maxlength="500" />
                        <x-ui.field name="content" label="Contenido de la adenda" as="textarea" rows="4" required maxlength="5000" />
                        <div class="form-actions"><x-ui.button type="submit" icon="plus">Agregar adenda</x-ui.button></div>
                    </form>
                @endif
            </x-ui.card>
        @elseif ($editable)
            <form method="POST" action="{{ route('expediente.notas.update', [$patient->id, $note->id]) }}" class="stack note-form" data-no-lock>
                @csrf @method('PUT')
                <x-ui.alert variant="warning" title="Borrador">Esta nota aún no está firmada y se puede editar. Una vez firmada será inmutable.</x-ui.alert>
                <x-ui.card>
                    @include('expediente.notas._fields', ['creating' => false])
                </x-ui.card>
                <div class="row row--between row--wrap">
                    <div>
                        <x-ui.confirm-form :action="route('expediente.notas.destroy', [$patient->id, $note->id])" message="¿Eliminar este borrador? No se puede deshacer." label="Eliminar borrador" icon="trash" variant="danger-outline" size="" />
                    </div>
                    <div class="row">
                        <x-ui.button variant="secondary" type="submit" name="action" value="guardar">Guardar borrador</x-ui.button>
                        @if ($canSign && $note->responsible_doctor_member_id === $memberId)
                            <x-ui.button type="submit" name="action" value="firmar" data-confirm="Al firmar, la nota queda inmutable (sólo adendas). ¿Firmar ahora?">Guardar y firmar</x-ui.button>
                        @endif
                    </div>
                </div>
            </form>
        @else
            <x-ui.alert variant="info">Este borrador sólo lo puede modificar su autor o el doctor responsable.</x-ui.alert>
            <x-ui.card title="Contenido (borrador)">
                <dl class="dl">
                    <dt>Doctor responsable</dt><dd>{{ Fmt::doctor($note->responsibleDoctor) }}</dd>
                    <dt>Contenido</dt><dd>{!! nl2br(e(\App\Services\Expediente\NoteService::summary($note, 3000) ?: '—')) !!}</dd>
                </dl>
            </x-ui.card>
        @endif
    </div>

    @include('expediente.partials.modal-editar-datos')
</x-app-layout>
