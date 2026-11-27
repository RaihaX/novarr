{{--
    A quoted note or reason: a small label over the words, 2px rule.
      $text   the note (newlines kept)
      $label  optional label ("Sam's note", "Reason")
      $tone   optional rule colour (default info)
--}}
@php($T = \App\Mail\MailTheme::class)
@if (!empty($label))
    <p class="nv-muted" style="margin: 0 0 6px; {{ $T::label() }} line-height: 14px; color: #8B95A5;">{{ $label }}</p>
@endif
<p class="nv-body" data-mail-quote style="margin: 0 0 18px; padding: 2px 0 2px 12px; border-left: 2px solid {{ $T::tone($tone ?? null) }}; {{ $T::body() }}">{!! nl2br(e($text)) !!}</p>
