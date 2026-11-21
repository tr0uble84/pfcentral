@extends('vpn.portal.layout')
@section('body')
    <h1>Connect this computer</h1>
    <p>The CFBTEL VPN app on <strong>{{ $p['name'] }}</strong> asked to connect{{ $company ? ' to ' . $company->name : '' }}. Only continue if you just clicked <em>Sign in</em> in the app.</p>
    @if(!empty($error))<p class="error" role="alert">{{ $error }}</p>@endif
    @if($sites->isEmpty())
        <p class="error">There's no office you can connect to yet. Ask your IT admin.</p>
    @else
        <form method="POST" action="/vpn/app">@csrf
            @foreach(['state', 'port', 'pub', 'name'] as $k)<input type="hidden" name="{{ $k }}" value="{{ $p[$k] }}">@endforeach
            @if($sites->count() > 1)
                <label>Office / site<select name="site">@foreach($sites as $s)<option value="{{ $s->id }}">{{ $s->firewall->name }}</option>@endforeach</select></label>
            @else
                <input type="hidden" name="site" value="{{ $sites->first()->id }}">
                <p>Office: <strong>{{ $sites->first()->firewall->name }}</strong></p>
            @endif
            <label>Device name<input name="device_name" value="{{ $p['name'] }}" maxlength="32" required></label>
            <button class="btn" type="submit">Connect</button>
        </form>
        <p class="small">Your computer made its own key; it never leaves it. The app sets up the VPN by itself.</p>
    @endif
@endsection
