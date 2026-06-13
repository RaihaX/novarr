@extends('layouts.app')

@section('content')
<div class="text-center py-5">
    <h1 class="mb-3">You're offline</h1>
    <p class="text-muted">This page isn't available without a connection.</p>
    <p class="text-muted">Chapters you've already opened are still readable — head back and pick one up.</p>
    <a href="{{ route('novels.index') }}" class="btn btn-primary mt-2">Back to Novels</a>
</div>
@endsection
