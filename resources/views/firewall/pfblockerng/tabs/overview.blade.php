@php
    $groups = $status['groups'] ?? [];
    $o = $status['options'] ?? [];
    $ip = $status['ip'] ?? [];
    $dns = $status['dnsbl'] ?? [];
    $ifaces = $status['interfaces'] ?? [];
    $names = fn ($list) => collect($list)->map(fn ($i) => $ifaces[$i] ?? $i)->implode(', ') ?: 'none';
@endphp

@unless ($general['configured'] ?? false)
    <div class="p-4 rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800 text-sm text-amber-900 dark:text-amber-200 flex flex-wrap items-center gap-3">
        <span>pfBlockerNG is installed but has not been set up. Quick setup turns on the recommended IP and DNSBL lists in one step.</span>
        <a href="{{ route('firewall.pfblockerng.index', ['firewall' => $firewall, 'tab' => 'setup']) }}" class="{{ $ui['btn'] }} ml-auto">Run Quick setup</a>
    </div>
@endunless

<div class="grid gap-6 lg:grid-cols-3">
    <div class="{{ $ui['card'] }} p-6 lg:col-span-2">
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">How it is set up</h3>
        <dl class="mt-4 grid sm:grid-cols-2 gap-x-8 gap-y-4 text-sm">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">IP blocking</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100">Inbound on {{ $names($ip['inbound_interface'] ?? []) }} ({{ $ip['inbound_deny_action'] ?? 'block' }})<br>Outbound on {{ $names($ip['outbound_interface'] ?? []) }} ({{ $ip['outbound_deny_action'] ?? 'reject' }})</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">DNSBL (domain blocking)</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100">
                    {{ ($dns['pfb_dnsbl'] ?? '') === 'on' ? 'On' : 'Off' }} · {{ $o['dnsbl_mode'][$dns['dnsbl_mode'] ?? 'dnsbl_unbound'] ?? '' }}
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Update schedule</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100">
                    {{ $o['interval'][$general['pfb_interval'] ?? '1'] ?? '' }}, at minute {{ $o['min'][$general['pfb_min'] ?? '0'] ?? '00' }}
                    @unless ($status['cron'] ?? false)<span class="text-amber-700 dark:text-amber-300">(no cron job installed)</span>@endunless
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Events in the logs</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100 tabular-nums">
                    {{ number_format($status['counts']['events']['ip_block'] ?? 0) }} IP blocks ·
                    {{ number_format($status['counts']['events']['dnsbl'] ?? 0) }} DNSBL blocks
                </dd>
            </div>
        </dl>
    </div>

    <div class="{{ $ui['card'] }} p-6">
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Update now</h3>
        <p class="{{ $ui['help'] }}">Download the lists again and rebuild the firewall aliases and DNSBL.</p>
        @unless ($ro)
            <div class="mt-4 flex flex-wrap gap-2">
                <form method="POST" action="{{ route('firewall.pfblockerng.run', $firewall) }}">
                    @csrf <input type="hidden" name="task" value="update">
                    <button class="{{ $ui['btn'] }}">Force update</button>
                </form>
                <form method="POST" action="{{ route('firewall.pfblockerng.run', $firewall) }}">
                    @csrf <input type="hidden" name="task" value="reload"><input type="hidden" name="reload" value="All">
                    <button class="{{ $ui['btn2'] }}">Force reload</button>
                </form>
            </div>
        @endunless
    </div>
</div>

<div class="{{ $ui['card'] }}">
    <div class="px-6 pt-5 pb-3 flex items-center justify-between">
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Feed groups</h3>
        <a href="{{ route('firewall.pfblockerng.index', ['firewall' => $firewall, 'tab' => 'feeds']) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">Manage feeds →</a>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr><th class="{{ $ui['th'] }}">Type</th><th class="{{ $ui['th'] }}">Name</th><th class="{{ $ui['th'] }}">Action</th><th class="{{ $ui['th'] }}">Updates</th><th class="{{ $ui['th'] }} text-right">Entries</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @php $any = false; @endphp
                @foreach ($types as $type => $typeLabel)
                    @foreach ($groups[$type] ?? [] as $g)
                        @php $any = true; @endphp
                        <tr>
                            <td class="{{ $ui['td'] }} text-gray-500 dark:text-gray-400">{{ $typeLabel }}</td>
                            <td class="{{ $ui['td'] }} font-medium">{{ $g['aliasname'] }}</td>
                            <td class="{{ $ui['td'] }}">{{ ($type === 'dnsbl' ? $o['dnsbl_action'] : $o['ip_action'])[$g['action']] ?? $g['action'] }}</td>
                            <td class="{{ $ui['td'] }}">{{ $o['cron'][$g['cron']] ?? $g['cron'] }}</td>
                            <td class="{{ $ui['td'] }} text-right tabular-nums">{{ number_format($g['entries']) }}</td>
                        </tr>
                    @endforeach
                @endforeach
                @unless ($any)
                    <tr><td colspan="5" class="px-4 py-6 text-sm text-center text-gray-500 dark:text-gray-400">No feed groups yet. Add some under Feeds, or use Quick setup.</td></tr>
                @endunless
            </tbody>
        </table>
    </div>
</div>
