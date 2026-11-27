{{-- App\Mail\NewSignIn in the shared frame: what signed in (facts table), what to do if it was not you, and the Account page. --}}
@extends('emails.layout')

@php
    $mailTitle = 'New sign-in to your Novarr account';
    $preheader = "A new device signed in to your Novarr account: {$device}.";
    $footer = 'You get this email because your Novarr account signed in from a new device.';
@endphp

@section('content')
    @include('emails.components.heading', ['text' => "Hi {$name},", 'eyebrow' => 'New sign-in', 'tone' => 'warning'])
    @include('emails.components.paragraph', ['text' => 'Your Novarr account was signed in to from a device it had not been used on before.'])
    @include('emails.components.facts', ['facts' => [
        ['When', $when],
        ['Device', $device],
        ['Address', $ip, true],
        ['Came in on', $where],
    ]])
    @include('emails.components.paragraph', ['text' => 'If this was not you, change your password on your Account page, or ask the person who runs this Novarr.', 'gap' => 10])
    @include('emails.components.paragraph', ['text' => 'If this was you, there is nothing to do.', 'muted' => true, 'gap' => 0])
    @include('emails.components.button', ['url' => $accountUrl, 'text' => 'Open your Account page', 'fallback' => false])
@endsection
