{{--
    App\Mail\AndroidReleaseMail in the shared frame: eyebrow + heading, the
    sentence, the release notes as a list ("What's new"), one button to the
    download page; the footer says why and links "Stop app update emails".
--}}
@extends('emails.layout')

@php
    $mailTitle = $heading;
    $preheader = $notes !== [] ? "What's new: " . implode(' ', $notes) : $intro;
    $footerLinks = array_filter(['Open Novarr' => $homeUrl]);
@endphp

@section('content')
    @include('emails.components.heading', ['text' => $heading, 'eyebrow' => 'Android app', 'tone' => 'info'])
    @if ($greeting)
        @include('emails.components.paragraph', ['text' => $greeting, 'gap' => 8])
    @endif
    @include('emails.components.paragraph', ['text' => $intro])

    @if ($items !== [])
        @include('emails.components.list', ['items' => $items, 'label' => "What's new"])
    @endif

    @include('emails.components.button', ['url' => $downloadUrl, 'text' => 'Update in the app'])
@endsection

@section('footer_note')
{{ $footer }}
@if ($unsubscribeUrl)
· <a class="nv-link" href="{{ $unsubscribeUrl }}" data-mail-unsubscribe style="color: #8EA2FF; text-decoration: none;">Stop app update emails</a>
@endif
@endsection
