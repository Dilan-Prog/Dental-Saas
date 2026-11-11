{{--
  Modal / overlay. Abrir:  <x-ui.button data-modal-open="nueva-cita">Nueva cita</x-ui.button>
                 JS:      UI.openModal('nueva-cita')
  Cerrar con cualquier elemento [data-modal-close], Esc o clic fuera (salvo `static`).

  <x-ui.modal id="nueva-cita" title="Nueva cita" subtitle="..." size="sm|md|lg|xl" static open-on-error
              action="{{ route('citas.store') }}" method="POST">
      ...campos del formulario...
      <x-slot name="footer">
          <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
          <x-ui.button type="submit">Guardar</x-ui.button>
      </x-slot>
  </x-ui.modal>

  - Si pasas `action`, el cuerpo y el pie se envuelven en un <form> (con @csrf y @method si method != GET/POST).
  - `open-on-error`: se abre solo tras un redirect con errores de validación (útil para modales con formulario).
  - Props: id (obligatorio), title, subtitle, size, static, openOnError, action, method.
--}}
@props(['id', 'title' => null, 'subtitle' => null, 'size' => 'md', 'static' => false, 'openOnError' => false, 'action' => null, 'method' => 'POST'])
@php $m = strtoupper($method); @endphp
<div id="{{ $id }}" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title"
     @if($static) data-static @endif @if($openOnError) data-open-on-error @endif>
    <div class="modal {{ $size !== 'md' ? 'modal--'.$size : '' }}">
        <div class="modal__header">
            <div>
                <div class="modal__title" id="{{ $id }}-title">{{ $title }}</div>
                @if ($subtitle)<div class="modal__subtitle">{{ $subtitle }}</div>@endif
            </div>
            <button type="button" class="icon-btn icon-btn--flat" data-modal-close aria-label="Cerrar"><x-ui.icon name="x" /></button>
        </div>
        @if ($action)
            <form method="{{ $m === 'GET' ? 'GET' : 'POST' }}" action="{{ $action }}" class="modal__form" {{ $attributes }}>
                @if ($m !== 'GET')@csrf @endif
                @if (! in_array($m, ['GET', 'POST']))@method($m)@endif
                <div class="modal__body">{{ $slot }}</div>
                @isset($footer)<div class="modal__footer">{{ $footer }}</div>@endisset
            </form>
        @else
            <div class="modal__body" {{ $attributes }}>{{ $slot }}</div>
            @isset($footer)<div class="modal__footer">{{ $footer }}</div>@endisset
        @endif
    </div>
</div>
