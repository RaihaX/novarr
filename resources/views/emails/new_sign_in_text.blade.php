{{-- Plain-text part of App\Mail\NewSignIn — same words as the HTML. --}}
@extends('emails.layout_text')

@php
    $footer = 'You get this email because your Novarr account signed in from a new device.';
@endphp

@section('content')
Hi {!! $name !!},

Your Novarr account was signed in to from a device it had not been used on before.

When: {!! $when !!}
Device: {!! $device !!}
Address: {!! $ip !!}
Came in on: {!! $where !!}

If this was not you, change your password on your Account page, or ask the person who runs this Novarr.

If this was you, there is nothing to do.

Your Account page:
{!! $accountUrl !!}
@endsection
