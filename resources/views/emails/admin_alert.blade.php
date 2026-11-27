{{--
    App\Mail\AdminAlertMail in the shared frame: eyebrow + heading, the
    sentence, then whichever of card / list / facts / note the alert has,
    and the button. $m = AdminAlertMail::viewModel().
--}}
@extends('emails.layout')

@php
    $mailTitle = $m['heading'];
    $preheader = $m['intro'];
    $footer = $m['footer'];
    $footerLinks = ['Open Novarr' => $m['home_url']];
@endphp

@section('content')
    @include('emails.components.heading', ['text' => $m['heading'], 'eyebrow' => $m['eyebrow'], 'tone' => $m['tone']])
    @include('emails.components.paragraph', ['text' => $m['intro']])

    @if ($m['title'] !== null)
        @include('emails.components.card', [
            'title' => $m['title'],
            'meta' => $m['meta'],
            'cover' => $m['cover'],
            'url' => $m['title_url'],
            'tone' => $m['tone'],
            'chip' => $m['chip'],
        ])
    @endif

    @if ($m['items'] !== [])
        @include('emails.components.list', ['items' => $m['items'], 'label' => $m['items_label'], 'more' => $m['more']])
    @endif

    @if ($m['facts'] !== [])
        @include('emails.components.facts', ['facts' => $m['facts']])
    @endif

    @if (!empty($m['note']))
        @include('emails.components.quote', ['text' => $m['note'], 'label' => $m['note_label'], 'tone' => $m['tone']])
    @endif

    @if ($m['button'] !== null)
        @include('emails.components.button', ['url' => $m['button_url'], 'text' => $m['button'], 'secondary' => $m['secondary']])
    @endif
@endsection
