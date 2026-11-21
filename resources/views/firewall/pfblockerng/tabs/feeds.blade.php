@php
    $o = $status['options'] ?? [];
    $groups = $status['groups'] ?? [];
    $catalog = $extra['catalog'] ?? [];
@endphp

<x-confirm-delete-modal>
<div class="space-y-6">
    @foreach ($types as $type => $typeLabel)
        @php $actions = $type === 'dnsbl' ? ($o['dnsbl_action'] ?? []) : ($o['ip_action'] ?? []); @endphp
        <section class="{{ $ui['card'] }}">
            <div class="px-6 pt-5 pb-3 flex flex-wrap items-center gap-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $typeLabel }} groups</h3>
                    <p class="{{ $ui['help'] }}">{{ $type === 'dnsbl' ? 'Domain lists blocked through the DNS Resolver.' : 'Address lists turned into firewall aliases and rules.' }}</p>
                </div>
                @unless ($ro)
                    <a href="{{ route('firewall.pfblockerng.groups.create', [$firewall, $type]) }}" class="{{ $ui['btn2'] }} ml-auto">Add {{ $typeLabel }} group</a>
                @endunless
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="{{ $ui['th'] }}">Name</th><th class="{{ $ui['th'] }}">Description</th><th class="{{ $ui['th'] }}">Action</th>
                            <th class="{{ $ui['th'] }}">Updates</th><th class="{{ $ui['th'] }} text-right">Sources</th><th class="{{ $ui['th'] }} text-right">Entries</th>
                            <th class="{{ $ui['th'] }}"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($groups[$type] ?? [] as $g)
                            @php $active = collect($g['rows'])->where('state', '!=', 'Disabled')->count(); @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="{{ $ui['td'] }} font-medium whitespace-nowrap">{{ $g['aliasname'] }}</td>
                                <td class="{{ $ui['td'] }} text-gray-600 dark:text-gray-400 max-w-md truncate" title="{{ $g['description'] }}">{{ $g['description'] }}</td>
                                <td class="{{ $ui['td'] }} whitespace-nowrap">
                                    <span @class(['inline-flex px-2 py-0.5 rounded text-xs font-medium',
                                        'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $g['action'] === 'Disabled',
                                        'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300' => str_starts_with($g['action'], 'Deny') || $g['action'] === 'unbound',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' => str_starts_with($g['action'], 'Permit'),
                                        'bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300' => str_starts_with($g['action'], 'Match') || str_starts_with($g['action'], 'Alias'),
                                    ])>{{ $actions[$g['action']] ?? $g['action'] }}</span>
                                </td>
                                <td class="{{ $ui['td'] }} whitespace-nowrap">{{ $o['cron'][$g['cron']] ?? $g['cron'] }}</td>
                                <td class="{{ $ui['td'] }} text-right tabular-nums">{{ $active }}/{{ count($g['rows']) }}@if ($g['custom'] !== '')<span class="text-gray-500"> + custom</span>@endif</td>
                                <td class="{{ $ui['td'] }} text-right tabular-nums">{{ number_format($g['entries']) }}</td>
                                <td class="{{ $ui['td'] }} text-right whitespace-nowrap">
                                    <a href="{{ route('firewall.pfblockerng.groups.edit', [$firewall, $type, $g['index']]) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 font-medium">{{ $ro ? 'View' : 'Edit' }}</a>
                                    @unless ($ro)
                                        <button type="button" class="ml-3 text-rose-600 hover:text-rose-800 dark:text-rose-400 font-medium"
                                            @click="openDelete(@js(route('firewall.pfblockerng.groups.destroy', [$firewall, $type, $g['index']]) . '?aliasname=' . urlencode($g['aliasname'])), @js($g['aliasname']))">Delete</button>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-sm text-center text-gray-500 dark:text-gray-400">No {{ $typeLabel }} groups.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    <section class="{{ $ui['card'] }} p-6" x-data="{ open: 'ipv4' }">
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Add from the pfBlockerNG feed catalog</h3>
        <p class="{{ $ui['help'] }}">Ready-made groups that ship with pfBlockerNG. Feeds that need a registration key or are discontinued are left out; add those by hand if you need them.</p>

        <div class="mt-4 flex gap-2" role="tablist">
            @foreach ($types as $type => $typeLabel)
                <button type="button" role="tab" @click="open = '{{ $type }}'" :aria-selected="open === '{{ $type }}'"
                    :class="open === '{{ $type }}' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600'"
                    class="px-3 py-1.5 rounded-md text-sm font-medium transition">{{ $typeLabel }}</button>
            @endforeach
        </div>

        @foreach ($types as $type => $typeLabel)
            @php $actions = $type === 'dnsbl' ? ($o['dnsbl_action'] ?? []) : ($o['ip_action'] ?? []); @endphp
            <div x-show="open === '{{ $type }}'" @if ($type !== 'ipv4') x-cloak @endif class="mt-4 divide-y divide-gray-100 dark:divide-gray-700 border border-gray-200 dark:border-gray-700 rounded-md">
                @forelse ($catalog[$type] ?? [] as $c)
                    @php $usable = collect($c['feeds'])->reject(fn ($f) => $f['register'] || $f['discontinued']); @endphp
                    <div class="p-4 flex flex-col lg:flex-row lg:items-start gap-4" x-data="{ more: false }">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $c['key'] }}</span>
                                @if ($c['added'])<span class="px-2 py-0.5 rounded text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">Added</span>@endif
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $usable->count() }} {{ Str::plural('feed', $usable->count()) }}</span>
                            </div>
                            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">{{ $c['description'] }}</p>
                            <button type="button" class="mt-1 text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline" @click="more = !more" x-text="more ? 'Hide feeds' : 'Show feeds'"></button>
                            <ul x-show="more" x-cloak class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-400">
                                @if ($c['info'])<li class="whitespace-pre-line mb-2">{{ $c['info'] }}</li>@endif
                                @foreach ($c['feeds'] as $f)
                                    <li @class(['line-through opacity-60' => $f['register'] || $f['discontinued']])>
                                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ $f['feed'] }}</span>
                                        <span class="font-mono">{{ $f['header'] }}</span>
                                        @if ($f['register'])<span class="no-underline">(needs registration)</span>@elseif ($f['discontinued'])(discontinued)@endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        @if (!$ro && !$c['added'] && $usable->isNotEmpty())
                            <form method="POST" action="{{ route('firewall.pfblockerng.catalog', $firewall) }}" class="flex flex-wrap items-center gap-2 lg:justify-end">
                                @csrf
                                <input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="key" value="{{ $c['key'] }}">
                                <label class="sr-only" for="act-{{ $type }}-{{ $c['key'] }}">Action</label>
                                <select id="act-{{ $type }}-{{ $c['key'] }}" name="action" class="{{ $ui['input'] }} !w-auto py-1.5">
                                    @foreach ($actions as $v => $l)
                                        <option value="{{ $v }}" @selected($v === ($type === 'dnsbl' ? 'unbound' : 'Deny_Both'))>{{ $l }}</option>
                                    @endforeach
                                </select>
                                <label class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400"><input type="checkbox" name="update" value="on" class="{{ $ui['check'] }}" checked> Download now</label>
                                <button type="submit" class="{{ $ui['btn'] }} py-1.5">Add</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="p-4 text-sm text-gray-500 dark:text-gray-400">The catalog could not be read from the firewall.</p>
                @endforelse
            </div>
        @endforeach
    </section>
</div>
</x-confirm-delete-modal>
