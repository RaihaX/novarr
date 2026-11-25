@extends('layouts.app')

@section('title', 'Stats')

@section('content')
<div class="page-toolbar">
    <h1 class="page-title mb-0">Reading stats</h1>
    <span class="mono-muted">Last {{ $window_days }} days</span>
</div>

@include('stats._body')
@endsection
