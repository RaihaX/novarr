{{-- Plain-text part of App\Mail\RequestMail — same words as the HTML, the URL spelled out. --}}
@extends('emails.layout_text')

@php
    $footerLinks = ['Open Novarr' => $homeUrl];
@endphp

@section('content')
{!! $heading !!}

{!! $intro !!}
@if ($showCard)

{!! $seriesTitle !!} ({!! $mediumLabel !!})
@endif
@if ($items !== [])

@if ($itemsLabel)
{!! $itemsLabel !!}:
@endif
@foreach ($items as $item)
{!! ($item['tone'] ?? null) === 'danger' ? '✗' : '✓' !!} {!! $item['title'] !!}{!! !empty($item['meta']) ? ' — ' . $item['meta'] : '' !!}
@endforeach
@endif
@if ($quote)

{!! $quoteLabel !!}:
{!! $quote !!}
@endif

{!! $buttonText !!}:
{!! $buttonUrl !!}
@endsection
