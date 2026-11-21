@php
    $input = 'block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
    $help = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $card = 'bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg';
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Security</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Who can sign in to {{ config('brand.name') }}, and how your team reaches each firewall. Everything here is off until you turn it on.</p>
    </x-slot>

<script>
window.securityPage = function (cfg) {
    const fmt = (iso) => iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—';
    const dur = (a, b) => { if (!a) return ''; const m = Math.round(((b ? new Date(b) : new Date()) - new Date(a)) / 60000); return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min'; };
    return {
        firewalls: cfg.firewalls,
        sessions: cfg.sessions,
        busy: {},
        confirmLock: null,
        openSession: null,
        async post(url) {
            const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
            let d = {}; try { d = await r.json(); } catch (e) {}
            if (!r.ok) throw new Error(d.message || 'The request failed (HTTP ' + r.status + ').');
            return d;
        },
        async lockAction(f, kind) {
            if (this.busy[f.id]) return;
            this.busy[f.id] = kind;
            try {
                const d = await this.post(cfg[kind + 'Url'].replace('__ID__', f.id));
                f.lock = Object.assign({}, f.lock || {}, { locked: d.locked, allowed: d.allowed || (f.lock && f.lock.allowed) || [], seen: d.seen || (f.lock && f.lock.seen) || [], port: d.port || (f.lock && f.lock.port), error: null, at: new Date().toISOString() });
                if (d.message) window.showToast && window.showToast('success', kind === 'unlock' ? 'Unlocked' : 'Locked', d.message, 8000);
            } catch (e) {
                f.lock = Object.assign({}, f.lock || {}, { error: e.message });
                window.showToast && window.showToast('error', 'Not done', e.message, 10000);
            } finally { this.busy[f.id] = null; this.confirmLock = null; }
        },
        async endSession(s) {
            try { const d = await this.post(cfg.endUrl.replace('__ID__', s.id)); s.ended = new Date().toISOString(); s.reason = 'ended by you'; s.removed = d.removed; }
            catch (e) { window.showToast && window.showToast('error', 'Not done', e.message, 8000); }
        },
        fmt, dur,
    };
};
</script>

    <div class="py-8" x-data="securityPage(@js([
            'firewalls' => $firewalls,
            'sessions' => $sessions,
            'statusUrl' => route('system.security.lock.status', '__ID__'),
            'lockUrl' => route('system.security.lock', '__ID__'),
            'unlockUrl' => route('system.security.unlock', '__ID__'),
            'endUrl' => route('system.security.session.end', '__ID__'),
        ]))">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @include('vpn.admin._notices')

            <form method="POST" action="{{ route('system.security.save') }}" class="space-y-6">
                @csrf
                {{-- Two-factor --}}
                <section class="{{ $card }} p-6">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Two-factor sign-in</h3>
                    <label class="mt-4 flex items-start gap-3">
                        <input type="hidden" name="require_2fa" value="0">
                        <input type="checkbox" name="require_2fa" value="1" @checked(old('require_2fa', $settings['require_2fa'])) class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        <span>
                            <span class="{{ $label }}">Require two-factor for everyone who signs in with a password or an emailed link</span>
                            <span class="{{ $help }} block">People who haven't set it up are sent to their profile to do it before they can open anything else. Signing in with Microsoft 365 counts as two-factor, because Microsoft asks for it.</span>
                        </span>
                    </label>
                    @if($without2fa->count())
                        <details class="mt-4 text-sm">
                            <summary class="cursor-pointer text-gray-700 dark:text-gray-300">{{ $without2fa->count() }} {{ \Illuminate\Support\Str::plural('person', $without2fa->count()) }} without two-factor</summary>
                            <ul class="mt-2 grid gap-1 sm:grid-cols-2">
                                @foreach($without2fa as $u)
                                    <li class="text-gray-600 dark:text-gray-400">{{ $u->name }} <span class="text-xs text-gray-400">{{ $u->email }}{{ $u->ms_oid ? ' · Microsoft 365' : '' }}</span></li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </section>

                {{-- Open through PFCentral --}}
                <section class="{{ $card }} p-6">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Open firewalls through {{ config('brand.name') }} <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">Optional</span></h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Adds "Open web interface" to each pfSense firewall's pages. {{ config('brand.name') }} signs the technician in with a temporary firewall account in their name, keeps the firewall's sign-in to itself, logs every change, and removes the account when the session ends.</p>
                    <label class="mt-4 flex items-start gap-3">
                        <input type="hidden" name="gui_proxy" value="0">
                        <input type="checkbox" name="gui_proxy" value="1" @checked(old('gui_proxy', $settings['gui_proxy'])) class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        <span class="{{ $label }}">Turn on</span>
                    </label>
                    <div class="mt-4 space-y-4 max-w-xl">
                        <label class="{{ $label }}">Host name for firewall web interfaces
                            <input name="gui_host" value="{{ old('gui_host', $settings['gui_host']) }}" placeholder="fwgui.cfbtel.com" class="mt-1 {{ $input }}" autocomplete="off">
                            <span class="{{ $help }} block">Its own name, like the VPN page's: point its DNS and reverse proxy (HTTPS) at this server. Only firewall web interfaces are served there.</span>
                        </label>
                        <label class="{{ $label }}">End a session after
                            <span class="mt-1 flex items-center gap-2"><input name="gui_idle" value="{{ old('gui_idle', $settings['gui_idle']) }}" inputmode="numeric" class="{{ str_replace('block w-full', 'block', $input) }}" style="width:6rem"> <span class="text-sm text-gray-500 whitespace-nowrap">minutes idle</span></span>
                            <span class="{{ $help }} block">And after 8 hours at most.</span>
                        </label>
                    </div>
                </section>

                {{-- Lock addresses --}}
                <section class="{{ $card }} p-6">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Lock firewall web interfaces to {{ config('brand.name') }} <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">Optional, per firewall</span></h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">A locked firewall's web interface only answers these addresses from the internet. LAN access is never changed. Before locking, {{ config('brand.name') }} checks that its own connection comes from one of them; afterwards, if it can't reach the firewall, the firewall undoes the lock by itself within 3 minutes.</p>
                    <label class="mt-4 block {{ $label }}">Allowed addresses
                        <textarea name="lock_allowed" rows="3" placeholder="{{ config('brand.name') }}'s public address, plus any office address (one per line, IP or network)" class="mt-1 {{ $input }} font-mono">{{ old('lock_allowed', $settings['lock_allowed']) }}</textarea>
                    </label>
                </section>

                <div class="flex justify-end">
                    <button class="inline-flex items-center rounded-md bg-brand-950 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-900 dark:bg-brand-500 dark:hover:bg-brand-400">Save</button>
                </div>
            </form>

            {{-- Per-firewall lock --}}
            <section class="{{ $card }}">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Firewall web interface lock</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Check a firewall to see its state and who is connected to its web interface. If {{ config('brand.name') }} itself is ever locked out, run <code class="rounded bg-gray-100 px-1 text-xs dark:bg-gray-900">pfctl -d</code> on the firewall's console to pause filtering, then unlock here.</p>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    <template x-for="f in firewalls" :key="f.id">
                        <li class="px-6 py-4">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium text-gray-900 dark:text-gray-100" x-text="f.name"></span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="f.company"></span>
                                </span>
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                      :class="f.lock && f.lock.locked ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                                      x-text="!f.lock ? 'Not checked' : (f.lock.locked ? 'Locked' : 'Not locked')"></span>
                                <button type="button" @click="lockAction(f, 'status')" :disabled="!!busy[f.id]" class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 disabled:opacity-50 dark:text-gray-200 dark:hover:bg-gray-700" x-text="busy[f.id] === 'status' ? 'Checking…' : 'Check'"></button>
                                <template x-if="!(f.lock && f.lock.locked) && confirmLock !== f.id">
                                    <button type="button" @click="confirmLock = f.id" :disabled="!!busy[f.id]" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Lock</button>
                                </template>
                                <template x-if="confirmLock === f.id">
                                    <span class="flex items-center gap-2 text-sm">
                                        <span class="text-gray-700 dark:text-gray-200">Lock its web interface to the allowed addresses?</span>
                                        <button type="button" @click="lockAction(f, 'lock')" :disabled="!!busy[f.id]" class="rounded-md bg-brand-950 px-3 py-1.5 font-medium text-white hover:bg-brand-900 disabled:opacity-60 dark:bg-brand-500" x-text="busy[f.id] === 'lock' ? 'Locking…' : 'Lock'"></button>
                                        <button type="button" @click="confirmLock = null" class="rounded-md px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                                    </span>
                                </template>
                                <template x-if="f.lock && f.lock.locked">
                                    <button type="button" @click="lockAction(f, 'unlock')" :disabled="!!busy[f.id]" class="rounded-md border border-red-200 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950" x-text="busy[f.id] === 'unlock' ? 'Unlocking…' : 'Unlock'"></button>
                                </template>
                            </div>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400" x-show="f.lock && (f.lock.seen || []).length" x-text="'Connected to its web interface now: ' + (f.lock?.seen || []).join(', ')"></p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-show="f.lock && f.lock.locked && (f.lock.allowed || []).length" x-text="'Allowed: ' + (f.lock?.allowed || []).join(', ') + (f.lock?.port ? ' · port ' + f.lock.port : '')"></p>
                            <p class="mt-1 text-xs text-red-700 dark:text-red-300" x-show="f.lock && f.lock.error" x-text="f.lock?.error"></p>
                        </li>
                    </template>
                    <li class="px-6 py-6 text-sm text-gray-500 dark:text-gray-400" x-show="!firewalls.length">No pfSense firewalls yet.</li>
                </ul>
            </section>

            {{-- Access log --}}
            <section class="{{ $card }}">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Firewall web interface sessions</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Who opened which firewall through {{ config('brand.name') }}, for how long, and what they changed.</p>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    <template x-for="s in sessions" :key="s.id">
                        <li class="px-6 py-3 text-sm">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                <span class="min-w-0 flex-1">
                                    <span class="font-medium text-gray-900 dark:text-gray-100" x-text="s.who"></span>
                                    <span class="text-gray-500 dark:text-gray-400" x-text="' · ' + s.firewall"></span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="fmt(s.started) + ' · ' + (s.ended ? dur(s.started, s.ended) + ', ' + (s.reason || 'ended') : 'open now') + ' · ' + s.actions.length + ' change' + (s.actions.length === 1 ? '' : 's')"></span>
                                </span>
                                <span class="text-xs text-amber-700 dark:text-amber-400" x-show="s.ended && !s.removed">Temporary account still on the firewall; retrying</span>
                                <button type="button" x-show="s.actions.length" @click="openSession = openSession === s.id ? null : s.id" class="text-xs font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white" x-text="openSession === s.id ? 'Hide changes' : 'Show changes'"></button>
                                <button type="button" x-show="!s.ended" @click="endSession(s)" class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950">End now</button>
                            </div>
                            <ol class="mt-2 space-y-1 rounded-md bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-900/40 dark:text-gray-300" x-show="openSession === s.id">
                                <template x-for="(a, i) in s.actions" :key="i"><li x-text="fmt(a.at) + '  ' + a.method + ' ' + a.page"></li></template>
                            </ol>
                        </li>
                    </template>
                    <li class="px-6 py-6 text-sm text-gray-500 dark:text-gray-400" x-show="!sessions.length">No sessions yet.</li>
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>
