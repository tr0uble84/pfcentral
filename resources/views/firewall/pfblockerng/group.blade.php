@php
    $ro = auth()->user()->isReadOnly();
    $o = $status['options'] ?? [];
    $isDns = $type === 'dnsbl';
    $actions = $isDns ? ($o['dnsbl_action'] ?? []) : ($o['ip_action'] ?? []);
    $formats = $isDns ? ($o['dnsbl_format'] ?? []) : ($o['ip_format'] ?? []);
    $g = $group ?? [];
    $rows = old('rows', $g['rows'] ?? [['format' => 'auto', 'state' => 'Enabled', 'url' => '', 'header' => '']]);
    $val = fn ($k, $d = '') => old($k, $g[$k] ?? $d);
    $ui = [
        'input' => 'block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm',
        'label' => 'block text-sm font-medium text-gray-700 dark:text-gray-300',
        'help' => 'mt-1 text-xs text-gray-500 dark:text-gray-400',
        'check' => 'rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 focus:ring-indigo-500',
        'btn' => 'inline-flex items-center justify-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition',
        'btn2' => 'inline-flex items-center justify-center gap-2 px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-md shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition',
        'card' => 'bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg',
    ];
    $back = route('firewall.pfblockerng.index', ['firewall' => $firewall, 'tab' => 'feeds']);
