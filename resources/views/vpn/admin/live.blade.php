<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">VPN Access</h2>
    </x-slot>

<script>
window.vpnLive = function (cfg) {
    const CONNECTED = cfg.connectedSeconds;
    const HIST = 60;          // samples kept (5 s apart = 5 minutes)
    const POLL_MS = 5000;

    const fmtRate = (bps) => {
        if (bps == null || isNaN(bps)) return '—';
        if (bps < 1000) return Math.round(bps) + ' b/s';
        if (bps < 1e6) return (bps / 1e3).toFixed(bps < 1e4 ? 1 : 0) + ' kb/s';
        if (bps < 1e9) return (bps / 1e6).toFixed(bps < 1e7 ? 1 : 0) + ' Mb/s';
        return (bps / 1e9).toFixed(2) + ' Gb/s';
    };
    const fmtBytes = (b) => {
        if (b == null) return '—';
        const u = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        while (b >= 1000 && i < u.length - 1) { b /= 1000; i++; }
        return (i === 0 ? b : b.toFixed(b < 10 ? 1 : 0)) + ' ' + u[i];
    };
    const fmtAgo = (s) => {
        if (s == null) return 'never';
        if (s < 60) return Math.max(0, Math.round(s)) + ' s ago';
        if (s < 3600) return Math.round(s / 60) + ' min ago';
        if (s < 86400) return Math.round(s / 3600) + ' h ago';
        return Math.round(s / 86400) + ' d ago';
    };
    const fmtFor = (s) => {
        if (s < 60) return Math.round(s) + ' s';
        if (s < 3600) return Math.round(s / 60) + ' min';
        const h = Math.floor(s / 3600), m = Math.round((s % 3600) / 60);
        return h + ' h' + (m ? ' ' + m + ' min' : '');
    };

    return {
        devices: cfg.devices,
        sites: cfg.sites,
        live: {},          // device id → {hs, ep, rx, tx, t, down, up, hist: [[down, up]], seenAt}
        siteState: {},     // site id → {ok, error, t, busy, connected}
        agg: [],           // [[down, up]]
        skew: 0,           // server clock − this browser's clock, seconds
        q: '',
        status: 'connected',
        company: '',
        siteFilter: null,
        sort: 'status',
        paused: false,
        lastUpdate: null,
        tick: 0,
        drawer: null,      // {id, loading, data, confirm, busy}
        blocked: [],
        showBlocked: false,
        timers: [],

        init() {
            this.sites.forEach(s => this.siteState[s.id] = { ok: null, error: null, t: 0, busy: false });
            this.pollSites();
            this.loadBlocked();
            this.timers.push(setInterval(() => this.pollSites(), POLL_MS));
            this.timers.push(setInterval(() => this.refreshDevices(), 60000));
            this.timers.push(setInterval(() => { this.tick++; }, 1000));
            this.timers.push(setInterval(() => this.sampleAggregate(), POLL_MS));
            if (cfg.status) this.status = cfg.status;
            window.addEventListener('keydown', (e) => {
                if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
                    e.preventDefault(); this.$refs.search.focus();
                }
                if (e.key === 'Escape' && this.drawer) this.closeDrawer();
            });
        },

        now() { return Date.now() / 1000 + this.skew; },

        async getJson(url) {
            const r = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (r.status === 401 || r.status === 419) { window.location.reload(); throw new Error('signed out'); }
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        },

        async post(url) {
            const r = await fetch(url, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            let body = {};
            try { body = await r.json(); } catch (e) {}
            if (!r.ok && r.status !== 202) throw new Error(body.message || ('The request failed (HTTP ' + r.status + ').'));
            return { status: r.status, ...body };
        },

        pollSites() {
            if (this.paused || document.hidden) return;
            this.sites.filter(s => s.ready).forEach(s => this.pollSite(s.id));
        },

        async pollSite(id) {
            const st = this.siteState[id];
            if (!st || st.busy) return;
            st.busy = true;
            try {
                const d = await this.getJson(cfg.siteUrl.replace('__ID__', id));
                this.skew = d.now - Date.now() / 1000;
                st.ok = d.ok; st.error = d.error;
                if (d.ok && d.t !== st.t) {
                    for (const [devId, p] of Object.entries(d.peers)) {
                        const prev = this.live[devId];
                        const row = { hs: p.hs, ep: p.ep, rx: p.rx, tx: p.tx, t: d.t, down: null, up: null, hist: prev ? prev.hist : [] };
                        if (prev && d.t > prev.t && p.rx >= prev.rx && p.tx >= prev.tx) {
                            const dt = d.t - prev.t;
                            row.down = (p.tx - prev.tx) * 8 / dt;   // firewall → person
                            row.up = (p.rx - prev.rx) * 8 / dt;     // person → firewall
                            row.hist = prev.hist.concat([[row.down, row.up]]).slice(-HIST);
                        } else if (prev) {
                            row.down = prev.down; row.up = prev.up;
                        }
                        row.seenAt = Date.now();
                        this.live[devId] = row;
                    }
                    st.t = d.t;
                }
                this.lastUpdate = Date.now();
            } catch (e) {
                st.ok = false; st.error = 'Couldn\'t reach PFCentral';
            } finally {
                st.busy = false;
            }
        },

        async refreshDevices() {
            if (this.paused || document.hidden) return;
            try {
                const d = await this.getJson(cfg.devicesUrl);
                this.devices = d.devices;
                d.sites.forEach(s => { if (!this.siteState[s.id]) this.siteState[s.id] = { ok: null, error: null, t: 0, busy: false }; });
                this.sites = d.sites;
            } catch (e) {}
        },

        async loadBlocked() {
            try { this.blocked = await this.getJson(cfg.blockedUrl); } catch (e) {}
        },

        sampleAggregate() {
            if (this.paused || document.hidden) return;
            let down = 0, up = 0, measured = false;
            for (const d of this.devices) {
                if (this.live[d.id]?.down != null) measured = true;
                const l = this.live[d.id];
                if (l && this.isConnected(d) && l.down != null && Date.now() - l.seenAt < 15000) { down += l.down; up += l.up; }
            }
            if (!measured && !this.agg.length) return; // wait for the first real measurement
            this.agg = this.agg.concat([[down, up]]).slice(-HIST);
        },

        // ---- state per device ----
        state(d) {
            this.tick;
            const st = this.siteState[d.site];
            const l = this.live[d.id];
            if (!st || st.ok === null) return 'loading';
            if (!st.ok && !l) return 'unknown';
            if (!l || !l.hs) return 'never';
            return this.now() - l.hs <= CONNECTED ? 'connected' : 'offline';
        },
        isConnected(d) { return this.state(d) === 'connected'; },
        seenAgo(d) { const l = this.live[d.id]; return l && l.hs ? this.now() - l.hs : null; },
        lastSeenText(d) {
            const s = this.state(d);
            if (s === 'loading') return 'Checking…';
            if (s === 'unknown') return 'Firewall not answering';
            if (s === 'never') return 'Never connected';
            return 'Handshake ' + fmtAgo(this.seenAgo(d));
        },
        rate(d, dir) { const l = this.live[d.id]; return this.isConnected(d) && l ? l[dir] : null; },

        // ---- filters ----
        get companies() {
            const m = new Map();
            this.sites.forEach(s => m.set(s.company.id, s.company.name));
            this.devices.forEach(d => m.set(d.company.id, d.company.name));
            return [...m.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
        },
        matches(d, status) {
            status = status || this.status;
            if (this.company && String(d.company.id) !== String(this.company)) return false;
            if (this.siteFilter && d.site !== this.siteFilter) return false;
            const s = this.state(d);
            if (status === 'connected' && s !== 'connected') return false;
            if (status === 'offline' && (s === 'connected' || s === 'loading')) return false;
            const q = this.q.trim().toLowerCase();
            if (!q) return true;
            const l = this.live[d.id];
            return [d.person.name, d.person.email, d.company.name, d.firewall, d.name, d.address, l?.ep].some(v => v && String(v).toLowerCase().includes(q));
        },
        get rows() {
            this.tick;
            const rows = this.devices.filter(d => this.matches(d));
            const traffic = (d) => (this.rate(d, 'down') || 0) + (this.rate(d, 'up') || 0);
            const rank = { connected: 0, offline: 1, never: 2, unknown: 3, loading: 4 };
            const by = {
                status: (a, b) => rank[this.state(a)] - rank[this.state(b)] || traffic(b) - traffic(a) || a.person.name.localeCompare(b.person.name),
                person: (a, b) => a.person.name.localeCompare(b.person.name),
                company: (a, b) => a.company.name.localeCompare(b.company.name) || a.person.name.localeCompare(b.person.name),
                traffic: (a, b) => traffic(b) - traffic(a),
                seen: (a, b) => (this.seenAgo(a) ?? 1e12) - (this.seenAgo(b) ?? 1e12),
            };
            return rows.sort(by[this.sort] || by.status);
        },
        countFor(status) {
            this.tick;
            return this.devices.filter(d => this.matches(d, status)).length;
        },
        get connectedCount() { this.tick; return this.devices.filter(d => this.isConnected(d)).length; },
        get companiesOnline() { this.tick; return new Set(this.devices.filter(d => this.isConnected(d)).map(d => d.company.id)).size; },
        get readySites() { return this.sites.filter(s => s.ready); },
        get sitesAnswering() { return this.readySites.filter(s => this.siteState[s.id]?.ok).length; },
        siteConnected(s) { this.tick; return this.devices.filter(d => d.site === s.id && this.isConnected(d)).length; },
        siteTone(s) {
            if (!s.ready) return 'idle';
            const st = this.siteState[s.id];
            if (!st || st.ok === null) return 'loading';
            return st.ok ? 'ok' : 'down';
        },
        get filtered() { return this.q.trim() !== '' || this.company !== '' || this.siteFilter !== null; },
        clearFilters() { this.q = ''; this.company = ''; this.siteFilter = null; },
        pickSite(s) { this.siteFilter = this.siteFilter === s.id ? null : s.id; if (this.siteFilter) this.status = 'all'; },
        siteName(id) { const s = this.sites.find(x => x.id === id); return s ? s.company.name + ' · ' + s.firewall : ''; },
        get updatedText() { this.tick; return this.lastUpdate ? fmtAgo((Date.now() - this.lastUpdate) / 1000) : 'connecting…'; },
        get aggNow() { const a = this.agg[this.agg.length - 1]; return a ? { down: a[0], up: a[1] } : { down: null, up: null }; },

        // ---- charts ----
        chart(series, w, h, n) {
            // series: [[down, up], …] → SVG paths, newest point at the right edge
            n = n || HIST;
            const max = Math.max(8000, ...series.map(p => Math.max(p[0] || 0, p[1] || 0))) * 1.15;
            const pt = (i, v) => [(w * (n - series.length + i) / (n - 1)).toFixed(1), (h - (v || 0) / max * (h - 2) - 1).toFixed(1)];
            const line = (k) => series.map((p, i) => (i ? 'L' : 'M') + pt(i, p[k]).join(' ')).join(' ');
            const down = series.length > 1 ? line(0) : '';
            const up = series.length > 1 ? line(1) : '';
            const first = series.length > 1 ? pt(0, 0)[0] : 0;
            const downArea = down ? down + ` L ${w} ${h} L ${first} ${h} Z` : '';
            return { down, up, downArea, max };
        },
        sparkFor(d) { const l = this.live[d.id]; return this.chart(l && this.isConnected(d) ? l.hist.slice(-24) : [], 96, 24, 24); },

        // ---- detail panel ----
        async openDrawer(d) {
            this.drawer = { id: d.id, row: d, loading: true, data: null, confirm: null, busy: false, error: null };
            try {
                const data = await this.getJson(cfg.deviceUrl.replace('__ID__', d.id));
                if (this.drawer && this.drawer.id === d.id) { this.drawer.data = data; this.drawer.loading = false; }
            } catch (e) {
                if (this.drawer) { this.drawer.loading = false; this.drawer.error = 'This device is no longer on the list. It may have just been removed.'; }
            }
            this.$nextTick(() => this.$refs.drawerClose?.focus());
        },
        closeDrawer() { this.drawer = null; },
        drawerDevice() { return this.drawer ? (this.devices.find(d => d.id === this.drawer.id) || this.drawer.row) : null; },
        async act(kind) {
            const dr = this.drawer;
            if (!dr || dr.busy) return;
            dr.busy = true;
            const url = kind === 'disconnect' ? cfg.disconnectUrl.replace('__ID__', dr.id)
                : (kind === 'block' ? cfg.blockUrl : cfg.unblockUrl).replace('__ID__', dr.data.person.id);
            try {
                const r = await this.post(url);
                window.showToast && window.showToast(r.ok ? 'success' : 'warning', kind === 'disconnect' ? 'Disconnected' : (kind === 'block' ? 'Blocked' : 'Unblocked'), r.message, 7000);
                if (kind === 'unblock') {
                    this.blocked = this.blocked.filter(b => b.id !== dr.data.person.id);
                    dr.data.person.blocked = false;
                    dr.busy = false; dr.confirm = null;
                    return;
                }
                const gone = kind === 'disconnect' ? [dr.id] : this.devices.filter(d => d.person.id === dr.data.person.id).map(d => d.id);
                this.devices = this.devices.filter(d => !gone.includes(d.id));
                gone.forEach(id => delete this.live[id]);
                this.closeDrawer();
                this.loadBlocked();
            } catch (e) {
                window.showToast && window.showToast('error', 'Not done', e.message, 8000);
                dr.busy = false;
            }
        },
        async unblock(b) {
            try {
                const r = await this.post(cfg.unblockUrl.replace('__ID__', b.id));
                this.blocked = this.blocked.filter(x => x.id !== b.id);
                window.showToast && window.showToast('success', 'Unblocked', r.message, 7000);
            } catch (e) {
                window.showToast && window.showToast('error', 'Not done', e.message, 8000);
            }
        },

        fmtRate, fmtBytes, fmtAgo, fmtFor,
        fmtDate(iso) { return iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—'; },
        initials(name) { return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join(''); },
    };
};
</script>

<div class="py-6" x-data="vpnLive(@js([
        'devices' => $devices,
        'sites' => $sites,
        'connectedSeconds' => $connectedSeconds,
        'status' => request('status'),
        'siteUrl' => route('vpn.live.site', ['site' => '__ID__']),
        'devicesUrl' => route('vpn.live.devices'),
        'blockedUrl' => route('vpn.live.blocked'),
        'deviceUrl' => route('vpn.live.device', ['device' => '__ID__']),
        'disconnectUrl' => route('vpn.live.disconnect', ['device' => '__ID__']),
        'blockUrl' => route('vpn.live.block', ['vpnUser' => '__ID__']),
        'unblockUrl' => route('vpn.live.unblock', ['vpnUser' => '__ID__']),
    ]))" x-cloak>
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">
        @include('vpn.admin._tabs')
        @include('vpn.admin._notices')

        {{-- Overview: who's on now, and the traffic over the last five minutes --}}
        <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg overflow-hidden" aria-labelledby="vpn-now">
            <div class="grid lg:grid-cols-[minmax(0,20rem)_1fr]">
                <div class="p-6 border-b lg:border-b-0 lg:border-r border-gray-100 dark:border-gray-700">
                    <div class="flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">
                        <span class="relative flex h-2 w-2" x-show="!paused">
                            <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-60 motion-safe:animate-ping"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        </span>
                        <span class="h-2 w-2 rounded-full bg-gray-400" x-show="paused"></span>
                        <span x-text="paused ? 'Paused' : 'Live · updated ' + updatedText"></span>
                        <button type="button" @click="paused = !paused; if (!paused) pollSites()"
                                class="ml-auto rounded-md px-2 py-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
                                x-text="paused ? 'Resume' : 'Pause'"></button>
                    </div>
                    <h3 id="vpn-now" class="mt-4 flex items-baseline gap-2">
                        <span class="text-4xl font-semibold tracking-tight tabular-nums text-gray-900 dark:text-white" x-text="connectedCount"></span>
                        <span class="text-base text-gray-600 dark:text-gray-300" x-text="connectedCount === 1 ? 'person connected' : 'people connected'"></span>
                    </h3>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-400">Companies online</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100" x-text="companiesOnline"></dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-400">Firewalls answering</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100"><span x-text="sitesAnswering"></span> of <span x-text="readySites.length"></span></dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-400">Devices with access</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100" x-text="devices.length"></dd></div>
                    </dl>
                </div>
                <div class="p-6 min-w-0">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                        <span class="font-medium text-gray-900 dark:text-gray-100">VPN traffic, last 5 minutes</span>
                        <span class="flex items-center gap-2 text-gray-600 dark:text-gray-300"><span class="h-0.5 w-4 rounded bg-brand-400"></span>To people <strong class="tabular-nums font-semibold text-gray-900 dark:text-white" x-text="fmtRate(aggNow.down)"></strong></span>
                        <span class="flex items-center gap-2 text-gray-600 dark:text-gray-300"><span class="h-0.5 w-4 rounded bg-brand-950 dark:bg-gray-300"></span>From people <strong class="tabular-nums font-semibold text-gray-900 dark:text-white" x-text="fmtRate(aggNow.up)"></strong></span>
                    </div>
                    <div class="relative mt-4 h-36">
                        <template x-if="agg.length < 2">
                            <div class="absolute inset-0 grid place-items-center text-sm text-gray-400 dark:text-gray-500">Measuring… the graph fills in over the next few seconds.</div>
                        </template>
                        <svg class="absolute inset-0 h-full w-full overflow-visible" viewBox="0 0 600 144" preserveAspectRatio="none" role="img" aria-label="Total VPN traffic over the last five minutes">
                            <defs>
                                <linearGradient id="vpnDownFill" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="0%" stop-color="#33C5F3" stop-opacity="0.28"/>
                                    <stop offset="100%" stop-color="#33C5F3" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <g class="text-gray-200 dark:text-gray-700" stroke="currentColor" stroke-width="1" vector-effect="non-scaling-stroke">
                                <line x1="0" x2="600" y1="36" y2="36" vector-effect="non-scaling-stroke" stroke-dasharray="2 4"/>
                                <line x1="0" x2="600" y1="72" y2="72" vector-effect="non-scaling-stroke" stroke-dasharray="2 4"/>
                                <line x1="0" x2="600" y1="108" y2="108" vector-effect="non-scaling-stroke" stroke-dasharray="2 4"/>
                                <line x1="0" x2="600" y1="143" y2="143" vector-effect="non-scaling-stroke"/>
                            </g>
                            <path :d="chart(agg, 600, 144).downArea" fill="url(#vpnDownFill)"/>
                            <path :d="chart(agg, 600, 144).down" fill="none" stroke="#33C5F3" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
                            <path :d="chart(agg, 600, 144).up" fill="none" class="stroke-brand-950 dark:stroke-gray-300" stroke-width="1.5" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
                        </svg>
                        <span class="absolute right-0 -top-1 text-[11px] tabular-nums text-gray-400 dark:text-gray-500" x-show="agg.length > 1" x-text="fmtRate(chart(agg, 600, 144).max)"></span>
                    </div>
                </div>
            </div>
        </section>

        {{-- Firewalls: click one to see only its people --}}
        <section aria-label="VPN firewalls" x-show="sites.length">
            <div class="flex flex-wrap gap-2">
                <template x-for="s in sites" :key="s.id">
                    <button type="button" @click="pickSite(s)"
                            :aria-pressed="siteFilter === s.id"
                            :title="s.ready ? (siteState[s.id]?.error || (s.endpoint ? 'Endpoint ' + s.endpoint : '')) : 'VPN not set up on this firewall yet'"
                            class="group flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm shadow-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
                            :class="siteFilter === s.id ? 'border-brand-400 bg-brand-50 dark:bg-brand-950 dark:border-brand-500' : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600'">
                        <span class="h-2 w-2 shrink-0 rounded-full"
                              :class="{ ok: 'bg-emerald-500', down: 'bg-red-500', loading: 'bg-gray-300 dark:bg-gray-600 motion-safe:animate-pulse', idle: 'bg-amber-400' }[siteTone(s)]"></span>
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-gray-900 dark:text-gray-100" x-text="s.firewall"></span>
                            <span class="block truncate text-xs text-gray-500 dark:text-gray-400" x-text="s.company.name"></span>
                        </span>
                        <span class="ml-2 rounded-md px-1.5 py-0.5 text-xs tabular-nums"
                              :class="siteTone(s) === 'down' ? 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300' : (siteTone(s) === 'idle' ? 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200')"
                              x-text="siteTone(s) === 'down' ? 'Not answering' : (siteTone(s) === 'idle' ? 'Not set up' : siteConnected(s) + ' on')"></span>
                    </button>
                </template>
            </div>
        </section>

        {{-- Search and filters --}}
        <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 p-4 dark:border-gray-700">
                <label class="relative min-w-[16rem] flex-1">
                    <span class="sr-only">Search</span>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="9" r="6"/><path d="m14 14 4 4" stroke-linecap="round"/></svg>
                    <input x-ref="search" x-model.debounce.150ms="q" type="search" autocomplete="off"
                           placeholder="Search a person, email, company, firewall, device or IP"
                           class="w-full rounded-md border-gray-300 bg-white py-2 pl-9 pr-10 text-sm placeholder:text-gray-400 focus:border-brand-400 focus:ring-brand-400 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <kbd class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 rounded border border-gray-200 px-1.5 text-[11px] text-gray-400 dark:border-gray-600" x-show="!q">/</kbd>
                </label>
                <select x-model="company" aria-label="Company"
                        class="rounded-md border-gray-300 py-2 pr-9 text-sm focus:border-brand-400 focus:ring-brand-400 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All companies</option>
                    <template x-for="c in companies" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
                </select>
                <div class="inline-flex rounded-md bg-gray-100 p-0.5 dark:bg-gray-900" role="group" aria-label="Show">
                    <template x-for="opt in [['connected', 'Connected'], ['all', 'All devices'], ['offline', 'Not connected']]" :key="opt[0]">
                        <button type="button" @click="status = opt[0]" :aria-pressed="status === opt[0]"
                                class="rounded px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
                                :class="status === opt[0] ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200'">
                            <span x-text="opt[1]"></span>
                            <span class="ml-1 tabular-nums text-xs text-gray-400" x-text="countFor(opt[0])"></span>
                        </button>
                    </template>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 px-4 py-2 text-xs text-gray-500 dark:text-gray-400" x-show="filtered">
                <span>Filtered:</span>
                <template x-if="siteFilter"><span class="rounded-full bg-brand-50 px-2 py-0.5 text-brand-800 dark:bg-brand-950 dark:text-brand-200" x-text="siteName(siteFilter)"></span></template>
                <button type="button" @click="clearFilters()" class="font-medium text-brand-700 hover:underline dark:text-brand-300">Clear filters</button>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="py-2.5 pl-4 pr-3"><button type="button" @click="sort = sort === 'person' ? 'status' : 'person'" class="hover:text-gray-900 dark:hover:text-gray-200" :class="sort === 'person' && 'text-gray-900 dark:text-gray-100'">Person</button></th>
                            <th scope="col" class="px-3 py-2.5"><button type="button" @click="sort = sort === 'company' ? 'status' : 'company'" class="hover:text-gray-900 dark:hover:text-gray-200" :class="sort === 'company' && 'text-gray-900 dark:text-gray-100'">Company · firewall</button></th>
                            <th scope="col" class="px-3 py-2.5">Device</th>
                            <th scope="col" class="px-3 py-2.5">Connected from</th>
                            <th scope="col" class="px-3 py-2.5"><button type="button" @click="sort = sort === 'seen' ? 'status' : 'seen'" class="hover:text-gray-900 dark:hover:text-gray-200" :class="sort === 'seen' && 'text-gray-900 dark:text-gray-100'">Last handshake</button></th>
                            <th scope="col" class="px-3 py-2.5"><button type="button" @click="sort = sort === 'traffic' ? 'status' : 'traffic'" class="hover:text-gray-900 dark:hover:text-gray-200" :class="sort === 'traffic' && 'text-gray-900 dark:text-gray-100'">Live traffic</button></th>
                            <th scope="col" class="py-2.5 pl-3 pr-4 text-right">Total transferred</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/70">
                        <template x-for="d in rows" :key="d.id">
                            <tr class="cursor-pointer transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/40"
                                :class="drawer && drawer.id === d.id && 'bg-brand-50/60 dark:bg-brand-950/60'"
                                @click="openDrawer(d)">
                                <td class="py-3 pl-4 pr-3">
                                    <button type="button" class="flex items-center gap-3 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 rounded" @click.stop="openDrawer(d)">
                                        <span class="relative grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gray-100 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-200" x-text="initials(d.person.name)"></span>
                                        <span class="min-w-0">
                                            <span class="flex items-center gap-1.5">
                                                <span class="h-1.5 w-1.5 shrink-0 rounded-full"
                                                      :class="{ connected: 'bg-emerald-500', offline: 'bg-gray-300 dark:bg-gray-600', never: 'bg-gray-200 dark:bg-gray-700', unknown: 'bg-amber-400', loading: 'bg-gray-200 dark:bg-gray-700' }[state(d)]"></span>
                                                <span class="truncate font-medium text-gray-900 dark:text-gray-100" x-text="d.person.name"></span>
                                            </span>
                                            <span class="block truncate text-xs text-gray-500 dark:text-gray-400" x-text="d.person.email"></span>
                                        </span>
                                    </button>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="block truncate text-gray-900 dark:text-gray-100" x-text="d.company.name"></span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400" x-text="d.firewall"></span>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="block truncate text-gray-900 dark:text-gray-100" x-text="d.name"></span>
                                    <span class="block font-mono text-xs tabular-nums text-gray-500 dark:text-gray-400" x-text="d.address"></span>
                                </td>
                                <td class="px-3 py-3 font-mono text-xs tabular-nums text-gray-600 dark:text-gray-300" x-text="live[d.id]?.ep || '—'"></td>
                                <td class="px-3 py-3 text-xs" :class="state(d) === 'connected' ? 'text-emerald-700 dark:text-emerald-400' : (state(d) === 'unknown' ? 'text-amber-700 dark:text-amber-400' : 'text-gray-500 dark:text-gray-400')" x-text="lastSeenText(d)"></td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-3" x-show="isConnected(d)">
                                        <svg class="h-6 w-24 shrink-0" viewBox="0 0 96 24" preserveAspectRatio="none" aria-hidden="true">
                                            <path :d="sparkFor(d).downArea" fill="#33C5F3" fill-opacity="0.15"/>
                                            <path :d="sparkFor(d).down" fill="none" stroke="#33C5F3" stroke-width="1.5" vector-effect="non-scaling-stroke"/>
                                            <path :d="sparkFor(d).up" fill="none" class="stroke-brand-950 dark:stroke-gray-300" stroke-width="1" vector-effect="non-scaling-stroke"/>
                                        </svg>
                                        <span class="whitespace-nowrap text-xs tabular-nums leading-tight">
                                            <span class="block text-gray-900 dark:text-gray-100"><span class="text-brand-500" aria-hidden="true">↓</span> <span x-text="fmtRate(rate(d, 'down'))"></span></span>
                                            <span class="block text-gray-500 dark:text-gray-400"><span aria-hidden="true">↑</span> <span x-text="fmtRate(rate(d, 'up'))"></span></span>
                                        </span>
                                    </div>
                                    <span class="text-xs text-gray-400 dark:text-gray-500" x-show="!isConnected(d)">—</span>
                                </td>
                                <td class="py-3 pl-3 pr-4 text-right text-xs tabular-nums text-gray-600 dark:text-gray-300">
                                    <template x-if="live[d.id] && live[d.id].hs">
                                        <span><span x-text="fmtBytes(live[d.id].tx)"></span> <span class="text-gray-400">↓</span> · <span x-text="fmtBytes(live[d.id].rx)"></span> <span class="text-gray-400">↑</span></span>
                                    </template>
                                    <template x-if="!(live[d.id] && live[d.id].hs)"><span class="text-gray-400">—</span></template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                {{-- Empty states --}}
                <div class="px-6 py-14 text-center" x-show="rows.length === 0" x-cloak>
                    <template x-if="devices.length === 0">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-gray-100">No VPN devices yet</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Devices appear here once people sign in on the staff VPN page or the CFBTEL VPN app.</p>
                            <a href="{{ route('vpn.settings') }}" class="mt-4 inline-block text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">Set up VPN for a company</a>
                        </div>
                    </template>
                    <template x-if="devices.length > 0 && filtered">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-gray-100" x-text="q ? 'Nothing matches “' + q + '”' : 'Nothing matches these filters'"></p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400" x-show="status === 'connected'">Only connected devices are shown. <button type="button" class="font-medium text-brand-700 hover:underline dark:text-brand-300" @click="status = 'all'">Show all devices</button></p>
                            <button type="button" @click="clearFilters()" class="mt-3 text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">Clear filters</button>
                        </div>
                    </template>
                    <template x-if="devices.length > 0 && !filtered && status === 'connected'">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-gray-100">Nobody is connected right now</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><button type="button" class="font-medium text-brand-700 hover:underline dark:text-brand-300" @click="status = 'all'">Show all <span x-text="devices.length"></span> devices</button></p>
                        </div>
                    </template>
                    <template x-if="devices.length > 0 && !filtered && status === 'offline'">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Every device is connected.</p>
                    </template>
                </div>
            </div>
        </section>

        {{-- Blocked people --}}
        <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg" x-show="blocked.length" x-cloak>
            <button type="button" @click="showBlocked = !showBlocked" :aria-expanded="showBlocked"
                    class="flex w-full items-center justify-between px-4 py-3 text-left text-sm font-medium text-gray-900 dark:text-gray-100">
                <span>Blocked <span class="ml-1 rounded-full bg-red-50 px-2 py-0.5 text-xs tabular-nums text-red-700 dark:bg-red-950 dark:text-red-300" x-text="blocked.length"></span></span>
                <svg class="h-4 w-4 text-gray-400 transition-transform" :class="showBlocked && 'rotate-180'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 8 5 5 5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-700 dark:border-gray-700" x-show="showBlocked" x-collapse>
                <template x-for="b in blocked" :key="b.id">
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-gray-900 dark:text-gray-100" x-text="b.name"></span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="b.email + ' · ' + (b.company || '—')"></span>
                        </span>
                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="'Blocked ' + fmtDate(b.blocked_at) + ' by ' + (b.blocked_by || '—')"></span>
                        <button type="button" @click="unblock(b)" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Unblock</button>
                    </li>
                </template>
            </ul>
        </section>
    </div>

    {{-- Detail panel --}}
    <div class="fixed inset-0 z-40" x-show="drawer" x-cloak aria-modal="true" role="dialog" aria-labelledby="vpn-drawer-title">
        <div class="absolute inset-0 bg-gray-900/30 backdrop-blur-[1px]" @click="closeDrawer()"
             x-show="drawer" x-transition.opacity.duration.150ms></div>
        <aside class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-2xl dark:bg-gray-800"
               x-show="drawer"
               x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-8 opacity-0" x-transition:enter-end="translate-x-0 opacity-100"
               x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="translate-x-8 opacity-0">
            <template x-if="drawer">
                <div class="flex h-full flex-col">
                    <header class="flex items-start gap-3 border-b border-gray-100 px-6 py-5 dark:border-gray-700">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-950 text-sm font-semibold text-white" x-text="initials(drawer.row.person.name)"></span>
                        <div class="min-w-0 flex-1">
                            <h3 id="vpn-drawer-title" class="truncate text-base font-semibold text-gray-900 dark:text-white" x-text="drawer.row.person.name"></h3>
                            <p class="truncate text-sm text-gray-500 dark:text-gray-400" x-text="drawer.row.person.email"></p>
                        </div>
                        <button type="button" x-ref="drawerClose" @click="closeDrawer()" class="rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 dark:hover:bg-gray-700 dark:hover:text-gray-200" aria-label="Close">
                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/></svg>
                        </button>
                    </header>

                    <div class="flex-1 space-y-6 overflow-y-auto px-6 py-5">
                        {{-- Status --}}
                        <div class="flex items-center gap-2 text-sm">
                            <template x-if="drawerDevice()">
                                <span class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-medium"
                                      :class="isConnected(drawerDevice()) ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="isConnected(drawerDevice()) ? 'bg-emerald-500' : 'bg-gray-400'"></span>
                                    <span x-text="isConnected(drawerDevice()) ? 'Connected' : lastSeenText(drawerDevice())"></span>
                                </span>
                            </template>
                            <span class="text-gray-500 dark:text-gray-400" x-show="drawerDevice() && isConnected(drawerDevice())" x-text="'Handshake ' + fmtAgo(seenAgo(drawerDevice()))"></span>
                        </div>

                        <template x-if="drawer.data && drawer.data.person.blocked">
                            <div class="rounded-md bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                                Blocked <span x-text="fmtDate(drawer.data.person.blocked_at)"></span> by <span x-text="drawer.data.person.blocked_by"></span>. They can't sign in or add devices.
                            </div>
                        </template>

                        {{-- Live traffic for this device --}}
                        <section x-show="drawerDevice() && isConnected(drawerDevice())">
                            <h4 class="text-xs font-medium text-gray-500 dark:text-gray-400">Live traffic</h4>
                            <div class="mt-2 grid grid-cols-2 gap-4 text-sm">
                                <div><div class="text-gray-500 dark:text-gray-400">To <span x-text="drawer.row.person.name.split(' ')[0]"></span></div><div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white" x-text="drawerDevice() ? fmtRate(rate(drawerDevice(), 'down')) : '—'"></div></div>
                                <div><div class="text-gray-500 dark:text-gray-400">From <span x-text="drawer.row.person.name.split(' ')[0]"></span></div><div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white" x-text="drawerDevice() ? fmtRate(rate(drawerDevice(), 'up')) : '—'"></div></div>
                            </div>
                            <svg class="mt-3 h-24 w-full" viewBox="0 0 400 96" preserveAspectRatio="none" aria-hidden="true">
                                <line x1="0" x2="400" y1="95" y2="95" class="stroke-gray-200 dark:stroke-gray-700" vector-effect="non-scaling-stroke"/>
                                <path :d="chart(live[drawer.id]?.hist || [], 400, 96).downArea" fill="#33C5F3" fill-opacity="0.15"/>
                                <path :d="chart(live[drawer.id]?.hist || [], 400, 96).down" fill="none" stroke="#33C5F3" stroke-width="2" vector-effect="non-scaling-stroke"/>
                                <path :d="chart(live[drawer.id]?.hist || [], 400, 96).up" fill="none" class="stroke-brand-950 dark:stroke-gray-300" stroke-width="1.5" vector-effect="non-scaling-stroke"/>
                            </svg>
                        </section>

                        {{-- Device --}}
                        <section>
                            <h4 class="text-xs font-medium text-gray-500 dark:text-gray-400">This device</h4>
                            <dl class="mt-2 grid grid-cols-[8rem_1fr] gap-x-4 gap-y-2 text-sm">
                                <dt class="text-gray-500 dark:text-gray-400">Name</dt><dd class="text-gray-900 dark:text-gray-100" x-text="drawer.row.name"></dd>
                                <dt class="text-gray-500 dark:text-gray-400">Company</dt>
                                <dd><a class="text-brand-700 hover:underline dark:text-brand-300" :href="drawer.data?.company.url || '#'" x-text="drawer.row.company.name"></a></dd>
                                <dt class="text-gray-500 dark:text-gray-400">Firewall</dt><dd class="text-gray-900 dark:text-gray-100" x-text="drawer.row.firewall"></dd>
                                <dt class="text-gray-500 dark:text-gray-400">VPN address</dt><dd class="font-mono tabular-nums text-gray-900 dark:text-gray-100" x-text="drawer.row.address"></dd>
                                <dt class="text-gray-500 dark:text-gray-400">Connecting from</dt><dd class="font-mono tabular-nums text-gray-900 dark:text-gray-100" x-text="live[drawer.id]?.ep || '—'"></dd>
                                <dt class="text-gray-500 dark:text-gray-400">Total</dt>
                                <dd class="tabular-nums text-gray-900 dark:text-gray-100" x-text="live[drawer.id]?.hs ? fmtBytes(live[drawer.id].tx) + ' down · ' + fmtBytes(live[drawer.id].rx) + ' up' : '—'"></dd>
                                <dt class="text-gray-500 dark:text-gray-400">Added</dt><dd class="text-gray-900 dark:text-gray-100" x-text="fmtDate(drawer.row.added)"></dd>
                            </dl>
                        </section>

                        <template x-if="drawer.loading">
                            <p class="text-sm text-gray-400">Loading details…</p>
                        </template>
                        <template x-if="drawer.error">
                            <p class="rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200" x-text="drawer.error"></p>
                        </template>

                        <template x-if="drawer.data">
                            <div class="space-y-6">
                                <section>
                                    <h4 class="text-xs font-medium text-gray-500 dark:text-gray-400">Person</h4>
                                    <dl class="mt-2 grid grid-cols-[8rem_1fr] gap-x-4 gap-y-2 text-sm">
                                        <dt class="text-gray-500 dark:text-gray-400">Signs in with</dt><dd class="text-gray-900 dark:text-gray-100" x-text="drawer.data.person.signs_in_with"></dd>
                                        <dt class="text-gray-500 dark:text-gray-400">Two-factor</dt><dd class="text-gray-900 dark:text-gray-100" x-text="drawer.data.person.two_factor"></dd>
                                        <dt class="text-gray-500 dark:text-gray-400">Last sign-in</dt><dd class="text-gray-900 dark:text-gray-100" x-text="fmtDate(drawer.data.person.last_sign_in)"></dd>
                                        <dt class="text-gray-500 dark:text-gray-400">Added by</dt><dd class="text-gray-900 dark:text-gray-100" x-text="drawer.data.person.added_by || '—'"></dd>
                                    </dl>
                                </section>

                                <section x-show="drawer.data.others.length">
                                    <h4 class="text-xs font-medium text-gray-500 dark:text-gray-400">Other devices</h4>
                                    <ul class="mt-2 divide-y divide-gray-100 rounded-md border border-gray-100 dark:divide-gray-700 dark:border-gray-700">
                                        <template x-for="o in drawer.data.others" :key="o.id">
                                            <li>
                                                <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700/40"
                                                        @click="const r = devices.find(x => x.id === o.id); if (r) openDrawer(r)">
                                                    <span class="h-1.5 w-1.5 rounded-full" :class="devices.find(x => x.id === o.id) && isConnected(devices.find(x => x.id === o.id)) ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'"></span>
                                                    <span class="flex-1 truncate text-gray-900 dark:text-gray-100" x-text="o.name"></span>
                                                    <span class="truncate text-xs text-gray-500 dark:text-gray-400" x-text="o.firewall"></span>
                                                </button>
                                            </li>
                                        </template>
                                    </ul>
                                </section>

                                <section x-show="drawer.data.activity.length">
                                    <h4 class="text-xs font-medium text-gray-500 dark:text-gray-400">Recent VPN activity</h4>
                                    <ol class="mt-2 space-y-2 text-sm">
                                        <template x-for="(a, i) in drawer.data.activity" :key="i">
                                            <li class="grid grid-cols-[7.5rem_1fr] gap-3">
                                                <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400" x-text="fmtDate(a.at)"></span>
                                                <span class="min-w-0 text-gray-700 dark:text-gray-200"><span x-text="a.action"></span> <span class="text-xs text-gray-400" x-show="a.actor !== drawer.data.person.email" x-text="'· ' + a.actor"></span></span>
                                            </li>
                                        </template>
                                    </ol>
                                </section>
                            </div>
                        </template>
                    </div>

                    {{-- Actions --}}
                    <footer class="border-t border-gray-100 px-6 py-4 dark:border-gray-700" x-show="drawer.data">
                        <template x-if="drawer.data && !drawer.confirm">
                            <div class="flex flex-wrap gap-2">
                                <button type="button" @click="drawer.confirm = 'disconnect'"
                                        class="inline-flex items-center rounded-md bg-red-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-800">Disconnect this device</button>
                                <button type="button" x-show="!drawer.data.person.blocked" @click="drawer.confirm = 'block'"
                                        class="inline-flex items-center rounded-md border border-red-200 px-3.5 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950">Block <span class="ml-1" x-text="drawer.row.person.name.split(' ')[0]"></span></button>
                                <button type="button" x-show="drawer.data.person.blocked" @click="act('unblock')" :disabled="drawer.busy"
                                        class="inline-flex items-center rounded-md border border-gray-300 px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Unblock</button>
                            </div>
                        </template>
                        <template x-if="drawer.data && drawer.confirm">
                            <div class="space-y-3">
                                <p class="text-sm text-gray-700 dark:text-gray-200" x-show="drawer.confirm === 'disconnect'">
                                    Disconnect <strong x-text="drawer.row.name"></strong> now? Its key is removed from <span x-text="drawer.row.firewall"></span> at once. To reconnect, <span x-text="drawer.row.person.name.split(' ')[0]"></span> signs in again on the VPN page or app.
                                </p>
                                <p class="text-sm text-gray-700 dark:text-gray-200" x-show="drawer.confirm === 'block'">
                                    Block <strong x-text="drawer.row.person.name"></strong>? All <span x-text="drawer.data.others.length + 1"></span> of their devices are disconnected and removed, and they can't sign in to the VPN until someone unblocks them here.
                                </p>
                                <div class="flex gap-2">
                                    <button type="button" @click="act(drawer.confirm)" :disabled="drawer.busy"
                                            class="inline-flex items-center gap-2 rounded-md bg-red-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700 disabled:opacity-60">
                                        <svg x-show="drawer.busy" class="h-4 w-4 motion-safe:animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                        <span x-text="drawer.busy ? 'Working…' : (drawer.confirm === 'disconnect' ? 'Disconnect now' : 'Block and disconnect')"></span>
                                    </button>
                                    <button type="button" @click="drawer.confirm = null" :disabled="drawer.busy"
                                            class="rounded-md px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                                </div>
                            </div>
                        </template>
                    </footer>
                </div>
            </template>
        </aside>
    </div>
</div>
</x-app-layout>
