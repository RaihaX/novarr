{{-- The page-note sentence about devices and addresses (one place, so the Readers pages say the same). --}}
@php $keepDays = (int) config('novarr.auth_log_days', 180); @endphp
Devices and addresses are recorded for reads from this release on; addresses are {{ $keepDays > 0 ? 'cleared after ' . $keepDays . ' days' : 'kept' }}.
