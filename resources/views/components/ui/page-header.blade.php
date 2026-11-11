{{--
  <x-ui.page-header title="Pacientes" subtitle="124 registros">
      <x-slot name="actions"> <x-ui.button icon="plus" href="...">Nuevo paciente</x-ui.button> </x-slot>
  </x-ui.page-header>
  Props: title, subtitle. Slot nombrado `actions` = botones a la derecha. El slot por defecto se ignora.
--}}
@props(['title', 'subtitle' => null])
<div {{ $attributes->merge(['class' => 'page-header']) }}>
    <div>
        <h1 class="page-header__title">{{ $title }}</h1>
        @if ($subtitle)<p class="page-header__subtitle">{{ $subtitle }}</p>@endif
    </div>
    @isset($actions)<div class="page-header__actions">{{ $actions }}</div>@endisset
</div>
