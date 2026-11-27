{{--
    App\Mail\SendToKindle: the ePub is the attachment and the subject is the
    Kindle library's title, so the body is only a short branded note: "Sent
    from Novarr" and the book.
--}}
@extends('emails.layout')

@php
    $mailTitle = $novelTitle;
    $preheader = "Sent from Novarr: {$novelTitle}";
    $footer = 'Sent from Novarr to the address you send your ePubs to.';
@endphp

@section('content')
    @include('emails.components.heading', ['text' => 'Sent from Novarr.', 'eyebrow' => 'Your ePub', 'tone' => 'success'])
    @include('emails.components.card', ['title' => $novelTitle, 'meta' => $author ? "by {$author}" : 'ePub attached', 'tone' => 'success'])
    @include('emails.components.paragraph', ['text' => 'The ePub is attached. Sent to a Kindle address, it appears in your Kindle library within a few minutes.', 'muted' => true, 'gap' => 0])
@endsection
