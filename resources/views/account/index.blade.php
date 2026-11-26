@extends('layouts.app')

@section('title', 'Account')

@php
    use App\DevicePairing;
    $android = \App\Support\AndroidApp::available();
@endphp

@section('content')
<div class="acct-shell account-page">

    {{-- Who you are, compactly: the title and the content share one left edge. --}}
    <header class="acct-head">
        <h1 class="page-title">Account</h1>
        <p class="acct-identity" id="accountIdentity">
            <span class="acct-identity-name">{{ $user->name }}</span>
            <span class="acct-identity-username" title="Your sign-in name">{{ $user->username }}</span>
            <span class="badge {{ $user->isAdmin() ? 'badge-accent' : 'badge-muted' }}">{{ $user->isAdmin() ? 'Admin' : 'Reader' }}</span>
            @if($user->email)
                <span class="acct-identity-email">{{ $user->email }}</span>
            @endif
        </p>
    </header>

    <div class="acct-grid">
        {{-- ============ Left: you ============ --}}
        <div class="acct-col">
            <section class="card form-panel acct-card" aria-labelledby="profileTitle">
                <div class="card-body">
                    <h2 class="acct-card-title" id="profileTitle">You</h2>
                    <form method="POST" action="{{ route('account.profile') }}">
                        @csrf
                        <div class="form-row">
                            <label for="name" class="form-label">Display name</label>
                            <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" maxlength="80" required autocomplete="name">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Shown in the menu and to the admin. You sign in as <strong>{{ $user->username }}</strong>{{ $user->email ? ' or with your email' : '' }}.</div>
                        </div>
                        <div class="acct-actions">
                            <button type="submit" class="btn btn-primary">Save name</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card form-panel acct-card" aria-labelledby="passwordTitle">
                <div class="card-body">
                    <h2 class="acct-card-title" id="passwordTitle">Password</h2>
                    <form method="POST" action="{{ route('account.password') }}">
                        @csrf
                        {{-- For password managers: which account this is. --}}
                        <input type="text" name="username" value="{{ $user->username }}" autocomplete="username" hidden readonly>
                        <div class="form-row">
                            <label for="current_password" class="form-label">Current password</label>
                            <input type="password" name="current_password" id="current_password" class="form-control @error('current_password', 'password') is-invalid @enderror" autocomplete="current-password" required>
                            @error('current_password', 'password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-row">
                            <label for="new_password" class="form-label">New password</label>
                            <input type="password" name="password" id="new_password" class="form-control @error('password', 'password') is-invalid @enderror" autocomplete="new-password" minlength="12" required aria-describedby="new_password_help">
                            @error('password', 'password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text" id="new_password_help">At least 12 characters, and not your name or email.</div>
                        </div>
                        <div class="form-row">
                            <label for="password_confirmation" class="form-label">New password again</label>
                            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" autocomplete="new-password" required>
                        </div>
                        <div class="acct-actions">
                            <button type="submit" class="btn btn-primary">Change password</button>
                        </div>
                    </form>
                </div>
            </section>

            {{-- "Confirm it's you" (App\Support\RecentConfirmation): the password at home, a second step on the public address. --}}
            @php
                $sec = $security;
                $confirmBag = $errors->getBag('confirm');
                $confirmNeeded = session(\App\Http\Controllers\AccountSecurityController::FLASH_CONFIRM_NEEDED) || $confirmBag->any();
            @endphp
            @unless($sec->confirmed)
                <section class="card form-panel acct-card {{ $confirmNeeded ? 'is-highlighted' : '' }}" aria-labelledby="confirmTitle" id="confirm">
                    <div class="card-body">
                        <h2 class="acct-card-title" id="confirmTitle">Confirm it's you</h2>
                        <p class="acct-text">Changing your password or sign-in steps, making a catalogue password and linking a device need this first. It lasts 10 minutes on this browser.</p>
                        @foreach($confirmBag->all() as $message)
                            <div class="alert alert-danger" role="alert">{{ $message }}</div>
                        @endforeach
                        @if(!$sec->confirmWithSecondStep)
                            <form method="POST" action="{{ route('account.confirm') }}">
                                @csrf
                                <input type="text" name="username" value="{{ $user->username }}" autocomplete="username" hidden readonly>
                                <div class="form-row">
                                    <label for="confirm_password" class="form-label">Your password</label>
                                    <input type="password" name="password" id="confirm_password" class="form-control" autocomplete="current-password" required>
                                </div>
                                <div class="acct-actions"><button type="submit" class="btn btn-primary">Confirm</button></div>
                            </form>
                        @else
                            @php $cm = $sec->confirmMethods; @endphp
                            @if(in_array('passkey', $cm, true))
                                <div data-passkey-confirm data-options-url="{{ route('account.confirm.passkey_options') }}" data-verify-url="{{ route('account.confirm') }}">
                                    <div class="alert alert-danger" role="alert" data-passkey-error hidden></div>
                                    <button type="button" class="btn btn-primary" data-passkey-button hidden><x-icon name="key" :size="14" /> Confirm with a passkey</button>
                                </div>
                            @endif
                            @if(in_array('totp', $cm, true) || in_array('email', $cm, true) || in_array('recovery', $cm, true))
                                <form method="POST" action="{{ route('account.confirm') }}" class="link-form">
                                    @csrf
                                    <div class="form-row">
                                        <label for="confirm_method" class="form-label">Use</label>
                                        <select name="method" id="confirm_method" class="form-control">
                                            @if(in_array('totp', $cm, true))<option value="totp">A code from your authenticator app</option>@endif
                                            @if(in_array('email', $cm, true))<option value="email" @selected(session('status') && str_contains((string) session('status'), 'emailed'))>The code we email you</option>@endif
                                            @if(in_array('recovery', $cm, true))<option value="recovery">A recovery code</option>@endif
                                        </select>
                                    </div>
                                    <div class="form-row">
                                        <label for="confirm_code" class="form-label">Code</label>
                                        <input type="text" name="code" id="confirm_code" class="form-control code-input" autocomplete="one-time-code" autocapitalize="none" spellcheck="false" maxlength="11" required>
                                    </div>
                                    <div class="acct-actions"><button type="submit" class="btn btn-primary">Confirm</button></div>
                                </form>
                            @endif
                            @if(in_array('email', $cm, true))
                                <form method="POST" action="{{ route('account.confirm.email') }}" class="acct-actions">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm">Email me a code</button>
                                    <span class="form-text m-0">It goes to {{ $sec->emailHint }}.</span>
                                </form>
                            @endif
                        @endif
                    </div>
                </section>
            @endunless

            {{-- The second step (App\Http\Controllers\AccountSecurityController) --}}
            <section class="card form-panel acct-card" aria-labelledby="securityTitle" id="security">
                <div class="card-body">
                    <h2 class="acct-card-title" id="securityTitle">Sign-in steps</h2>
                    <p class="acct-text">On the public address Novarr asks for a second step after your password: a passkey, a code from an authenticator app, a code we email you, or a recovery code. On your home network the password is enough. Set them up here from either.</p>

                    {{-- Passkeys --}}
                    <h3 class="acct-subtitle" id="passkeys">Passkeys</h3>
                    @if($sec->passkeys->isEmpty())
                        <p class="form-text">No passkeys yet. A passkey also signs you in on its own, without your password.</p>
                    @else
                        <ul class="account-devices" id="passkeyList">
                            @foreach($sec->passkeys as $passkey)
                                <li class="account-device">
                                    <span class="account-device-main">
                                        <span class="account-device-name">{{ $passkey->name }}</span>
                                        <span class="account-device-meta">For {{ $passkey->rp_id }} · added {{ $passkey->created_at?->format('j M Y') }} · {{ $passkey->last_used_at ? 'last used ' . $passkey->last_used_at->diffForHumans() : 'not used yet' }}</span>
                                    </span>
                                    <form method="POST" action="{{ route('account.passkeys.destroy', $passkey->id) }}" class="account-device-action"
                                          data-confirm="Remove the passkey “{{ $passkey->name }}”? It stops working for Novarr." data-confirm-title="Remove passkey?" data-confirm-text="Remove">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Remove</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if($sec->passkeysHere)
                        <div class="link-form" id="passkeyAdd" data-passkey-register
                             data-options-url="{{ route('account.passkeys.options') }}" data-store-url="{{ route('account.passkeys.store') }}">
                            <div class="alert alert-danger" role="alert" data-passkey-error hidden></div>
                            <label for="passkey_name" class="form-label">Name it</label>
                            <div class="link-form-row">
                                <input type="text" id="passkey_name" class="form-control" maxlength="80" placeholder="Phone, laptop, security key" data-passkey-name>
                                <button type="button" class="btn btn-primary" data-passkey-button hidden>Add a passkey</button>
                            </div>
                            <div class="form-text">It works on {{ $sec->rpId }} only. <span data-passkey-unsupported hidden>This browser cannot make passkeys.</span></div>
                        </div>
                    @else
                        <p class="form-text" id="passkeyUnavailable">{{ \App\Services\Passkeys::UNAVAILABLE_MESSAGE }} Open Novarr at its https address to add one.</p>
                    @endif

                    {{-- Authenticator app --}}
                    <h3 class="acct-subtitle" id="authenticator">Authenticator app</h3>
                    @php $totpBag = $errors->getBag('authenticator'); @endphp
                    @if($sec->totpSetup)
                        <p class="acct-text">Scan this with your authenticator app (or type the key), then enter the 6-digit code it shows.</p>
                        <div class="totp-qr" id="totpQr">{!! $sec->totpSetup->qr !!}</div>
                        <p class="form-text">Key: <span class="secret-once" id="totpSecret">{{ $sec->totpSetup->secret }}</span></p>
                        <form method="POST" action="{{ route('account.authenticator.store') }}" class="link-form">
                            @csrf
                            <label for="totp_code" class="form-label">Code from the app</label>
                            <div class="link-form-row">
                                <input type="text" name="code" id="totp_code" class="form-control code-input @if($totpBag->has('code')) is-invalid @endif" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required>
                                <button type="submit" class="btn btn-primary">Turn on</button>
                            </div>
                            @if($totpBag->has('code'))<div class="invalid-feedback d-block">{{ $totpBag->first('code') }}</div>@endif
                        </form>
                    @elseif($sec->totpOn)
                        <div class="acct-actions">
                            <span class="badge badge-accent">On</span>
                            <form method="POST" action="{{ route('account.authenticator.start') }}" class="d-inline">@csrf<button type="submit" class="btn btn-secondary btn-sm">Move to another app</button></form>
                            <form method="POST" action="{{ route('account.authenticator.destroy') }}" class="d-inline"
                                  data-confirm="Remove the authenticator app? Its codes stop working for Novarr." data-confirm-title="Remove authenticator app?" data-confirm-text="Remove">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger btn-sm">Remove</button>
                            </form>
                        </div>
                    @else
                        <p class="form-text">Any app that makes 6-digit codes works (1Password, Google Authenticator, Authy…).</p>
                        <form method="POST" action="{{ route('account.authenticator.start') }}" class="acct-actions">@csrf<button type="submit" class="btn btn-secondary">Set up an authenticator app</button></form>
                    @endif

                    {{-- Emailed codes --}}
                    <h3 class="acct-subtitle" id="email-codes">Emailed codes</h3>
                    @if(!$sec->email)
                        <p class="form-text" id="emailCodesState">Not available: {{ $user->email ? 'email is not set up on this server.' : 'your account has no email. Ask the person who runs this Novarr to add one.' }}</p>
                    @else
                        <p class="form-text" id="emailCodesState">{{ $sec->emailOn ? 'On: codes go to ' . $sec->email . '.' : 'Off.' }}</p>
                        @if($sec->strong)
                            <form method="POST" action="{{ route('account.email_codes') }}" class="acct-actions">
                                @csrf
                                <input type="hidden" name="on" value="{{ $sec->emailOn ? '0' : '1' }}">
                                <button type="submit" class="btn btn-secondary btn-sm">{{ $sec->emailOn ? 'Turn emailed codes off' : 'Turn emailed codes on' }}</button>
                            </form>
                        @endif
                    @endif

                    {{-- Recovery codes --}}
                    <h3 class="acct-subtitle" id="recovery">Recovery codes</h3>
                    @if($codes = session(\App\Http\Controllers\AccountSecurityController::FLASH_RECOVERY_CODES))
                        <div class="invite-result is-sent" role="status" id="recoveryCodes">
                            <p class="invite-result-text">Save these now (print them or keep them in a password manager): they are not shown again. Each one works once, instead of a passkey or code.</p>
                            <ul class="recovery-codes">
                                @foreach($codes as $code)<li>{{ $code }}</li>@endforeach
                            </ul>
                        </div>
                    @endif
                    @if($sec->strong)
                        <p class="form-text" id="recoveryState">{{ $sec->recoveryLeft > 0 ? $sec->recoveryLeft . ' unused ' . Str::plural('code', $sec->recoveryLeft) . '.' : 'None yet: make some in case you lose your passkey or phone.' }}</p>
                        <form method="POST" action="{{ route('account.recovery_codes') }}" class="acct-actions"
                              @if($sec->recoveryLeft > 0) data-confirm="Make new recovery codes? The old ones stop working." data-confirm-title="New recovery codes?" data-confirm-text="Make new codes" @endif>
                            @csrf
                            <button type="submit" class="btn btn-secondary btn-sm">{{ $sec->recoveryLeft > 0 ? 'Make new codes' : 'Make recovery codes' }}</button>
                        </form>
                    @else
                        <p class="form-text" id="recoveryState">Once you have a passkey or an authenticator app you can make recovery codes.</p>
                    @endif
                </div>
            </section>

            {{-- "Send to my Kindle / email" (App\Services\SendEpub): where a book's or novel's ePub is emailed. --}}
            <section class="card form-panel acct-card" aria-labelledby="sendToTitle" id="send-to">
                <div class="card-body">
                    <h2 class="acct-card-title" id="sendToTitle">Send to Kindle or email</h2>
                    <p class="acct-text">The address <strong>Send to my Kindle / email</strong> on a book or novel emails its ePub to: your Kindle’s Send-to-Kindle address (name_xxx@kindle.com), or any email.</p>
                    <form method="POST" action="{{ route('account.send_to') }}" class="acct-actions">
                        @csrf
                        <label for="send_to_email" class="form-label visually-hidden">Send-to address</label>
                        <input type="email" name="send_to_email" id="send_to_email" class="form-control @error('send_to_email') is-invalid @enderror"
                               value="{{ old('send_to_email', $user->send_to_email) }}" placeholder="name_xxx@kindle.com" autocomplete="email" maxlength="191">
                        @error('send_to_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                    </form>
                    <div class="alert alert-warning mb-2" role="note">
                        <strong>Sending to a Kindle?</strong> Amazon only accepts email from addresses you approve. Add
                        <strong>{{ \App\Services\SendEpub::fromAddress() }}</strong> to your approved list first: amazon.com → Account → <em>Content Library</em> / <em>Content &amp; Devices</em> → <em>Preferences</em> → <em>Personal Document Settings</em> → <em>Approved Personal Document E-mail List</em> → <em>Add a new approved e-mail address</em>. Without it, Amazon drops the book without telling you.
                    </div>
                    @if(!$user->send_to_email && $user->isAdmin() && \App\Services\SendEpub::address($user))
                        <p class="form-text mb-0">Until you set one, the Kindle email in Settings is used ({{ \App\Services\SendEpub::address($user) }}).</p>
                    @endif
                </div>
            </section>

            {{-- The reader's daily summary email (novel:email-summary); the owner's is in Settings. --}}
            @if($summary)
                <section class="card form-panel acct-card" aria-labelledby="summaryEmailTitle" id="summary-email">
                    <div class="card-body">
                        <h2 class="acct-card-title" id="summaryEmailTitle">Daily summary email</h2>
                        <p class="acct-text">New chapters and completed series across the library, once a day.</p>
                        @if(!$summary->email)
                            <p class="form-text" id="summaryEmailState">Not available: your account has no email. Ask the person who runs this Novarr to add one.</p>
                        @elseif(!$summary->serverOn)
                            <p class="form-text" id="summaryEmailState">Switched off for this server by the person who runs this Novarr.</p>
                        @else
                            <p class="form-text" id="summaryEmailState">{{ $summary->on ? 'On: daily at ' . $summary->time . ' to ' . $summary->email . '.' : 'Off.' }}</p>
                            <form method="POST" action="{{ route('account.summary_email') }}" class="acct-actions">
                                @csrf
                                <input type="hidden" name="on" value="{{ $summary->on ? '0' : '1' }}">
                                <button type="submit" class="btn btn-secondary btn-sm">{{ $summary->on ? 'Turn the daily summary off' : 'Turn the daily summary on' }}</button>
                            </form>
                        @endif
                    </div>
                </section>
            @endif

            {{-- Own sign-ins and failed attempts (App\AuthEvent): spot one that was not you. --}}
            <section class="card form-panel acct-card" aria-labelledby="signInsTitle" id="sign-ins">
                <div class="card-body">
                    <h2 class="acct-card-title" id="signInsTitle">Recent sign-ins</h2>
                    @if($signIns->isEmpty())
                        <div class="acct-empty">
                            <x-icon name="users" :size="18" />
                            <p>No sign-ins recorded yet.</p>
                        </div>
                    @else
                        <ul class="account-devices history-list" id="signInsList">
                            @foreach($signIns as $event)
                                <li class="account-device history-row {{ $event->isFailure() ? 'is-failure' : '' }}">
                                    <span class="account-device-main">
                                        <span class="account-device-name">{{ $event->event === \App\AuthEvent::SIGN_IN ? 'Signed in' : 'Failed attempt' }} · {{ $event->device() }}</span>
                                        <span class="account-device-meta">
                                            <time datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $event->occurred_at->timezone(config('app.timezone'))->format('j M Y, H:i') }}</time>
                                            · {{ $event->ip }} · {{ $event->public ? 'public address' : 'home network' }}
                                        </span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="form-text acct-footnote">If one of these was not you, change your password above.</p>
                    @endif
                </div>
            </section>
        </div>

        {{-- ============ Right: devices ============ --}}
        <div class="acct-col">
            {{-- Link a device (Kindle / KOReader): code → which device → confirm --}}
            <section class="card form-panel acct-card" aria-labelledby="linkTitle" id="link">
                <div class="card-body">
                    <h2 class="acct-card-title" id="linkTitle">Link a device</h2>
                    @if($pairing && $pairing->user_id === null && $pairing->expires_at->isFuture())
                        <p class="acct-text">Link <strong>{{ $pairing->displayName() }}</strong> (code <span class="acct-code">{{ DevicePairing::formatCode($pairing->code) }}</span>) to your account? It will read and sync as {{ $user->name }}.</p>
                        <form method="POST" action="{{ route('account.link.confirm') }}" class="acct-actions">
                            @csrf
                            <input type="hidden" name="pairing" value="{{ $pairing->id }}">
                            <input type="hidden" name="code" value="{{ $pairing->code }}">
                            <button type="submit" class="btn btn-primary">Link this device</button>
                            <a href="{{ route('account') }}#link" class="btn btn-ghost">Cancel</a>
                        </form>
                    @else
                        <p class="acct-text">On the device, choose Link with a code: it shows a 6-character code. Type it here.</p>
                        <form method="POST" action="{{ route('account.link') }}" class="link-form">
                            @csrf
                            <label for="code" class="form-label">Code</label>
                            <div class="link-form-row">
                                <input type="text" name="code" id="code" class="form-control link-code-input @error('code', 'link') is-invalid @enderror"
                                       value="{{ old('code') }}" placeholder="K7M 4QX" maxlength="9" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" required
                                       @error('code', 'link') aria-describedby="code-error" @enderror>
                                <button type="submit" class="btn btn-primary">Next</button>
                            </div>
                            @error('code', 'link')<div class="invalid-feedback d-block" id="code-error">{{ $message }}</div>@enderror
                        </form>
                    @endif
                </div>
            </section>

            {{-- Web browsers signed in to this account (App\Services\BrowserSessions) --}}
            <section class="card form-panel acct-card" aria-labelledby="browsersTitle" id="browsers">
                <div class="card-body">
                    <h2 class="acct-card-title" id="browsersTitle">Signed-in browsers</h2>
                    <ul class="account-devices" id="browsersList">
                        @foreach($browsers as $browser)
                            <li class="account-device {{ $browser->current ? 'is-current' : '' }}">
                                <span class="account-device-main">
                                    <span class="account-device-name">
                                        {{ $browser->device }}
                                        @if($browser->current)
                                            <span class="badge badge-accent">This browser</span>
                                        @endif
                                    </span>
                                    <span class="account-device-meta">
                                        {{ $browser->ip }} · {{ $browser->public ? 'public address' : 'home network' }}
                                    </span>
                                    <span class="account-device-meta">
                                        Signed in {{ $browser->signed_in_at?->timezone(config('app.timezone'))->format('j M Y') }}
                                        · last active {{ $browser->last_active_at?->diffForHumans() }}
                                    </span>
                                </span>
                                @unless($browser->current)
                                    <form method="POST" action="{{ route('account.browsers.sign_out', $browser->id) }}" class="account-device-action" data-turbo="false"
                                          data-confirm="Sign out {{ $browser->device }}? Your other browsers stay signed in." data-confirm-title="Sign out this browser?" data-confirm-text="Sign out">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Sign out {{ $browser->device }}">Sign out</button>
                                    </form>
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                    @if($browsers->where('current', false)->isNotEmpty())
                        <form method="POST" action="{{ route('account.browsers.sign_out_others') }}" class="acct-actions" data-turbo="false"
                              data-confirm="Sign out every other browser signed in to your account? This browser and your apps and devices stay signed in." data-confirm-title="Sign out other browsers?" data-confirm-text="Sign out">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger btn-sm" id="signOutOthers">Sign out all other browsers</button>
                        </form>
                    @endif
                </div>
            </section>

            {{-- Signed-in devices (tokens) --}}
            <section class="card form-panel acct-card" aria-labelledby="devicesTitle" id="devices">
                <div class="card-body">
                    <h2 class="acct-card-title" id="devicesTitle">Signed-in apps and devices</h2>
                    @if($tokens->isEmpty())
                        <div class="acct-empty">
                            <x-icon name="book-open" :size="18" />
                            <p>No apps or devices are signed in yet. Sign in from the app, or link it with a code (Link a device, above).</p>
                        </div>
                    @else
                        <ul class="account-devices">
                            @foreach($tokens as $token)
                                <li class="account-device">
                                    <span class="account-device-main">
                                        <span class="account-device-name">{{ $token->name }}</span>
                                        <span class="account-device-meta">
                                            {{ $token->last_used_at ? 'Last used ' . $token->last_used_at->diffForHumans() : 'Not used yet' }}
                                            · signed in {{ $token->created_at?->format('j M Y') }}
                                        </span>
                                    </span>
                                    <form method="POST" action="{{ route('account.tokens.revoke', $token->id) }}" class="account-device-action"
                                          data-confirm="Sign this device out? A Kindle will need a new code to link again." data-confirm-title="Sign out {{ $token->name }}?" data-confirm-text="Sign out">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Revoke {{ $token->name }} (signs it out)" title="Signs this app or device out">Revoke</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if($android)
                        <p class="acct-android" id="accountAndroid">
                            <a href="{{ \App\Support\AndroidApp::url(request()) }}" data-turbo="false" download>{{ \App\Support\AndroidApp::linkText() }}</a>
                        </p>
                    @endif
                </div>
            </section>

            {{-- Catalogue (OPDS) passwords: App\ApiToken kind catalogue --}}
            <section class="card form-panel acct-card" aria-labelledby="catalogueTitle" id="catalogue">
                <div class="card-body">
                    <h2 class="acct-card-title" id="catalogueTitle">Catalogue passwords</h2>
                    <p class="acct-text">For e-reader apps that browse the catalogue at <code>{{ url('/opds') }}</code>: sign in there with <strong>{{ $user->username }}</strong> and one of these as the password. On the public address the catalogue does not take your account password.</p>
                    @if($made = session(\App\Http\Controllers\AccountSecurityController::FLASH_CATALOGUE_PASSWORD))
                        <div class="invite-result is-sent" role="status" id="cataloguePassword">
                            <p class="invite-result-text">The password for “{{ $made['name'] }}”. Copy it now: it is not shown again.</p>
                            <div class="invite-link-row">
                                <input type="text" class="form-control invite-link" id="catalogueNew" value="{{ $made['password'] }}" readonly aria-label="Catalogue password for {{ $made['name'] }}" data-select-on-focus>
                                <button type="button" class="btn btn-primary" data-copy-target="#catalogueNew" data-copied-text="Copied" hidden>Copy</button>
                            </div>
                        </div>
                    @endif
                    @if($catalogue->isNotEmpty())
                        <ul class="account-devices" id="catalogueList">
                            @foreach($catalogue as $token)
                                <li class="account-device">
                                    <span class="account-device-main">
                                        <span class="account-device-name">{{ $token->name }}</span>
                                        <span class="account-device-meta">{{ $token->last_used_at ? 'Last used ' . $token->last_used_at->diffForHumans() : 'Not used yet' }} · made {{ $token->created_at?->format('j M Y') }}</span>
                                    </span>
                                    <form method="POST" action="{{ route('account.catalogue.destroy', $token->id) }}" class="account-device-action"
                                          data-confirm="Revoke the catalogue password “{{ $token->name }}”? The app using it can no longer open the catalogue." data-confirm-title="Revoke {{ $token->name }}?" data-confirm-text="Revoke">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Revoke</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @php $catBag = $errors->getBag('catalogue'); @endphp
                    <form method="POST" action="{{ route('account.catalogue.store') }}" class="link-form">
                        @csrf
                        <label for="catalogue_name" class="form-label">Name it</label>
                        <div class="link-form-row">
                            <input type="text" name="name" id="catalogue_name" class="form-control @if($catBag->has('name')) is-invalid @endif" maxlength="80" placeholder="KOReader, Moon+ Reader" required>
                            <button type="submit" class="btn btn-secondary">Make a catalogue password</button>
                        </div>
                        @if($catBag->has('name'))<div class="invalid-feedback d-block">{{ $catBag->first('name') }}</div>@endif
                    </form>
                </div>
            </section>
        </div>
    </div>

    {{-- Page footer: a secondary action, below both columns at every width. --}}
    <footer class="acct-foot">
        <form method="POST" action="{{ route('logout') }}" data-turbo="false">
            @csrf
            <button type="submit" class="btn btn-secondary"><x-icon name="log-out" :size="14" /> Sign out of this browser</button>
        </form>
    </footer>
</div>
@endsection
