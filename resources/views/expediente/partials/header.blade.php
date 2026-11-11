{{-- Encabezado del paciente (Figma: avatar, nombre, folio · edad · teléfono · correo, botón Editar datos). --}}
@php use App\Services\Expediente\Fmt; @endphp
<x-ui.card class="exp-header">
    <div class="row row--between row--wrap">
        <div class="row" style="gap:16px">
            <x-ui.avatar :name="$patient->full_name" size="lg" />
            <div>
                <div class="row" style="gap:10px">
                    <h1 class="exp-header__name">{{ $patient->full_name }}</h1>
                    @if ($patient->status?->value === 'inactive')
                        <x-ui.badge variant="neutral">Inactivo</x-ui.badge>
                    @endif
                </div>
                <div class="text-muted text-sm exp-header__meta">
                    <span class="mono">{{ $patient->folio }}</span>
                    @if ($patient->birth_date) · {{ $patient->age }} años @endif
                    @if ($patient->phone) · {{ $patient->phone }} @endif
                    @if ($patient->email) · {{ $patient->email }} @endif
                </div>
            </div>
        </div>
        <div class="row">
            @if (Route::has('pacientes.show'))
                <x-ui.button variant="ghost" icon="user" :href="route('pacientes.show', $patient->id)">Ficha</x-ui.button>
            @endif
            @if (auth()->user()->hasPermission('pacientes', 'editar') && ! $patient->anonymized_at)
                <x-ui.button variant="secondary" icon="edit" data-modal-open="editar-datos">Editar datos</x-ui.button>
            @endif
        </div>
    </div>
</x-ui.card>
