@extends('vpn.portal.layout', ['title' => 'CFBTEL VPN – Help'])
@section('body')
    <h1>How CFBTEL VPN works</h1>
    <p>Sign in with your work Microsoft 365 account. Your company's IT admin decides who has access. For each computer or phone you add, a personal WireGuard key is created on your office firewall. While connected, your internet traffic goes through the office and you can reach office systems.</p>
    @foreach($platforms as $k => $l)
        <h2>{{ $l }}</h2>
        @include('vpn.portal._steps', ['p' => $k])
    @endforeach
    <h2>Leaving or losing a device</h2>
    <p>Remove the device on <a href="/vpn">Your devices</a>. When your admin removes your access, or your Microsoft 365 account is disabled or deleted, your devices stop working automatically.</p>
    <p class="small">Problems: <a href="mailto:help@cfbtel.com">help@cfbtel.com</a></p>
@endsection
