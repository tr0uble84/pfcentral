{{--
    Type-to-search picker for Microsoft 365 groups: pick several, remove with ×, saved with the surrounding form.
    $pickerName  input name (e.g. "ms_groups")       $pickerSelected  [{id, name}]
    $company     for the search URL (or $pickerUrl)                  $input           input classes from the page
--}}
@once
<script>
    window.msGroupPicker = function (selected, url) {
        return {
            selected: selected, url: url, q: '', results: [], open: false, loading: false, error: '', active: -1, seq: 0, lastQ: null,
            has(id) { return this.selected.some(g => g.id === id); },
            async search() {
                if (this.lastQ === this.q.trim() && !this.error) { this.open = true; return; }
                this.lastQ = this.q.trim();
                const mine = ++this.seq;
                this.loading = true; this.error = ''; this.open = true;
                try {
                    const r = await fetch(this.url + '?q=' + encodeURIComponent(this.q.trim()), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                    const data = await r.json().catch(() => ({ error: 'Couldn\'t search Microsoft 365 groups.' }));
                    if (mine !== this.seq) return;
                    if (!r.ok) { this.results = []; this.lastQ = null; this.error = data.error || 'Couldn\'t search Microsoft 365 groups.'; }
                    else { this.results = data; this.active = this.results.findIndex(g => !this.has(g.id)); }
                } catch (e) {
                    if (mine === this.seq) { this.results = []; this.lastQ = null; this.error = 'Couldn\'t reach the server.'; }
                } finally {
                    if (mine === this.seq) this.loading = false;
                }
            },
            pick(g) {
                if (!g || this.has(g.id)) return;
                this.selected.push({ id: g.id, name: g.name });
                this.q = ''; this.$refs.box.focus(); this.search();
                this.active = this.results.findIndex(r => !this.has(r.id));
            },
            remove(id) { this.selected = this.selected.filter(g => g.id !== id); },
            move(step) {
                if (!this.open) { this.search(); return; }
                const n = this.results.length; if (!n) return;
                let i = this.active;
                for (let k = 0; k < n; k++) { i = (i + step + n) % n; if (!this.has(this.results[i].id)) { this.active = i; break; } }
                this.$nextTick(() => this.$refs.list?.querySelector('[data-active="1"]')?.scrollIntoView({ block: 'nearest' }));
            },
            enter() { if (this.open && this.results[this.active]) this.pick(this.results[this.active]); },
            back() { if (this.q === '' && this.selected.length) this.selected.pop(); },
        };
    };
</script>
@endonce
<div x-data="msGroupPicker(@js(array_values($pickerSelected)), @js($pickerUrl ?? route('vpn.groups.search', $company)))" @click.outside="open = false" @keydown.escape.prevent="open = false" class="relative">
    <input type="hidden" name="{{ $pickerName }}_present" value="1">
    <template x-for="g in selected" :key="g.id"><input type="hidden" name="{{ $pickerName }}[]" :value="g.id"></template>
    <div class="flex flex-wrap items-center gap-1.5 p-1.5 rounded-md border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 focus-within:ring-2 focus-within:ring-indigo-500 cursor-text" @click="$refs.box.focus()">
        <template x-for="g in selected" :key="'c' + g.id">
            <span class="inline-flex items-center gap-1 pl-2 pr-1 py-0.5 rounded-full text-sm bg-indigo-100 text-indigo-800 dark:bg-indigo-900/60 dark:text-indigo-200">
                <span x-text="g.name"></span>
                <button type="button" class="w-5 h-5 leading-none rounded-full hover:bg-indigo-200 dark:hover:bg-indigo-800" :aria-label="'Remove ' + g.name" @click.stop="remove(g.id)">&times;</button>
            </span>
        </template>
        <input x-ref="box" x-model="q" type="text" autocomplete="off" role="combobox" :aria-expanded="open"
               :placeholder="selected.length ? 'Add another group…' : @js($pickerPlaceholder ?? 'Type to search this company\'s Microsoft 365 groups')"
               class="flex-1 min-w-[12rem] border-0 bg-transparent p-1 text-sm focus:ring-0 dark:text-gray-200"
               @focus="search()" @input.debounce.300ms="search()"
               @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="enter()" @keydown.backspace="back()">
    </div>
    <div x-show="open" x-cloak x-ref="list" class="absolute z-30 mt-1 w-full max-h-72 overflow-y-auto rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg">
        <p x-show="loading && !results.length" class="px-3 py-2 text-sm text-gray-500">Searching…</p>
        <p x-show="error" x-text="error" class="px-3 py-2 text-sm text-red-600 dark:text-red-400"></p>
        <p x-show="!loading && !error && !results.length" class="px-3 py-2 text-sm text-gray-500">No matching groups<span x-show="q.trim()" x-text="' for “' + q.trim() + '”'"></span>.</p>
        <template x-for="(g, i) in results" :key="g.id">
            <button type="button" :data-active="i === active ? 1 : 0" :disabled="has(g.id)" @click="pick(g)" @mouseenter="if (!has(g.id)) active = i"
                    class="w-full text-left px-3 py-2 flex items-center justify-between gap-3 disabled:opacity-50 disabled:cursor-default"
                    :class="i === active && !has(g.id) ? 'bg-indigo-50 dark:bg-indigo-900/40' : ''">
                <span><span class="block text-sm font-medium" x-text="g.name"></span><span class="block text-xs text-gray-500" x-text="g.kind"></span></span>
                <span x-show="has(g.id)" class="text-xs text-gray-500">added</span>
            </button>
        </template>
    </div>
</div>
