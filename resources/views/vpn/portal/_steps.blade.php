@php
    $apps = [
        'windows' => '<a href="' . e(route('vpn.portal.download.windows')) . '">CFBTEL VPN for Windows</a> <span class="small">(ARM PC? <a href="' . e(route('vpn.portal.download.windows', ['arch' => 'arm64'])) . '">get this one</a>)</span>',
        'macos' => '<a href="' . e(route('vpn.portal.download.macos')) . '">CFBTEL VPN for Mac</a> <span class="small">(Apple silicon and Intel, macOS 11 or later)</span>',
        'iphone' => '<a href="https://apps.apple.com/app/wireguard/id1441195209" rel="noopener">WireGuard</a> from the App Store',
        'android' => '<a href="https://play.google.com/store/apps/details?id=com.wireguard.android" rel="noopener">WireGuard</a> from Google Play',
    ];
    $amnezia = 'Prefer AmneziaVPN? Install it from <a href="https://amnezia.org/en/downloads" rel="noopener">amnezia.org</a>, tap <em>+</em> and import the same file.';
@endphp
@if($p === 'windows')
    <ol class="steps">
        <li>Install {!! $apps[$p] !!}.</li>
        <li>Click <strong>Download setup file</strong>.</li>
        <li>In CFBTEL VPN choose <em>Import tunnel(s) from file</em> and pick the <em>.conf</em> file.</li>
        <li>Click <strong>Activate</strong>.</li>
    </ol>
    <p class="small">Easier next time: open CFBTEL VPN and click <strong>Sign in</strong>. It sets itself up, no file needed.</p>
@elseif($p === 'macos')
    <ol class="steps">
        <li>Install {!! $apps[$p] !!}. If macOS says it can't check the installer, open <em>System Settings → Privacy &amp; Security</em> and click <em>Open Anyway</em>.</li>
        <li>CFBTEL VPN opens by itself. Click <strong>Sign in to CFBTEL</strong>, sign in and pick your office. It sets itself up, no file needed.</li>
    </ol>
    <p class="small">Prefer the WireGuard app? Install <a href="https://apps.apple.com/app/wireguard/id1451685025" rel="noopener">WireGuard</a> from the Mac App Store, click <strong>Download setup file</strong>, choose <em>Import tunnel(s) from file</em>, click <em>Allow</em>, then <em>Activate</em>.</p>
@else
    <ol class="steps">
        <li>Install {!! $apps[$p] !!}.</li>
        <li><strong>Setting up from a computer screen?</strong> In WireGuard tap <em>+</em> → <em>{{ $p === 'iphone' ? 'Create from QR code' : 'Scan from QR code' }}</em> and scan the code.<br>
            <strong>On the phone itself?</strong> Tap <strong>Download setup file</strong>, then {!! $p === 'iphone' ? 'open it and share it to WireGuard' : 'tap <em>+</em> → <em>Import from file or archive</em>' !!}.</li>
        <li>Switch it on.</li>
    </ol>
@endif
@if($p !== 'windows' && $p !== 'macos')
    <p class="small">{!! $amnezia !!}</p>
@endif
