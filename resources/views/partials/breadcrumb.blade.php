{{--
    Breadcrumb trail items for the layout's page-level breadcrumb
    (layouts/app.blade.php wraps these in <nav><ol>).

    Usage (System pages):
        @section('breadcrumb')
            @include('partials.breadcrumb', ['trail' => [
                ['System'],
                ['Logs', route('logs.index')],
                [$filename],
            ]])
        @endsection

    Each entry is [label, url?, mono?]. The last entry is the current page;
    pass mono = true for identifiers such as a file name.
--}}
@foreach($trail as $i => $crumb)
    @php [$crumbLabel, $crumbUrl, $crumbMono] = [$crumb[0], $crumb[1] ?? null, $crumb[2] ?? false]; $crumbLast = $i === count($trail) - 1; @endphp
    <li @if($crumbMono) class="is-mono" @endif @if($crumbLast) aria-current="page" @endif>
        @if($crumbUrl && !$crumbLast)
            <a href="{{ $crumbUrl }}">{{ $crumbLabel }}</a>
        @else
            <span>{{ $crumbLabel }}</span>
        @endif
    </li>
@endforeach
