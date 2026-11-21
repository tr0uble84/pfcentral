{{-- Who can deploy: Microsoft 365 people and groups of the MSP's tenant. Global admins only. --}}
<script>
window.deployAccess = function (cfg) {
    return {
        users: cfg.access.users,
        groups: cfg.access.groups,
        type: 'users',
        q: '',
        results: [],
        searching: false,
        error: '',
        saving: false,
        dirty: false,
        timer: null,
        search() {
            clearTimeout(this.timer);
            this.timer = setTimeout(async () => {
                this.searching = true; this.error = '';
                try {
                    const r = await fetch(cfg.searchUrl + '?type=' + this.type + '&q=' + encodeURIComponent(this.q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    let d = {}; try { d = await r.json(); } catch (e) {}
                    if (!r.ok) throw new Error(d.message || 'Search failed (HTTP ' + r.status + ').');
                    this.results = d.results;
                } catch (e) { this.error = e.message; this.results = []; } finally { this.searching = false; }
            }, 250);
        },
        picked(x) { return this.type === 'users' ? this.users.some(u => u.oid === x.oid) : this.groups.some(g => g.id === x.id); },
        pick(x) {
            if (this.picked(x)) return;
            this.type === 'users' ? this.users.push(x) : this.groups.push(x);
            this.dirty = true;
        },
        drop(list, i) { this[list].splice(i, 1); this.dirty = true; },
        async save() {
            this.saving = true; this.error = '';
            try {
                const r = await fetch(cfg.saveUrl, { method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ users: this.users, groups: this.groups }) });
                let d = {}; try { d = await r.json(); } catch (e) {}
                if (!r.ok) throw new Error(d.message || 'Not saved (HTTP ' + r.status + ').');
                this.dirty = false;
                window.showToast && window.showToast('success', 'Saved', 'Who can deploy was updated.', 5000);
                setTimeout(() => location.reload(), 600);
            } catch (e) { this.error = e.message; } finally { this.saving = false; }
        },
    };
};
</script>
<section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg" x-data="deployAccess(@js([
        'access' => $access,
        'searchUrl' => route('deployments.access.search'),
        'saveUrl' => route('deployments.access.save'),
    ]))">
    <header class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Who can deploy</h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Only these Microsoft 365 people, and people in these groups, can create, start, schedule or undo deployments. They sign in to {{ config('brand.name') }} with Microsoft 365. Everyone else can only look. Group membership is checked with Microsoft.</p>
    </header>
    <div class="space-y-4 px-6 py-4">
        @unless($canPick)
            <p class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">Set up "Sign in with Microsoft 365" first (Sign-in settings): people and groups are picked from that tenant.</p>
        @endunless
        <div>
            <p class="text-sm font-medium text-gray-700 dark:text-gray-200">People</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <template x-for="(u, i) in users" :key="u.oid">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 py-1 pl-3 pr-1.5 text-sm text-gray-800 dark:bg-gray-700 dark:text-gray-100">
                        <span x-text="u.name"></span><span class="text-xs text-gray-500 dark:text-gray-400" x-text="u.upn"></span>
                        <button type="button" @click="drop('users', i)" class="rounded-full p-0.5 text-gray-500 hover:bg-gray-200 hover:text-gray-800 dark:hover:bg-gray-600" :aria-label="'Remove ' + u.name"><svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l8 8M14 6l-8 8" stroke-linecap="round"/></svg></button>
                    </span>
                </template>
                <span x-show="!users.length" class="text-sm text-gray-500 dark:text-gray-400">None</span>
            </div>
        </div>
        <div>
            <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Groups</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <template x-for="(g, i) in groups" :key="g.id">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 py-1 pl-3 pr-1.5 text-sm text-brand-900 dark:bg-brand-950 dark:text-brand-100">
                        <span x-text="g.name"></span>
                        <button type="button" @click="drop('groups', i)" class="rounded-full p-0.5 hover:bg-brand-100 dark:hover:bg-brand-900" :aria-label="'Remove ' + g.name"><svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l8 8M14 6l-8 8" stroke-linecap="round"/></svg></button>
                    </span>
                </template>
                <span x-show="!groups.length" class="text-sm text-gray-500 dark:text-gray-400">None</span>
            </div>
        </div>
        @if($canPick)
            <div class="rounded-md border border-gray-200 p-3 dark:border-gray-700">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-md border border-gray-300 p-0.5 text-sm dark:border-gray-600" role="tablist">
                        <button type="button" @click="type = 'users'; results = []; q && search()" :class="type === 'users' ? 'bg-gray-100 dark:bg-gray-700' : ''" class="rounded px-2.5 py-1 font-medium text-gray-700 dark:text-gray-200">People</button>
                        <button type="button" @click="type = 'groups'; results = []; q && search()" :class="type === 'groups' ? 'bg-gray-100 dark:bg-gray-700' : ''" class="rounded px-2.5 py-1 font-medium text-gray-700 dark:text-gray-200">Groups</button>
                    </div>
                    <input x-model="q" @input="search()" @focus="!results.length && search()" type="search" :placeholder="type === 'users' ? 'Search Microsoft 365 people by name or email' : 'Search Microsoft 365 groups'" class="min-w-0 flex-1 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <span x-show="searching" class="text-xs text-gray-500">Searching…</span>
                </div>
                <ul class="mt-2 max-h-56 overflow-y-auto" x-show="results.length">
                    <template x-for="x in results" :key="x.oid || x.id">
                        <li>
                            <button type="button" @click="pick(x)" :disabled="picked(x)" class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-gray-50 disabled:opacity-50 dark:hover:bg-gray-700/40">
                                <span class="font-medium text-gray-900 dark:text-gray-100" x-text="x.name"></span>
                                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="x.upn || ''"></span>
                                <span class="ml-auto text-xs text-gray-500" x-text="picked(x) ? 'Added' : 'Add'"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        @endif
        <p x-show="error" class="text-sm text-red-700 dark:text-red-300" x-text="error"></p>
        <div class="flex items-center gap-3">
            <button type="button" @click="save()" :disabled="!dirty || saving" class="rounded-md bg-brand-950 px-3.5 py-2 text-sm font-medium text-white hover:bg-brand-900 disabled:opacity-50 dark:bg-brand-500" x-text="saving ? 'Saving…' : 'Save'"></button>
            <span x-show="!users.length && !groups.length" class="text-sm text-gray-500 dark:text-gray-400">With nobody picked, nobody can deploy.</span>
        </div>
    </div>
</section>
