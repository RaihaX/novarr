@extends('layouts.app')

@section('title', 'Sign-in code')

{{-- Nobody is signed in yet on this browser (as on the sign-in page). --}}
@section('signed-out', '1')

@php
    use App\Services\SecondStep;
    $has = fn(string $m) => in_array($m, $methods, true);
@endphp

@section('content')
<div class="auth-page">
    <div class="card auth-card auth-card-wide">
        <div class="card-body">
            <div class="auth-brand" aria-hidden="true">
                <x-brand-mark :size="40" />
            </div>
            <h1 class="auth-title">One more step</h1>
            <p class="auth-lead">Your password was right. On this address Novarr also asks for a second step.</p>

            @if(session('status'))
                <div class="alert alert-success" role="status">{{ session('status') }}</div>
            @endif

            {{-- 1. Passkey (resources/js/passkeys.js) --}}
            @if($has(SecondStep::PASSKEY))
                <section class="second-step" id="passkey" data-passkey-second-step
                         data-options-url="{{ route('login.second_step.passkey_options') }}" data-verify-url="{{ route('login.second_step.verify') }}">
                    <h2 class="second-step-title">Passkey</h2>
                    @error('passkey')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
                    <div class="alert alert-danger" role="alert" data-passkey-error hidden></div>
                    <button type="button" class="btn btn-primary w-100" data-passkey-button hidden><x-icon name="key" :size="14" /> Use a passkey</button>
                    <p class="form-text" data-passkey-unsupported hidden>This browser cannot use passkeys: use another way below.</p>
                </section>
            @endif

            {{-- 2. Authenticator app --}}
            @if($has(SecondStep::TOTP))
                <section class="second-step" id="totp">
                    <h2 class="second-step-title">Authenticator app</h2>
                    <form method="POST" action="{{ route('login.second_step.verify') }}" class="auth-form" data-turbo="false" novalidate>
                        @csrf
                        <input type="hidden" name="method" value="totp">
                        @error('totp')<div class="alert alert-danger" role="alert" id="totpError">{{ $message }}</div>@enderror
                        <div class="form-row">
                            <label for="totp-code" class="form-label">Code from your authenticator app</label>
                            <input type="text" name="code" id="totp-code" class="form-control code-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
                                   autocomplete="one-time-code" autocorrect="off" spellcheck="false" required @unless($has(SecondStep::PASSKEY)) autofocus @endunless>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Continue</button>
                    </form>
                </section>
            @endif

            {{-- 3. Emailed code: only when asked for --}}
            @if($has(SecondStep::EMAIL))
                <section class="second-step" id="email">
                    <h2 class="second-step-title">Emailed code</h2>
                    @error('email')<div class="alert alert-danger" role="alert" id="emailError">{{ $message }}</div>@enderror
                    @if($sent)
                        <p class="form-text">We sent a 6-digit code to {{ $emailHint }}. It works for 10 minutes.</p>
                        <form method="POST" action="{{ route('login.second_step.verify') }}" class="auth-form" data-turbo="false" novalidate>
                            @csrf
                            <input type="hidden" name="method" value="email">
                            <div class="form-row">
                                <label for="email-code" class="form-label">Code from the email</label>
                                <input type="text" name="code" id="email-code" class="form-control code-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
                                       autocomplete="one-time-code" autocorrect="off" spellcheck="false" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Continue</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('login.second_step.email') }}" class="auth-actions" data-turbo="false">
                        @csrf
                        <button type="submit" class="btn {{ $sent ? 'btn-ghost' : 'btn-secondary' }}" id="emailMeACode">{{ $sent ? 'Send another code' : 'Email me a code' }}</button>
                    </form>
                    @unless($sent)
                        <p class="form-text auth-note">It goes to {{ $emailHint }}.</p>
                    @endunless
                </section>
            @endif

            {{-- 4. Recovery code --}}
            @if($has(SecondStep::RECOVERY))
                <details class="second-step acct-disclosure" id="recovery" @if($errors->has('recovery')) open @endif>
                    <summary>Use a recovery code</summary>
                    <form method="POST" action="{{ route('login.second_step.verify') }}" class="auth-form" data-turbo="false" novalidate>
                        @csrf
                        <input type="hidden" name="method" value="recovery">
                        @error('recovery')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
                        <div class="form-row">
                            <label for="recovery-code" class="form-label">Recovery code</label>
                            <input type="text" name="code" id="recovery-code" class="form-control code-input" placeholder="xxxxx-xxxxx" maxlength="11"
                                   autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" required>
                            <div class="form-text">Each recovery code works once.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Continue</button>
                    </form>
                </details>
            @endif

            <p class="form-text auth-note">{{ $triesLeft }} wrong {{ $triesLeft === 1 ? 'code' : 'codes' }} and you start again. <a href="{{ route('login') }}">Start again</a></p>
        </div>
    </div>
    <p class="auth-help">Lost your phone or passkey? Ask the person who runs this Novarr to reset your second step.</p>
</div>
@endsection
