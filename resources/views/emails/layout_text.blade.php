{{--
    The plain-text frame of every Novarr email (the twin of emails.layout):
    "NOVARR." (+ " — $textTitle"), the body, then "--", the footer links
    spelled out and the reason line. Section 'content' is the body;
    'footer_note' replaces the $footer line.
--}}
NOVARR.{!! !empty($textTitle) ? ' — ' . $textTitle : '' !!}

{!! trim($__env->yieldContent('content')) !!}

--
@foreach (array_filter((array) ($footerLinks ?? [])) as $label => $href)
{!! $label !!}: {!! $href !!}
@endforeach
@hasSection('footer_note')
{!! trim($__env->yieldContent('footer_note')) !!}
@else
{!! $footer ?? 'Sent by Novarr.' !!}
@endif
