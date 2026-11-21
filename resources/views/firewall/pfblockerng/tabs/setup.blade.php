@php
    $interfaces = $status['interfaces'] ?? [];
    $ip = $status['ip'] ?? [];
    $configured = $general['configured'] ?? false;
    $inbound = old('inbound_interface', $ip['inbound_interface'] ?? [] ?: (isset($interfaces['wan']) ? ['wan'] : []));
    $outbound = old('outbound_interface', $ip['outbound_interface'] ?? [] ?: array_values(array_diff(array_keys($interfaces), ['wan'])));
@endphp

<form method="POST" action="{{ route('firewall.pfblockerng.quick-setup', $firewall) }}" class="{{ $ui['card'] }} p-6 space-y-6 max-w-3xl"
    x-data="{ confirmed: false, busy: false }" @submit="busy = true">
    @csrf
    <div>
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Quick setup</h3>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Does the same as the pfBlockerNG wizard in pfSense: turns pfBlockerNG on, blocks the PRI1 list of known attackers and
            malware servers, blocks ads with the ADs Basic domain list, and downloads everything.
        </p>
    </div>

    @if ($configured)
        <div class="p-4 rounded-md border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800 text-sm text-amber-900 dark:text-amber-200">
            pfBlockerNG is already set up on this firewall. Quick setup <strong>removes all current pfBlockerNG settings and feed groups</strong> and starts again from the defaults.
        </div>
    @endif

    <fieldset @disabled($ro) class="space-y-6">
        <div>
            <span class="{{ $ui['label'] }}">Inbound interfaces</span>
            @include('firewall.pfblockerng._interfaces', ['name' => 'inbound_interface', 'selected' => $inbound])
            <p class="{{ $ui['help'] }}">Where traffic comes in from the internet. Usually WAN.</p>
        </div>
        <div>
            <span class="{{ $ui['label'] }}">Outbound interfaces</span>
            @include('firewall.pfblockerng._interfaces', ['name' => 'outbound_interface', 'selected' => $outbound])
            <p class="{{ $ui['help'] }}">Your inside networks. Devices here cannot reach listed addresses.</p>
        </div>
        <div class="grid sm:grid-cols-3 gap-6">
            <div>
                <label for="vip_address" class="{{ $ui['label'] }}">DNSBL virtual IP</label>
                <input id="vip_address" name="vip_address" value="{{ old('vip_address', '10.10.10.1') }}" class="{{ $ui['input'] }} mt-1 font-mono" required>
                <p class="{{ $ui['help'] }}">An unused address. It is added on Localhost if it is not there yet.</p>
            </div>
            <div>
                <label for="qs_port" class="{{ $ui['label'] }}">HTTP port</label>
                <input id="qs_port" name="pfb_dnsport" type="number" min="1" max="65535" value="{{ old('pfb_dnsport', 8081) }}" class="{{ $ui['input'] }} mt-1 tabular-nums" required>
            </div>
            <div>
                <label for="qs_port_ssl" class="{{ $ui['label'] }}">HTTPS port</label>
                <input id="qs_port_ssl" name="pfb_dnsport_ssl" type="number" min="1" max="65535" value="{{ old('pfb_dnsport_ssl', 8443) }}" class="{{ $ui['input'] }} mt-1 tabular-nums" required>
            </div>
        </div>
        <label class="flex items-start gap-3">
            <input type="checkbox" name="dnsbl_whitelist" value="on" class="{{ $ui['check'] }} mt-0.5" checked>
            <span><span class="{{ $ui['label'] }}">Add the default whitelist</span>
            <span class="{{ $ui['help'] }} block">Never block common services such as Google, YouTube, GitHub, Dropbox, Stack Overflow and pfSense updates.</span></span>
        </label>
        <label class="flex items-start gap-3 p-3 rounded-md bg-gray-50 dark:bg-gray-900/40">
            <input type="checkbox" name="confirm" value="1" class="{{ $ui['check'] }} mt-0.5" x-model="confirmed">
            <span class="text-sm text-gray-800 dark:text-gray-200">
                I understand this {{ $configured ? 'replaces the current pfBlockerNG settings and ' : '' }}restarts the DNS Resolver, so DNS stops for a few seconds.
            </span>
        </label>
    </fieldset>

    @unless ($ro)
        <div class="pt-2 border-t border-gray-100 dark:border-gray-700">
            <button type="submit" class="{{ $ui['btn'] }}" :disabled="!confirmed || busy">
                <span x-show="!busy">Run Quick setup</span><span x-show="busy" x-cloak>Setting up…</span>
            </button>
        </div>
    @endunless
</form>
