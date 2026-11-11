{{--
  <x-ui.dropdown align="right|left">
      <x-slot name="trigger"> <x-ui.button variant="secondary" icon="more-horizontal" icon-only aria-label="Acciones" /> </x-slot>
      <a class="dropdown-item" href="#"><x-ui.icon name="edit" /> Editar</a>
      <div class="dropdown-sep"></div>
      <button class="dropdown-item dropdown-item--danger" type="button">Eliminar</button>
  </x-ui.dropdown>
  Se cierra con clic fuera o Esc. Items: .dropdown-item, .dropdown-item--danger, .dropdown-sep, .dropdown-header.
--}}
@props(['align' => 'right', 'wide' => false])
<div class="dropdown" data-dropdown {{ $attributes }}>
    <span data-dropdown-toggle aria-haspopup="true" aria-expanded="false" style="display:inline-flex">{{ $trigger }}</span>
    <div class="dropdown-menu {{ $align === 'left' ? 'dropdown-menu--left' : '' }} {{ $wide ? 'dropdown-menu--wide' : '' }}">{{ $slot }}</div>
</div>
