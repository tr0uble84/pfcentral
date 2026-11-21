@extends('vpn.portal.layout')
@section('body')
    <h1>CFBTEL VPN</h1>
    <p>Sign in with your work Microsoft 365 account to set up the VPN on a computer or phone.</p>
    @if($error)<p class="error" role="alert">{{ $error }}</p>@endif
    <form method="POST" action="/vpn/login">@csrf
        <label>Work email<input type="email" name="email" required autocomplete="email" placeholder="you@company.com" autofocus></label>
        <button class="btn ms" type="submit"><img src="/vpn-assets/microsoft.svg" alt="" width="18" height="18"> Continue with Microsoft</button>
    </form>
    <p class="small"><a href="/vpn/help">How it works</a></p>
@endsection
