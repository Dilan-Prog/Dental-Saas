{{--
  <x-ui.kpi label="Ingresos del día" value="$14,350" delta="+18% vs. ayer" trend="up" />
  Props: label, value, delta (texto pequeño), trend (up|down|null => color del delta). Slot opcional bajo el delta.
--}}
@props(['label', 'value', 'delta' => null, 'trend' => null])
<div {{ $attributes->merge(['class' => 'kpi']) }}>
    <div class="kpi__label">{{ $label }}</div>
    <div class="kpi__value mono">{{ $value }}</div>
    @if ($delta)<div class="kpi__delta {{ $trend ? 'kpi__delta--'.$trend : '' }}">{{ $delta }}</div>@endif
    {{ $slot }}
</div>
