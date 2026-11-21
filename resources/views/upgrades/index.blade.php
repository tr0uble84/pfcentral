<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Upgrades</h2>
    </x-slot>

<script>
window.upgradeCentre = function (cfg) {
    const ago = (iso) => {
        if (!iso) return 'never';
        const s = (Date.now() - new Date(iso).getTime()) / 1000;
        if (s < 60) return 'just now';
        if (s < 3600) return Math.round(s / 60) + ' min ago';
        if (s < 86400) return Math.round(s / 3600) + ' h ago';
        return Math.round(s / 86400) + ' d ago';
    };
    return {
        firewalls: cfg.data.firewalls,
        rollouts: cfg.data.rollouts,
        canManage: cfg.data.can_manage,
        selected: [],
        q: '',
        filter: 'all',
        open: null,
        busy: false,
        timer: null,

        init() {
            this.timer = setInterval(() => this.refresh(), this.anyRunning() ? 5000 : 30000);
            this.$watch('firewalls', () => { clearInterval(this.timer); this.timer = setInterval(() => this.refresh(), this.anyRunning() ? 5000 : 30000); });
        },
        anyRunning() { return this.firewalls.some(f => f.check && f.check.status === 'running'); },
        async refresh() {
            if (document.hidden) return;
            try {
                const r = await fetch(cfg.dataUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (r.status === 401 || r.status === 419) { location.reload(); return; }
                if (!r.ok) return;
                const d = await r.json();
                this.firewalls = d.firewalls; this.rollouts = d.rollouts;
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

        // ---- table ----
        stateOf(f) {
            if (f.os !== 'pfsense') return 'unsupported';
            if (f.rollout) return 'rollout';
            if (!f.check) return 'unchecked';
            if (f.check.status === 'running') return 'checking';
            if (f.check.status === 'error') return 'error';
            if (f.check.status === 'fail') return 'blocked';
            if (f.check.available) return 'available';
            if (f.check.up_to_date) return 'current';
            return 'unknown';
        },
        label(f) {
            return {
                unsupported: 'OPNsense: not here yet', rollout: 'In an upgrade', unchecked: 'Not checked yet', checking: 'Checking…',
                error: 'Couldn\'t check', blocked: 'Fix before upgrading', available: 'Update available', current: 'Up to date', unknown: 'No answer about updates',
            }[this.stateOf(f)];
        },
        tone(f) {
            return {
                available: 'bg-brand-50 text-brand-800 ring-brand-200 dark:bg-brand-950 dark:text-brand-200 dark:ring-brand-800',
                current: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:ring-emerald-900',
                blocked: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950 dark:text-red-300 dark:ring-red-900',
                error: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:ring-amber-900',
                rollout: 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-950 dark:text-violet-300 dark:ring-violet-900',
            }[this.stateOf(f)] || 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600';
        },
        get rows() {
            const q = this.q.trim().toLowerCase();
            return this.firewalls.filter(f => {
                const s = this.stateOf(f);
                if (this.filter === 'available' && s !== 'available') return false;
                if (this.filter === 'problems' && !['blocked', 'error'].includes(s) && !(f.check && f.check.status === 'warn')) return false;
                if (this.filter === 'unchecked' && s !== 'unchecked') return false;
                return !q || [f.name, f.company, f.check?.version, f.check?.available].some(v => v && String(v).toLowerCase().includes(q));
            });
        },
        count(k) {
            return this.firewalls.filter(f => {
                const s = this.stateOf(f);
                return k === 'all' || (k === 'available' && s === 'available') || (k === 'unchecked' && s === 'unchecked')
                    || (k === 'problems' && (['blocked', 'error'].includes(s) || (f.check && f.check.status === 'warn')));
            }).length;
        },
        selectable(f) { return this.canManage && f.os === 'pfsense' && !f.rollout; },
        toggleAll(on) { this.selected = on ? this.rows.filter(f => this.selectable(f)).map(f => f.id) : []; },
        get allChecked() { const s = this.rows.filter(f => this.selectable(f)); return s.length > 0 && s.every(f => this.selected.includes(f.id)); },
        levelIcon(l) { return { ok: '✓', warn: '!', fail: '✕', info: 'i' }[l] || '·'; },
        levelTone(l) {
            return { ok: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', warn: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300', fail: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300', info: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }[l];
        },
        summary(f) {
            const c = (f.check?.checks || []);
            const n = (l) => c.filter(x => x.level === l).length;
            return { fail: n('fail'), warn: n('warn') };
        },

        async check(ids) {
            if (!ids.length || this.busy) return;
            this.busy = true;
            try {
                const d = await this.post(cfg.checkUrl, { firewall_ids: ids });
                window.showToast && window.showToast('info', 'Checking', d.message, 5000);
                ids.forEach(id => { const f = this.firewalls.find(x => x.id === id); if (f && f.os === 'pfsense') f.check = Object.assign({}, f.check || {}, { status: 'running' }); });
            } catch (e) {
                window.showToast && window.showToast('error', 'Not done', e.message, 7000);
            } finally { this.busy = false; }
        },
        plan() {
            if (!this.selected.length) return;
            const f = this.$refs.planForm;
            f.querySelectorAll('input[name="firewall_ids[]"]').forEach(i => i.remove());
            this.selected.forEach(id => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'firewall_ids[]'; i.value = id; f.appendChild(i); });
            f.submit();
        },
        rolloutTone(s) {
            return { running: 'bg-brand-50 text-brand-800 dark:bg-brand-950 dark:text-brand-200', attention: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300', completed: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', draft: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200', stopped: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' }[s];
        },
        rolloutLabel(s) { return { running: 'Running', attention: 'Needs attention', completed: 'Completed', draft: 'Not started', stopped: 'Stopped' }[s] || s; },
        ago,
    };
};
</script>

<div class="py-6" x-data="upgradeCentre(@js([
        'data' => $data,
        'dataUrl' => route('upgrades.data'),
        'checkUrl' => route('upgrades.check'),
    ]))" x-cloak>
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">
        @include('vpn.admin._notices')

        <div class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900 dark:border-brand-900 dark:bg-brand-950 dark:text-brand-100">
            <strong class="font-semibold">pfSense 26.10 is coming before the end of November.</strong>
            After it, a firewall on its own hardware can move to a new major version online only with a Netgate subscription; otherwise it's reinstalled from the installer and its configuration restored. Netgate hardware keeps online upgrades.
        </div>

        {{-- Upgrades in progress and recent --}}
        <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg" x-show="rollouts.length">
            <h3 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Upgrades</h3>
            <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                <template x-for="r in rollouts" :key="r.id">
                    <li>
                        <a :href="r.url" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium text-gray-900 dark:text-gray-100" x-text="r.name"></span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="'Planned by ' + r.created_by + ' · ' + ago(r.created)"></span>
                            </span>
                            <span class="w-40">
                                <span class="block h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><span class="block h-full rounded-full bg-brand-400 transition-all" :style="'width:' + (r.items ? Math.round(r.done / r.items * 100) : 0) + '%'"></span></span>
                                <span class="mt-1 block text-xs tabular-nums text-gray-500 dark:text-gray-400" x-text="r.done + ' of ' + r.items + ' done'"></span>
                            </span>
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="rolloutTone(r.status)" x-text="rolloutLabel(r.status)"></span>
                        </a>
                    </li>
                </template>
            </ul>
        </section>

        {{-- Firewalls --}}
        <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 p-4 dark:border-gray-700">
                <label class="relative min-w-[14rem] flex-1">
                    <span class="sr-only">Search</span>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="9" r="6"/><path d="m14 14 4 4" stroke-linecap="round"/></svg>
                    <input x-model.debounce.150ms="q" type="search" placeholder="Search firewall, company or version"
                           class="w-full rounded-md border-gray-300 py-2 pl-9 text-sm focus:border-brand-400 focus:ring-brand-400 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </label>
                <div class="inline-flex rounded-md bg-gray-100 p-0.5 dark:bg-gray-900" role="group" aria-label="Show">
                    <template x-for="o in [['all', 'All'], ['available', 'Update available'], ['problems', 'Needs a look'], ['unchecked', 'Not checked']]" :key="o[0]">
                        <button type="button" @click="filter = o[0]" :aria-pressed="filter === o[0]"
                                class="rounded px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
                                :class="filter === o[0] ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200'">
                            <span x-text="o[1]"></span> <span class="tabular-nums text-xs text-gray-400" x-text="count(o[0])"></span>
                        </button>
                    </template>
                </div>
                <template x-if="canManage">
                    <div class="flex items-center gap-2">
                        <button type="button" @click="check(selected.length ? selected : firewalls.filter(f => f.os === 'pfsense' && !f.rollout).map(f => f.id))" :disabled="busy"
                                class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                                x-text="selected.length ? 'Check ' + selected.length + ' selected' : 'Check all'"></button>
                        <button type="button" @click="plan()" :disabled="!selected.length"
                                class="rounded-md bg-brand-950 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-900 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-brand-500 dark:hover:bg-brand-400"
                                x-text="selected.length ? 'Plan upgrade (' + selected.length + ')' : 'Plan upgrade'"></button>
                    </div>
                </template>
            </div>
            <form x-ref="planForm" method="POST" action="{{ route('upgrades.plan') }}" class="hidden">@csrf</form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                        <tr>
                            <th class="w-10 py-2.5 pl-4" x-show="canManage"><input type="checkbox" class="rounded border-gray-300 text-brand-600 focus:ring-brand-400" :checked="allChecked" @change="toggleAll($event.target.checked)" aria-label="Select all"></th>
                            <th class="px-3 py-2.5">Firewall</th>
                            <th class="px-3 py-2.5">Version</th>
                            <th class="px-3 py-2.5">Status</th>
                            <th class="px-3 py-2.5">Pre-checks</th>
                            <th class="py-2.5 pl-3 pr-4 text-right">Last checked</th>
                        </tr>
                    </thead>
                        <template x-for="f in rows" :key="f.id">
                            <tbody class="border-t border-gray-100 dark:border-gray-700/70">
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="py-3 pl-4" x-show="canManage">
                                        <input type="checkbox" class="rounded border-gray-300 text-brand-600 focus:ring-brand-400 disabled:opacity-30" :value="f.id" x-model.number="selected" :disabled="!selectable(f)" :aria-label="'Select ' + f.name">
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="block font-medium text-gray-900 dark:text-gray-100" x-text="f.name"></span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="f.company"></span>
                                    </td>
                                    <td class="px-3 py-3 tabular-nums">
                                        <span class="block text-gray-900 dark:text-gray-100" x-text="f.check?.version || '—'"></span>
                                        <span class="block text-xs text-brand-700 dark:text-brand-300" x-show="f.check?.available" x-text="'→ ' + f.check?.available"></span>
                                    </td>
                                    <td class="px-3 py-3">
                                        <template x-if="f.rollout"><a :href="f.rollout.url" class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset hover:underline" :class="tone(f)" x-text="label(f)"></a></template>
                                        <template x-if="!f.rollout"><span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset" :class="tone(f)">
                                            <svg x-show="stateOf(f) === 'checking'" class="h-3 w-3 motion-safe:animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                            <span x-text="label(f)"></span></span></template>
                                    </td>
                                    <td class="px-3 py-3">
                                        <template x-if="f.check && f.check.checks && f.check.checks.length">
                                            <button type="button" @click="open = open === f.id ? null : f.id" :aria-expanded="open === f.id" class="flex items-center gap-1.5 rounded text-xs font-medium text-gray-700 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 dark:text-gray-300 dark:hover:text-white">
                                                <span x-show="summary(f).fail" class="rounded-full bg-red-100 px-1.5 text-red-700 dark:bg-red-950 dark:text-red-300" x-text="summary(f).fail + ' to fix'"></span>
                                                <span x-show="summary(f).warn" class="rounded-full bg-amber-100 px-1.5 text-amber-800 dark:bg-amber-950 dark:text-amber-300" x-text="summary(f).warn + ' to note'"></span>
                                                <span x-show="!summary(f).fail && !summary(f).warn" class="text-emerald-700 dark:text-emerald-400">All passed</span>
                                                <svg class="h-3.5 w-3.5 text-gray-400 transition-transform" :class="open === f.id && 'rotate-180'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 8 5 5 5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </button>
                                        </template>
                                        <span class="text-xs text-amber-700 dark:text-amber-400" x-show="f.check?.status === 'error'" x-text="f.check?.error"></span>
                                        <span class="text-xs text-gray-400" x-show="!f.check || (f.check.status === 'running' && !(f.check.checks || []).length)">—</span>
                                    </td>
                                    <td class="py-3 pl-3 pr-4 text-right text-xs text-gray-500 dark:text-gray-400" x-text="f.check?.at ? ago(f.check.at) : 'never'"></td>
                                </tr>
                                <tr x-show="open === f.id" x-cloak>
                                    <td :colspan="canManage ? 6 : 5" class="bg-gray-50 px-4 py-3 dark:bg-gray-900/40">
                                        <ul class="grid gap-2 md:grid-cols-2">
                                            <template x-for="c in (f.check?.checks || [])" :key="c.key">
                                                <li class="flex gap-2.5 text-sm">
                                                    <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full text-[11px] font-bold" :class="levelTone(c.level)" x-text="levelIcon(c.level)" aria-hidden="true"></span>
                                                    <span><span class="font-medium text-gray-900 dark:text-gray-100" x-text="c.label"></span> <span class="text-gray-600 dark:text-gray-300" x-text="c.detail"></span></span>
                                                </li>
                                            </template>
                                        </ul>
                                    </td>
                                </tr>
                            </tbody>
                        </template>
                </table>
                <div class="px-6 py-14 text-center text-sm text-gray-500 dark:text-gray-400" x-show="!rows.length">
                    <span x-show="!firewalls.length">No firewalls yet.</span>
                    <span x-show="firewalls.length">Nothing matches. <button type="button" class="font-medium text-brand-700 hover:underline dark:text-brand-300" @click="q = ''; filter = 'all'">Show all</button></span>
                </div>
            </div>
        </section>

        <section class="grid gap-4 text-sm text-gray-600 dark:text-gray-300 md:grid-cols-3">
            <div><h4 class="font-medium text-gray-900 dark:text-gray-100">1. Check</h4><p class="mt-1">PFCentral reads each firewall's version, free disk, saved config versions and whether a newer version is offered. It checks every night too.</p></div>
            <div><h4 class="font-medium text-gray-900 dark:text-gray-100">2. Plan in waves</h4><p class="mt-1">Select firewalls and plan an upgrade. The first one becomes wave 1, a test; the rest go in wave 2. You can move firewalls between waves.</p></div>
            <div><h4 class="font-medium text-gray-900 dark:text-gray-100">3. Start each wave</h4><p class="mt-1">For each firewall PFCentral checks again, saves the configuration, starts the upgrade and waits until it's back on the new version.</p></div>
        </section>
    </div>
</div>
</x-app-layout>
