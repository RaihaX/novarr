{{-- App\Mail\ReaderInvite in the shared frame: the invite, its button, when it expires, and the Android app when offered. --}}
@extends('emails.layout')

@php
    $mailTitle = "You're invited to Novarr";
    $preheader = "{$inviterName} invited you to read on Novarr.";
    $footer = 'Not expecting this? You can ignore it: nothing happens unless you open the link.';
@endphp

@section('content')
    @include('emails.components.heading', ['text' => "Hi {$name},", 'eyebrow' => "You're invited", 'tone' => 'info'])
    @include('emails.components.paragraph', ['text' => "{$inviterName} invited you to read on Novarr. Open the link to choose your sign-in name and password. Your reading progress and highlights are your own."])

    @include('emails.components.button', ['url' => $link, 'text' => 'Accept the invite', 'fallback' => false])
    @include('emails.components.paragraph', ['text' => "The link works until {$expires}. If the button does not work, copy this address into your browser:", 'muted' => true, 'gap' => 4])
    <p style="margin: 0; font-family: {{ \App\Mail\MailTheme::MONO }}; font-size: 12px; line-height: 1.6; word-break: break-all;"><a class="nv-link" href="{{ $link }}" style="color: #8EA2FF; text-decoration: none;">{{ $link }}</a></p>

    @if ($androidUrl)
        @include('emails.components.divider')
        @include('emails.components.paragraph', ['text' => 'Reading on an Android phone? After you have set your password, install the Novarr app:', 'gap' => 6])
        <p style="margin: 0 0 6px; font-family: {{ \App\Mail\MailTheme::MONO }}; font-size: 12px; line-height: 1.6; word-break: break-all;"><a class="nv-link" href="{{ $androidUrl }}" style="color: #8EA2FF; text-decoration: none;">{{ $androidUrl }}</a></p>
        @include('emails.components.paragraph', ['text' => 'Android will ask you to allow installing apps from your browser.', 'muted' => true, 'gap' => 0])
    @endif
@endsection
