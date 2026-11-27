{{--
    The body's heading: an optional eyebrow (a small mono label with a tone
    dot: "● DOWNLOADS") over the h1.
      $text     the heading
      $eyebrow  optional label above it
      $tone     info | success | warning | danger (the eyebrow's colour)
--}}
@php($T = \App\Mail\MailTheme::class)
@if (!empty($eyebrow))
    <p data-mail-eyebrow data-tone="{{ $tone ?? 'info' }}" style="margin: 0 0 10px; font-family: {{ $T::MONO }}; font-size: 11px; line-height: 16px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: {{ $T::tone($tone ?? null) }};"><span style="font-size: 9px;">&#9679;</span>&nbsp; {{ $eyebrow }}</p>
@endif
<h1 class="nv-text" style="margin: 0 0 12px; font-family: {{ $T::SANS }}; font-size: 21px; line-height: 1.3; font-weight: 600; letter-spacing: -0.01em; color: #E8EBF0;">{{ $text }}</h1>
