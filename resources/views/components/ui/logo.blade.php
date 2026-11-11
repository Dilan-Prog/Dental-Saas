{{--
  <x-ui.logo size="32" />
  Logo de la marca (cuadro azul con muela). size = lado en px.
--}}
@props(['size' => 32])
<svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 32 32" aria-label="Dental SaaS" role="img">
    <rect width="32" height="32" rx="8" fill="#2563eb"/>
    <g transform="translate(4 4) scale(1)" fill="#fff" stroke="#0f172a" stroke-width="1.2" stroke-linejoin="round">
        <path d="M7.5 3.5c1.6-.6 3 .1 4.5.8 1.5-.7 2.9-1.4 4.5-.8 2.1.8 3 3.3 2.4 6-.4 1.9-1.1 3.1-1.4 5.1-.3 2-.6 5.4-2 5.4-1.2 0-1.4-2.6-1.8-4.2-.3-1.2-.7-1.8-1.7-1.8s-1.4.6-1.7 1.8c-.4 1.6-.6 4.2-1.8 4.2-1.4 0-1.7-3.4-2-5.4-.3-2-1-3.2-1.4-5.1-.6-2.7.3-5.2 2.4-6Z"/>
    </g>
</svg>
