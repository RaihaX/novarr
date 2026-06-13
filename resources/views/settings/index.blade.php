@extends('layouts.app')

@section('content')
<h1 class="mb-4">Settings</h1>

@if(session('status'))
    <div class="alert alert-success py-2">{{ session('status') }}</div>
@endif

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                @if($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('settings.update') }}">
                    @csrf

                    @foreach($fields as $key => $field)
                        <div class="mb-3">
                            <label for="{{ $key }}" class="form-label">{{ $field['label'] }}</label>
                            <input type="{{ $field['type'] }}" name="{{ $key }}" id="{{ $key }}"
                                   class="form-control"
                                   value="{{ old($key, $field['value']) }}"
                                   @if(!empty($field['default'])) placeholder="{{ $field['default'] }}" @endif>
                            <div class="form-text">{{ $field['help'] }}</div>
                        </div>
                    @endforeach

                    <button type="submit" class="btn btn-primary">Save settings</button>
                </form>
            </div>
        </div>
        <p class="text-muted mt-3" style="font-size: 13px;">
            Saved values override the matching <code>.env</code> entries. Leave a field blank to fall back to the <code>.env</code> default.
        </p>
    </div>
</div>
@endsection
