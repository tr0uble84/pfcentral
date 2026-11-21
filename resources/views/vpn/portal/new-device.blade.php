@extends('vpn.portal.layout', ['title' => 'CFBTEL VPN – Set up ' . $device->name])
@section('body')
    <h1>Set up “{{ $device->name }}”</h1>
    <p class="small">{{ $site->firewall->name }}</p>
    <p class="warn">This setup is shown <strong>only once</strong> and works on one device. Don't share it.</p>
    @include('vpn.portal._steps', ['p' => $platform])
    <div class="qr" aria-label="WireGuard QR code">{!! $qrSvg !!}</div>
    <div class="actions">
        <a class="btn" href="{{ $downloadHref }}" download="{{ $fileName }}">Download setup file</a>
        <a class="btn secondary" href="/vpn">Done</a>
    </div>
    <p class="small">VPN address: {{ $device->address }}. Leaving this page discards the setup; you can always create the device again.</p>
@endsection
