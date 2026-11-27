{{-- App\Mail\SignInCode in the shared frame: the code large and mono, what it is for, and where it was asked for. No links: a code mail is never a place to click. --}}
@extends('emails.layout')

@php
    $mailTitle = 'Your Novarr sign-in code';
    $preheader = "Your sign-in code is {$code}. It works for {$minutes} minutes.";
    $footer = 'You get this email because a sign-in code was asked for on your Novarr account.';
@endphp

@section('content')
    @include('emails.components.heading', ['text' => "Hi {$name},", 'eyebrow' => 'Sign-in code', 'tone' => 'info'])
    @include('emails.components.paragraph', ['text' => 'Your sign-in code is', 'gap' => 8])
    @include('emails.components.code', ['code' => $code])
    @include('emails.components.paragraph', ['text' => "Enter it {$purpose}. It works for {$minutes} minutes, once."])
    @include('emails.components.facts', ['facts' => [['Asked for from', $ip, true]]])
    @include('emails.components.paragraph', ['text' => 'If that was not you, someone knows your password: change it on your Account page, or ask the person who runs this Novarr.', 'muted' => true, 'gap' => 0])
@endsection
