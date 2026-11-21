@php
    $btn = 'inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700';
    $btn2 = 'inline-flex items-center px-3 py-1.5 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest hover:bg-gray-50 dark:hover:bg-gray-600';
    $btnRed = 'inline-flex items-center px-3 py-1.5 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500';
    $card = 'bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100';
    $input = 'border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm';
    $th = 'px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider';
    $td = 'px-3 py-2 text-sm';
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $company->name }} – VPN (WireGuard)</h2>
        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            <a href="{{ route('companies.show', $company) }}" class="font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">{{ $company->name }}</a>
            <span class="mx-2 text-gray-300 dark:text-gray-600">/</span> VPN
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-full mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('vpn.admin._notices')

            <div class="{{ $card }}">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    People this company gives VPN access sign in at <a class="text-indigo-600 dark:text-indigo-400 underline" href="{{ $portalUrl }}" target="_blank" rel="noopener">{{ $portalUrl }}</a>
                    with their own Microsoft 365 account (or email and password) and add up to {{ \App\Services\Vpn\VpnSettings::maxDevices() }} devices (CFBTEL VPN for Windows, WireGuard or AmneziaVPN app).
                </p>
            </div>

            {{-- 1. Microsoft 365 --}}
            <div class="{{ $card }}">
                <h3 class="text-lg font-bold mb-3">1. Microsoft 365</h3>
                @if($vc->ms_tenant_id)
                    <p class="text-sm">✓ Connected to <strong>{{ $vc->ms_tenant_name ?? $vc->ms_tenant_id }}</strong> <span class="text-gray-500">({{ $vc->ms_tenant_id }})</span>
                        · {{ $vc->hasOwnApp() ? 'the company\'s own Microsoft app (' . $vc->ms_client_id . ')' : 'CFBTEL\'s shared app' }}
                        @if($vc->ms_auto_setup_at) · set up automatically {{ $vc->ms_auto_setup_at->format('M j, Y') }}@endif</p>
                    @if($vc->hasOwnApp() && $vc->ms_secret_expires_at)
                        @php $days = (int) now()->diffInDays($vc->ms_secret_expires_at, false); @endphp
                        <p class="text-sm {{ $days < 30 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-500' }}">App secret {{ $days < 0 ? 'expired' : 'expires' }} {{ $vc->ms_secret_expires_at->format('M j, Y') }}{{ $days < 30 ? ' — run the automatic setup again to renew it.' : '' }}</p>
                    @endif
                @elseif($consentError && !$setupReady)
                    <p class="text-sm text-yellow-700 dark:text-yellow-300">{{ $consentError }}</p>
                @else
                    <p class="text-sm mb-3">Not connected yet.</p>
                    @if($canManage && $consentUrl && !$setupReady)
                        <p class="text-sm mb-3">A Global Administrator of this company's Microsoft 365 approves CFBTEL's VPN app once, or you enter the company's own Microsoft app below.</p>
                        <a href="{{ $consentUrl }}" class="{{ $btn }}">Approve CFBTEL's app</a>
                        <label class="block mt-3 text-xs text-gray-500">Or send this link to the company's Microsoft 365 admin (valid 7 days)
                            <input readonly value="{{ $consentUrl }}" class="mt-1 w-full {{ $input }}" onclick="this.select()">
                        </label>
                    @endif
                @endif

                @if($canManage && $setupLink)
                    <div class="mt-4 p-4 rounded border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-950/40 max-w-2xl">
                        <p class="text-sm font-semibold">{{ $vc->hasOwnApp() ? 'Renew or repair automatically' : 'Connect Microsoft 365 automatically' }}</p>
                        <p class="text-xs text-gray-600 dark:text-gray-300 mt-1">Sign in as a <strong>Global Administrator of this company's Microsoft 365</strong>. PFCentral creates the company's own "CFBTEL VPN" app there with all its permissions approved, saves the app ID, secret and email domains here, then removes its setup access. Nothing to copy or type.</p>
                        <a href="{{ $setupLink }}" class="{{ $btn }} mt-3 inline-block">{{ $vc->hasOwnApp() ? 'Run automatic setup again' : 'Connect Microsoft 365 automatically' }}</a>
                        <label class="block mt-3 text-xs text-gray-500">Not their admin? Send this link to the company's Microsoft 365 admin (valid {{ \App\Http\Controllers\Vpn\MicrosoftSetupController::LINK_DAYS }} days, works once)
                            <input readonly value="{{ $setupLink }}" class="mt-1 w-full {{ $input }}" onclick="this.select()">
                        </label>
                    </div>
                @endif

                @if($canManage)
                    <form method="POST" action="{{ route('vpn.microsoft.save', $company) }}" class="mt-5 space-y-3 max-w-2xl">@csrf
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Work email domains
                            <input name="email_domains" value="{{ old('email_domains', implode(', ', $vc->domains())) }}" placeholder="acme.com, acme.us" class="mt-1 w-full {{ $input }}">
                        </label>
                        <p class="text-xs text-gray-500">Staff type their work email on the VPN page; its domain picks this company. Required when the company uses its own Microsoft app.</p>

                        <details class="mt-2" @if($vc->hasOwnApp() || $errors->hasAny(['ms_tenant_id', 'ms_client_id', 'ms_client_secret'])) open @endif>
                            <summary class="cursor-pointer text-sm font-semibold">Enter the company's own Microsoft app by hand (advanced)</summary>
                            <div class="mt-3 space-y-3">
                                <ol class="text-xs text-gray-600 dark:text-gray-300 list-decimal ml-5 space-y-1">
                                    <li>In <strong>the company's</strong> Entra admin center: App registrations → New registration → name <em>CFBTEL VPN</em>, <em>Accounts in this organizational directory only</em>.</li>
                                    <li>Redirect URI (Web): <code>{{ $callbackUrl }}</code></li>
                                    <li>API permissions → Microsoft Graph: delegated <code>openid</code>, <code>profile</code>, <code>email</code>, <code>User.Read</code>; application <code>User.Read.All</code> and <code>GroupMember.Read.All</code> → <strong>Grant admin consent</strong>.</li>
                                    <li>Certificates &amp; secrets → New client secret; copy its <em>Value</em>. Note its expiry and enter a new one here before it ends.</li>
                                </ol>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Directory (tenant) ID
                                    <input name="ms_tenant_id" value="{{ old('ms_tenant_id', $vc->hasOwnApp() ? $vc->ms_tenant_id : '') }}" placeholder="00000000-0000-0000-0000-000000000000" class="mt-1 w-full {{ $input }}" autocomplete="off">
                                </label>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Application (client) ID
                                    <input name="ms_client_id" value="{{ old('ms_client_id', $vc->ms_client_id) }}" placeholder="00000000-0000-0000-0000-000000000000" class="mt-1 w-full {{ $input }}" autocomplete="off">
                                </label>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Client secret {{ $vc->hasOwnApp() ? '(saved — leave blank to keep it)' : '' }}
                                    <input name="ms_client_secret" type="password" class="mt-1 w-full {{ $input }}" autocomplete="new-password">
                                </label>
                            </div>
                        </details>
                        <button class="{{ $btn2 }}">Save</button>
                    </form>
                    @if($vc->hasOwnApp())
                        <form method="POST" action="{{ route('vpn.microsoft.remove', $company) }}" class="mt-2" onsubmit="return confirm('Stop using the company\'s own Microsoft app?')">@csrf<button class="{{ $btnRed }}">Remove own app</button></form>
                    @endif
                @endif
            </div>

            {{-- 2. Firewalls --}}
            <div class="{{ $card }}">
                <h3 class="text-lg font-bold mb-3">2. Firewalls</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead><tr><th class="{{ $th }}">Firewall</th><th class="{{ $th }}">Type</th><th class="{{ $th }}">VPN</th><th class="{{ $th }}">Endpoint</th><th class="{{ $th }}">VPN network</th><th class="{{ $th }}">Who can use it</th><th class="{{ $th }}"></th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($firewalls as $fw)
                            @php $s = $sites[$fw->id] ?? null; @endphp
                            <tr>
                                <td class="{{ $td }} font-medium">{{ $fw->name }}</td>
                                <td class="{{ $td }}">{{ $fw->os_display_name }}</td>
                                <td class="{{ $td }}">
                                    @if(!$s) <span class="text-gray-400">off</span>
                                    @elseif($s->isReady()) <span class="text-green-600 dark:text-green-400 font-semibold">ready</span>
                                    @else <span class="text-yellow-600 dark:text-yellow-400">{{ $s->status }}</span> @endif
                                </td>
                                <td class="{{ $td }}">{{ $s?->endpoint ?? '—' }}</td>
                                <td class="{{ $td }}">{{ $s?->subnet ?? '—' }}</td>
                                <td class="{{ $td }} text-xs">@if(!$s) — @else @php $who = $siteAccess[$s->id] ?? ''; @endphp <span class="{{ str_starts_with($who, 'blocked') ? 'text-red-600 dark:text-red-400 font-semibold' : '' }}">{{ $who }}</span> @endif</td>
                                <td class="{{ $td }} text-right">
                                    @if($s)
                                        <a href="{{ route('vpn.site', [$company, $s]) }}" class="{{ $btn2 }}">{{ $s->isReady() ? 'Settings' : 'Set up' }}</a>
                                    @elseif($canManage)
                                        <form method="POST" action="{{ route('vpn.firewall.enable', [$company, $fw]) }}" class="inline">@csrf<button class="{{ $btn2 }}">Enable VPN</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="{{ $td }} text-gray-500">This company has no firewalls in PFCentral yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- 3. People --}}
            <div class="{{ $card }}">
                <h3 class="text-lg font-bold mb-3">3. Who has VPN access</h3>

                {{-- Access groups: which firewalls who may use --}}
                @php $vpnSites = $sites->values(); $blockedSites = $vpnSites->filter(fn ($vs) => str_starts_with($siteAccess[$vs->id] ?? '', 'blocked')); @endphp
                <div class="mb-6 p-4 rounded border border-gray-200 dark:border-gray-700 max-w-4xl">
                    <p class="text-sm font-semibold">Access groups <span class="font-normal text-gray-500">— which firewalls each person can use</span></p>
                    @if($accessGroups->isEmpty() && !$canMakeGroups)
                        <p class="text-xs text-gray-500 mt-2">Access groups are for companies with 2 or more firewalls with VPN. With one firewall, everyone with VPN access uses it.</p>
                    @else
                        <p class="text-xs text-gray-500 mt-1">A group names one or more firewalls and its members: everyone with VPN access, people picked from the list below, and/or Microsoft 365 groups. A person can use the firewalls of all their groups. @if($accessGroups->isEmpty())<strong>No groups yet: every firewall is open to everyone with VPN access.</strong> Once a group exists, firewalls in no group are blocked. @else Firewalls in no group are blocked. @endif Changes reach devices at the next sync (within 15 minutes).</p>
                        @if($accessGroups->isNotEmpty() && $blockedSites->isNotEmpty())
                            <p class="text-sm text-red-600 dark:text-red-400 mt-2">Blocked (in no group): {{ $blockedSites->map(fn ($bs) => $bs->firewall->name)->join(', ') }}</p>
                        @endif
                        <ul class="mt-3 divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($accessGroups as $ag)
                                <li class="py-2">
                                    <details @if((string) old('group_id') === (string) $ag->id) open @endif>
                                        <summary class="cursor-pointer text-sm">
                                            <strong>{{ $ag->name }}</strong>
                                            <span class="text-gray-500"> · {{ $ag->sites->map(fn ($gs) => $gs->firewall?->name)->filter()->join(', ') }}</span>
                                            <span class="text-xs text-gray-500"> · @if($ag->everyone) everyone with VPN access @else {{ $ag->users->count() }} {{ \Illuminate\Support\Str::plural('person', $ag->users->count()) }}@foreach($ag->links as $ln), {{ $ln->providerName() }} “{{ $ln->external_name ?: $ln->external_id }}”@endforeach @endif</span>
                                        </summary>
                                        @if($canManage)
                                            @include('vpn.admin._access-group-form', ['ag' => $ag])
                                            @php $otherLinks = $ag->links->where('provider', '!=', 'microsoft'); @endphp
                                            @if($otherLinks->isNotEmpty())
                                                <ul class="mt-2 text-sm space-y-1">
                                                    @foreach($otherLinks as $ln)
                                                        <li class="flex items-center justify-between gap-3 max-w-xl">
                                                            <span>{{ $ln->providerName() }} group <strong>{{ $ln->external_name ?: $ln->external_id }}</strong> — its members are in this group</span>
                                                            <form method="POST" action="{{ route('vpn.access-groups.unlink', [$company, $ln]) }}" onsubmit="return confirm('Unlink this group? Its members lose this access at the next sync.')">@csrf<button class="{{ $btnRed }}">Unlink</button></form>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                            <form method="POST" action="{{ route('vpn.access-groups.delete', [$company, $ag]) }}" class="mt-2" onsubmit="return confirm('Delete the access group {{ addslashes($ag->name) }}? Its members lose its firewalls at the next sync{{ $accessGroups->count() === 1 ? ', and every firewall opens to everyone with VPN access' : '' }}.')">@csrf<button class="{{ $btnRed }}">Delete group</button></form>
                                        @endif
                                    </details>
                                </li>
                            @endforeach
                        </ul>
                        @if($canManage && $canMakeGroups)
                            <details class="mt-3" @if(old('group_id') === 'new') open @endif>
                                <summary class="cursor-pointer text-sm font-semibold text-indigo-600 dark:text-indigo-400">+ New access group</summary>
                                @if($accessGroups->isEmpty())
                                    <p class="text-xs text-yellow-700 dark:text-yellow-400 mt-2">After this first group, firewalls you don't put in a group are blocked.</p>
                                @endif
                                @include('vpn.admin._access-group-form', ['ag' => null])
                            </details>
                        @endif
                    @endif
                </div>

                {{-- Microsoft 365 groups for the whole company --}}
                <div class="mb-6 p-4 rounded border border-gray-200 dark:border-gray-700 max-w-4xl">
                    <p class="text-sm font-semibold">Microsoft 365 groups that give VPN access <span class="font-normal text-gray-500">(optional)</span></p>
                    <p class="text-xs text-gray-500 mt-1">Members of these groups get VPN access without being added one by one (nested groups count). Leaving a group removes that access within 15 minutes. People added by hand below keep access. To decide which firewalls someone uses, add groups inside an access group above.</p>
                    @if($canManage && $vc->ms_tenant_id)
                        <form method="POST" action="{{ route('vpn.groups.company', $company) }}" class="mt-3 flex flex-col sm:flex-row gap-2 sm:items-start">@csrf
                            <div class="flex-1">@include('vpn.admin._ms-group-picker', ['pickerName' => 'ms_groups', 'pickerSelected' => $vc->companyGroups()])</div>
                            <button class="{{ $btn2 }} shrink-0">Save groups</button>
                        </form>
                    @elseif($vc->companyGroups())
                        <p class="text-sm mt-2">{{ collect($vc->companyGroups())->pluck('name')->join(', ') }}</p>
                    @elseif($canManage)
                        <p class="text-xs text-gray-500 mt-2">Connect Microsoft 365 first to use its groups.</p>
                    @else
                        <p class="text-sm text-gray-500 mt-2">None.</p>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead><tr><th class="{{ $th }}">Person</th><th class="{{ $th }}">Signs in with</th><th class="{{ $th }}">Devices</th><th class="{{ $th }}">Last sign-in</th><th class="{{ $th }}">Added by</th><th class="{{ $th }}"></th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($users as $u)
                            <tr>
                                <td class="{{ $td }}"><div class="font-medium">{{ $u->name }}@if($u->isBlocked()) <span class="ml-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300" title="Blocked by {{ $u->blocked_by }} on the live VPN page">Blocked</span>@endif</div><div class="text-xs text-gray-500">{{ $u->upn }}</div></td>
                                <td class="{{ $td }}">
                                    @if($u->isLocal())
                                        Email &amp; password
                                        @php $st = $u->status(); @endphp
                                        <span class="text-xs {{ $st === 'active' ? 'text-green-600 dark:text-green-400' : 'text-yellow-600 dark:text-yellow-400' }}">· {{ $st === 'invited' ? 'invited, password not set' : $st }}</span>
                                        @php $mfaLabel = \App\Services\Vpn\VpnMfa::label($u); @endphp
                                        <span class="text-xs {{ str_starts_with($mfaLabel, '2FA on') ? 'text-green-600 dark:text-green-400' : (str_contains($mfaLabel, 'required') ? 'text-yellow-600 dark:text-yellow-400' : 'text-gray-500') }}">· {{ $mfaLabel }}@if($u->mfa_mode) <span class="text-gray-500">({{ strtolower(\App\Services\Vpn\VpnMfa::MODES[$u->mfa_mode]) }} for this person)</span>@endif</span>
                                    @else
                                        Microsoft 365 @if($u->viaGroup()) <span class="text-xs text-indigo-600 dark:text-indigo-400">· through group</span>@endif
                                    @endif
                                </td>
                                <td class="{{ $td }}">{{ $u->active_devices_count }}</td>
                                <td class="{{ $td }}">{{ $u->last_login_at?->diffForHumans() ?? 'never' }}</td>
                                <td class="{{ $td }} text-xs text-gray-500">{{ $u->added_by }}</td>
                                <td class="{{ $td }} text-right whitespace-nowrap">
                                    @if($canManage)
                                        @if($u->isLocal())
                                            <details class="inline-block text-left align-middle">
                                                <summary class="{{ $btn2 }} cursor-pointer">Edit</summary>
                                                <form method="POST" action="{{ route('vpn.users.update', [$company, $u]) }}" class="mt-2 space-y-2 p-3 rounded-md bg-gray-50 dark:bg-gray-700/50">@csrf
                                                    <input name="name" value="{{ $u->name }}" class="w-56 {{ $input }}" aria-label="Name">
                                                    <input name="email" type="email" value="{{ $u->upn }}" class="w-56 {{ $input }}" aria-label="Email">
                                                    <label class="block text-xs text-gray-500">Two-factor sign-in
                                                        <select name="mfa_mode" class="block w-56 {{ $input }}">
                                                            <option value="" @selected(!$u->mfa_mode)>Company setting ({{ strtolower(\App\Services\Vpn\VpnMfa::MODES[$mfaCompany]) }})</option>
                                                            @foreach(\App\Services\Vpn\VpnMfa::MODES as $k => $l)<option value="{{ $k }}" @selected($u->mfa_mode === $k)>{{ $l }}</option>@endforeach
                                                        </select>
                                                    </label>
                                                    <button class="{{ $btn2 }}">Save</button>
                                                </form>
                                                @if(\App\Services\Vpn\VpnMfa::enabled($u))
                                                    <form method="POST" action="{{ route('vpn.users.mfa-reset', [$company, $u]) }}" class="mt-2" onsubmit="return confirm('Reset two-factor for {{ addslashes($u->name) }}? Use this when they lost their phone. Their authenticator and recovery codes stop working.')">@csrf<button class="{{ $btn2 }}">Reset two-factor</button></form>
                                                @endif
                                            </details>
                                            <form method="POST" action="{{ route('vpn.users.resend', [$company, $u]) }}" class="inline">@csrf<button class="{{ $btn2 }}">{{ $u->password ? 'Send password link' : 'Resend invite' }}</button></form>
                                        @endif
                                        <form method="POST" action="{{ route('vpn.users.remove', [$company, $u]) }}" class="inline" onsubmit="return confirm('Remove this person\'s VPN access? Their devices stop working right away.')">@csrf<button class="{{ $btnRed }}">Remove</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="{{ $td }} text-gray-500">Nobody has VPN access yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if($canManage)
                    <h4 class="font-semibold mt-6 mb-2">Give someone VPN access — from Microsoft 365</h4>
                    @if($vc->ms_tenant_id)
                        <form method="GET" action="{{ route('vpn.company', $company) }}" class="flex gap-2 max-w-xl">
                            <input name="q" value="{{ $q }}" placeholder="Search this company's Microsoft 365 by name or email" class="flex-1 {{ $input }}">
                            <button class="{{ $btn2 }}" name="search" value="1">Search</button>
                        </form>
                        @if($searchError)<p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $searchError }}</p>@endif
                        @if($results !== null)
                            <ul class="mt-3 divide-y divide-gray-200 dark:divide-gray-700 max-w-xl">
                                @forelse($results as $r)
                                    <li class="py-2 flex items-center justify-between gap-3">
                                        <div><div class="text-sm font-medium">{{ $r['name'] }}</div><div class="text-xs text-gray-500">{{ $r['mail'] ?? $r['upn'] }}@if($r['guest']) · guest @endif</div></div>
                                        <form method="POST" action="{{ route('vpn.users.store', $company) }}">@csrf<input type="hidden" name="oid" value="{{ $r['oid'] }}"><button class="{{ $btn2 }}">Give VPN access</button></form>
                                    </li>
                                @empty
                                    <li class="py-2 text-sm text-gray-500">No matching people{{ $q !== '' ? " for “{$q}”" : '' }}.</li>
                                @endforelse
                            </ul>
                        @endif
                    @else
                        <p class="text-sm text-gray-500">Connect Microsoft 365 first (or invite by email below).</p>
                    @endif

                    <h4 class="font-semibold mt-6 mb-1">…or invite by email (no Microsoft needed)</h4>
                    <form method="POST" action="{{ route('vpn.mfa.save', $company) }}" class="flex flex-wrap items-center gap-2 mb-3 max-w-2xl">@csrf
                        <label class="text-sm">Two-factor sign-in (authenticator app with QR code) for these accounts:</label>
                        <select name="mfa_mode" class="{{ $input }}">
                            @foreach(\App\Services\Vpn\VpnMfa::MODES as $k => $l)<option value="{{ $k }}" @selected($mfaCompany === $k)>{{ $l }}</option>@endforeach
                        </select>
                        <button class="{{ $btn2 }}">Save</button>
                        <span class="text-xs text-gray-500 w-full">Required: everyone sets up Google Authenticator (or similar) at their first sign-in. Optional: people can turn it on on the VPN page. Each person can be set differently under Edit. Microsoft 365 accounts use Microsoft's own MFA.</span>
                    </form>
                    <p class="text-xs text-gray-500 mb-2">For companies without Microsoft Entra. The person gets an email with a link (valid 7 days) to set their own password, then signs in on the VPN page with their email and password.</p>
                    @error('invite')<p class="text-sm text-red-600 dark:text-red-400 mb-2">{{ $message }}</p>@enderror
                    @error('edit')<p class="text-sm text-red-600 dark:text-red-400 mb-2">{{ $message }}</p>@enderror
                    <form method="POST" action="{{ route('vpn.users.invite', $company) }}" class="flex flex-wrap gap-2 max-w-2xl">@csrf
                        <input name="name" value="{{ old('name') }}" placeholder="Full name" required maxlength="120" class="flex-1 min-w-[10rem] {{ $input }}">
                        <input name="email" type="email" value="{{ old('email') }}" placeholder="name@company.com" required class="flex-1 min-w-[12rem] {{ $input }}">
                        <select name="mfa_mode" class="{{ $input }}" aria-label="Two-factor sign-in for this person">
                            <option value="">2FA: company setting ({{ strtolower(\App\Services\Vpn\VpnMfa::MODES[$mfaCompany]) }})</option>
                            @foreach(\App\Services\Vpn\VpnMfa::MODES as $k => $l)<option value="{{ $k }}" @selected(old('mfa_mode') === $k)>2FA: {{ strtolower($l) }}</option>@endforeach
                        </select>
                        <button class="{{ $btn }}">Send invitation</button>
                    </form>
                @endif
            </div>

            {{-- 4. Devices --}}
            <div class="{{ $card }}">
                <h3 class="text-lg font-bold mb-3">4. Devices</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead><tr><th class="{{ $th }}">Person</th><th class="{{ $th }}">Device</th><th class="{{ $th }}">Firewall</th><th class="{{ $th }}">Address</th><th class="{{ $th }}">Added</th><th class="{{ $th }}"></th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($devices as $d)
                            <tr>
                                <td class="{{ $td }}">{{ $d->user?->upn ?? '(access removed)' }}</td>
                                <td class="{{ $td }}">{{ $d->name }}</td>
                                <td class="{{ $td }}">{{ $d->site?->firewall?->name }}</td>
                                <td class="{{ $td }}">{{ $d->address }}</td>
                                <td class="{{ $td }}">{{ $d->created_at?->diffForHumans() }}</td>
                                <td class="{{ $td }} text-right">
                                    @if($d->revoked_at)
                                        <span class="text-xs text-yellow-600 dark:text-yellow-400">being removed</span>
                                    @elseif($canManage)
                                        <form method="POST" action="{{ route('vpn.devices.remove', [$company, $d]) }}" class="inline">@csrf<button class="{{ $btnRed }}">Remove</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="{{ $td }} text-gray-500">No devices yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Support tickets from the Windows app --}}
            <div class="{{ $card }}">
                <h3 class="text-lg font-bold mb-3">Support tickets from the app</h3>
                <p class="text-xs text-gray-500 mb-2">Sent from CFBTEL VPN → Diagnostics, with the checks and the VPN log. Each one is also emailed to {{ \App\Http\Controllers\Vpn\AppSupportController::supportAddress() }}.</p>
                <ul class="text-sm divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($supportReports as $sr)
                        <li class="py-2 flex items-center justify-between gap-3">
                            <div>
                                <a href="{{ route('vpn.support.show', [$company, $sr->id]) }}" class="font-medium text-indigo-600 dark:text-indigo-400">{{ $sr->reference() }} · {{ $sr->subject }}</a>
                                <div class="text-xs text-gray-500">{{ $sr->created_at?->diffForHumans() }} · {{ $sr->contact_name ?: $sr->contact_email }} · {{ $sr->device_name }} @if($sr->computer)({{ $sr->computer }})@endif · {{ $sr->problems }} problem(s), {{ $sr->warnings }} warning(s)</div>
                            </div>
                            @if($sr->emailed_at)<span class="text-xs text-green-600 dark:text-green-400">emailed</span>@else<span class="text-xs text-yellow-600 dark:text-yellow-400" title="{{ $sr->email_error }}">not emailed</span>@endif
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">None yet.</li>
                    @endforelse
                </ul>
            </div>

            {{-- Activity --}}
            <div class="{{ $card }}">
                <h3 class="text-lg font-bold mb-3">Activity</h3>
                <ul class="text-sm space-y-1">
                    @forelse($audit as $a)
                        <li><span class="text-xs text-gray-500">{{ $a->created_at }} · {{ $a->actor }}</span> {{ $a->action }}</li>
                    @empty
                        <li class="text-gray-500">Nothing yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
