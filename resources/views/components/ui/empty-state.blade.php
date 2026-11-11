{{--
  <x-ui.empty-state icon="user" title="Aún no hay pacientes" text="Crea el primero para comenzar.">
      <x-ui.button icon="plus" href="...">Nuevo paciente</x-ui.button>
  </x-ui.empty-state>
  Props: icon, title, text. El slot son las acciones.
--}}
@props(['icon' => 'search', 'title' => 'Sin resultados', 'text' => null])
<div {{ $attributes->merge(['class' => 'empty']) }}>
    <div class="empty__icon"><x-ui.icon :name="$icon" size="lg" /></div>
    <div class="empty__title">{{ $title }}</div>
    @if ($text)<div class="empty__text">{{ $text }}</div>@endif
    @if (! $slot->isEmpty())<div class="row mt-2">{{ $slot }}</div>@endif
</div>
