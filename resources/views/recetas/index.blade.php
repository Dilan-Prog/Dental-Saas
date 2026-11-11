@extends('layouts.app')

@section('title', 'Recetas')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/recetas.css') }}?v={{ @filemtime(public_path('css/modules/recetas.css')) }}">
@endpush

@php
    use App\Services\Recetas\RxPresenter;

    $user = auth()->user();
    $can = fn (string $a) => $user->hasPermission('recetas', $a);
    $keep = array_filter(['q' => $filters['q'] ?: null, 'estado' => $filters['estado']]);
@endphp

@section('content')
    <x-ui.page-header title="Recetas" subtitle="Prescripciones médicas con firma del doctor">
        <x-slot name="actions">
            <x-ui.button variant="secondary" icon="pill" :href="route('recetas.medicamentos.index')">Catálogo de medicamentos</x-ui.button>
            @if ($can('crear'))
                <x-ui.button icon="plus" :href="route('recetas.create')">Crear receta</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="stack stack--lg">
        <div class="grid grid-3">
            <x-ui.kpi label="Recetas del mes" :value="$kpis['month']" :delta="RxPresenter::monthYear()" />
            <x-ui.kpi label="Firmadas" :value="$kpis['signed']" delta="con firma del doctor" />
            <x-ui.kpi label="Borradores" :value="$kpis['drafts']" delta="pendientes de firmar" />
        </div>

        <div class="rx-layout">
            {{-- Lista --}}
            <section class="card card--flush rx-list" aria-label="Listado de recetas">
                <div class="card__header rx-list__head">
                    <div class="row"><span class="card__title">Recetas</span> <span class="badge badge--primary">{{ $prescriptions->total() }}</span></div>
                </div>
                <div class="table-toolbar">
                    <form method="GET" action="{{ route('recetas.index') }}" class="input-wrap" data-no-lock>
                        <x-ui.icon name="search" size="sm" />
                        <input type="search" name="q" class="input" value="{{ $filters['q'] }}" placeholder="Paciente, folio o diagnóstico…" aria-label="Buscar recetas">
                        @if ($filters['estado'])<input type="hidden" name="estado" value="{{ $filters['estado'] }}">@endif
                    </form>
                    <div class="segmented">
                        <a href="{{ route('recetas.index', array_filter(['q' => $filters['q'] ?: null])) }}" class="{{ ! $filters['estado'] ? 'is-active' : '' }}">Todas</a>
                        <a href="{{ route('recetas.index', array_filter(['q' => $filters['q'] ?: null, 'estado' => 'firmada'])) }}" class="{{ $filters['estado'] === 'firmada' ? 'is-active' : '' }}">Firmadas</a>
                        <a href="{{ route('recetas.index', array_filter(['q' => $filters['q'] ?: null, 'estado' => 'borrador'])) }}" class="{{ $filters['estado'] === 'borrador' ? 'is-active' : '' }}">Borradores</a>
                    </div>
                </div>

                @forelse ($prescriptions as $item)
                    @php $name = $item->patient?->full_name ?? 'Paciente'; @endphp
                    <a href="{{ route('recetas.show', ['prescription' => $item] + $keep) }}"
                       class="rx-item {{ $selected && $selected->id === $item->id ? 'is-active' : '' }}">
                        <span class="avatar {{ RxPresenter::avatarClass($name) }}" aria-hidden="true">{{ collect(preg_split('/\s+/', $name))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}</span>
                        <span class="rx-item__body">
                            <span class="rx-item__title">{{ $name }} <small class="rx-item__folio">{{ $item->folio }}</small></span>
                            <span class="rx-item__dx">{{ $item->diagnosis ?: 'Sin diagnóstico' }}</span>
                            <span class="rx-item__meta">{{ RxPresenter::date($item->issued_on) }} · {{ RxPresenter::medsCount($item->items_count) }} · {{ $item->doctor?->user?->full_name }}</span>
                        </span>
                        @if ($item->isSigned())
                            <x-ui.badge variant="success">Firmada</x-ui.badge>
                        @else
                            <x-ui.badge variant="warning">Borrador</x-ui.badge>
                        @endif
                    </a>
                @empty
                    <x-ui.empty-state icon="clipboard" :title="$filters['q'] || $filters['estado'] ? 'Sin resultados' : 'Aún no hay recetas'"
                                      :text="$filters['q'] || $filters['estado'] ? 'Prueba con otra búsqueda o quita los filtros.' : 'Crea la primera receta para comenzar.'">
                        @if ($can('crear') && ! $filters['q'] && ! $filters['estado'])
                            <x-ui.button icon="plus" :href="route('recetas.create')">Crear receta</x-ui.button>
                        @endif
                    </x-ui.empty-state>
                @endforelse

                @if ($prescriptions->hasPages())
                    <div class="pagination-wrap">{{ $prescriptions->links() }}</div>
                @endif
            </section>

            {{-- Documento --}}
            <section class="rx-panel" aria-label="Documento de la receta">
                @if ($selected)
                    <div class="rx-toolbar no-print">
                        <div class="row row--wrap">
                            @if ($selected->isSigned())
                                <x-ui.button variant="secondary" size="sm" icon="printer" :href="route('recetas.print', $selected)" target="_blank" rel="noopener">Imprimir</x-ui.button>
                                <x-ui.button variant="secondary" size="sm" icon="download" :href="route('recetas.pdf', $selected)" target="_blank" rel="noopener">PDF</x-ui.button>
                                @if ($can('editar'))
                                    <x-ui.dropdown align="left">
                                        <x-slot name="trigger"><x-ui.button variant="secondary" size="sm" icon="send">Enviar</x-ui.button></x-slot>
                                        @foreach (['email' => ['mail', 'Por correo electrónico'], 'whatsapp' => ['phone', 'Por WhatsApp'], 'impresion' => ['printer', 'Registrar impresión']] as $channel => [$icon, $label])
                                            <form method="POST" action="{{ route('recetas.send', $selected) }}">
                                                @csrf
                                                <input type="hidden" name="channel" value="{{ $channel }}">
                                                <button type="submit" class="dropdown-item"><x-ui.icon :name="$icon" /> {{ $label }}</button>
                                            </form>
                                        @endforeach
                                    </x-ui.dropdown>
                                @endif
                            @else
                                <x-ui.button variant="secondary" size="sm" icon="eye" :href="route('recetas.print', $selected)" target="_blank" rel="noopener">Vista previa</x-ui.button>
                                @if ($can('editar'))
                                    <x-ui.button variant="secondary" size="sm" icon="edit" :href="route('recetas.edit', $selected)">Editar</x-ui.button>
                                @endif
                                @if ($can('emitir_recetas'))
                                    <x-ui.confirm-form :action="route('recetas.emit', $selected)" method="POST" variant="primary" icon="check"
                                                       label="Firmar y emitir" title="Firmar y emitir"
                                                       message="Al firmar, la receta {{ $selected->folio }} será inmutable. ¿Deseas emitirla?" />
                                @endif
                                @if ($can('editar'))
                                    <x-ui.confirm-form :action="route('recetas.destroy', $selected)" method="DELETE" variant="danger-outline" icon="trash"
                                                       label="Eliminar" title="Eliminar borrador"
                                                       message="¿Eliminar el borrador {{ $selected->folio }}? Esta acción no se puede deshacer." />
                                @endif
                            @endif
                        </div>
                        @if ($selected->isSigned() && $can('crear'))
                            <form method="POST" action="{{ route('recetas.repeat', $selected) }}">
                                @csrf
                                <button type="submit" class="btn btn--ghost btn--sm rx-link-btn"><x-ui.icon name="copy" size="sm" /> Repetir receta</button>
                            </form>
                        @endif
                    </div>

                    @include('recetas._document', ['rx' => $selected, 'org' => $org])

                    @if ($selected->isSigned() && $selected->deliveries->isNotEmpty())
                        <div class="rx-deliveries no-print">
                            <div class="rx-doc__label">Envíos</div>
                            @foreach ($selected->deliveries as $d)
                                <div class="rx-delivery">
                                    <x-ui.icon :name="['email' => 'mail', 'whatsapp' => 'phone', 'impresion' => 'printer'][$d->channel] ?? 'send'" size="sm" />
                                    {{ ['email' => 'Correo electrónico', 'whatsapp' => 'WhatsApp', 'impresion' => 'Impresión'][$d->channel] ?? $d->channel }}
                                    <span class="text-muted">· {{ $d->sent_at ? 'enviada '.RxPresenter::date($d->sent_at) : 'en cola' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @else
                    <x-ui.card>
                        <x-ui.empty-state icon="clipboard" title="Selecciona una receta" text="Elige una receta de la lista para ver su documento." />
                    </x-ui.card>
                @endif
            </section>
        </div>
    </div>

    @if ($form)
        @include('recetas._form', ['form' => $form, 'org' => $org])
    @endif
@endsection

@push('scripts')
    <script>
        window.RX = {
            patientsUrl: @json(route('recetas.pacientes.buscar')),
            medsUrl: @json(route('recetas.medicamentos.buscar')),
            openForm: @json($form !== null),
            returnUrl: @json($form !== null && ($form['mode'] ?? '') === 'edit' && ! empty($form['rx']) ? route('recetas.show', $form['rx']) : route('recetas.index')),
        };
    </script>
    <script src="{{ asset('js/modules/recetas.js') }}?v={{ @filemtime(public_path('js/modules/recetas.js')) }}"></script>
@endpush
