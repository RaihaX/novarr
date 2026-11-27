{{--
    A paragraph of running text (escaped; newlines kept).
      $text   the words
      $muted  smaller, muted (a side note)
      $gap    space under it in px (default 16)
--}}
@php($T = \App\Mail\MailTheme::class)
@if (!empty($muted))
<p class="nv-muted" style="margin: 0 0 {{ $gap ?? 16 }}px; font-family: {{ $T::SANS }}; font-size: 13px; line-height: 1.6; color: #8B95A5;">{!! nl2br(e($text)) !!}</p>
@else
<p class="nv-body" style="margin: 0 0 {{ $gap ?? 16 }}px; {{ $T::body() }}">{!! nl2br(e($text)) !!}</p>
@endif
