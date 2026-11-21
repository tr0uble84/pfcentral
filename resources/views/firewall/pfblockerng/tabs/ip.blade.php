@php
    $o = $status['options'] ?? [];
    $ip = $status['ip'] ?? [];
    $interfaces = $status['interfaces'] ?? [];
    $val = fn ($k, $d = '') => old($k, $ip[$k] ?? $d);
    $on = fn ($k) => old() ? (bool) old($k) : (($ip[$k] ?? '') === 'on');
@endphp

<form method="POST" action="{{ route('firewall.pfblockerng.ip', $firewall) }}" class="space-y-6 max-w-4xl">
    @csrf
    <fieldset @disabled($ro) class="space-y-6">
        <section class="{{ $ui['card'] }} p-6 space-y-5">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Firewall rules</h3>
            <div>
                <span class="{{ $ui['label'] }}">Inbound interfaces</span>
                @include('firewall.pfblockerng._interfaces', ['name' => 'inbound_interface', 'selected' => old('inbound_interface', $ip['inbound_interface'] ?? [])])
                <p class="{{ $ui['help'] }}">Usually WAN. Traffic coming in from listed addresses is stopped here.</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-6">
                <div>
                    <label for="inbound_deny_action" class="{{ $ui['label'] }}">Inbound action</label>
                    <select id="inbound_deny_action" name="inbound_deny_action" class="{{ $ui['input'] }} mt-1">
                        @foreach ($o['deny_action'] ?? [] as $v => $l)<option value="{{ $v }}" @selected($val('inbound_deny_action', 'block') === $v)>{{ $l }}</option>@endforeach
                    </select>
                    <p class="{{ $ui['help'] }}">Block is recommended inbound: it gives no answer to the sender.</p>
                </div>
                <div>
                    <label for="outbound_deny_action" class="{{ $ui['label'] }}">Outbound action</label>
                    <select id="outbound_deny_action" name="outbound_deny_action" class="{{ $ui['input'] }} mt-1">
                        @foreach ($o['deny_action'] ?? [] as $v => $l)<option value="{{ $v }}" @selected($val('outbound_deny_action', 'reject') === $v)>{{ $l }}</option>@endforeach
                    </select>
                    <p class="{{ $ui['help'] }}">Reject is recommended outbound: devices give up straight away.</p>
                </div>
            </div>
            <div>
                <span class="{{ $ui['label'] }}">Outbound interfaces</span>
                @include('firewall.pfblockerng._interfaces', ['name' => 'outbound_interface', 'selected' => old('outbound_interface', $ip['outbound_interface'] ?? [])])
                <p class="{{ $ui['help'] }}">Usually LAN and other inside networks. Devices here cannot reach listed addresses.</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-6">
                <div>
                    <label for="pass_order" class="{{ $ui['label'] }}">Rule order</label>
                    <select id="pass_order" name="pass_order" class="{{ $ui['input'] }} mt-1">
                        @foreach ($o['pass_order'] ?? [] as $v => $l)<option value="{{ $v }}" @selected($val('pass_order', 'order_0') === $v)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="autorule_suffix" class="{{ $ui['label'] }}">Rule description suffix</label>
                    <select id="autorule_suffix" name="autorule_suffix" class="{{ $ui['input'] }} mt-1">
                        @foreach ($o['autorule_suffix'] ?? [] as $v => $l)<option value="{{ $v }}" @selected($val('autorule_suffix', 'autorule') === $v)>{{ $l }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div class="grid sm:grid-cols-2 gap-3">
                @foreach ([
                    'enable_float' => ['Floating rules', 'Put the pfBlockerNG rules on the Floating tab instead of each interface.'],
                    'killstates' => ['Kill states', 'Close open connections to newly listed addresses after each update.'],
                ] as $key => [$label, $help])
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="{{ $key }}" value="on" class="{{ $ui['check'] }} mt-0.5" @checked($on($key))>
                        <span><span class="{{ $ui['label'] }}">{{ $label }}</span><span class="{{ $ui['help'] }} block">{{ $help }}</span></span>
                    </label>
                @endforeach
            </div>
        </section>

        <section class="{{ $ui['card'] }} p-6 space-y-5">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">List processing</h3>
            <div class="grid sm:grid-cols-2 gap-3">
                @foreach ([
                    'enable_dup' => ['De-duplication', 'Remove addresses that are in more than one list.'],
                    'enable_agg' => ['CIDR aggregation', 'Merge neighbouring addresses into ranges to keep the tables small.'],
                    'suppression' => ['Suppression', 'Never block private, local or listed addresses (see the suppression list below).'],
                    'enable_log' => ['Global logging', 'Log every packet the pfBlockerNG rules stop.'],
                ] as $key => [$label, $help])
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="{{ $key }}" value="on" class="{{ $ui['check'] }} mt-0.5" @checked($on($key))>
                        <span><span class="{{ $ui['label'] }}">{{ $label }}</span><span class="{{ $ui['help'] }} block">{{ $help }}</span></span>
                    </label>
                @endforeach
            </div>
            <div class="max-w-xs">
                <label for="ip_placeholder" class="{{ $ui['label'] }}">Placeholder IP</label>
                <input id="ip_placeholder" name="ip_placeholder" value="{{ $val('ip_placeholder', '127.1.7.7') }}" class="{{ $ui['input'] }} mt-1 font-mono" required>
                <p class="{{ $ui['help'] }}">Used in empty lists. It must not be used anywhere on your network.</p>
            </div>
            <div>
                <label for="v4suppression" class="{{ $ui['label'] }}">IPv4 suppression list</label>
                <textarea id="v4suppression" name="v4suppression" rows="5" class="{{ $ui['input'] }} mt-1 font-mono" placeholder="203.0.113.10/32 # vendor portal">{{ $val('v4suppression') }}</textarea>
                <p class="{{ $ui['help'] }}">One address per line, ending in /32 (single address) or /24. These are never blocked. Text after # is a comment.</p>
            </div>
        </section>

        <section class="{{ $ui['card'] }} p-6 space-y-5">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">MaxMind GeoIP and ASN</h3>
                <p class="{{ $ui['help'] }}">Country blocking needs a free MaxMind account. Keys are stored on the firewall and are never shown again here.</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-6">
                <div>
                    <label for="maxmind_account" class="{{ $ui['label'] }}">MaxMind account ID</label>
                    <input id="maxmind_account" name="maxmind_account" value="{{ $val('maxmind_account') }}" class="{{ $ui['input'] }} mt-1" autocomplete="off">
                </div>
                <div>
                    <label for="maxmind_key" class="{{ $ui['label'] }}">MaxMind license key</label>
                    <input id="maxmind_key" name="maxmind_key" type="password" class="{{ $ui['input'] }} mt-1" autocomplete="new-password"
                        placeholder="{{ ($ip['maxmind_key_set'] ?? false) ? 'Saved. Type a new key to replace it.' : 'Not set' }}">
                    @if ($ip['maxmind_key_set'] ?? false)
                        <label class="mt-2 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400"><input type="checkbox" name="clear_maxmind_key" value="on" class="{{ $ui['check'] }}"> Remove the saved key</label>
                    @endif
                </div>
                <div>
                    <label for="maxmind_locale" class="{{ $ui['label'] }}">GeoIP language</label>
                    <select id="maxmind_locale" name="maxmind_locale" class="{{ $ui['input'] }} mt-1">
                        @foreach ($o['maxmind_locale'] ?? [] as $v => $l)<option value="{{ $v }}" @selected($val('maxmind_locale', 'en') === $v)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="database_cc" value="on" class="{{ $ui['check'] }} mt-0.5" @checked($on('database_cc'))>
                        <span><span class="{{ $ui['label'] }}">Skip MaxMind updates</span><span class="{{ $ui['help'] }} block">Keep the current GeoIP database.</span></span>
                    </label>
                </div>
                <div>
                    <label for="asn_reporting" class="{{ $ui['label'] }}">ASN reporting</label>
                    <select id="asn_reporting" name="asn_reporting" class="{{ $ui['input'] }} mt-1">
                        @foreach ($o['asn_reporting'] ?? [] as $v => $l)<option value="{{ $v }}" @selected($val('asn_reporting', 'disabled') === $v)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="asn_token" class="{{ $ui['label'] }}">IPinfo token</label>
                    <input id="asn_token" name="asn_token" type="password" class="{{ $ui['input'] }} mt-1" autocomplete="new-password"
                        placeholder="{{ ($ip['asn_token_set'] ?? false) ? 'Saved. Type a new token to replace it.' : 'Not set' }}">
                    @if ($ip['asn_token_set'] ?? false)
                        <label class="mt-2 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400"><input type="checkbox" name="clear_asn_token" value="on" class="{{ $ui['check'] }}"> Remove the saved token</label>
                    @endif
                </div>
            </div>
        </section>
    </fieldset>

    @unless ($ro)
        <div class="flex flex-wrap items-center gap-4">
            <button type="submit" class="{{ $ui['btn'] }}">Save IP settings</button>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" name="reload" value="on" class="{{ $ui['check'] }}" checked> Apply now (reload IP lists)
            </label>
        </div>
    @endunless
</form>
