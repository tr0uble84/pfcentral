@php
    $log = $extra['log'] ?? [];
    $current = $log['name'] ?? 'pfblockerng';
    $lines = (int) request('lines', 200);
@endphp

<div class="grid gap-6 lg:grid-cols-4">
    <nav class="{{ $ui['card'] }} p-3 h-max" aria-label="Log files">
        <ul class="space-y-0.5">
            @foreach ($logs as $key => $label)
                <li>
                    <a href="{{ route('firewall.pfblockerng.index', ['firewall' => $firewall, 'tab' => 'logs', 'log' => $key, 'lines' => $lines]) }}"
                        @class(['block px-3 py-1.5 rounded-md text-sm',
                            'bg-indigo-50 text-indigo-700 font-medium dark:bg-indigo-900/40 dark:text-indigo-300' => $current === $key,
                            'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700' => $current !== $key])
                        @if ($current === $key) aria-current="page" @endif>{{ $label }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    <section class="{{ $ui['card'] }} p-6 lg:col-span-3">
        <div class="flex flex-wrap items-center gap-3">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $logs[$current] ?? $current }}</h3>
            @if ($log['exists'] ?? false)
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ number_format(($log['size'] ?? 0) / 1024, 1) }} KB · changed {{ \Carbon\Carbon::createFromTimestamp($log['mtime'])->diffForHumans() }}
                </span>
            @endif
            <form method="GET" class="flex items-center gap-2 ml-auto">
                <input type="hidden" name="tab" value="logs"><input type="hidden" name="log" value="{{ $current }}">
                <label for="log-lines" class="text-sm text-gray-600 dark:text-gray-400">Last</label>
                <select id="log-lines" name="lines" class="{{ $ui['input'] }} !w-auto py-1.5" onchange="this.form.submit()">
                    @foreach ([50, 200, 500, 1000, 2000] as $n)<option value="{{ $n }}" @selected($lines === $n)>{{ number_format($n) }} lines</option>@endforeach
                </select>
                <a href="{{ request()->fullUrl() }}" class="{{ $ui['btn2'] }} py-1.5">Refresh</a>
            </form>
        </div>
        @if (!($log['exists'] ?? false))
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">This log file does not exist yet.</p>
        @else
            <pre class="mt-4 overflow-auto rounded-md bg-gray-950 text-gray-100 text-xs leading-relaxed p-4 font-mono max-h-[40rem]">@foreach ($log['lines'] ?? [] as $line){{ $line }}
@endforeach</pre>
        @endif
    </section>
</div>
