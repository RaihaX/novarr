{{-- Plain-text part of App\Mail\SignInCode — same words as the HTML. --}}
NOVARR.

Hi {!! $name !!},

Your sign-in code is {!! $code !!}

Enter it {!! $purpose !!}. It works for {!! $minutes !!} minutes, once.

It was asked for from the address {!! $ip !!}. If that was not you, someone knows your password: change it on your Account page, or ask the person who runs this Novarr.
