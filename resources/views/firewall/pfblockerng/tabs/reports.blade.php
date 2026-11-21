@php
    $r = $extra['reports'] ?? [];
    $top = $r['top'] ?? [];
    $lines = (int) request('lines', 500);
    $tops = [
        'ip_hosts' => 'Most blocked addresses',
        'ip_feeds' => 'Busiest IP aliases',
        'ip_countries' => 'Blocked by country',
        'dns_domains' => 'Most blocked domains',
        'dns_clients' => 'Devices with most DNSBL blocks',
        'dns_groups' => 'Busiest DNSBL groups',
    ];
@endphp

<div class="space-y-6" x-data="{ view: 'dnsbl', q: '' }">
    <div class="flex flex-wrap items-center gap-3">
        <p class="text-sm text-gray-600 dark:text-gray-400">From the last {{ number_format($lines) }} lines of each log.</p>
        <form method="GET" class="flex items-center gap-2 ml-auto">
            <input type="hidden" name="tab" value="reports">
            <label for="lines" class="text-sm text-gray-600 dark:text-gray-400">Lines</label>
            <select id="lines" name="lines" class="{{ $ui['input'] }} !w-auto py-1.5" onchange="this.form.submit()">
                @foreach ([200, 500, 1000, 2000] as $n)<option value="{{ $n }}" @selected($lines === $n)>{{ number_format($n) }}</option>@endforeach
            </select>
        </form>
    </div>

    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($tops as $key => $title)
            <section class="{{ $ui['card'] }} p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</h3>
                @php $items = $top[$key] ?? []; $max = max(1, ...array_values($items ?: [1])); @endphp
                <ol class="mt-3 space-y-1.5">
                    @forelse ($items as $label => $count)
                        <li class="text-sm">
                            <div class="flex justify-between gap-3"><span class="truncate font-mono text-xs text-gray-800 dark:text-gray-200" title="{{ $label }}">{{ $label }}</span><span class="tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($count) }}</span></div>
                            <div class="mt-0.5 h-1 rounded bg-gray-100 dark:bg-gray-700"><div class="h-1 rounded bg-indigo-500" style="width: {{ round($count / $max * 100) }}%"></div></div>
                        </li>
                    @empty
                        <li class="text-sm text-gray-500 dark:text-gray-400">Nothing yet.</li>
                    @endforelse
                </ol>
            </section>
        @endforeach
    </div>

    <section class="{{ $ui['card'] }}">
        <div class="px-6 pt-5 pb-3 flex flex-wrap items-center gap-3">
            <div class="flex gap-2" role="tablist">
                <button type="button" role="tab" @click="view = 'dnsbl'" :aria-selected="view === 'dnsbl'" :class="view === 'dnsbl' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200'" class="px-3 py-1.5 rounded-md text-sm font-medium">DNSBL events ({{ count($r['dnsbl'] ?? []) }})</button>
                <button type="button" role="tab" @click="view = 'ip'" :aria-selected="view === 'ip'" :class="view === 'ip' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200'" class="px-3 py-1.5 rounded-md text-sm font-medium">IP events ({{ count($r['ip'] ?? []) }})</button>
            </div>
            <label class="sr-only" for="filter">Filter</label>
            <input id="filter" type="search" x-model="q" placeholder="Filter by domain, address, feed…" class="{{ $ui['input'] }} sm:!w-72 ml-auto">
        </div>

        <div x-show="view === 'dnsbl'" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/50"><tr>
                    <th class="{{ $ui['th'] }}">Time</th><th class="{{ $ui['th'] }}">Domain</th><th class="{{ $ui['th'] }}">Device</th>
                    <th class="{{ $ui['th'] }}">Group / feed</th><th class="{{ $ui['th'] }}">Type</th><th class="{{ $ui['th'] }}"><span class="sr-only">Whitelist</span></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($r['dnsbl'] ?? [] as $e)
                        <tr x-show="!q || @js(strtolower(implode(' ', [$e['domain'], $e['client'], $e['group'], $e['feed']]))).includes(q.toLowerCase())">
                            <td class="{{ $ui['td'] }} whitespace-nowrap tabular-nums text-gray-500 dark:text-gray-400">{{ $e['time'] }}</td>
                            <td class="{{ $ui['td'] }} font-mono text-xs break-all">{{ $e['domain'] }}@if ($e['evaluated'] && $e['evaluated'] !== $e['domain'])<span class="block text-gray-500">listed as {{ $e['evaluated'] }}</span>@endif</td>
                            <td class="{{ $ui['td'] }} font-mono text-xs">{{ $e['client'] }}</td>
                            <td class="{{ $ui['td'] }} text-xs">{{ $e['group'] }}<span class="block text-gray-500">{{ $e['feed'] }}</span></td>
                            <td class="{{ $ui['td'] }} text-xs whitespace-nowrap">{{ $e['b_type'] }} · {{ $e['p_type'] }}</td>
                            <td class="{{ $ui['td'] }} text-right">
                                @unless ($ro)
                                    <form method="POST" action="{{ route('firewall.pfblockerng.whitelist', $firewall) }}" class="inline"
                                        onsubmit="return confirm(@js('Never block ' . $e['domain'] . '? It is added to the DNSBL whitelist and DNSBL is reloaded.'))">
                                        @csrf
                                        <input type="hidden" name="domain" value="{{ $e['domain'] }}">
                                        <button class="text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">Whitelist</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-sm text-center text-gray-500 dark:text-gray-400">No DNSBL events logged.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div x-show="view === 'ip'" x-cloak class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/50"><tr>
                    <th class="{{ $ui['th'] }}">Time</th><th class="{{ $ui['th'] }}">Result</th><th class="{{ $ui['th'] }}">Interface</th>
                    <th class="{{ $ui['th'] }}">Source</th><th class="{{ $ui['th'] }}">Destination</th><th class="{{ $ui['th'] }}">Alias / feed</th><th class="{{ $ui['th'] }}">Country</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($r['ip'] ?? [] as $e)
                        <tr x-show="!q || @js(strtolower(implode(' ', [$e['src'], $e['dst'], $e['alias'], $e['feed'], $e['geoip'], $e['host']]))).includes(q.toLowerCase())">
                            <td class="{{ $ui['td'] }} whitespace-nowrap tabular-nums text-gray-500 dark:text-gray-400">{{ $e['time'] }}</td>
                            <td class="{{ $ui['td'] }} text-xs whitespace-nowrap">
                                <span @class(['px-2 py-0.5 rounded font-medium',
                                    'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300' => $e['kind'] === 'block',
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' => $e['kind'] === 'permit',
                                    'bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300' => $e['kind'] === 'match'])>{{ ucfirst($e['kind']) }} {{ $e['direction'] }}</span>
                            </td>
                            <td class="{{ $ui['td'] }} text-xs">{{ $e['interface'] }}</td>
                            <td class="{{ $ui['td'] }} font-mono text-xs whitespace-nowrap">{{ $e['src'] }}@if ($e['sport'] !== ''):{{ $e['sport'] }}@endif</td>
                            <td class="{{ $ui['td'] }} font-mono text-xs whitespace-nowrap">{{ $e['dst'] }}@if ($e['dport'] !== ''):{{ $e['dport'] }}@endif <span class="text-gray-500">{{ $e['proto'] }}</span></td>
                            <td class="{{ $ui['td'] }} text-xs">{{ $e['alias'] }}<span class="block text-gray-500">{{ $e['feed'] }}</span></td>
                            <td class="{{ $ui['td'] }} text-xs">{{ $e['geoip'] }}@if ($e['asn'] && $e['asn'] !== 'Unknown')<span class="block text-gray-500">{{ $e['asn'] }}</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-sm text-center text-gray-500 dark:text-gray-400">No IP events logged. Turn on logging for a group, or Global logging on the IP tab.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
