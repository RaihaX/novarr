{{-- Plain-text part of App\Mail\ReaderInvite — same words as the HTML, the URL spelled out. --}}
NOVARR.

Hi {!! $name !!},

{!! $inviterName !!} invited you to read on Novarr. Open the link to choose your sign-in name and password. Your reading progress and highlights are your own.

Accept the invite:
{!! $link !!}

The link works until {{ $expires }}.
@if ($androidUrl)

Reading on an Android phone? After you have set your password, install the Novarr app:
{!! $androidUrl !!}
Android will ask you to allow installing apps from your browser.
@endif

Not expecting this? You can ignore it: nothing happens unless you open the link.
