@php
    use App\Services\Expediente\DocumentService;
    use App\Services\Expediente\Fmt;
    $isHistoria = $template === DocumentService::HISTORIA;
    $v = fn (string $k, $fallback = null) => $data[$k] ?? $fallback;
    $line = fn ($value) => filled($value) ? e($value) : '<span class="blank-line"></span>';
    $sexes = App\Enums\SexType::options();
    $clinic = \App\Models\Organization::current();
    $crumbs = ['Documentos' => route('documentos.index'), $blank ? 'Vista previa' : $meta['short']];
    $mark = fn (bool $on) => $on ? '☒' : '☐';
@endphp
<x-app-layout :title="$meta['title']" :breadcrumbs="$crumbs">
    @push('styles')<link rel="stylesheet" href="{{ asset('css/modules/expediente.css') }}">@endpush
    @push('scripts')<script src="{{ asset('js/modules/expediente.js') }}" defer></script>@endpush

    <div class="stack docs">
        <div class="row row--between row--wrap no-print">
            <div>
                <h1 class="page-header__title">{{ $meta['title'] }}</h1>
                <p class="page-header__subtitle">{{ $blank ? 'Plantilla en blanco lista para imprimir.' : 'Documento guardado en el expediente · '.Fmt::dateTime($payload['created_at'] ?? null) }}</p>
            </div>
            <div class="row">
                <x-ui.button variant="secondary" :href="route('documentos.index')">Volver</x-ui.button>
                @if (! $blank && $patientInfo)
                    <x-ui.button variant="secondary" :href="route('expediente.show', $patientInfo['id'])">Ir al expediente</x-ui.button>
                @endif
                <x-ui.button icon="printer" onclick="window.print()">Imprimir</x-ui.button>
            </div>
        </div>

        @if (! $blank)
            <x-ui.alert class="no-print" :variant="($integrityOk ?? false) ? 'success' : 'danger'"
                        :title="($integrityOk ?? false) ? 'Integridad verificada' : 'La huella del documento no coincide'">
                Huella SHA-256: <span class="mono text-xs" style="word-break:break-all">{{ $file?->sha256 }}</span>
            </x-ui.alert>
        @endif

        <article class="paper">
            <header class="paper__head">
                <div>
                    <div class="paper__clinic">{{ $clinic?->commercial_name ?? 'Clínica Dental' }}</div>
                    <div class="text-muted text-sm">{{ $clinic?->address_line }} {{ $clinic?->phone ? '· Tel. '.$clinic->phone : '' }}</div>
                </div>
                <div class="text-right">
                    <h2 class="paper__title">{{ strtoupper($meta['title']) }}</h2>
                    <div class="text-sm">
                        @if ($patientInfo)<strong>{{ $patientInfo['full_name'] }}</strong> · <span class="mono">{{ $patientInfo['folio'] }}</span><br>@endif
                        Fecha: {{ $blank ? '____ / ____ / ________' : Fmt::date($payload['created_at'] ?? null, 'd/m/Y') }}
                    </div>
                </div>
            </header>

            @if ($isHistoria)
                <section class="paper__section">
                    <h3>Datos de identificación</h3>
                    <div class="paper__grid">
                        <div class="span-2"><b>Nombre del paciente:</b> {!! $line($v('full_name')) !!}</div>
                        <div><b>Fecha de nacimiento:</b> {!! $line($v('birth_date') ? Fmt::date($v('birth_date'), 'd/m/Y') : null) !!}</div>
                        <div><b>Sexo:</b> {!! $line($sexes[$v('sex')] ?? null) !!}</div>
                        <div class="span-2"><b>Domicilio:</b> {!! $line($v('address')) !!}</div>
                        <div><b>Teléfono:</b> {!! $line($v('phone')) !!}</div>
                        <div><b>Ocupación:</b> {!! $line($v('occupation')) !!}</div>
                        <div class="span-2"><b>Motivo principal de la consulta:</b> {!! $line($v('chief_complaint')) !!}</div>
                    </div>
                </section>

                <section class="paper__section">
                    <h3>Antecedentes y hábitos</h3>
                    <div class="paper__grid">
                        <div><b>¿Experiencia desagradable anterior?</b> {{ $mark($v('bad_experience') === 'si') }} Sí {{ $mark($v('bad_experience') === 'no') }} No</div>
                        <div><b>¿Por qué?</b> {!! $line($v('bad_experience_reason')) !!}</div>
                        <div><b>Higiene dental:</b> {{ $mark($v('hygiene') === 'cepillo') }} Utiliza cepillo {{ $mark($v('hygiene') === 'no_utiliza') }} No utiliza</div>
                        <div><b>Veces al día:</b> {!! $line($v('brushing_times')) !!}</div>
                        <div class="span-2"><b>Extracciones pasadas (complicaciones):</b>
                            @foreach ($complications as $key => $label) {{ $mark(in_array($key, (array) $v('extraction_complications', []))) }} {{ $label }} @endforeach
                        </div>
                    </div>
                </section>

                <section class="paper__section">
                    <h3>Historia médica</h3>
                    <div class="paper__grid">
                        <div class="span-2"><b>¿Está bajo tratamiento médico?</b> {!! $line($v('under_treatment')) !!}</div>
                        <div class="span-2"><b>Padecimientos:</b></div>
                        <div class="span-2 paper__checks">
                            @foreach ($conditions as $key => $label)<span>{{ $mark(in_array($key, (array) $v('conditions', []))) }} {{ $label }}</span>@endforeach
                        </div>
                        <div class="span-2"><b>Alergias / reacciones:</b> {!! $line($v('allergies_detail')) !!}</div>
                    </div>
                </section>

                <section class="paper__section">
                    <h3>Signos vitales</h3>
                    <div class="paper__grid paper__grid--4">
                        <div><b>Presión arterial:</b> {!! $line($v('blood_pressure')) !!}</div>
                        <div><b>Pulso:</b> {!! $line($v('pulse') ? $v('pulse').' lpm' : null) !!}</div>
                        <div><b>Respiración:</b> {!! $line($v('respiration')) !!}</div>
                        <div><b>Temperatura:</b> {!! $line($v('temperature') ? $v('temperature').' °C' : null) !!}</div>
                        <div class="span-4"><b>Observaciones:</b> {!! $line($v('observations')) !!}</div>
                    </div>
                </section>
            @else
                <section class="paper__section">
                    <p class="paper__legal">
                        El paciente (o su tutor) declara haber recibido información clara y suficiente sobre el diagnóstico,
                        el plan de tratamiento, sus alternativas, riesgos, beneficios y costos, y <b>autoriza</b> la realización
                        de los procedimientos descritos en este documento.
                    </p>
                </section>

                <section class="paper__section">
                    <h3>Control de aplicaciones de flúor</h3>
                    <table class="paper__table">
                        <thead><tr><th style="width:120px">Año</th><th>Aplicaciones</th></tr></thead>
                        <tbody>
                            @forelse ((array) $v('fluoride', []) as $r)
                                <tr><td>{{ $r['year'] ?? '' }}</td><td>{{ $r['details'] ?? '' }}</td></tr>
                            @empty
                                @for ($i = 0; $i < ($blank ? 3 : 1); $i++)<tr><td>&nbsp;</td><td></td></tr>@endfor
                            @endforelse
                        </tbody>
                    </table>
                </section>

                <section class="paper__section">
                    <h3>Diagnóstico dental</h3>
                    <table class="paper__table">
                        <thead><tr><th>Diagnóstico dental</th><th style="width:140px">Fecha</th></tr></thead>
                        <tbody>
                            @forelse ((array) $v('diagnoses', []) as $r)
                                <tr><td>{{ $r['diagnosis'] ?? '' }}</td><td>{{ ! empty($r['date']) ? Fmt::date($r['date'], 'd/m/Y') : '' }}</td></tr>
                            @empty
                                @for ($i = 0; $i < ($blank ? 4 : 1); $i++)<tr><td>&nbsp;</td><td></td></tr>@endfor
                            @endforelse
                        </tbody>
                    </table>
                </section>

                <section class="paper__section">
                    <h3>Desarrollo de tratamiento</h3>
                    <table class="paper__table">
                        <thead><tr><th style="width:120px">Fecha</th><th>Actividades realizadas</th><th style="width:200px">Estomatólogo</th></tr></thead>
                        <tbody>
                            @forelse ((array) $v('activities', []) as $r)
                                <tr><td>{{ ! empty($r['date']) ? Fmt::date($r['date'], 'd/m/Y') : '' }}</td><td>{{ $r['activity'] ?? '' }}</td><td>{{ $r['doctor'] ?? '' }}</td></tr>
                            @empty
                                @for ($i = 0; $i < ($blank ? 6 : 1); $i++)<tr><td>&nbsp;</td><td></td><td></td></tr>@endfor
                            @endforelse
                        </tbody>
                    </table>
                </section>
            @endif

            <footer class="paper__sign">
                <div><div class="sign-line"></div>Firma del paciente o tutor</div>
                <div><div class="sign-line"></div>Nombre y firma del doctor</div>
            </footer>
        </article>
    </div>
</x-app-layout>
