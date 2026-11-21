@extends('vpn.portal.layout')
@section('body')
    <h1>Set up two-factor sign-in</h1>
    <p class="small">{{ $account->upn }}</p>
    @if($required)
        <p class="warn">Your company requires two-factor sign-in. Set it up once now; after that you'll type a code from your phone when you sign in.</p>
    @else
        <p>Protect your VPN account: after your password, you'll also type a code from your phone.</p>
    @endif
    <ol class="steps">
        <li>Install an authenticator app on your phone, such as <strong>Google Authenticator</strong> or <strong>Microsoft Authenticator</strong>.</li>
        <li>In the app, add an account and scan this QR code.</li>
        <li>Type the 6-digit code the app shows for <strong>CFBTEL VPN</strong>.</li>
    </ol>
    <div class="qr" role="img" aria-label="QR code for your authenticator app">{!! $qr !!}</div>
    <details>
        <summary class="small">Can't scan it? Enter this key in the app instead</summary>
        <p><code class="log">{{ $secret }}</code></p>
        <p class="small">Account: {{ $account->upn }} · Type: time-based</p>
    </details>
    @if($error)<p class="error" role="alert">{{ $error }}</p>@endif
    <form method="POST" action="/vpn/mfa/setup">@csrf
        <label>6-digit code from the app<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9\s]{6,8}" maxlength="8" required autofocus placeholder="123 456"></label>
        <button class="btn" type="submit">Turn on two-factor</button>
    </form>
    @unless($required)<p class="small"><a href="/vpn">Not now</a></p>@endunless
@endsection
