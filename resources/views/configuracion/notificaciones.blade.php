@extends('configuracion.layout')

@section('cfg')
    <x-ui.page-header title="Recordatorios automáticos" subtitle="Elige qué avisos se envían y con qué anticipación" />

    <form method="POST" action="{{ route('configuracion.notificaciones.update') }}" novalidate>
        @csrf
        @method('PUT')

        <x-ui.card>
            <div class="cfg-notifs">
                @foreach ($rows as $event => $row)
                    <div class="cfg-notif" data-notif>
                        <div class="cfg-notif__head">
                            <div>
                                <div class="cfg-notif__label">{{ $row['label'] }}</div>
                                <div class="text-sm text-muted">{{ $row['hint'] }}</div>
                            </div>
                            <x-ui.switch :name="'events['.$event.'][enabled]'" value="1" unchecked="0" :checked="$row['enabled']" data-notif-toggle :aria-label="$row['label']" />
                        </div>

                        @if ($row['lead'] || $row['role'])
                            <div class="cfg-notif__opts" data-notif-opts>
                                @if ($row['lead'])
                                    <x-ui.field :name="'events.'.$event.'.lead'" label="Anticipación (minutos)" class="cfg-notif__opt">
                                        <input type="number" id="events.{{ $event }}.lead" name="events[{{ $event }}][lead]" class="input {{ $errors->has('events.'.$event.'.lead') ? 'has-error' : '' }}"
                                               value="{{ old('events.'.$event.'.lead', $row['lead_value']) }}"
                                               min="{{ $row['lead']['min'] }}" max="{{ $row['lead']['max'] }}" step="5">
                                    </x-ui.field>
                                @endif
                                @if ($row['role'])
                                    <x-ui.field :name="'events.'.$event.'.role'" label="Se notifica a" class="cfg-notif__opt">
                                        <select id="events.{{ $event }}.role" name="events[{{ $event }}][role]" class="select {{ $errors->has('events.'.$event.'.role') ? 'has-error' : '' }}">
                                            <option value="">Selecciona un rol</option>
                                            @foreach ($roles as $role)
                                                <option value="{{ $role->id }}" @selected(old('events.'.$event.'.role', $row['role_id']) == $role->id)>{{ $role->name }}</option>
                                            @endforeach
                                        </select>
                                    </x-ui.field>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <x-slot name="footer">
                <x-ui.button type="submit">Guardar</x-ui.button>
            </x-slot>
        </x-ui.card>
    </form>
@endsection
