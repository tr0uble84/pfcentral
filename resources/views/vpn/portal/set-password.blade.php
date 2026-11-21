@extends('vpn.portal.layout')
@section('body')
    <h1>Set your VPN password</h1>
    <p class="small">{{ $account->name }} · {{ $account->upn }}</p>
    @if($error)<p class="error" role="alert">{{ $error }}</p>@endif
    <form method="POST" action="/vpn/password/{{ $token }}">@csrf
        <input type="hidden" name="username" value="{{ $account->upn }}" autocomplete="username">
        <label>New password (at least {{ $min }} characters)<input type="password" name="password" required minlength="{{ $min }}" autocomplete="new-password" autofocus></label>
        <label>Repeat it<input type="password" name="password_confirmation" required minlength="{{ $min }}" autocomplete="new-password"></label>
        <button class="btn" type="submit">Save and continue</button>
    </form>
    <p class="small">Tip: a password manager can create and remember a long password for you.</p>
@endsection
