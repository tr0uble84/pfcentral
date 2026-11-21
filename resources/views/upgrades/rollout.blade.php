<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Upgrades</h2>
    </x-slot>

<script>
window.upgradeRollout = function (cfg) {
    const fmt = (iso) => iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—';
    const mins = (a, b) => { if (!a) return ''; const s = ((b ? new Date(b) : new Date()) - new Date(a)) / 1000; return s < 60 ? Math.round(s) + ' s' : Math.round(s / 60) + ' min'; };
    return {
        d: cfg.data,
        u: cfg,
        busy: null,
        confirmWave: null,
        confirmStop: false,
        openLog: {},
        timer: null,
        tick: 0,

        init() {
            this.timer = setInterval(() => this.refresh(), 5000);
            setInterval(() => this.tick++, 1000);
        },
        async refresh() {
            if (document.hidden) return;
            try {
                const r = await fetch(cfg.dataUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (r.status === 401 || r.status === 419) { location.reload(); return; }
                if (r.status === 404) { location.href = cfg.indexUrl; return; }
                if (r.ok) this.d = await r.json();
            } catch (e) {}
        },
        async post(url, body) {
            const r = await fetch(url, {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify(body || {}),
            });
            let d = {}; try { d = await r.json(); } catch (e) {}
            if (!r.ok) throw new Error(d.message || 'The request failed (HTTP ' + r.status + ').');
            return d;
        },
        async act(key, url, body, toast) {
            if (this.busy) return;
            this.busy = key;
            try {
                const d = await this.post(url, body);
                if (d.redirect) { location.href = d.redirect; return; }
                if (toast || d.message) window.showToast && window.showToast('success', toast || 'Done', d.message || '', 6000);
                await this.refresh();
            } catch (e) {
                window.showToast && window.showToast('error', 'Not done', e.message, 8000);
            } finally { this.busy = null; this.confirmWave = null; this.confirmStop = false; }
        },
        url(tpl, item, wave) { return tpl.replace('__ITEM__', item ?? '').replace('__WAVE__', wave ?? ''); },

        // ---- display ----
        statusLabel(s) {
            return { pending: 'Not started', queued: 'Starting soon', backing_up: 'Checking and backing up', starting: 'Starting', upgrading: 'Upgrading', done: 'Upgraded', up_to_date: 'Already up to date', needs_api: 'Back, API missing', failed: 'Needs attention', skipped: 'Skipped', stopped: 'Not started' }[s] || s;
        },
        statusTone(s) {
            if (['done', 'up_to_date'].includes(s)) return 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:ring-emerald-900';
            if (['failed', 'needs_api'].includes(s)) return 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950 dark:text-red-300 dark:ring-red-900';
            if (['queued', 'backing_up', 'starting', 'upgrading'].includes(s)) return 'bg-brand-50 text-brand-800 ring-brand-200 dark:bg-brand-950 dark:text-brand-200 dark:ring-brand-800';
            return 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600';
        },
        active(s) { return ['queued', 'backing_up', 'starting', 'upgrading'].includes(s); },
        rolloutLabel(s) { return { running: 'Running', attention: 'Needs attention', completed: 'Completed', draft: 'Not started', stopped: 'Stopped' }[s] || s; },
        rolloutTone(s) {
            return { running: 'bg-brand-50 text-brand-800 dark:bg-brand-950 dark:text-brand-200', attention: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300', completed: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', draft: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200', stopped: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' }[s];
        },
        checkNote(i) {
            if (!i.check) return 'Not checked yet: PFCentral checks it again before starting.';
            if (i.check.status === 'running') return 'Checking…';
            if (i.check.status === 'error') return 'Last check failed: ' + (i.check.error || '');
            const f = (i.check.checks || []).filter(c => c.level === 'fail');
            if (f.length) return 'Will stop: ' + f.map(c => c.label).join(', ');
            if (i.check.up_to_date && !i.to) return 'Up to date at the last check.';
            const w = (i.check.checks || []).filter(c => c.level === 'warn');
            return w.length ? 'Passed, with notes: ' + w.map(c => c.label).join(', ') : 'Pre-checks passed.';
        },
        get total() { return this.d.waves.reduce((n, w) => n + w.items.length, 0); },
        get done() { return this.d.waves.reduce((n, w) => n + w.items.filter(i => ['done', 'up_to_date', 'skipped'].includes(i.status)).length, 0); },
        get finished() { return ['completed', 'stopped'].includes(this.d.rollout.status); },
        nextWave() { return Math.max(1, ...this.d.waves.map(w => w.wave)) + 1; },
        fmt, mins,
    };
};
</script>

<div class="py-6" x-data="upgradeRollout(@js([
        'data' => $data,
        'dataUrl' => route('upgrades.rollouts.data', $data['rollout']['id']),
        'indexUrl' => route('upgrades.index'),
        'startUrl' => route('upgrades.rollouts.start', [$data['rollout']['id'], '__WAVE__']),
        'stopUrl' => route('upgrades.rollouts.stop', $data['rollout']['id']),
        'waveUrl' => route('upgrades.rollouts.wave', [$data['rollout']['id'], '__ITEM__']),
        'removeUrl' => route('upgrades.rollouts.remove', [$data['rollout']['id'], '__ITEM__']),
        'retryUrl' => route('upgrades.rollouts.retry', [$data['rollout']['id'], '__ITEM__']),
        'skipUrl' => route('upgrades.rollouts.skip', [$data['rollout']['id'], '__ITEM__']),
    ]))" x-cloak>
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-5">
        <a href="{{ route('upgrades.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 5-5 5 5 5" stroke-linecap="round" stroke-linejoin="round"/></svg>All firewalls
        </a>
        @include('vpn.admin._notices')

        <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-wrap items-start gap-4">
                <div class="min-w-0 flex-1">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white" x-text="d.rollout.name"></h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400" x-text="'Planned by ' + d.rollout.created_by + ' · ' + fmt(d.rollout.created)"></p>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="rolloutTone(d.rollout.status)" x-text="rolloutLabel(d.rollout.status)"></span>
            </div>
            <div class="mt-5">
                <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-brand-400 transition-all duration-500" :style="'width:' + (total ? Math.round(done / total * 100) : 0) + '%'"></div></div>
                <p class="mt-2 text-sm tabular-nums text-gray-600 dark:text-gray-300" x-text="done + ' of ' + total + ' firewall' + (total === 1 ? '' : 's') + ' finished'"></p>
            </div>
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300" x-show="d.rollout.status === 'draft'">
                Start with wave 1. Each firewall in a wave is checked again, its configuration is saved here, and then it's upgraded and watched until it's back. The next wave can start once every firewall in the earlier waves finished.
            </p>
            <template x-if="d.can_manage && !finished">
                <div class="mt-4 flex items-center gap-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                    <button type="button" x-show="!confirmStop" @click="confirmStop = true" class="text-sm font-medium text-red-700 hover:underline dark:text-red-400">Stop this upgrade</button>
                    <template x-if="confirmStop">
                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            <span class="text-gray-700 dark:text-gray-200">Stop? Firewalls already upgrading finish on their own; nothing else starts.</span>
                            <button type="button" @click="act('stop', u.stopUrl, null, 'Stopped')" class="rounded-md bg-red-600 px-3 py-1.5 font-medium text-white hover:bg-red-700" :disabled="busy">Stop</button>
                            <button type="button" @click="confirmStop = false" class="rounded-md px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                        </div>
                    </template>
                </div>
            </template>
        </section>

        <template x-for="w in d.waves" :key="w.wave">
            <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                <header class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                    <h4 class="text-base font-semibold text-gray-900 dark:text-white">
                        Wave <span x-text="w.wave"></span>
                        <span class="ml-1 text-sm font-normal text-gray-500 dark:text-gray-400" x-text="'· ' + w.items.length + ' firewall' + (w.items.length === 1 ? '' : 's') + (w.wave === 1 && d.waves.length > 1 ? ' · the test' : '')"></span>
                    </h4>
                    <div class="ml-auto flex items-center gap-2" x-show="d.can_manage">
                        <span class="text-xs text-gray-500 dark:text-gray-400" x-show="!w.can_start && w.blocked && !w.started" x-text="w.blocked"></span>
                        <template x-if="w.can_start && confirmWave !== w.wave">
                            <button type="button" @click="confirmWave = w.wave" class="rounded-md bg-brand-950 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-900 dark:bg-brand-500 dark:hover:bg-brand-400" x-text="'Start wave ' + w.wave"></button>
                        </template>
                        <template x-if="confirmWave === w.wave">
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-gray-700 dark:text-gray-200" x-text="'Upgrade ' + w.items.filter(i => i.status === 'pending').length + ' firewall(s) now? They restart during the upgrade.'"></span>
                                <button type="button" @click="act('wave' + w.wave, url(u.startUrl, null, w.wave), null, 'Started')" :disabled="busy" class="rounded-md bg-brand-950 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-900 disabled:opacity-60 dark:bg-brand-500">Start</button>
                                <button type="button" @click="confirmWave = null" class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                            </div>
                        </template>
                    </div>
                </header>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700/70">
                    <template x-for="i in w.items" :key="i.id">
                        <li class="px-5 py-4">
                            <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-gray-900 dark:text-gray-100" x-text="i.firewall.name"></span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="i.firewall.company"></span>
                                    </div>
                                    <div class="mt-1 text-sm tabular-nums text-gray-600 dark:text-gray-300">
                                        <span x-text="i.from || 'Version not known yet'"></span>
                                        <span x-show="i.to" class="text-gray-400">→</span>
                                        <span x-show="i.to" class="font-medium text-gray-900 dark:text-gray-100" x-text="i.to"></span>
                                    </div>
                                </div>
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset" :class="statusTone(i.status)">
                                    <svg x-show="active(i.status)" class="h-3 w-3 motion-safe:animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                    <span x-text="statusLabel(i.status)"></span>
                                    <span x-show="i.status === 'upgrading'" class="tabular-nums" x-text="(tick, mins(i.started))"></span>
                                </span>
                            </div>

                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300" x-show="i.status === 'pending'" x-text="checkNote(i)"></p>
                            <p class="mt-2 break-words text-sm" :class="['failed', 'needs_api'].includes(i.status) ? 'text-red-700 dark:text-red-300' : 'text-gray-600 dark:text-gray-300'" x-show="i.message && i.status !== 'pending'" x-text="i.message"></p>

                            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                                <a x-show="i.backup" :href="i.backup" class="inline-flex items-center gap-1.5 font-medium text-brand-700 hover:underline dark:text-brand-300">
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 3v10m0 0-4-4m4 4 4-4M4 16h12" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <span x-text="'Config backup (' + fmt(i.backup_at) + ')'"></span>
                                </a>
                                <button type="button" x-show="i.log" @click="openLog[i.id] = !openLog[i.id]" class="font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white" x-text="openLog[i.id] ? 'Hide log' : 'Show log'"></button>
                                <template x-if="d.can_manage && !finished">
                                    <span class="flex flex-wrap items-center gap-x-4 gap-y-2">
                                        <label x-show="i.status === 'pending'" class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300">Wave
                                            <select class="rounded-md border-gray-300 py-1 pl-2 pr-7 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" :value="w.wave"
                                                    @change="act('wave' + i.id, url(u.waveUrl, i.id), { wave: +$event.target.value })">
                                                <template x-for="n in [...new Set([...d.waves.map(x => x.wave), nextWave()])].sort((a, b) => a - b)" :key="n"><option :value="n" x-text="n" :selected="n === w.wave"></option></template>
                                            </select>
                                        </label>
                                        <button type="button" x-show="i.status === 'pending'" @click="act('rm' + i.id, url(u.removeUrl, i.id))" class="font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Remove</button>
                                        <button type="button" x-show="['failed', 'needs_api'].includes(i.status)" @click="act('retry' + i.id, url(u.retryUrl, i.id), null, i.status === 'needs_api' ? 'Checking again' : 'Trying again')" class="rounded-md bg-brand-950 px-3 py-1.5 font-medium text-white hover:bg-brand-900 dark:bg-brand-500" x-text="i.status === 'needs_api' ? 'Check again' : 'Try again'"></button>
                                        <button type="button" x-show="['failed', 'needs_api', 'pending'].includes(i.status) && d.rollout.status !== 'draft'" @click="act('skip' + i.id, url(u.skipUrl, i.id), null, 'Skipped')" class="font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Skip</button>
                                    </span>
                                </template>
                            </div>
                            <pre x-show="openLog[i.id] && i.log" class="mt-3 max-h-56 overflow-auto whitespace-pre-wrap rounded-md bg-gray-950 p-3 font-mono text-xs leading-relaxed text-gray-100" x-text="i.log"></pre>
                        </li>
                    </template>
                </ul>
            </section>
        </template>

        <details class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <summary class="cursor-pointer px-5 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">If a firewall needs a reinstall instead (pfSense 26.10 and later, own hardware, no subscription)</summary>
            <ol class="list-decimal space-y-2 px-10 pb-5 text-sm text-gray-600 dark:text-gray-300">
                <li>Download the firewall's config backup above (or from its last check-and-backup).</li>
                <li>Download the pfSense installer from Netgate and write it to a USB stick.</li>
                <li>Boot the firewall from the USB stick and install. The installer offers to keep the configuration; if it doesn't find it, restore the backup under Diagnostics → Backup &amp; Restore.</li>
                <li>Reinstall the REST API package from the console (option 8): <code class="break-all rounded bg-gray-100 px-1 py-0.5 text-xs dark:bg-gray-900">{{ \App\Services\Upgrades\PfSenseFirmwareDriver::API_REINSTALL }}</code></li>
                <li>Back here, click "Check" on the Upgrades page to confirm the new version.</li>
            </ol>
        </details>
    </div>
</div>
</x-app-layout>
