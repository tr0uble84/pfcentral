@php
    $card = 'bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-200';
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100';
    $small = 'rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100';
    $help = 'mt-1 block text-xs text-gray-500 dark:text-gray-400';
    $kinds = \App\Services\Deploy\DeployItems::KINDS;
    $groups = \App\Services\Deploy\DeployItems::GROUPS;
    $fields = \App\Services\Deploy\DeployItems::fields();
    $settings = \App\Services\Deploy\DeployItems::SETTINGS;
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $deployment ? 'Edit deployment' : 'New deployment' }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The same changes on many firewalls. Each firewall is checked first; nothing changes until you start a wave.</p>
    </x-slot>

<script>
window.deployForm = function (cfg) {
    const blank = (kind) => {
        const it = { kind, mode: 'present' };
        (cfg.fields[kind] || []).forEach(f => {
            if (f.type === 'checkbox') it[f.key] = false;
            else if (f.type === 'rows') it[f.key] = [{ format: 'auto', state: 'Enabled', url: '', header: '' }];
            else if (f.type === 'ranges') it[f.key] = [{ days: [1, 2, 3, 4, 5], start: '08:00', stop: '17:59', descr: '' }];
            else it[f.key] = f.default ?? '';
        });
        return it;
    };
    const times = []; for (let h = 0; h < 24; h++) for (const m of ['00', '15', '30', '45', '59']) times.push(String(h).padStart(2, '0') + ':' + m);
    return {
        name: cfg.name,
        items: (cfg.items || []).map(i => Object.assign(blank(i.kind), i)),
        firewalls: cfg.firewalls,
        selected: cfg.selected.map(Number),
        q: '',
        open: 0,
        menu: false,
        times,
        days: [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']],
        copy: { open: false, fw: '', loading: false, error: '', items: [], picked: [], filter: '' },
        init() {
            if (!this.items.length && cfg.startKind) this.add(cfg.startKind);
        },
        spec(kind) { return cfg.fields[kind] || []; },
        isSetting(kind) { return cfg.settings.includes(kind); },
        visible(it, f) {
            if (it.mode === 'remove' && !f.id) return false;
            if (!f.when) return true;
            return Object.entries(f.when).every(([k, vals]) => vals.includes(String(it[k])));
        },
        add(kind) { this.items.push(blank(kind)); this.open = this.items.length - 1; this.menu = false; this.$nextTick(() => document.getElementById('item-' + this.open)?.scrollIntoView({ behavior: 'smooth', block: 'center' })); },
        remove(i) { this.items.splice(i, 1); this.open = Math.max(0, Math.min(this.open, this.items.length - 1)); },
        duplicate(i) { this.items.splice(i + 1, 0, JSON.parse(JSON.stringify(this.items[i]))); this.open = i + 1; },
        move(i, d) { const j = i + d; if (j < 0 || j >= this.items.length) return; const x = this.items.splice(i, 1)[0]; this.items.splice(j, 0, x); this.open = j; },
        get shown() { const q = this.q.trim().toLowerCase(); return this.firewalls.filter(f => !q || (f.name + ' ' + f.company).toLowerCase().includes(q)); },
        toggle(id) { const i = this.selected.indexOf(id); i === -1 ? this.selected.push(id) : this.selected.splice(i, 1); },
        allShown() { const ids = this.shown.filter(f => !f.opn).map(f => f.id); const all = ids.every(id => this.selected.includes(id)); this.selected = all ? this.selected.filter(id => !ids.includes(id)) : [...new Set([...this.selected, ...ids])]; },
        title(it) {
            const rm = it.mode === 'remove' ? 'Remove · ' : '';
            const main = it.descr || it.name || (it.host ? it.host + '.' : '') + (it.domain || '') || it.network || it.tunable || it.timezone || (it.servers ? String(it.servers).split(/\s+/).filter(Boolean).join(', ') : '') || 'New';
            const where = it.interface ? ' · ' + (it.interface === '@internal' ? 'every internal interface' : it.interface) : '';
            return rm + main + where;
        },
        toggleDay(r, d) { const i = r.days.indexOf(d); i === -1 ? r.days.push(d) : r.days.splice(i, 1); r.days.sort(); },
        payload() { return JSON.stringify(this.items); },

        // Copy from a firewall
        async loadCopy() {
            if (!this.copy.fw) return;
            this.copy.loading = true; this.copy.error = ''; this.copy.items = []; this.copy.picked = [];
            try {
                const r = await fetch(cfg.exportUrl.replace('__FW__', this.copy.fw), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                let d = {}; try { d = await r.json(); } catch (e) {}
                if (!r.ok) throw new Error(d.message || 'Couldn\'t read the firewall (HTTP ' + r.status + ').');
                this.copy.items = d.items;
                if (!d.items.length) this.copy.error = 'Nothing on this firewall can be copied (rules and NAT need a description).';
            } catch (e) { this.copy.error = e.message; } finally { this.copy.loading = false; }
        },
        get copyShown() { const q = this.copy.filter.trim().toLowerCase(); return this.copy.items.map((it, i) => ({ it, i })).filter(x => !q || (x.it.label + ' ' + x.it.kindLabel).toLowerCase().includes(q)); },
        get copyKinds() { return [...new Set(this.copyShown.map(x => x.it.kindLabel))]; },
        copyToggle(i) { const p = this.copy.picked.indexOf(i); p === -1 ? this.copy.picked.push(i) : this.copy.picked.splice(p, 1); },
        copyAll(kindLabel) { const ids = this.copyShown.filter(x => x.it.kindLabel === kindLabel).map(x => x.i); const all = ids.every(i => this.copy.picked.includes(i)); this.copy.picked = all ? this.copy.picked.filter(i => !ids.includes(i)) : [...new Set([...this.copy.picked, ...ids])]; },
        copyAdd() {
            [...this.copy.picked].sort((a, b) => a - b).forEach(i => { const it = JSON.parse(JSON.stringify(this.copy.items[i])); delete it.label; delete it.kindLabel; this.items.push(Object.assign(blank(it.kind), it)); });
            this.selected = this.selected.filter(id => id !== +this.copy.fw);
            this.copy.open = false; this.open = -1;
        },
    };
};
</script>

<div class="py-6" x-data="deployForm(@js([
        'name' => old('name', $name),
        'items' => old('items') ? json_decode(old('items'), true) : $items,
        'firewalls' => $firewalls,
        'selected' => old('firewall_ids', $selected),
        'startKind' => $startKind,
        'kinds' => $kinds,
        'fields' => $fields,
        'settings' => $settings,
        'exportUrl' => route('deployments.export', '__FW__'),
    ]))" x-cloak>
    <form method="POST" action="{{ $deployment ? route('deployments.update', $deployment) : route('deployments.store') }}" class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-5" @submit="$refs.items.value = payload()">
        @csrf
        @if($deployment) @method('PUT') @endif
        <input type="hidden" name="items" x-ref="items">
        <template x-for="id in selected" :key="id"><input type="hidden" name="firewall_ids[]" :value="id"></template>

        <a href="{{ $deployment ? route('deployments.show', $deployment) : route('deployments.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 5-5 5 5 5" stroke-linecap="round" stroke-linejoin="round"/></svg>{{ $deployment ? 'Back to the deployment' : 'All deployments' }}
        </a>

        @if($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200" role="alert">
                <p class="font-medium">Please fix:</p>
                <ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="{{ $card }} p-6">
            <label class="{{ $label }}">Name
                <input name="name" x-model="name" maxlength="120" placeholder="For example: RDP allow list on all DCs" class="{{ $input }}">
            </label>
            <p class="{{ $help }}">Shown in the list of deployments and in each firewall's configuration history.</p>
        </section>

        {{-- Changes --}}
        <section class="{{ $card }}">
            <header class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Changes</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Applied in this order, all in one save on each firewall. Something with the same name (for rules and NAT: the same description on the same interface) is updated to match. Any change can also remove that item from the firewalls.</p>
                </div>
                <button type="button" @click="copy.open = true" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Copy from a firewall…</button>
            </header>

            <ol class="divide-y divide-gray-100 dark:divide-gray-700/70">
                <template x-for="(it, i) in items" :key="i">
                    <li :id="'item-' + i" class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            <button type="button" @click="open = open === i ? -1 : i" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold tabular-nums text-gray-600 dark:bg-gray-700 dark:text-gray-300" x-text="i + 1"></span>
                                <span class="shrink-0 rounded px-1.5 py-0.5 text-xs font-medium" :class="it.mode === 'remove' ? 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200' : 'bg-brand-50 text-brand-800 dark:bg-brand-950 dark:text-brand-200'" x-text="@js($kinds)[it.kind]"></span>
                                <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="title(it)"></span>
                            </button>
                            <button type="button" @click="move(i, -1)" :disabled="i === 0" class="rounded p-1 text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" aria-label="Move up"><svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m5 12 5-5 5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                            <button type="button" @click="move(i, 1)" :disabled="i === items.length - 1" class="rounded p-1 text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" aria-label="Move down"><svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m5 8 5 5 5-5" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                            <button type="button" @click="duplicate(i)" class="rounded px-2 py-1 text-sm font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">Copy</button>
                            <button type="button" @click="remove(i)" class="rounded px-2 py-1 text-sm font-medium text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950">Delete</button>
                        </div>

                        <div x-show="open === i" class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2 flex flex-wrap items-center gap-4 text-sm text-gray-700 dark:text-gray-200" x-show="!isSetting(it.kind)">
                                <label class="flex items-center gap-2"><input type="radio" value="present" x-model="it.mode" class="text-brand-600 focus:ring-brand-500"> Add, or update to match</label>
                                <label class="flex items-center gap-2"><input type="radio" value="remove" x-model="it.mode" class="text-red-600 focus:ring-red-500"> Remove from the firewalls</label>
                            </div>
                            <p class="sm:col-span-2 text-xs text-gray-500 dark:text-gray-400" x-show="it.mode === 'remove'">Only the fields that identify it are needed. Firewalls that don't have it show "Already set".</p>
                            <p class="sm:col-span-2 text-xs text-gray-500 dark:text-gray-400" x-show="isSetting(it.kind)">One setting per firewall: it replaces what the firewall has now. Undo puts the old value back.</p>

                            <template x-for="f in spec(it.kind)" :key="f.key">
                                <div x-show="visible(it, f)" :class="f.wide || ['rows', 'ranges', 'textarea'].includes(f.type) ? 'sm:col-span-2' : ''">
                                    <template x-if="f.type === 'text' || f.type === 'number'">
                                        <label class="{{ $label }}"><span x-text="f.label"></span>
                                            <input :type="f.type === 'number' ? 'number' : 'text'" x-model="it[f.key]" :maxlength="f.type === 'text' ? f.max : null" :min="f.min" :max="f.type === 'number' ? f.max : null" :placeholder="f.placeholder || ''" :list="f.list || null" class="{{ $input }}">
                                            <span class="{{ $help }}" x-show="f.help" x-text="f.help"></span>
                                        </label>
                                    </template>
                                    <template x-if="f.type === 'textarea'">
                                        <label class="{{ $label }}"><span x-text="f.label"></span>
                                            <textarea x-model="it[f.key]" rows="4" :placeholder="f.placeholder || ''" class="{{ $input }} font-mono"></textarea>
                                            <span class="{{ $help }}" x-show="f.help" x-text="f.help"></span>
                                        </label>
                                    </template>
                                    <template x-if="f.type === 'select'">
                                        <label class="{{ $label }}"><span x-text="f.label"></span>
                                            <select x-model="it[f.key]" class="{{ $input }}">
                                                <template x-for="(t, v) in f.options" :key="v"><option :value="v" x-text="t" :selected="String(it[f.key]) === String(v)"></option></template>
                                            </select>
                                            <span class="{{ $help }}" x-show="f.help" x-text="f.help"></span>
                                        </label>
                                    </template>
                                    <template x-if="f.type === 'checkbox'">
                                        <label class="flex items-center gap-2 pt-1 text-sm text-gray-700 dark:text-gray-200 sm:pt-6"><input type="checkbox" x-model="it[f.key]" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600"> <span x-text="f.label"></span></label>
                                    </template>
                                    <template x-if="f.type === 'rows'">
                                        <div>
                                            <span class="{{ $label }}" x-text="f.label"></span>
                                            <template x-for="(r, ri) in it[f.key]" :key="ri">
                                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                                    <select x-model="r.format" style="width:8rem" class="{{ $small }}" aria-label="Format"><template x-for="(t, v) in f.formats" :key="v"><option :value="v" x-text="t" :selected="r.format === v"></option></template></select>
                                                    <select x-model="r.state" style="width:6.5rem" class="{{ $small }}" aria-label="State"><option value="Enabled">On</option><option value="Flex">Flex</option><option value="Hold">Hold</option><option value="Disabled">Off</option></select>
                                                    <input x-model="r.url" style="flex:1 1 16rem;min-width:12rem" class="{{ $small }}" placeholder="https://… /list.txt" aria-label="Source URL">
                                                    <input x-model="r.header" style="width:11rem" class="{{ $small }}" placeholder="Header" aria-label="Header">
                                                    <button type="button" @click="it[f.key].splice(ri, 1)" class="rounded px-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950">Remove</button>
                                                </div>
                                            </template>
                                            <button type="button" @click="it[f.key].push({ format: 'auto', state: 'Enabled', url: '', header: '' })" class="mt-2 text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">+ Add a source</button>
                                            <p class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">Needs pfBlockerNG (version 3 or newer). Firewalls without it are skipped and listed so you can install it and retry. After deploying, pfBlockerNG updates its lists by itself.</p>
                                        </div>
                                    </template>
                                    <template x-if="f.type === 'ranges'">
                                        <div>
                                            <span class="{{ $label }}" x-text="f.label"></span>
                                            <template x-for="(r, ri) in it[f.key]" :key="ri">
                                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                                    <template x-for="d in days" :key="d[0]">
                                                        <button type="button" @click="toggleDay(r, d[0])" class="rounded-md border px-2 py-1 text-xs font-medium" :class="r.days.includes(d[0]) ? 'border-brand-600 bg-brand-50 text-brand-800 dark:bg-brand-950 dark:text-brand-200' : 'border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300'" :aria-pressed="r.days.includes(d[0])" x-text="d[1]"></button>
                                                    </template>
                                                    <select x-model="r.start" class="{{ $small }}" aria-label="From"><template x-for="t in times" :key="t"><option :value="t" x-text="t" :selected="r.start === t"></option></template></select>
                                                    <span class="text-sm text-gray-500">to</span>
                                                    <select x-model="r.stop" class="{{ $small }}" aria-label="Until"><template x-for="t in times" :key="t"><option :value="t" x-text="t" :selected="r.stop === t"></option></template></select>
                                                    <button type="button" @click="it[f.key].splice(ri, 1)" class="rounded px-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950">Remove</button>
                                                </div>
                                            </template>
                                            <button type="button" @click="it[f.key].push({ days: [1, 2, 3, 4, 5], start: '08:00', stop: '17:59', descr: '' })" class="mt-2 text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">+ Add a time range</button>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </li>
                </template>
                <li x-show="!items.length" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No changes yet. Add one below, or copy them from a firewall.</li>
            </ol>

            <div class="relative border-t border-gray-100 px-6 py-4 dark:border-gray-700" @click.outside="menu = false">
                <button type="button" @click="menu = !menu" :aria-expanded="menu" class="rounded-md bg-brand-950 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-900 dark:bg-brand-500 dark:hover:bg-brand-400">+ Add a change</button>
                <div x-show="menu" x-transition.opacity class="absolute left-6 z-20 mt-2 grid gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-lg dark:border-gray-700 dark:bg-gray-800 sm:grid-cols-3" style="width:min(44rem,calc(100vw - 3rem))">
                    @foreach($groups as $g => $list)
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $g }}</p>
                            <ul class="mt-1">
                                @foreach($list as $k)
                                    <li><button type="button" @click="add('{{ $k }}')" class="w-full rounded px-2 py-1.5 text-left text-sm text-gray-800 hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-gray-700">{{ $kinds[$k] }}</button></li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Firewalls --}}
        <section class="{{ $card }}">
            <header class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Firewalls</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><span class="tabular-nums" x-text="selected.length"></span> selected. The first one becomes wave 1, the test; the rest are wave 2. You can change waves on the next page.</p>
                </div>
                <input x-model="q" type="search" placeholder="Search firewalls or companies" class="w-64 {{ $small }}">
                <button type="button" @click="allShown()" class="text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">Select / clear shown</button>
            </header>
            <ul class="max-h-96 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700/70">
                <template x-for="f in shown" :key="f.id">
                    <li>
                        <label class="flex items-center gap-3 px-6 py-2.5" :class="f.opn ? 'opacity-60' : 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40'">
                            <input type="checkbox" :checked="selected.includes(f.id)" @change="toggle(f.id)" :disabled="f.opn" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-gray-900 dark:text-gray-100" x-text="f.name"></span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="f.company + (f.opn ? ' · OPNsense, not supported yet' : '')"></span>
                            </span>
                            <span x-show="selected.indexOf(f.id) === 0" class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-800 dark:bg-brand-950 dark:text-brand-200">Wave 1</span>
                        </label>
                    </li>
                </template>
                <li x-show="!shown.length" class="px-6 py-6 text-center text-sm text-gray-500">No firewalls match.</li>
            </ul>
        </section>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ $deployment ? route('deployments.show', $deployment) : route('deployments.index') }}" class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</a>
            <button type="submit" :disabled="!items.length || !selected.length" class="rounded-md bg-brand-950 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-900 disabled:opacity-50 dark:bg-brand-500 dark:hover:bg-brand-400">Check the firewalls</button>
        </div>
        <datalist id="pfc-ifaces"><option value="WAN"><option value="LAN"><option value="OPT1"><option value="OPT2"><option value="@internal" label="Every internal interface"></datalist>
    </form>

    {{-- Copy from a firewall --}}
    <div x-show="copy.open" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-gray-900/50 p-4 pt-16" role="dialog" aria-modal="true" aria-labelledby="copy-title" @keydown.escape.window="copy.open = false">
        <div class="w-full max-w-3xl rounded-lg bg-white shadow-xl dark:bg-gray-800" @click.outside="copy.open = false">
            <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                <h3 id="copy-title" class="text-base font-semibold text-gray-900 dark:text-white">Copy from a firewall</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Read one firewall's rules, aliases, NAT, DNS overrides, routes, pfBlockerNG lists and settings, and pick what to send to the others. Nothing on that firewall changes.</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <select x-model="copy.fw" class="{{ $small }}" style="min-width:16rem" aria-label="Firewall to copy from">
                        <option value="">Pick a firewall…</option>
                        <template x-for="f in firewalls.filter(x => !x.opn)" :key="f.id"><option :value="f.id" x-text="f.name + ' · ' + f.company"></option></template>
                    </select>
                    <button type="button" @click="loadCopy()" :disabled="!copy.fw || copy.loading" class="rounded-md bg-brand-950 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-900 disabled:opacity-50 dark:bg-brand-500" x-text="copy.loading ? 'Reading…' : 'Read'"></button>
                    <input x-show="copy.items.length" x-model="copy.filter" type="search" placeholder="Filter" class="ml-auto w-48 {{ $small }}">
                </div>
                <p x-show="copy.error" class="mt-2 text-sm text-red-700 dark:text-red-300" x-text="copy.error"></p>
            </div>
            <div class="overflow-y-auto px-6 py-3" style="max-height:55vh">
                <template x-for="k in copyKinds" :key="k">
                    <div class="py-2">
                        <div class="flex items-center gap-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" x-text="k"></p>
                            <button type="button" @click="copyAll(k)" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Select / clear</button>
                        </div>
                        <template x-for="x in copyShown.filter(y => y.it.kindLabel === k)" :key="x.i">
                            <label class="mt-1 flex cursor-pointer items-start gap-2 rounded px-1 py-1 text-sm hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <input type="checkbox" :checked="copy.picked.includes(x.i)" @change="copyToggle(x.i)" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600">
                                <span class="text-gray-800 dark:text-gray-100" x-text="x.it.label"></span>
                            </label>
                        </template>
                    </div>
                </template>
                <p x-show="!copy.loading && !copy.items.length && !copy.error" class="py-6 text-center text-sm text-gray-500">Pick a firewall and click Read.</p>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
                <span class="mr-auto text-sm text-gray-500 dark:text-gray-400" x-text="copy.picked.length + ' selected'"></span>
                <button type="button" @click="copy.open = false" class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                <button type="button" @click="copyAdd()" :disabled="!copy.picked.length" class="rounded-md bg-brand-950 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-900 disabled:opacity-50 dark:bg-brand-500">Add to the changes</button>
            </div>
        </div>
    </div>
</div>
</x-app-layout>
