@extends('vpn.portal.layout')
@section('body')
    <h1>CFBTEL VPN</h1>
    @if($error)<p class="error" role="alert">{{ $error }}</p>@endif
    <form method="POST" action="/vpn/login/password">@csrf
        <label>Work email<input type="email" name="email" value="{{ $email }}" required autocomplete="username"></label>
        <label>Password<input type="password" name="password" required autocomplete="current-password" autofocus></label>
        <button class="btn" type="submit">Sign in</button>
    </form>
    <form method="POST" action="/vpn/forgot" class="inline">@csrf<input type="hidden" name="email" value="{{ $email }}">
        <p class="small"><button class="link" type="submit">Forgot password?</button> · <a href="/vpn">Use another email</a></p>
    </form>
@endsection
