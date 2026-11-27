{{-- Plain-text part of App\Mail\TestMail. --}}
@extends('emails.layout_text')

@php
    $footer = 'You get this email because "Send test email" was pressed in Novarr\'s Settings.';
    $footerLinks = ['Open Novarr' => $homeUrl];
@endphp

@section('content')
Mail is working

{!! \App\Mail\TestMail::MESSAGE !!}

Sent: {!! $sentAt !!}
Mailer: {!! $mailer !!}
@endsection
