{{--
    App\Mail\RequestMail (RequestCreated / RequestDeclined / RequestAvailable /
    BookInboxReply) in the shared frame: eyebrow + heading, the sentence, the
    series card (cover, title, medium), an optional list (what arrived) or
    quoted note / reason, and one button.
--}}
@extends('emails.layout')

@php
    $mailTitle = $heading;
    $preheader = $intro;
    $footerLinks = ['Open Novarr' => $homeUrl];
@endphp

@section('content')
    @include('emails.components.heading', ['text' => $heading, 'eyebrow' => $eyebrow, 'tone' => $tone])
    @include('emails.components.paragraph', ['text' => $intro])

    @if ($showCard)
    @include('emails.components.card', [
        'title' => $seriesTitle,
        'meta' => \Illuminate\Support\Str::ucfirst($mediumLabel),
        'cover' => $coverUrl,
        'tone' => $tone,
        'chip' => $chip,
    ])
    @endif

    @if ($items !== [])
        @include('emails.components.list', ['items' => $items, 'label' => $itemsLabel])
    @endif

    @if ($quote)
        @include('emails.components.quote', ['text' => $quote, 'label' => $quoteLabel])
    @endif

    @include('emails.components.button', ['url' => $buttonUrl, 'text' => $buttonText])
@endsection
