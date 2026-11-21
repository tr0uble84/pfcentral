<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Deployments</h2>
    </x-slot>

<script>
window.deploymentPage = function (cfg) {
    const fmt = (iso) => iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—';
    const pad = (n) => String(n).padStart(2, '0');
    return {
        d: cfg.data,
        u: cfg,
        busy: null,
        confirm: null,
        open: {},
        when: {},
        timer: null,

        init() {
            this.timer = setInterval(() => this.refresh(), 4000);
            const t = new Date(Date.now() + 60 * 60 * 1000);
            const v = t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate()) + 'T' + pad(t.getHours()) + ':00';
            (this.d.waves || []).forEach(w => this.when[w.wave] = v);
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
                if (toast || d.message) window.showToast && window.showToast('success', toast || 'Done', d.message || '', 6000);
                await this.refresh();
            } catch (e) {
                window.showToast && window.showToast('error', 'Not done', e.message, 8000);
            } finally { this.busy = null; this.confirm = null; }
        },
        url(tpl, target, wave) { return tpl.replace('__T__', target ?? '').replace('__W__', wave ?? ''); },

        // ---- display ----
        label(s) {
            return { pending: 'Waiting to be checked', checking: 'Checking', ready: 'Ready', problem: 'Won\'t fit', needs_package: 'Needs a package', queued: 'Starting soon', deploying: 'Deploying',
                done: 'Deployed', failed: 'Needs attention', undone: 'Undone automatically', undoing: 'Undoing', reverted: 'Undone', skipped: 'Skipped' }[s] || s;
        },
        tone(s) {
            if (['done', 'ready'].includes(s)) return 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:ring-emerald-900';
            if (['failed', 'undone', 'problem'].includes(s)) return 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950 dark:text-red-300 dark:ring-red-900';
            if (s === 'needs_package') return 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-900';
            if (['checking', 'queued', 'deploying', 'undoing'].includes(s)) return 'bg-brand-50 text-brand-800 ring-brand-200 dark:bg-brand-950 dark:text-brand-200 dark:ring-brand-800';
            return 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600';
        },
        spinning(s) { return ['checking', 'queued', 'deploying', 'undoing'].includes(s); },
        planWord(s) { return { create: 'Will add', update: 'Will update', remove: 'Will remove', same: 'Already set', needs_package: 'Needs package', error: 'Won\'t fit' }[s] || s; },
        planTone(s) {
            return { remove: 'text-red-700 dark:text-red-300', create: 'text-emerald-700 dark:text-emerald-300', update: 'text-brand-700 dark:text-brand-300', same: 'text-gray-500 dark:text-gray-400', needs_package: 'text-amber-700 dark:text-amber-300', error: 'text-red-700 dark:text-red-300' }[s] || 'text-gray-500';
        },
        depLabel(s) { return { draft: 'Checking firewalls', ready: 'Ready to deploy', running: 'Running', attention: 'Needs attention', completed: 'Completed', stopped: 'Stopped' }[s] || s; },
        depTone(s) {
            return { running: 'bg-brand-50 text-brand-800 dark:bg-brand-950 dark:text-brand-200', attention: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300', completed: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', ready: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' }[s] || 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
        },
        inWave(w) { return this.d.targets.filter(t => t.wave === w); },
        pending(w) { return this.inWave(w).filter(t => ['ready', 'problem'].includes(t.status) || (t.status === 'needs_package' && !t.startedAt)).length; },
        count(list, states) { return list.filter(t => states.includes(t.status)).length; },
        get waveNumbers() { return [...new Set([...this.d.waves.map(w => w.wave), Math.max(1, ...this.d.waves.map(w => w.wave)) + 1])].sort((a, b) => a - b); },
        get finished() { return ['completed', 'stopped'].includes(this.d.status); },
        get undoable() { return this.d.targets.filter(t => t.canUndo).length; },
        canEditTarget(t) { return ['pending', 'checking', 'ready', 'problem'].includes(t.status) && !this.d.readOnly; },
        summary(t) {
            const p = t.plan || [];
            if (!p.length) return '';
            const c = s => p.filter(x => x.status === s).length;
            return [c('create') && c('create') + ' to add', c('update') && c('update') + ' to update', c('remove') && c('remove') + ' to remove', c('same') && c('same') + ' already set'].filter(Boolean).join(', ');
        },
        fmt,
    };
};
</script>

