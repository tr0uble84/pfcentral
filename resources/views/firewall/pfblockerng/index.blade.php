@php
    $ro = auth()->user()->isReadOnly();
    $installed = $status['installed'] ?? false;
    $general = $status['general'] ?? [];
    $enabled = ($general['enable_cb'] ?? '') === 'on';
    $tabs = [
        'overview' => 'Overview',
        'general' => 'General',
        'ip' => 'IP',
        'dnsbl' => 'DNSBL',
        'feeds' => 'Feeds',
        'update' => 'Update',
        'reports' => 'Reports',
        'logs' => 'Logs',
        'setup' => 'Quick setup',
    ];
    $ui = [
        'input' => 'block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm',
        'label' => 'block text-sm font-medium text-gray-700 dark:text-gray-300',
        'help' => 'mt-1 text-xs text-gray-500 dark:text-gray-400',
        'check' => 'rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 focus:ring-indigo-500',
        'btn' => 'inline-flex items-center justify-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 transition',
        'btn2' => 'inline-flex items-center justify-center gap-2 px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-md shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition',
        'card' => 'bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg',
        'th' => 'px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider',
        'td' => 'px-4 py-2.5 text-sm text-gray-800 dark:text-gray-200',
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <x-firewall-header title="{{ __('pfBlockerNG') }}" :firewall="$firewall" />
    </x-slot>

    <div class="py-8">
        <div class="max-w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('success'))
                <div class="p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 dark:bg-emerald-900/30 dark:border-emerald-800 dark:text-emerald-300 text-sm" role="status">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error') || $errors->any())
                <div class="p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 dark:bg-rose-900/30 dark:border-rose-800 dark:text-rose-300 text-sm" role="alert">
                    @if (session('error'))<p>{{ session('error') }}</p>@endif
                    @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
                </div>
            @endif

            @if (!$supported)
                <div class="{{ $ui['card'] }}">
                    <x-api-not-supported :firewall="$firewall" url-suffix="" feature-name="pfBlockerNG"
                        message="pfBlockerNG is a pfSense package. On OPNsense, use its built-in Intrusion Detection and Unbound DNS blocklists instead." />
                </div>
            @elseif ($error)
                <div class="{{ $ui['card'] }} p-6">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Could not reach pfBlockerNG on this firewall</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $error }}</p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">PFCentral talks to pfBlockerNG through the pfSense REST API command prompt. Check that the firewall is online and that its API user may use Diagnostics → Command Prompt.</p>
                    <a href="{{ route('firewall.pfblockerng.index', ['firewall' => $firewall, 'tab' => $tab]) }}" class="{{ $ui['btn2'] }} mt-4">Try again</a>
                </div>
            @elseif (!$installed)
                <div class="{{ $ui['card'] }} p-6">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">pfBlockerNG is not installed on {{ $firewall->name }}</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400 max-w-2xl">
                        pfBlockerNG blocks traffic to and from known bad IP addresses (IP lists and GeoIP) and blocks ads, trackers and malware domains through the DNS Resolver (DNSBL).
                        Installing adds the <span class="font-mono">{{ \App\Services\PfBlockerNgService::PACKAGE }}</span> package from the pfSense package manager.
                    </p>
                    @unless ($ro)
                        <form method="POST" action="{{ route('firewall.pfblockerng.install', $firewall) }}" class="mt-4" x-data="{ busy: false }" @submit="busy = true">
                            @csrf
                            <button type="submit" class="{{ $ui['btn'] }}" :disabled="busy">
                                <span x-show="!busy">Install pfBlockerNG</span>
                                <span x-show="busy" x-cloak>Installing… this can take a few minutes</span>
                            </button>
                        </form>
                    @endunless
                </div>
            @else
                {{-- Status strip --}}
                <div class="{{ $ui['card'] }} px-6 py-4 flex flex-wrap items-center gap-x-8 gap-y-3">
                    <div class="flex items-center gap-2">
                        @if ($enabled)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Enabled
                            </span>
                        @elseif (!($general['configured'] ?? false))
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">Not set up yet</span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">Disabled</span>
                        @endif
                        @if ($status['running'] ?? false)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300">
                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-pulse"></span> Updating
                            </span>
                        @endif
                    </div>
                    <dl class="flex flex-wrap gap-x-8 gap-y-2 text-sm">
                        <div><dt class="inline text-gray-500 dark:text-gray-400">Version</dt> <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ $status['package']['version'] ?? '?' }}</dd></div>
                        <div><dt class="inline text-gray-500 dark:text-gray-400">Blocked IP entries</dt> <dd class="inline font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($status['counts']['ip_entries'] ?? 0) }}</dd></div>
                        <div><dt class="inline text-gray-500 dark:text-gray-400">DNSBL domains</dt> <dd class="inline font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($status['counts']['dnsbl_domains'] ?? 0) }}</dd></div>
                        <div><dt class="inline text-gray-500 dark:text-gray-400">Last update</dt> <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ !empty($status['last_update']) ? \Carbon\Carbon::createFromTimestamp($status['last_update'])->diffForHumans() : 'never' }}</dd></div>
                    </dl>
                    <a href="{{ rtrim($firewall->url, '/') }}/pfblockerng/pfblockerng_general.php" target="_blank" rel="noopener"
                        class="ml-auto text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">Open in pfSense ↗</a>
                </div>

                {{-- Tabs --}}
                <nav class="border-b border-gray-200 dark:border-gray-700 overflow-x-auto" aria-label="pfBlockerNG sections">
                    <ul class="flex gap-1 -mb-px min-w-max">
                        @foreach ($tabs as $key => $label)
                            <li>
                                <a href="{{ route('firewall.pfblockerng.index', ['firewall' => $firewall, 'tab' => $key]) }}"
                                    @class([
                                        'inline-block px-4 py-2.5 text-sm font-medium border-b-2 transition',
                                        'border-indigo-500 text-indigo-600 dark:text-indigo-400' => $tab === $key,
                                        'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300' => $tab !== $key,
                                    ])
                                    @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>

                @include('firewall.pfblockerng.tabs.' . $tab)
            @endif
        </div>
    </div>
</x-app-layout>
