@php
    $btn = 'inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700';
    $btn2 = 'inline-flex items-center px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest hover:bg-gray-50 dark:hover:bg-gray-600';
    $btnRed = 'inline-flex items-center px-3 py-1.5 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500';
    $card = 'bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100';
    $input = 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
    $fw = $site->firewall;
    $ifOpts = function ($sel) use ($interfaces) {
        return collect($interfaces ?? [])->map(fn ($i) => '<option value="' . e($i['id']) . '"' . ($i['id'] === $sel ? ' selected' : '') . '>' . e($i['descr']) . ' (' . e($i['id']) . ($i['ipaddr'] ? ' ' . e($i['ipaddr']) . ($i['subnet'] ? '/' . e($i['subnet']) : '') : '') . ')</option>')->implode('');
    };
    $lanDefault = collect($interfaces ?? [])->firstWhere('id', 'lan')['id'] ?? (collect($interfaces ?? [])->first(fn ($i) => $i['id'] !== 'wan')['id'] ?? 'lan');
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $fw->name }} – VPN</h2>
        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            <a href="{{ route('vpn.company', $company) }}" class="font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">← {{ $company->name }} VPN</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('vpn.admin._notices')
            @if($log)
                <div class="{{ $card }}"><ul class="text-sm space-y-1">@foreach($log as $l)<li>✓ {{ $l }}</li>@endforeach</ul></div>
            @endif

            <div class="{{ $card }}">
                <p class="text-sm">{{ $fw->os_display_name }} · {{ $fw->url }} · status: <strong>{{ $site->status }}</strong></p>
                @if($site->last_error)<p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $site->last_error }}</p>@endif
                @if($readError)<p class="mt-2 text-sm text-red-600 dark:text-red-400">Couldn't read the firewall: {{ $readError }}</p>@endif
            </div>

            @if($site->isReady())
                <div class="{{ $card }}">
                    <h3 class="text-lg font-bold mb-3">WireGuard on this firewall</h3>
                    <dl class="grid grid-cols-3 gap-2 text-sm mb-4">
                        <dt class="text-gray-500">Tunnel</dt><dd class="col-span-2">{{ $site->tunnel_name }} ({{ $site->tunnel }})</dd>
                        <dt class="text-gray-500">VPN network</dt><dd class="col-span-2">{{ $site->subnet }}</dd>
                        <dt class="text-gray-500">Routed through the VPN</dt><dd class="col-span-2">{{ $site->allowed_ips }} (full tunnel)</dd>
                        <dt class="text-gray-500">Devices</dt><dd class="col-span-2">{{ $site->devices()->whereNull('revoked_at')->count() }}</dd>
                    </dl>
                    @if($canManage)
                        <form method="POST" action="{{ route('vpn.site.settings', [$company, $site]) }}" class="space-y-3 max-w-lg">@csrf
                            <label class="{{ $label }}">Endpoint (host:port devices connect to)<input name="endpoint" value="{{ old('endpoint', $site->endpoint) }}" class="{{ $input }}"></label>
                            <label class="{{ $label }}">DNS servers for devices<input name="dns" value="{{ old('dns', $site->dns) }}" class="{{ $input }}"></label>
                            <button class="{{ $btn2 }}">Save</button>
                        </form>
                    @endif
                </div>
            @elseif($canManage && $interfaces !== null)
                <div class="{{ $card }}">
                    <h3 class="text-lg font-bold mb-1">Create WireGuard automatically</h3>
                    <p class="text-sm text-gray-500 mb-4">Creates the tunnel, its firewall rules (DNS to the firewall, block the firewall's admin pages, allow the chosen LAN, block other private networks, internet), the WAN rule and outbound NAT{{ $fw->isPfSense() ? ' (Automatic is switched to Hybrid, which keeps the automatic rules), and lets the VPN network use the DNS Resolver. Installs the WireGuard package if needed' : '' }}. Full tunnel: devices browse through this office.</p>
                    @if(!empty($needsPackage))
                        <p class="text-sm mb-4 p-3 rounded bg-yellow-50 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200">The WireGuard package isn't installed on this firewall yet. <strong>Create WireGuard installs it first</strong> (pfSense downloads it; this can take 1–3 minutes, keep the page open). The PFCentral API user needs the <code>api-v2-system-package-post</code> privilege for that.</p>
                    @endif
                    <form method="POST" action="{{ route('vpn.site.setup', [$company, $site]) }}" class="space-y-3 max-w-lg" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Working… (installing WireGuard can take a few minutes)'">@csrf
                        <label class="{{ $label }}">VPN users may reach<select name="lan" class="{{ $input }}">{!! $ifOpts($lanDefault) !!}</select></label>
                        <label class="{{ $label }}">Internet connection (WAN)<select name="wan" class="{{ $input }}">{!! $ifOpts('wan') !!}</select></label>
                        <label class="{{ $label }}">WireGuard port (UDP)<input name="port" value="{{ old('port', 51820) }}" inputmode="numeric" class="{{ $input }}"></label>
                        <label class="{{ $label }}">VPN network<input name="subnet" value="{{ old('subnet', $suggestedSubnet) }}" class="{{ $input }}"></label>
                        <label class="{{ $label }}">Public name or IP devices connect to<input name="endpoint_host" value="{{ old('endpoint_host', $suggestedHost) }}" class="{{ $input }}"></label>
                        <button class="{{ $btn }}">Create WireGuard</button>
                    </form>
                </div>
                @if(!empty($tunnels))
                    <div class="{{ $card }}">
                        <h3 class="text-lg font-bold mb-1">…or adopt an existing tunnel</h3>
                        <p class="text-sm text-gray-500 mb-4">For a firewall where WireGuard was already set up by hand. Its rules and NAT are left as they are.</p>
                        <form method="POST" action="{{ route('vpn.site.adopt', [$company, $site]) }}" class="space-y-3 max-w-lg">@csrf
                            <label class="{{ $label }}">Tunnel<select name="tunnel" class="{{ $input }}">@foreach($tunnels as $t)<option value="{{ $t['ref'] }}">{{ $t['name'] }} – {{ $t['ref'] }} (UDP {{ $t['listen_port'] }})</option>@endforeach</select></label>
                            <label class="{{ $label }}">VPN network the tunnel uses<input name="subnet" placeholder="10.0.70.0/24" value="{{ old('subnet') }}" class="{{ $input }}"></label>
                            <label class="{{ $label }}">Endpoint (host:port)<input name="endpoint" placeholder="{{ $suggestedHost }}:51820" value="{{ old('endpoint') }}" class="{{ $input }}"></label>
                            <label class="{{ $label }}">DNS servers for devices<input name="dns" placeholder="192.168.1.1" value="{{ old('dns') }}" class="{{ $input }}"></label>
                            <button class="{{ $btn2 }}">Adopt tunnel</button>
                        </form>
                    </div>
                @endif
            @endif

            @if($canManage)
                <div class="{{ $card }}">
                    <h3 class="font-semibold mb-2">Turn off VPN for this firewall</h3>
                    <p class="text-sm text-gray-500 mb-3">Stops managing it in PFCentral. The WireGuard tunnel on the firewall is left as it is. Remove its devices first (devices already being removed don't count).</p>
                    <form method="POST" action="{{ route('vpn.site.delete', [$company, $site]) }}" onsubmit="return confirm('Turn off VPN management for this firewall?')">@csrf<button class="{{ $btnRed }}">Turn off</button></form>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
