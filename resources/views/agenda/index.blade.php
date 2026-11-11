@extends('layouts.app')

@section('title', 'Agenda')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/agenda.css') }}?v={{ @filemtime(public_path('css/modules/agenda.css')) }}">
@endpush

@php
    /** URL de la agenda conservando filtros; $over reemplaza parámetros. */
    $agendaUrl = function (array $over = []) use ($view, $date, $filters) {
        $params = array_merge([
            'view' => $view,
            'date' => $date->toDateString(),
            'doctor' => $filters['doctor'],
            'branch' => $filters['branch'],
            'status' => $filters['status'] !== 'activas' ? $filters['status'] : null,
        ], $over);

        return route('agenda.index', array_filter($params, fn ($v) => $v !== null && $v !== ''));
    };
    $dayNames = ['LUN', 'MAR', 'MIÉ', 'JUE', 'VIE', 'SÁB', 'DOM'];
    $views = ['day' => 'Día', 'week' => 'Semana', 'month' => 'Mes'];
    $icons = ['completada' => 'check-circle', 'en_curso' => 'activity', 'confirmada' => 'check', 'cancelada' => 'x-circle', 'no_asistio' => 'x-circle'];
@endphp

@section('content')
    <x-ui.page-header title="Agenda" :subtitle="$subtitle">
        <x-slot name="actions">
            @if ($canCreate)
                <x-ui.button icon="plus" data-modal-open="cita-nueva" data-agenda-new>Nueva cita</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    {{-- ===================== Barra de herramientas ===================== --}}
    <div class="ag-toolbar">
        <div class="ag-toolbar__left">
            <div class="ag-nav">
                <a href="{{ $agendaUrl(['date' => $prev->toDateString()]) }}" class="icon-btn" aria-label="Anterior"><x-ui.icon name="chevron-left" /></a>
                <span class="ag-nav__label">{{ $label }}</span>
                <a href="{{ $agendaUrl(['date' => $next->toDateString()]) }}" class="icon-btn" aria-label="Siguiente"><x-ui.icon name="chevron-right" /></a>
                <a href="{{ $agendaUrl(['date' => now()->toDateString()]) }}" class="btn btn--secondary btn--sm">Hoy</a>
            </div>
            <div class="segmented" role="tablist" aria-label="Vista">
                @foreach ($views as $key => $text)
                    <a href="{{ $agendaUrl(['view' => $key]) }}" class="{{ $view === $key ? 'is-active' : '' }}" role="tab" aria-selected="{{ $view === $key ? 'true' : 'false' }}">{{ $text }}</a>
                @endforeach
            </div>
        </div>

        <form method="GET" action="{{ route('agenda.index') }}" class="ag-toolbar__filters" data-no-lock>
            <input type="hidden" name="view" value="{{ $view }}">
            <input type="hidden" name="date" value="{{ $date->toDateString() }}">
            @if ($filters['doctor'])<input type="hidden" name="doctor" value="{{ $filters['doctor'] }}">@endif
            @if ($branches->count() > 1)
                <select name="branch" class="select select--sm" aria-label="Sucursal" onchange="this.form.submit()">
                    <option value="">Todas las sucursales</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->id }}" @selected($filters['branch'] === $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            @endif
            <select name="status" class="select select--sm" aria-label="Estado" onchange="this.form.submit()">
                @foreach ($statusOptions as $value => $text)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $text }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="ag-chips" aria-label="Filtrar por doctor">
        <a href="{{ $agendaUrl(['doctor' => null]) }}" class="ag-chip {{ $filters['doctor'] ? '' : 'is-active' }}">Todos</a>
        @foreach ($doctors as $doc)
            <a href="{{ $agendaUrl(['doctor' => $doc->id]) }}" class="ag-chip {{ $colors[$doc->id] ?? 'dc-0' }} {{ $filters['doctor'] === $doc->id ? 'is-active' : '' }}">
                <span class="ag-chip__dot"></span>{{ \Illuminate\Support\Str::of($doc->name)->replaceMatches('/^(Dra?\.)\s+/u', '') }}
            </a>
        @endforeach
    </div>

    {{-- ===================== Calendario ===================== --}}
    @if ($view === 'month')
        <div class="ag-month">
            <div class="ag-month__head">
                @foreach ($dayNames as $n)<div>{{ $n }}</div>@endforeach
            </div>
            @foreach ($weeks as $week)
                <div class="ag-month__week">
                    @foreach ($week as $day)
                        <div class="ag-mday {{ $day['in_month'] ? '' : 'is-out' }} {{ $day['is_today'] ? 'is-today' : '' }}">
                            <a class="ag-mday__num" href="{{ $agendaUrl(['view' => 'day', 'date' => $day['key']]) }}">{{ $day['date']->day }}</a>
                            @foreach ($day['items'] as $c)
                                <button type="button" class="ag-mini {{ $c['color'] }} is-{{ $c['status'] }}" data-appt="{{ json_encode($c['payload'], JSON_UNESCAPED_UNICODE) }}" data-appt-open="detail">
                                    <b>{{ $c['time'] }}</b> {{ $c['patient'] }}
                                </button>
                            @endforeach
                            @if ($day['more'] > 0)
                                <a class="ag-more" href="{{ $agendaUrl(['view' => 'day', 'date' => $day['key']]) }}">+{{ $day['more'] }} más</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @else
        <div class="ag-scroll">
            <div class="ag-grid ag-grid--{{ $view }}" style="--cols: {{ count($columns) }}; --row-h: {{ $row_height }}px;">
                <div class="ag-head">
                    <div class="ag-corner"></div>
                    @foreach ($columns as $col)
                        <div class="ag-dayhead {{ $col['is_today'] ? 'is-today' : '' }}">
                            <span>{{ $dayNames[$col['date']->dayOfWeekIso - 1] }}</span>
                            <strong>{{ $col['date']->day }}</strong>
                            @if ($view === 'day')<small>{{ $col['date']->translatedFormat('F Y') }}</small>@endif
                        </div>
                    @endforeach
                </div>
                <div class="ag-body" style="height: {{ $grid_height }}px;">
                    <div class="ag-hours">
                        @foreach ($hours as $h)
                            <div class="ag-hour"><span>{{ $h }}:00</span></div>
                        @endforeach
                    </div>
                    @foreach ($columns as $col)
                        <div class="ag-col {{ $col['is_today'] ? 'is-today' : '' }}">
                            @foreach ($hours as $h)
                                @if ($canCreate)
                                    <button type="button" class="ag-cell" data-new-slot data-date="{{ $col['key'] }}" data-hour="{{ sprintf('%02d:00', $h) }}" aria-label="Nueva cita {{ $col['key'] }} {{ $h }}:00"></button>
                                @else
                                    <div class="ag-cell"></div>
                                @endif
                            @endforeach

                            @foreach ($col['cards'] as $c)
                                <button type="button"
                                        class="ag-card {{ $c['color'] }} is-{{ $c['status'] }}"
                                        style="top: {{ $c['top'] }}px; height: {{ $c['height'] }}px; left: calc({{ $c['lane'] }} * 100% / {{ $c['lanes'] }} + 3px); width: calc(100% / {{ $c['lanes'] }} - 6px);"
                                        data-appt="{{ json_encode($c['payload'], JSON_UNESCAPED_UNICODE) }}" data-appt-open="detail"
                                        title="{{ $c['patient'] }} · {{ $c['status_label'] }}">
                                    <span class="ag-card__time">{{ $c['time'] }}@if(isset($icons[$c['status']]))<x-ui.icon :name="$icons[$c['status']]" size="sm" />@endif</span>
                                    <span class="ag-card__name">{{ $c['patient'] }}</span>
                                    @if ($c['treatment'])<span class="ag-card__sub">{{ $c['treatment'] }}</span>@endif
                                    @if ($view === 'day')<span class="ag-card__doc">{{ $c['doctor'] }}</span>@endif
                                </button>
                            @endforeach

                            @if ($col['now_top'] !== null)
                                <div class="ag-now" style="top: {{ $col['now_top'] }}px;"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if ($total === 0)
        <p class="ag-empty text-muted">No hay citas en este periodo con los filtros seleccionados.@if($canCreate) Haz clic en una hora del calendario o en «Nueva cita» para programar una.@endif</p>
    @endif

    @include('agenda._modals', ['mode' => 'full'])
@endsection

@push('scripts')
    <script>
        window.AGENDA = {
            urls: { patients: @json(route('agenda.patients')), slots: @json(route('agenda.slots')) },
            config: @json(['step' => $config['step'], 'defaultDuration' => $config['default_duration']]),
            filters: @json(['doctor' => $filters['doctor'], 'view' => $view]),
            today: @json(now()->toDateString()),
            preselected: @json($preselected ? ['id' => $preselected->id, 'name' => $preselected->full_name, 'folio' => $preselected->folio] : null),
            openNew: @json($createFailed || ((bool) $preselected && ! $errors->any())),
            createFailed: @json($createFailed),
            reopen: @json($reopen),
        };
    </script>
    <script src="{{ asset('js/modules/agenda.js') }}?v={{ @filemtime(public_path('js/modules/agenda.js')) }}"></script>
@endpush
