{{-- Plain-text part of App\Mail\RequestMail — same words as the HTML, the URL spelled out. --}}
NOVARR.

{!! $heading !!}

{!! $intro !!}

{!! $seriesTitle !!} ({!! $mediumLabel !!})
@if ($quote)

{!! $quoteLabel !!}:
{!! $quote !!}
@endif

{!! $buttonText !!}:
{!! $buttonUrl !!}

{!! $footer !!}
