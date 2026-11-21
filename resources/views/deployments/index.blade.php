@php
    $tones = [
        'draft' => ['Checking firewalls', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'],
        'ready' => ['Ready to deploy', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'],
        'running' => ['Running', 'bg-brand-50 text-brand-800 dark:bg-brand-950 dark:text-brand-200'],
        'attention' => ['Needs attention', 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300'],
        'completed' => ['Completed', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'],
        'stopped' => ['Stopped', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'],
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-4">
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Deployments</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The same firewall rules, aliases, port forwards or pfBlockerNG lists on many firewalls, checked first, in waves, with undo.</p>
            </div>
            @if(!$why)
                <a href="{{ route('deployments.create') }}" class="rounded-md bg-brand-950 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-900 dark:bg-brand-500 dark:hover:bg-brand-400">New deployment</a>
            @endif
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-5">
            @include('vpn.admin._notices')
            @if($why)
                <p class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">{{ $why }}</p>
            @endif
            @if($manageAccess)
                @include('deployments._access')
            @endif
            <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                @forelse($deployments as $d)
                    @php [$word, $tone] = $tones[$d->status] ?? [$d->status, $tones['draft'][1]]; @endphp
                    <a href="{{ route('deployments.show', $d) }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-gray-100 px-6 py-4 last:border-0 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/40">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $d->name }}</span>
                            <span class="block text-sm text-gray-500 dark:text-gray-400">
                                {{ count($d->items) }} {{ \Illuminate\Support\Str::plural('change', count($d->items)) }} ·
                                {{ $d->targets_count }} {{ \Illuminate\Support\Str::plural('firewall', $d->targets_count) }} ·
                                {{ $d->created_by }} · {{ $d->created_at?->diffForHumans() }}
                            </span>
                        </span>
                        <span class="text-sm tabular-nums text-gray-600 dark:text-gray-300">
                            {{ $d->done_count }} of {{ $d->targets_count }} deployed
                            @if($d->package_count) · <span class="text-amber-700 dark:text-amber-300">{{ $d->package_count }} need a package</span>@endif
                            @if($d->attention_count) · <span class="text-red-700 dark:text-red-300">{{ $d->attention_count }} need attention</span>@endif
                        </span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $tone }}">{{ $word }}</span>
                    </a>
                @empty
                    <div class="px-6 py-12 text-center">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">No deployments yet</p>
                        <p class="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">Pick firewalls on the Firewalls page and choose "Deploy changes", or start a new deployment here.</p>
                    </div>
                @endforelse
            </section>
        </div>
    </div>
</x-app-layout>
