@php
    $card = 'bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg';
    $btn = 'inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-semibold shadow-sm disabled:opacity-50 disabled:cursor-not-allowed';
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Server health</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The {{ config('brand.name') }} server itself: disk, database, Redis, background tasks and the maintenance timer. Firewalls are on the dashboard.</p>
    </x-slot>

<script>
window.healthPage = function (cfg) {
    const csrf = () => document.querySelector('meta[name=csrf-token]').content;
    return {
        checks: cfg.checks,
        overall: cfg.overall,
        checkedAt: cfg.checkedAt,
        busy: null,
        output: '',
        copied: null,
        label: { ok: 'OK', warn: 'Needs attention', bad: 'Problem', unknown: 'Unknown' },
        dot(s) { return { ok: 'bg-green-500', warn: 'bg-amber-500', bad: 'bg-red-600', unknown: 'bg-gray-400' }[s]; },
        pill(s) {
            return {
                ok: 'bg-green-50 text-green-800 ring-green-600/20 dark:bg-green-900/30 dark:text-green-200',
                warn: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-900/30 dark:text-amber-200',
                bad: 'bg-red-50 text-red-800 ring-red-600/20 dark:bg-red-900/30 dark:text-red-200',
                unknown: 'bg-gray-50 text-gray-700 ring-gray-500/20 dark:bg-gray-700 dark:text-gray-200',
            }[s];
        },
        when(iso) { return iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''; },
        async refresh() {
            this.busy = 'refresh';
            try {
                const r = await fetch(cfg.dataUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const d = await r.json();
                this.checks = d.checks; this.overall = d.overall; this.checkedAt = d.checkedAt;
            } catch (e) { window.showToast && window.showToast('error', 'Could not refresh', e.message, 6000); }
            finally { this.busy = null; }
        },
        async run(kind) {
            this.busy = kind; this.output = '';
            try {
                const r = await fetch(cfg[kind + 'Url'], { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
                let d = {}; try { d = await r.json(); } catch (e) {}
                if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status));
                this.output = d.message || 'Done.';
                window.showToast && window.showToast('success', 'Done', kind === 'maintenance' ? 'Maintenance finished.' : 'Cleanup finished.', 5000);
                this.busy = null;
                await this.refresh();
            } catch (e) {
                this.output = e.message;
                window.showToast && window.showToast('error', 'Not done', e.message, 10000);
            } finally { this.busy = null; }
        },
        copy(text, key) {
            navigator.clipboard && navigator.clipboard.writeText(text).then(() => { this.copied = key; setTimeout(() => this.copied = null, 1500); });
        },
    };
};
</script>

    <div class="py-8" x-data="healthPage(@js([
            'checks' => $checks,
            'overall' => $overall,
            'checkedAt' => $checkedAt,
            'dataUrl' => route('system.health.data'),
            'maintenanceUrl' => route('system.health.maintenance'),
            'cleanupUrl' => route('system.health.cleanup'),
        ]))">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="{{ $card }} p-5 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold ring-1 ring-inset" :class="pill(overall)" x-text="label[overall]"></span>
                    <span class="text-sm text-gray-500 dark:text-gray-400">Checked at <span x-text="when(checkedAt)"></span></span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="refresh()" :disabled="busy !== null"
                        class="{{ $btn }} bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 ring-1 ring-inset ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600">
                        <span x-text="busy === 'refresh' ? 'Checking…' : 'Check again'"></span>
                    </button>
                    <button type="button" @click="run('cleanup')" :disabled="busy !== null"
                        title="Removes expired cache rows, failed jobs older than 30 days, expired sessions and log files older than 14 days."
                        class="{{ $btn }} bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 ring-1 ring-inset ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600">
                        <span x-text="busy === 'cleanup' ? 'Cleaning up…' : 'Clean up PFCentral data'"></span>
                    </button>
                    <button type="button" @click="run('maintenance')" :disabled="busy !== null"
                        title="Runs the server maintenance now: trims MySQL change history to one day, the system journal and old logs."
                        class="{{ $btn }} bg-brand-600 text-white hover:bg-brand-500">
                        <span x-text="busy === 'maintenance' ? 'Running maintenance…' : 'Run maintenance now'"></span>
                    </button>
                </div>
            </div>

            <template x-if="output">
                <pre class="{{ $card }} p-4 text-xs text-gray-700 dark:text-gray-200 whitespace-pre-wrap" x-text="output"></pre>
            </template>

            <div class="{{ $card }} divide-y divide-gray-100 dark:divide-gray-700">
                <template x-for="c in checks" :key="c.key">
                    <div class="p-4 sm:px-5 flex gap-4">
                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="dot(c.status)" :title="label[c.status]"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="c.label"></h3>
                                <span class="text-sm font-medium tabular-nums" :class="c.status === 'ok' ? 'text-gray-700 dark:text-gray-300' : (c.status === 'bad' ? 'text-red-700 dark:text-red-300' : 'text-amber-700 dark:text-amber-300')" x-text="c.value"></span>
                            </div>
                            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400" x-text="c.detail"></p>
                            <template x-if="c.key === 'disk' && c.raw && c.raw.pct !== undefined">
                                <div class="mt-2 h-2 w-full max-w-md rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
                                    <div class="h-full rounded-full" :class="dot(c.status)" :style="'width:' + Math.min(100, c.raw.pct) + '%'"></div>
                                </div>
                            </template>
                            <template x-if="c.fix">
                                <div class="mt-2 flex items-start gap-2">
                                    <p class="text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-900/60 rounded px-2 py-1.5 font-mono break-all flex-1" x-text="c.fix"></p>
                                    <button type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400 hover:underline shrink-0 mt-1" @click="copy(c.fix, c.key)" x-text="copied === c.key ? 'Copied' : 'Copy'"></button>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <div class="{{ $card }} p-5 text-sm text-gray-600 dark:text-gray-400 space-y-2">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">How {{ config('brand.name') }} keeps the server clean</h3>
                <p>Every night it removes expired cache rows, failed jobs older than 30 days, expired sessions and log files older than 14 days.</p>
                <p>A maintenance timer on the server runs every 6 hours as root. It keeps one day of MySQL change history, caps the system journal, trims large logs and restarts Redis if it stops accepting writes. New installs get it automatically. On a server installed before v0.21, install it once:</p>
                <div class="flex items-start gap-2">
                    <code class="text-xs bg-gray-50 dark:bg-gray-900/60 rounded px-2 py-1.5 break-all flex-1">{{ $installCmd }}</code>
                    <button type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400 hover:underline shrink-0 mt-1" @click="copy(@js($installCmd), 'install')" x-text="copied === 'install' ? 'Copied' : 'Copy'"></button>
                </div>
                <p>To get disk warnings in Microsoft Teams, add <code class="text-xs">TEAMS_WEBHOOK_URL="…"</code> to <code class="text-xs">/etc/pfcentral-maintenance.conf</code> on the server.</p>
            </div>
        </div>
    </div>
</x-app-layout>