<div class="py-6" x-data="deploymentPage(@js([
        'data' => $data,
        'dataUrl' => route('deployments.data', $deployment),
        'indexUrl' => route('deployments.index'),
        'editUrl' => route('deployments.edit', $deployment),
        'againUrl' => route('deployments.create', ['from' => $deployment->id]),
        'recheckUrl' => route('deployments.recheck', $deployment),
        'startUrl' => route('deployments.start', [$deployment, '__W__']),
        'scheduleUrl' => route('deployments.schedule', [$deployment, '__W__']),
        'unscheduleUrl' => route('deployments.unschedule', $deployment),
        'stopUrl' => route('deployments.stop', $deployment),
        'undoAllUrl' => route('deployments.undo-all', $deployment),
        'waveUrl' => route('deployments.targets.wave', [$deployment, '__T__']),
        'removeUrl' => route('deployments.targets.remove', [$deployment, '__T__']),
        'retryUrl' => route('deployments.targets.retry', [$deployment, '__T__']),
        'skipUrl' => route('deployments.targets.skip', [$deployment, '__T__']),
        'undoUrl' => route('deployments.targets.undo', [$deployment, '__T__']),
    ]))" x-cloak>
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-5">
        <a href="{{ route('deployments.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 5-5 5 5 5" stroke-linecap="round" stroke-linejoin="round"/></svg>All deployments
        </a>
        @include('vpn.admin._notices')

        {{-- Summary --}}
        <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-wrap items-start gap-4">
                <div class="min-w-0 flex-1">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white" x-text="d.name"></h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400" x-text="'By ' + (d.createdBy || '—') + ' · ' + fmt(d.createdAt)"></p>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="depTone(d.status)" x-text="depLabel(d.status)"></span>
            </div>

            <h4 class="mt-5 text-sm font-medium text-gray-900 dark:text-gray-100">Changes</h4>
            <ol class="mt-2 space-y-1.5">
                <template x-for="(it, n) in d.items" :key="n">
                    <li class="flex items-baseline gap-2 text-sm">
                        <span class="w-5 shrink-0 text-right tabular-nums text-gray-400" x-text="n + 1"></span>
                        <span class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-200" x-text="it.kindLabel"></span>
                        <span class="min-w-0 break-words text-gray-800 dark:text-gray-200" x-text="it.label"></span>
                    </li>
                </template>
            </ol>
            <p class="mt-3 text-sm text-amber-800 dark:text-amber-200" x-show="d.why" x-text="d.why"></p>
            <p class="mt-3 text-sm text-amber-800 dark:text-amber-200" x-show="d.packages.length" x-text="'Needs ' + d.packages.join(', ') + ' on each firewall. Firewalls without it are skipped and listed below.'"></p>

            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300" x-show="d.draft">
                Nothing has been changed yet. Each firewall shows what the changes would do there. Start wave 1 (the test) now or at a set time; each firewall is checked again just before, and all its changes are saved together. If a firewall can't load the new rules, it puts the previous settings back by itself.
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-gray-100 pt-4 text-sm dark:border-gray-700" x-show="!d.readOnly">
                <a x-show="d.draft" :href="u.editUrl" class="font-medium text-brand-700 hover:underline dark:text-brand-300">Edit changes or firewalls</a>
                <button type="button" x-show="d.draft" @click="act('recheck', u.recheckUrl, null, 'Checking again')" :disabled="busy" class="font-medium text-gray-700 hover:text-gray-900 dark:text-gray-200 dark:hover:text-white">Check again</button>
                <a :href="u.againUrl" class="font-medium text-gray-700 hover:text-gray-900 dark:text-gray-200 dark:hover:text-white">Use as a new deployment</a>
                <template x-if="undoable && confirm !== 'undoAll'">
                    <button type="button" @click="confirm = 'undoAll'" class="font-medium text-red-700 hover:underline dark:text-red-400" x-text="'Undo on all ' + undoable + ' deployed firewall' + (undoable === 1 ? '' : 's')"></button>
                </template>
                <template x-if="confirm === 'undoAll'">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="text-gray-700 dark:text-gray-200">Take these changes off every firewall they were deployed to, and put back what they replaced?</span>
                        <button type="button" @click="act('undoAll', u.undoAllUrl, null, 'Undoing')" :disabled="busy" class="rounded-md bg-red-600 px-3 py-1.5 font-medium text-white hover:bg-red-700">Undo</button>
                        <button type="button" @click="confirm = null" class="rounded-md px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                    </span>
                </template>
                <template x-if="!finished && confirm !== 'stop'">
                    <button type="button" @click="confirm = 'stop'" class="font-medium text-red-700 hover:underline dark:text-red-400">Stop</button>
                </template>
                <template x-if="confirm === 'stop'">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="text-gray-700 dark:text-gray-200">Stop? Firewalls being deployed now finish; nothing else starts. Deployed firewalls keep the changes.</span>
                        <button type="button" @click="act('stop', u.stopUrl, null, 'Stopped')" :disabled="busy" class="rounded-md bg-red-600 px-3 py-1.5 font-medium text-white hover:bg-red-700">Stop</button>
                        <button type="button" @click="confirm = null" class="rounded-md px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                    </span>
                </template>
            </div>
            <div x-show="d.scheduledAt" class="mt-4 flex flex-wrap items-center gap-3 rounded-md bg-brand-50 px-3 py-2 text-sm text-brand-900 dark:bg-brand-950 dark:text-brand-100">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.5 2" stroke-linecap="round"/></svg>
                <span x-text="'Wave ' + d.scheduledWave + ' starts ' + fmt(d.scheduledAt) + ' (set by ' + d.scheduledBy + ').'"></span>
                <button type="button" x-show="!d.readOnly" @click="act('unschedule', u.unscheduleUrl, null, 'Schedule removed')" class="font-medium underline">Cancel the schedule</button>
            </div>
        </section>

        {{-- Waves --}}
        <template x-for="w in d.waves" :key="w.wave">
            <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                <header class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                    <h4 class="text-base font-semibold text-gray-900 dark:text-white">
                        Wave <span x-text="w.wave"></span>
                        <span class="ml-1 text-sm font-normal text-gray-500 dark:text-gray-400" x-text="'· ' + inWave(w.wave).length + ' firewall' + (inWave(w.wave).length === 1 ? '' : 's') + (w.wave === 1 && d.waves.length > 1 ? ' · the test' : '')"></span>
                    </h4>
                    <div class="ml-auto flex flex-wrap items-center gap-2" x-show="!d.readOnly && !finished">
                        <span class="text-xs text-gray-500 dark:text-gray-400" x-show="!w.canStart && w.why && (pending(w.wave) || count(inWave(w.wave), ['pending', 'checking']))" x-text="w.why"></span>
                        <template x-if="w.canStart && confirm !== 'w' + w.wave && confirm !== 's' + w.wave">
                            <span class="flex items-center gap-2">
                                <button type="button" @click="confirm = 's' + w.wave" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Schedule</button>
                                <button type="button" @click="confirm = 'w' + w.wave" class="rounded-md bg-brand-950 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-900 dark:bg-brand-500 dark:hover:bg-brand-400" x-text="'Deploy wave ' + w.wave + ' now'"></button>
                            </span>
                        </template>
                        <template x-if="!w.canStart && w.wave > 1 && confirm !== 's' + w.wave && pending(w.wave) && !d.scheduledAt">
                            <button type="button" @click="confirm = 's' + w.wave" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Schedule</button>
                        </template>
                        <template x-if="confirm === 'w' + w.wave">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm text-gray-700 dark:text-gray-200" x-text="'Deploy to ' + pending(w.wave) + ' firewall(s) now?'"></span>
                                <button type="button" @click="act('w' + w.wave, url(u.startUrl, null, w.wave), null, 'Started')" :disabled="busy" class="rounded-md bg-brand-950 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-900 disabled:opacity-60 dark:bg-brand-500">Deploy</button>
                                <button type="button" @click="confirm = null" class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                            </div>
                        </template>
                        <template x-if="confirm === 's' + w.wave">
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="text-sm text-gray-700 dark:text-gray-200">Start at
                                    <input type="datetime-local" x-model="when[w.wave]" class="ml-1 rounded-md border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                </label>
                                <button type="button" @click="act('s' + w.wave, url(u.scheduleUrl, null, w.wave), { at: when[w.wave], tz: Intl.DateTimeFormat().resolvedOptions().timeZone }, 'Scheduled')" :disabled="busy || !when[w.wave]" class="rounded-md bg-brand-950 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-900 disabled:opacity-60 dark:bg-brand-500">Schedule</button>
                                <button type="button" @click="confirm = null" class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                            </div>
                        </template>
                    </div>
                </header>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700/70">
                    <template x-for="t in inWave(w.wave)" :key="t.id">
                        <li class="px-5 py-4">
                            <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a :href="t.firewallUrl" class="font-medium text-gray-900 hover:underline dark:text-gray-100" x-text="t.firewall"></a>
                                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="t.company"></span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-gray-500 dark:text-gray-400">
                                        <span x-show="t.version" x-text="'pfSense ' + t.version"></span>
                                        <span x-show="d.packages.length && t.pfb" x-text="t.pfb && t.pfb.installed ? 'pfBlockerNG ' + t.pfb.version : 'No pfBlockerNG'"></span>
                                        <span x-show="summary(t) && !['done', 'reverted', 'ready'].includes(t.status)" x-text="summary(t)"></span>
                                    </div>
                                </div>
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset" :class="tone(t.status)">
                                    <svg x-show="spinning(t.status)" class="h-3 w-3 motion-safe:animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                    <span x-text="label(t.status)"></span>
                                </span>
                            </div>
                            <p class="mt-2 break-words text-sm" :class="['failed', 'undone', 'problem'].includes(t.status) ? 'text-red-700 dark:text-red-300' : (t.status === 'needs_package' ? 'text-amber-800 dark:text-amber-200' : 'text-gray-600 dark:text-gray-300')" x-show="t.message" x-text="t.message"></p>

                            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                                <button type="button" x-show="t.plan.length" @click="open[t.id] = !open[t.id]" class="font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white" x-text="open[t.id] ? 'Hide details' : 'Details per change'"></button>
                                <template x-if="!d.readOnly && !finished">
                                    <span class="flex flex-wrap items-center gap-x-4 gap-y-2">
                                        <label x-show="canEditTarget(t)" class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300">Wave
                                            <select class="rounded-md border-gray-300 py-1 pl-2 pr-7 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                                    @change="act('wave' + t.id, url(u.waveUrl, t.id), { wave: +$event.target.value })">
                                                <template x-for="n in waveNumbers" :key="n"><option :value="n" x-text="n" :selected="n === t.wave"></option></template>
                                            </select>
                                        </label>
                                        <button type="button" x-show="canEditTarget(t) && d.targets.length > 1" @click="act('rm' + t.id, url(u.removeUrl, t.id))" class="font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Remove</button>
                                        <button type="button" x-show="['failed', 'undone', 'needs_package', 'skipped'].includes(t.status) || (d.draft && ['problem', 'needs_package'].includes(t.status))" @click="act('retry' + t.id, url(u.retryUrl, t.id), null, d.draft ? 'Checking again' : 'Trying again')" class="rounded-md bg-brand-950 px-3 py-1.5 font-medium text-white hover:bg-brand-900 dark:bg-brand-500" x-text="d.draft ? 'Check again' : 'Retry'"></button>
                                        <button type="button" x-show="['failed', 'undone', 'needs_package', 'problem'].includes(t.status) && !d.draft" @click="act('skip' + t.id, url(u.skipUrl, t.id), null, 'Skipped')" class="font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Skip</button>
                                    </span>
                                </template>
                                <template x-if="!d.readOnly && t.canUndo && confirm !== 'u' + t.id">
                                    <button type="button" @click="confirm = 'u' + t.id" class="font-medium text-red-700 hover:underline dark:text-red-400">Undo on this firewall</button>
                                </template>
                                <template x-if="confirm === 'u' + t.id">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="text-gray-700 dark:text-gray-200">Remove what this deployment added and put back what it changed?</span>
                                        <button type="button" @click="act('u' + t.id, url(u.undoUrl, t.id), null, 'Undoing')" :disabled="busy" class="rounded-md bg-red-600 px-3 py-1.5 font-medium text-white hover:bg-red-700">Undo</button>
                                        <button type="button" @click="confirm = null" class="rounded-md px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                                    </span>
                                </template>
                            </div>

                            <ol x-show="open[t.id]" class="mt-3 space-y-1.5 rounded-md bg-gray-50 p-3 dark:bg-gray-900/60">
                                <template x-for="p in t.plan" :key="p.n">
                                    <li class="text-sm">
                                        <span class="font-medium" :class="planTone(p.status)" x-text="planWord(p.status)"></span>
                                        <span class="text-gray-700 dark:text-gray-200" x-text="'· ' + (d.items[p.n] ? d.items[p.n].label : 'Change ' + (p.n + 1)) + (p.target && d.items[p.n] && d.items[p.n].internal ? ' (' + p.target + ')' : '')"></span>
                                        <template x-for="m in p.messages" :key="m"><span class="block pl-4 text-xs text-gray-600 dark:text-gray-400" x-text="m"></span></template>
                                    </li>
                                </template>
                            </ol>
                        </li>
                    </template>
                </ul>
            </section>
        </template>
    </div>
</div>
</x-app-layout>
