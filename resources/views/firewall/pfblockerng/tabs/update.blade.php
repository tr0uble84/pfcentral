<div class="grid gap-6 lg:grid-cols-3"
    x-data="{
        running: @js((bool) ($status['running'] ?? false)),
        lines: [],
        error: '',
        timer: null,
        async poll() {
            try {
                const r = await fetch(@js(route('firewall.pfblockerng.progress', $firewall)), { headers: { 'Accept': 'application/json' } });
                const d = await r.json();
                if (!r.ok || d.ok === false) { this.error = d.error || 'Could not read the progress.'; return; }
                this.error = '';
                this.running = d.running;
                this.lines = d.log || [];
                this.$nextTick(() => { const el = this.$refs.log; if (el) el.scrollTop = el.scrollHeight; });
            } catch (e) { this.error = 'Could not read the progress.'; }
            this.timer = setTimeout(() => this.poll(), this.running ? 3000 : 20000);
        }
    }"
    x-init="poll()">

    <section class="{{ $ui['card'] }} p-6 space-y-5">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Run a task</h3>
            <p class="{{ $ui['help'] }}">Only one task can run at a time.</p>
        </div>
        @if ($ro)
            <p class="text-sm text-gray-500 dark:text-gray-400">Read-only users can watch the progress but cannot start tasks.</p>
        @else
            <form method="POST" action="{{ route('firewall.pfblockerng.run', $firewall) }}" class="space-y-1">
                @csrf <input type="hidden" name="task" value="update">
                <button class="{{ $ui['btn'] }} w-full" :disabled="running">Force update</button>
                <p class="{{ $ui['help'] }}">Download every feed again now, then rebuild the lists.</p>
            </form>
            <form method="POST" action="{{ route('firewall.pfblockerng.run', $firewall) }}" class="space-y-1">
                @csrf <input type="hidden" name="task" value="cron">
                <button class="{{ $ui['btn2'] }} w-full" :disabled="running">Force cron</button>
                <p class="{{ $ui['help'] }}">Run the scheduled job now: only feeds that are due are downloaded.</p>
            </form>
            <form method="POST" action="{{ route('firewall.pfblockerng.run', $firewall) }}" class="space-y-2">
                @csrf <input type="hidden" name="task" value="reload">
                <div class="flex gap-2">
                    <label class="sr-only" for="reload">What to reload</label>
                    <select id="reload" name="reload" class="{{ $ui['input'] }}">
                        <option value="All">IP and DNSBL</option>
                        <option value="IP">IP only</option>
                        <option value="DNSBL">DNSBL only</option>
                    </select>
                    <button class="{{ $ui['btn2'] }} shrink-0" :disabled="running">Force reload</button>
                </div>
                <p class="{{ $ui['help'] }}">Rebuild from the lists already downloaded and apply setting changes. A DNSBL reload restarts the DNS Resolver for a moment.</p>
            </form>
        @endif
    </section>

    <section class="{{ $ui['card'] }} p-6 lg:col-span-2 flex flex-col min-h-[28rem]">
        <div class="flex items-center gap-3">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">pfblockerng.log</h3>
            <span x-show="running" x-cloak class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300">
                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-pulse"></span> Running
            </span>
            <span x-show="!running" class="text-xs text-gray-500 dark:text-gray-400">Idle</span>
            <span x-show="error" x-text="error" x-cloak class="text-xs text-rose-600 dark:text-rose-400 ml-auto"></span>
        </div>
        <pre x-ref="log" class="mt-3 flex-1 overflow-auto rounded-md bg-gray-950 text-gray-100 text-xs leading-relaxed p-4 font-mono max-h-[36rem]"><template x-for="(line, i) in lines" :key="i"><div x-text="line || ' '"></div></template><div x-show="!lines.length" class="text-gray-400">No log yet.</div></pre>
    </section>
</div>
