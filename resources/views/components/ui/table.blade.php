{{--
  <x-ui.table clickable>
      <x-slot name="head"> <tr><th>Paciente</th><th>Estado</th><th class="actions"></th></tr> </x-slot>
      @forelse ($items as $i) <tr>...</tr> @empty <tr><td colspan="3"><x-ui.empty-state title="Sin resultados" icon="search" /></td></tr> @endforelse
  </x-ui.table>
  Envuelve la tabla en .table-wrap (scroll horizontal en tablet/celular).
  Usa class="actions" en th/td para la columna de botones alineada a la derecha.
  Props: clickable (filas con cursor pointer). Slots: head (contenido de <thead>), default (<tr> del <tbody>).
--}}
@props(['clickable' => false])
<div class="table-wrap">
    <table {{ $attributes->merge(['class' => 'table'.($clickable ? ' table--clickable' : '')]) }}>
        @isset($head)<thead>{{ $head }}</thead>@endisset
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
