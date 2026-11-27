{{-- App\Mail\TestMail: Settings → "Send test email". --}}
@extends('emails.layout')

@php
    $mailTitle = 'Novarr test email';
    $preheader = \App\Mail\TestMail::MESSAGE;
    $footer = 'You get this email because "Send test email" was pressed in Novarr\'s Settings.';
    $footerLinks = ['Open Novarr' => $homeUrl];
@endphp

@section('content')
    @include('emails.components.heading', ['text' => 'Mail is working', 'eyebrow' => 'Test email', 'tone' => 'success'])
    @include('emails.components.paragraph', ['text' => \App\Mail\TestMail::MESSAGE])
    @include('emails.components.facts', ['facts' => [['Sent', $sentAt], ['Mailer', $mailer, true]]])
    @include('emails.components.paragraph', ['text' => 'Request emails, owner alerts and the daily summary all go out the same way.', 'muted' => true, 'gap' => 0])
@endsection
