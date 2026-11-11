{{-- Pestanas del modulo Finanzas (navegacion por enlaces). Uso: @include('finanzas.partials.tabs', ['active' => 'resumen']) --}}
<div style="margin-bottom:20px">
    <x-ui.tabs :active="$active" :items="[
        'resumen' => ['label' => 'Resumen', 'href' => route('finanzas.index'), 'icon' => 'trending-up'],
        'cajas' => ['label' => 'Cajas y turnos', 'href' => route('finanzas.cajas.index'), 'icon' => 'credit-card'],
        'categorias' => ['label' => 'Categorías', 'href' => route('finanzas.categorias.index'), 'icon' => 'filter'],
    ]" />
</div>
