{{-- Plain-text part of App\Mail\SendToKindle. --}}
@extends('emails.layout_text')

@php
    $footer = 'Sent from Novarr to the address you send your ePubs to.';
@endphp

@section('content')
Sent from Novarr.

{!! $novelTitle !!}{!! $author ? ' by ' . $author : '' !!}

The ePub is attached. Sent to a Kindle address, it appears in your Kindle library within a few minutes.
@endsection
