{{--
  Formulario de acción destructiva/sensible con confirmación (modal propio, no confirm() nativo).
  <x-ui.confirm-form :action="route('pacientes.destroy', $p)" method="DELETE"
                     message="¿Dar de baja a este paciente?" label="Dar de baja" icon="trash" variant="danger" size="sm" />
  Props: action, method (DELETE|POST|PATCH|PUT), message, title, label (texto del botón), confirmText, variant, size, icon, iconOnly.
  Con iconOnly pon `label` igualmente (se usa como aria-label).
--}}
@props(['action', 'method' => 'DELETE', 'message' => '¿Confirmas esta acción?', 'title' => 'Confirmar acción', 'label' => 'Eliminar',
        'confirmText' => null, 'variant' => 'danger', 'size' => 'sm', 'icon' => null, 'iconOnly' => false])
@php $m = strtoupper($method); @endphp
<form method="POST" action="{{ $action }}" style="display:inline"
      data-confirm="{{ $message }}" data-confirm-title="{{ $title }}" data-confirm-text="{{ $confirmText ?? $label }}"
      data-confirm-variant="{{ in_array($variant, ['primary']) ? 'primary' : 'danger' }}">
    @csrf
    @if ($m !== 'POST')@method($m)@endif
    <x-ui.button type="submit" :variant="$variant" :size="$size" :icon="$icon" :icon-only="$iconOnly" aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes }}>
        @unless($iconOnly){{ $label }}@endunless
    </x-ui.button>
</form>