@endphp
<x-app-layout>
    <x-slot name="header">
        <x-firewall-header title="{{ $group ? 'pfBlockerNG · ' . $group['aliasname'] : 'pfBlockerNG · New ' . $typeLabel . ' group' }}" :firewall="$firewall" />
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <a href="{{ $back }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">← Back to feeds</a>

            @if (session('error') || $errors->any())
                <div class="p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 dark:bg-rose-900/30 dark:border-rose-800 dark:text-rose-300 text-sm" role="alert">
                    @if (session('error'))<p>{{ session('error') }}</p>@endif
                    @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
                </div>
            @endif

            <form method="POST" class="space-y-6"
                action="{{ $group ? route('firewall.pfblockerng.groups.update', [$firewall, $type, $group['index']]) : route('firewall.pfblockerng.groups.store', [$firewall, $type]) }}"
                x-data="{
                    rows: @js(array_values($rows)),
                    cron: @js($val('cron', $isDns ? 'EveryDay' : '01hour')),
                    add() { this.rows.push({ format: 'auto', state: 'Enabled', url: '', header: '' }); },
                    remove(i) { this.rows.splice(i, 1); }
                }">
                @csrf
                @if ($group) @method('PUT') @endif

                <fieldset @disabled($ro) class="space-y-6">
                    <section class="{{ $ui['card'] }} p-6 space-y-5">
                        <div class="grid sm:grid-cols-2 gap-6">
                            <div>
                                <label for="aliasname" class="{{ $ui['label'] }}">Name</label>
                                <input id="aliasname" name="aliasname" value="{{ $val('aliasname') }}" required maxlength="{{ $isDns ? 64 : 24 }}" pattern="\w+"
                                    class="{{ $ui['input'] }} mt-1 font-mono">
                                <p class="{{ $ui['help'] }}">Letters, numbers and underscores only.{{ $isDns ? '' : ' Up to 24 characters; the firewall alias is called pfB_<name>.' }}</p>
                            </div>
                            <div>
                                <label for="description" class="{{ $ui['label'] }}">Description</label>
                                <input id="description" name="description" value="{{ $val('description') }}" maxlength="250" class="{{ $ui['input'] }} mt-1">
                            </div>
                            <div>
                                <label for="action" class="{{ $ui['label'] }}">Action</label>
                                <select id="action" name="action" class="{{ $ui['input'] }} mt-1">
                                    @foreach ($actions as $v => $l)<option value="{{ $v }}" @selected($val('action', $isDns ? 'unbound' : 'Deny_Both') === $v)>{{ $l }}</option>@endforeach
                                </select>
                                <p class="{{ $ui['help'] }}">
                                    @if ($isDns) Unbound blocks the domains. Disabled keeps the group without using it.
                                    @else Deny blocks, Permit always allows, Match only logs. Inbound and outbound use the interfaces on the IP tab. Alias actions only build the alias for your own rules.
                                    @endif
                                </p>
                            </div>
                            <div>
                                <label for="cron" class="{{ $ui['label'] }}">Update frequency</label>
                                <select id="cron" name="cron" x-model="cron" class="{{ $ui['input'] }} mt-1">
                                    @foreach ($o['cron'] ?? [] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                </select>
                            </div>
                            <div x-show="cron === 'Weekly'" x-cloak>
                                <label for="dow" class="{{ $ui['label'] }}">Weekly on</label>
                                <select id="dow" name="dow" class="{{ $ui['input'] }} mt-1">
                                    @foreach ($o['dow'] ?? [] as $v => $l)<option value="{{ $v }}" @selected((string) $val('dow', '1') === (string) $v)>{{ $l }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                @if ($isDns)
                                    <label for="logging" class="{{ $ui['label'] }}">Logging and blocked page</label>
                                    <select id="logging" name="logging" class="{{ $ui['input'] }} mt-1">
                                        @foreach ($o['dnsbl_logging'] ?? [] as $v => $l)<option value="{{ $v }}" @selected($val('logging', 'enabled') === $v)>{{ $l }}</option>@endforeach
                                    </select>
                                @else
                                    <label for="aliaslog" class="{{ $ui['label'] }}">Log blocked traffic</label>
                                    <select id="aliaslog" name="aliaslog" class="{{ $ui['input'] }} mt-1">
                                        @foreach ($o['aliaslog'] ?? [] as $v => $l)<option value="{{ $v }}" @selected($val('aliaslog', 'enabled') === $v)>{{ $l }}</option>@endforeach
                                    </select>
                                @endif
                            </div>
                        </div>
                    </section>

                    <section class="{{ $ui['card'] }} p-6">
                        <div class="flex items-center gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Sources</h3>
                                <p class="{{ $ui['help'] }}">List URLs (or file paths on the firewall). The header names each source in the logs and reports.</p>
                            </div>
                            @unless ($ro)<button type="button" @click="add()" class="{{ $ui['btn2'] }} ml-auto">Add source</button>@endunless
                        </div>
                        <div class="mt-4 space-y-3">
                            <template x-for="(row, i) in rows" :key="i">
                                <div class="grid gap-2 sm:grid-cols-12 items-start p-3 rounded-md border border-gray-200 dark:border-gray-700">
                                    <div class="sm:col-span-2">
                                        <label class="sr-only" :for="'state-' + i">State</label>
                                        <select :id="'state-' + i" :name="'rows[' + i + '][state]'" x-model="row.state" class="{{ $ui['input'] }}">
                                            @foreach ($o['state'] ?? [] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="sr-only" :for="'format-' + i">Format</label>
                                        <select :id="'format-' + i" :name="'rows[' + i + '][format]'" x-model="row.format" class="{{ $ui['input'] }}">
                                            @foreach ($formats as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div class="sm:col-span-5">
                                        <label class="sr-only" :for="'url-' + i">Source</label>
                                        <input :id="'url-' + i" :name="'rows[' + i + '][url]'" x-model="row.url" placeholder="https://example.com/list.txt" class="{{ $ui['input'] }} font-mono text-xs">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="sr-only" :for="'header-' + i">Header</label>
                                        <input :id="'header-' + i" :name="'rows[' + i + '][header]'" x-model="row.header" placeholder="Header" class="{{ $ui['input'] }} font-mono text-xs">
                                    </div>
                                    <div class="sm:col-span-1 flex sm:justify-end">
                                        @unless ($ro)
                                            <button type="button" @click="remove(i)" class="p-2 text-gray-400 hover:text-rose-600" :aria-label="'Remove source ' + (i + 1)">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        @endunless
                                    </div>
                                </div>
                            </template>
                            <p x-show="!rows.length" class="text-sm text-gray-500 dark:text-gray-400">No sources. Custom entries below are enough on their own.</p>
                        </div>
                    </section>

                    <section class="{{ $ui['card'] }} p-6">
                        <label for="custom" class="{{ $ui['label'] }}">Custom entries</label>
                        <textarea id="custom" name="custom" rows="8" class="{{ $ui['input'] }} mt-1 font-mono text-xs"
                            placeholder="{{ $isDns ? "baddomain.example\n.tracker.example # with subdomains" : "203.0.113.25\n198.51.100.0/24 # office blocks" }}">{{ $val('custom') }}</textarea>
                        <p class="{{ $ui['help'] }}">{{ $isDns ? 'Your own domains to block, one per line.' : 'Your own addresses or ranges, one per line.' }} Text after # is a comment.</p>
                    </section>
                </fieldset>

                @unless ($ro)
                    <div class="flex flex-wrap items-center gap-4">
                        <button type="submit" class="{{ $ui['btn'] }}">{{ $group ? 'Save group' : 'Add group' }}</button>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" name="update" value="on" class="{{ $ui['check'] }}" checked> Download and apply now (Force update)
                        </label>
                        <a href="{{ $back }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline ml-auto">Cancel</a>
                    </div>
                @endunless
            </form>
        </div>
    </div>
</x-app-layout>
