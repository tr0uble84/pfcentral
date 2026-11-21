@extends('vpn.portal.layout')
@section('body')
    <h1>Two-factor sign-in</h1>
    <p class="small">{{ $account->upn }}</p>
    <p>Open your authenticator app (for example Google Authenticator) and type the 6-digit code for <strong>CFBTEL VPN</strong>.</p>
    @if($error)<p class="error" role="alert">{{ $error }}</p>@endif
    <form method="POST" action="/vpn/mfa">@csrf
        <label>Code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9A-Za-z\s\-]{6,12}" maxlength="12" required autofocus placeholder="123 456"></label>
        <button class="btn" type="submit">Sign in</button>
    </form>
    <p class="small">Lost your phone? Type one of your recovery codes instead (like ABCDE-23456), or ask your IT admin to reset two-factor for your account. · <a href="/vpn">Start over</a></p>
@endsection
