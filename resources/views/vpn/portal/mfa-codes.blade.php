@extends('vpn.portal.layout')
@section('body')
    <h1>Two-factor sign-in is on</h1>
    <p class="ok" role="status">From now on, sign in with your password and a code from your authenticator app.</p>
    <h2>Save your recovery codes</h2>
    <p>If you lose your phone, each of these codes signs you in once instead of the app code. Keep them somewhere safe, like a password manager. <strong>They are shown only now.</strong></p>
    <pre class="log" aria-label="Recovery codes">@foreach($codes as $c){{ $c }}
@endforeach</pre>
    <p class="actions"><a class="btn" href="{{ $next }}">I've saved them, continue</a></p>
@endsection
