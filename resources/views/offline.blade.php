@extends('layouts.app')

@section('title', 'Offline')

@section('content')
<div class="offline-state">
    <x-brand-mark variant="mono" :size="48" class="offline-mark" />
    <h1 class="offline-title">You're offline</h1>
    <p class="offline-body">This page isn't available without a connection.</p>
    <p class="offline-body">Chapters you've already opened are still readable — head back and pick one up.</p>
    {{-- The Downloads page is part of the cached app shell; the Library is not. --}}
    <a href="{{ route('library') }}" class="btn btn-primary">Open Downloads</a>
</div>
@endsection
