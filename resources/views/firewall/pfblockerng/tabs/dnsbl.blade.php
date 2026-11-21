@php
    $o = $status['options'] ?? [];
    $d = $status['dnsbl'] ?? [];
    $interfaces = $status['interfaces'] ?? [];
    $vips = $status['vips'] ?? null; // null = older pfBlockerNG where the VIP is a plain address
    $val = fn ($k, $def = '') => old($k, $d[$k] ?? $def);
    $on = fn ($k) => old() ? (bool) old($k) : (($d[$k] ?? '') === 'on');
@endphp

<form method="POST" action="{{ route('firewall.pfblockerng.dnsbl', $firewall) }}" class="space-y-6 max-w-4xl">
    @csrf
    <fieldset @disabled($ro) class="space-y-6">
        <section class="{{ $ui['card'] }} p-6 space-y-5">
            <label class="flex items-start gap-3">
                <input type="checkbox" name="pfb_dnsbl" value="on" class="{{ $ui['check'] }} mt-0.5" @checked($on('pfb_dnsbl'))>
                <span>
                    <span class="{{ $ui['label'] }}">Enable DNSBL</span>
                    <span class="{{ $ui['help'] }} block">Block ads, trackers and malware domains through the DNS Resolver. Devices must use this firewall for DNS.</span>
                </span>
            </label>

            <div class="grid sm:grid-cols-2 gap-6">
                <div>
                    <label for="dnsbl_mode" class="{{ $ui['label'] }}">Mode</label>
                    <select id="dnsbl_mode" name="dnsbl_mode" class="{{ $ui['input'] }} mt-1">
                        @foreach ($o['dnsbl_mode'] ?? [] as $v => $l)<option value="{{ $v }}" @selected($val('dnsbl_mode', 'dnsbl_unbound') === $v)>{{ $l }}</option>@endforeach
                    </select>
                    <p class="{{ $ui['help'] }}">Python mode uses less memory and adds wildcard (TLD) blocking. Changing mode clears the DNSBL database and reloads it.</p>
                </div>
                <div>
                    <label for="pfb_dnsvip4" class="{{ $ui['label'] }}">Web server virtual IP</label>
                    @if (is_array($vips))
                        <select id="pfb_dnsvip4" name="pfb_dnsvip4" class="{{ $ui['input'] }} mt-1">
                            <option value="">None</option>
                            @foreach ($vips as $id => $label)<option value="{{ $id }}" @selected($val('pfb_dnsvip4') === $id)>{{ $label }}</option>@endforeach
                        </select>
                        <p class="{{ $ui['help'] }}">An IP alias on Localhost (for example 10.10.10.1/32) that shows the blocked page. Add one under
                            <a class="text-indigo-600 dark:text-indigo-400 hover:underline" href="{{ route('firewall.virtual_ips.index', $firewall) }}">Virtual IPs</a>, or Quick setup adds it for you.</p>
                    @else
                        <input id="pfb_dnsvip4" name="pfb_dnsvip4" value="{{ $val('pfb_dnsvip4', '10.10.10.1') }}" class="{{ $ui['input'] }} mt-1 font-mono">
                        <p class="{{ $ui['help'] }}">An unused address for the blocked page web server, for example 10.10.10.1.</p>
                    @endif
                </div>
                <div>
                    <label for="pfb_dnsport" class="{{ $ui['label'] }}">Web server port (HTTP)</label>
                    <input id="pfb_dnsport" name="pfb_dnsport" type="number" min="1" max="65535" value="{{ $val('pfb_dnsport', '8081') }}" class="{{ $ui['input'] }} mt-1 tabular-nums">
                </div>
                <div>
                    <label for="pfb_dnsport_ssl" class="{{ $ui['label'] }}">Web server port (HTTPS)</label>
                    <input id="pfb_dnsport_ssl" name="pfb_dnsport_ssl" type="number" min="1" max="65535" value="{{ $val('pfb_dnsport_ssl', '8443') }}" class="{{ $ui['input'] }} mt-1 tabular-nums">
                </div>
                <div>
                    <label for="global_log" class="{{ $ui['label'] }}">Logging for all groups</label>
                    <select id="global_log" name="global_log" class="{{ $ui['input'] }} mt-1">
                        <option value="">Use each group's setting</option>
                        @foreach ($o['dnsbl_logging'] ?? [] as $v => $l)<option value="{{ $v }}" @selected($val('global_log') === $v)>{{ $l }}</option>@endforeach
                    </select>
                </div>
            </div>

            <div class="grid sm:grid-cols-2 gap-3">
                @foreach ([
                    'pfb_dnsbl_rule' => ['Allow the blocked page from other networks', 'Add floating rules so the networks below can reach the DNSBL web server. Only needed with several LAN segments.'],
                    'pfb_py_block' => ['Python blocking', 'Python mode: block the listed domains inside the resolver (recommended in Python mode).'],
                    'pfb_tld' => ['Wildcard blocking (TLD)', 'Block a listed domain and every subdomain under it.'],
                    'pfb_hsts' => ['HSTS mode', 'Python mode: null-block sites that force HTTPS so browsers do not show certificate errors.'],
                    'pfb_cname' => ['CNAME validation', 'Python mode: also check the targets of CNAME answers.'],
                    'pfb_idn' => ['Block IDN domains', 'Python mode: block internationalised (punycode) domains.'],
                    'pfb_regex' => ['Regex blocking', 'Python mode: block domains that match regular expressions.'],
                    'pfb_noaaaa' => ['No AAAA', 'Python mode: drop IPv6 (AAAA) answers for listed domains.'],
                ] as $key => [$label, $help])
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="{{ $key }}" value="on" class="{{ $ui['check'] }} mt-0.5" @checked($on($key))>
                        <span><span class="{{ $ui['label'] }}">{{ $label }}</span><span class="{{ $ui['help'] }} block">{{ $help }}</span></span>
                    </label>
                @endforeach
            </div>

            <div>
                <span class="{{ $ui['label'] }}">Networks that may reach the blocked page</span>
                @include('firewall.pfblockerng._interfaces', ['name' => 'dnsbl_allow_int', 'selected' => old('dnsbl_allow_int', $d['dnsbl_allow_int'] ?? [])])
            </div>
        </section>

        <section class="{{ $ui['card'] }} p-6 space-y-5">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Allow and block lists</h3>
            <div>
                <label for="suppression" class="{{ $ui['label'] }}">DNSBL whitelist</label>
                <textarea id="suppression" name="suppression" rows="7" class="{{ $ui['input'] }} mt-1 font-mono" placeholder=".microsoft.com # with all subdomains&#10;login.example.com">{{ $val('suppression') }}</textarea>
                <p class="{{ $ui['help'] }}">Domains that are never blocked, one per line. Start with a dot to include every subdomain. Text after # is a comment.</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-6">
                <div>
                    <label for="tldblacklist" class="{{ $ui['label'] }}">Top-level domains to block</label>
                    <textarea id="tldblacklist" name="tldblacklist" rows="4" class="{{ $ui['input'] }} mt-1 font-mono" placeholder="xyz&#10;top">{{ $val('tldblacklist') }}</textarea>
                    <p class="{{ $ui['help'] }}">Block whole endings such as .xyz. One per line, without the dot.</p>
                </div>
                <div>
                    <label for="tldwhitelist" class="{{ $ui['label'] }}">Exceptions to blocked top-level domains</label>
                    <textarea id="tldwhitelist" name="tldwhitelist" rows="4" class="{{ $ui['input'] }} mt-1 font-mono" placeholder="good-site.xyz">{{ $val('tldwhitelist') }}</textarea>
                </div>
            </div>
        </section>
    </fieldset>

    @unless ($ro)
        <div class="flex flex-wrap items-center gap-4">
            <button type="submit" class="{{ $ui['btn'] }}">Save DNSBL settings</button>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" name="reload" value="on" class="{{ $ui['check'] }}" checked> Apply now (reload DNSBL)
            </label>
        </div>
    @endunless
</form>
