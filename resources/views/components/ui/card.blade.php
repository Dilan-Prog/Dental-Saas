{{--
  <x-ui.card title="Agenda de hoy" subtitle="8 citas" flush>
      <x-slot name="actions"> <x-ui.button size="sm" variant="secondary">Ver todo</x-ui.button> </x-slot>
      ...contenido...
      <x-slot name="footer"> botones </x-slot>
  </x-ui.card>
  Props: title, subtitle, flush (sin padding en el cuerpo: útil para tablas).
  Slots opcionales: actions (derecha del encabezado), footer.
--}}
@props(['title' => null, 'subtitle' => null, 'flush' => false])
<div {{ $attributes->merge(['class' => 'card'.($flush ? ' card--flush' : '')]) }}>
    @if ($title || isset($actions))
        <div class="card__header" @if($flush) style="padding-bottom:16px;border-bottom:1px solid var(--border)" @endif>
            <div>
                @if ($title)<div class="card__title">{{ $title }}</div>@endif
                @if ($subtitle)<div class="card__subtitle">{{ $subtitle }}</div>@endif
            </div>
            @isset($actions)<div class="row">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div class="card__body" @if(! $title && ! isset($actions) && ! $flush) style="padding-top:20px" @endif>{{ $slot }}</div>
    @isset($footer)<div class="card__footer">{{ $footer }}</div>@endisset
</div>
