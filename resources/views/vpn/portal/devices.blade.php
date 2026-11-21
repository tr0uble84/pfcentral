@extends('vpn.portal.layout', ['title' => 'CFBTEL VPN – Your devices'])
@section('body')
    @if($sites->isEmpty() && $noGroup)
        <h1>No VPN location for you yet</h1>
        <p>You have VPN access at {{ $company?->name ?? 'your company' }}, but none of its locations is open to you yet. Ask your company's IT admin to add you to an access group, or contact <a href="mailto:help@cfbtel.com">help@cfbtel.com</a>.</p>
    @elseif($sites->isEmpty())
        <h1>VPN not set up yet</h1>
        <p>{{ $company?->name ?? 'Your company' }}'s VPN isn't ready yet. Contact <a href="mailto:help@cfbtel.com">CFBTEL support</a>.</p>
    @else
        <h1>Your devices</h1>
        <p class="small">{{ $company?->name }}</p>
        @if($notice)<p class="{{ $notice[0] === 'error' ? 'error' : 'ok' }}" role="status">{{ $notice[1] }}</p>@endif
        <ul class="devices">
            @forelse($devices as $d)
                <li class="device">
                    <div><strong>{{ $d->name }}</strong><span class="small">{{ $d->site->firewall->name }} · {{ $d->address }}</span></div>
                    <form class="inline" method="POST" action="/vpn/devices/{{ $d->id }}/remove">@csrf<button class="btn danger small-btn" type="submit">Remove</button></form>
                </li>
            @empty
                <li class="small">No devices yet.</li>
            @endforelse
        </ul>
        <h2>Add a device</h2>
        @if($devices->count() >= $max)
            <p class="small">You have the maximum of {{ $max }} devices. Remove one first.</p>
        @else
            <form method="POST" action="/vpn/devices">@csrf
                @if($sites->count() > 1)
                    <label>Office / site<select name="site">@foreach($sites as $s)<option value="{{ $s->id }}">{{ $s->firewall->name }}</option>@endforeach</select></label>
                @else
                    <input type="hidden" name="site" value="{{ $sites->first()->id }}">
                    <p class="small">Office / site: <strong>{{ $sites->first()->firewall->name }}</strong></p>
                @endif
                <label>Device name<input name="name" maxlength="32" placeholder="e.g. Work laptop" required pattern="[A-Za-z0-9 ._\-]{1,32}"></label>
                <label>Type<select name="platform">@foreach($platforms as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <button class="btn" type="submit">Create VPN setup</button>
            </form>
        @endif
        <p class="small">Each device gets its own key. Lost a device? Remove it here and it can no longer connect.</p>
    @endif
    @if($user->isLocal())
        <h2>Two-factor sign-in</h2>
        @if($mfaOn)
            <p class="small"><span class="badge ready">On</span> You sign in with your password and a code from your authenticator app. Recovery codes left: {{ $recoveryLeft }}.</p>
            @if($mfaRequired)
                <p class="small">Your company requires it. Lost your phone? Ask your IT admin to reset it.</p>
            @else
                <details>
                    <summary class="small">Turn two-factor off</summary>
                    <form method="POST" action="/vpn/mfa/disable" class="row">@csrf
                        <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required placeholder="Current code" aria-label="Current code from your authenticator app">
                        <button class="btn secondary" type="submit">Turn off</button>
                    </form>
                </details>
            @endif
        @else
            <p class="small">Off. Add a code from your phone (Google Authenticator, Microsoft Authenticator…) to your sign-in, so a stolen password alone can't get in.</p>
            <p class="actions"><a class="btn secondary" href="/vpn/mfa/setup">Turn on two-factor</a></p>
        @endif
    @endif
@endsection
