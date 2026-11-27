{{-- Plain-text part of App\Mail\AdminAlertMail — same words as the HTML, the URLs spelled out. --}}
@extends('emails.layout_text')

@php
    $footer = $m['footer'];
    $footerLinks = ['Open Novarr' => $m['home_url']];
@endphp

@section('content')
{!! $m['heading'] !!}

{!! $m['intro'] !!}
@if ($m['title'] !== null)

{!! $m['title'] !!}{!! !empty($m['meta']) ? ' — ' . $m['meta'] : '' !!}{!! !empty($m['chip']) ? ' [' . $m['chip'] . ']' : '' !!}
@endif
@if ($m['items'] !== [])

@if (!empty($m['items_label']))
{!! $m['items_label'] !!}:
@endif
@foreach ($m['items'] as $item)
- {!! $item['title'] !!}{!! !empty($item['meta']) ? ' — ' . $item['meta'] : '' !!}{!! !empty($item['status']) ? ' [' . $item['status'] . ']' : '' !!}
@endforeach
@if (!empty($m['more']))
{!! $m['more'] !!}
@endif
@endif
@if ($m['facts'] !== [])

@foreach ($m['facts'] as $key => $fact)
{!! is_array($fact) ? $fact[0] : $key !!}: {!! is_array($fact) ? $fact[1] : $fact !!}
@endforeach
@endif
@if (!empty($m['note']))

{!! !empty($m['note_label']) ? $m['note_label'] . ':' . "\n" : '' !!}{!! $m['note'] !!}
@endif
@if ($m['button'] !== null)

{!! $m['button'] !!}:
{!! $m['button_url'] !!}
@if (!empty($m['secondary']['url']))
{!! $m['secondary']['text'] !!}: {!! $m['secondary']['url'] !!}
@endif
@endif
@endsection
