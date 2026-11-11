{{-- Dashboard: saludo, KPIs, agenda de hoy, proximas citas, ingresos 30 dias y alertas. --}}
<x-app-layout title="Dashboard">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/modules/dashboard.css') }}?v={{ @filemtime(public_path('css/modules/dashboard.css')) }}">
    @endpush

    <x-ui.page-header :title="$greeting['title'].' 👋'" :subtitle="$greeting['subtitle']">
        <x-slot name="actions">
            @if ($reportUrl)
                <x-ui.button variant="secondary" href="{{ $reportUrl }}">Reporte</x-ui.button>
            @endif
            @if ($newAppointmentUrl)
                <x-ui.button icon="plus" href="{{ $newAppointmentUrl }}">Nueva cita</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="stack stack--lg">
        @if (count($kpis))
            <div class="dash-kpis">
                @foreach ($kpis as $k)
                    <x-ui.kpi :label="$k['label']" :value="$k['value']" :delta="$k['delta']" :trend="$k['trend']" />
                @endforeach
            </div>
        @endif

        @if ($agenda !== null)
            <div class="dash-grid">
                <x-ui.card title="Agenda de hoy" :subtitle="ucfirst(now()->translatedFormat('l, j \d\e F \d\e Y')).' · '.$agenda['count'].' '.($agenda['count'] === 1 ? 'cita' : 'citas')">
                    @if ($agendaUrl)
                        <x-slot name="actions">
                            <x-ui.button size="sm" variant="secondary" href="{{ $agendaUrl }}">Ver agenda</x-ui.button>
                        </x-slot>
                    @endif

                    <div class="timeline" style="--hour-px: {{ $agenda['hour_px'] }}px; --hours: {{ $agenda['end_hour'] - $agenda['start_hour'] + 1 }}">
                        <div class="timeline__hours">
                            @for ($h = $agenda['start_hour']; $h <= $agenda['end_hour']; $h++)
                                <div class="timeline__hour">{{ sprintf('%02d:00', $h) }}</div>
                            @endfor
                        </div>
                        <div class="timeline__track">
                            @foreach ($agenda['events'] as $e)
                                <div class="evt evt--{{ $e['variant'] }}"
                                     style="top: {{ $e['top'] }}px; height: {{ $e['height'] }}px; left: calc({{ $e['lane'] }} * 100% / {{ $e['lanes'] }}); width: calc(100% / {{ $e['lanes'] }} - 4px)"
                                     title="{{ $e['range'] }} · {{ $e['patient'] }}">
                                    <div class="evt__title truncate">{{ $e['patient'] }}@if ($e['treatment']) · {{ $e['treatment'] }}@endif</div>
                                    <div class="evt__meta truncate">{{ $e['range'] }}@if ($e['doctor']) · {{ $e['doctor'] }}@endif · {{ $e['status'] }}</div>
                                </div>
                            @endforeach
                            @if ($agenda['count'] === 0)
                                <div class="timeline__empty">No hay citas programadas para hoy.</div>
                            @endif
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card title="Próximas citas">
                    @forelse ($upcoming as $u)
                        <div class="upcoming">
                            <div class="upcoming__when">{{ $u['when'] }}</div>
                            <div class="upcoming__name">{{ $u['patient'] }}</div>
                            @if ($u['treatment'])<div class="upcoming__sub">{{ $u['treatment'] }}</div>@endif
                        </div>
                    @empty
                        <x-ui.empty-state icon="calendar" title="Sin citas próximas" text="Cuando agendes citas aparecerán aquí." />
                    @endforelse
                </x-ui.card>
            </div>
        @endif

        <div class="dash-grid">
            @if ($income !== null)
                <x-ui.card title="Ingresos · últimos 30 días"
                           :subtitle="'Total '.\App\Support\Money::format($income['total']).' MXN'.($income['delta'] ? ' · '.$income['delta'] : '')">
                    @if ($income['max'] > 0)
                        <div class="chart" role="img" aria-label="Ingresos diarios de los últimos 30 días">
                            @foreach ($income['days'] as $d)
                                @php($pct = $income['max'] > 0 ? max(2, round((float) $d['amount'] / $income['max'] * 100, 1)) : 2)
                                <div class="chart__col" title="{{ $d['label'] }}">
                                    <div class="chart__bar {{ $loop->index >= count($income['days']) - 2 ? 'is-recent' : '' }} {{ (float) $d['amount'] <= 0 ? 'is-zero' : '' }}"
                                         style="height: {{ $pct }}%"></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="chart__axis">
                            <span>{{ $income['days'][0]['date']->translatedFormat('j M') }}</span>
                            <span>Hoy</span>
                        </div>
                    @else
                        <x-ui.empty-state icon="trending-up" title="Sin ingresos en el periodo" text="Los pagos registrados en los últimos 30 días aparecerán aquí." />
                    @endif
                </x-ui.card>
            @endif

            <x-ui.card title="Pendientes & alertas">
                @forelse ($alerts as $a)
                    <div class="alert-row">
                        <x-ui.badge :variant="$a['variant']">{{ $a['badge'] }}</x-ui.badge>
                        @if ($a['url'])
                            <a href="{{ $a['url'] }}" class="alert-row__text">{{ $a['text'] }}</a>
                        @else
                            <span class="alert-row__text">{{ $a['text'] }}</span>
                        @endif
                    </div>
                @empty
                    <x-ui.empty-state icon="check-circle" title="Todo al día" text="No tienes pendientes ni alertas por atender." />
                @endforelse
            </x-ui.card>
        </div>
    </div>
</x-app-layout>
