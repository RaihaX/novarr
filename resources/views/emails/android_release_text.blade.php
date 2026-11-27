{{-- Plain-text part of App\Mail\AndroidReleaseMail — same words as the HTML, the URLs spelled out. --}}
@extends('emails.layout_text')

@php
    $textTitle = 'Android app';
    $footerLinks = array_filter(['Open Novarr' => $homeUrl]);
@endphp

@section('content')
{!! $heading !!}

@if ($greeting)
{!! $greeting !!}

@endif
{!! $intro !!}
@if ($notes !== [])

What's new:
@foreach ($notes as $note)
- {!! $note !!}
@endforeach
@endif

Update in the app:
{!! $downloadUrl !!}
@endsection

@section('footer_note')
{!! $footer !!}
@if ($unsubscribeUrl)
Stop app update emails: {!! $unsubscribeUrl !!}
@endif
@endsection
