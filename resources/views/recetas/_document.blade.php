{{--
  Documento de la receta (membrete + paciente + medicamentos + firma).
  Variables: $rx (Prescription con patient, doctor.user, doctor.doctorProfile, items), $org (Organization).
  Se usa en la pantalla de recetas (panel derecho) y en la vista imprimible.
--}}
@php
    use App\Services\Recetas\RxPresenter;

    $signed = $rx->isSigned();
    $doctorName = $signed ? $rx->doctor_name_snapshot : ($rx->doctor?->user?->full_name);
    $license = $signed ? $rx->doctor_license_snapshot : ($rx->doctor?->doctorProfile?->professional_license);
    // Firma manuscrita: nombre sin el título (Dra./Dr.)
    $signature = $doctorName ? trim(preg_replace('/^(dra?\.?|lic\.?|c\.?d\.?)\s+/i', '', $doctorName)) : '';
    $contact = collect([$org?->phone, $org?->email])->filter()->implode(' · ');
@endphp
<article class="rx-doc {{ $signed ? '' : 'rx-doc--draft' }}" aria-label="Receta {{ $rx->folio }}">
    @unless ($signed)
        <div class="rx-doc__watermark" aria-hidden="true">BORRADOR</div>
    @endunless

    <header class="rx-doc__head">
        <div>
            <div class="rx-doc__clinic">{{ $org?->commercial_name ?? 'Clínica Dental' }}</div>
            @if ($org?->address_line)<div class="rx-doc__muted">{{ $org->address_line }}</div>@endif
            @if ($contact)<div class="rx-doc__muted">{{ $contact }}</div>@endif
        </div>
        <div class="rx-doc__rx">
            <div class="rx-doc__rx-mark">Rx</div>
            <div class="rx-doc__muted">{{ $rx->folio }}</div>
        </div>
    </header>

    <hr class="rx-doc__rule">

    <div class="rx-doc__meta">
        <div>
            <div class="rx-doc__label">Paciente</div>
            <div class="rx-doc__value">{{ $rx->patient?->full_name ?? '—' }}</div>
        </div>
        <div>
            <div class="rx-doc__label">Expediente</div>
            <div class="rx-doc__value">{{ $rx->patient?->folio ?? '—' }}</div>
        </div>
        <div>
            <div class="rx-doc__label">Edad</div>
            <div class="rx-doc__value">{{ RxPresenter::age($rx->patient, $rx->issued_on) }}</div>
        </div>
        <div>
            <div class="rx-doc__label">Fecha</div>
            <div class="rx-doc__value">{{ RxPresenter::date($rx->issued_on) }}</div>
        </div>
    </div>

    @if ($rx->diagnosis)
        <section class="rx-doc__block">
            <div class="rx-doc__label">Diagnóstico</div>
            <div class="rx-doc__value">{{ $rx->diagnosis }}</div>
        </section>
    @endif

    <ol class="rx-doc__items">
        @foreach ($rx->items as $item)
            <li class="rx-doc__rx-item">
                <div class="rx-doc__item-title">
                    {{ $item->line_number }}. {{ $item->medication_name }}
                    @if ($item->strength && ! str_contains(mb_strtolower($item->medication_name), mb_strtolower($item->strength)))
                        <span class="rx-doc__item-sub">{{ $item->strength }}</span>
                    @endif
                    @if ($item->presentation)<span class="rx-doc__item-sub">· {{ $item->presentation }}</span>@endif
                </div>
                <div class="rx-doc__item-line">
                    {{ collect([$item->dose, $item->frequency, $item->duration, $item->route ? 'vía '.mb_strtolower($item->route) : null])->filter()->implode(' · ') }}@if ($item->instructions) — {{ $item->instructions }}@endif
                </div>
            </li>
        @endforeach
    </ol>

    @if ($rx->general_instructions)
        <section class="rx-doc__block">
            <div class="rx-doc__label">Indicaciones generales</div>
            <div class="rx-doc__text">{{ $rx->general_instructions }}</div>
        </section>
    @endif

    <footer class="rx-doc__sign">
        @if ($signed && $signature !== '')
            <div class="rx-doc__signature">{{ $signature }}</div>
        @else
            <div class="rx-doc__signature rx-doc__signature--empty">&nbsp;</div>
        @endif
        <div class="rx-doc__sign-line"></div>
        <div class="rx-doc__sign-name">{{ $doctorName ?? '—' }}</div>
        <div class="rx-doc__muted">{{ $license ? 'Céd. Prof. '.$license : 'Cédula profesional no registrada' }}</div>
        @if ($signed)
            <div class="rx-doc__muted rx-doc__sign-meta">Firmada el {{ RxPresenter::date($rx->signed_at) }} · Folio de integridad {{ \Illuminate\Support\Str::limit($rx->content_hash, 12, '') }}</div>
        @else
            <div class="rx-doc__muted rx-doc__sign-meta">Borrador sin validez: pendiente de firma del doctor.</div>
        @endif
    </footer>
</article>
