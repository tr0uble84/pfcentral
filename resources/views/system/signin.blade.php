@php
    $input = 'block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
    $help = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $connected = $tenantId && $clientId && $hasSecret;
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Sign-in settings</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">How your own team signs in to {{ config('brand.name') }}.</p>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 dark:bg-emerald-900/30 dark:border-emerald-800 dark:text-emerald-300 text-sm" role="status">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 dark:bg-rose-900/30 dark:border-rose-800 dark:text-rose-300 text-sm" role="alert">
                    @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
                </div>
            @endif

            {{-- Step 1: connect Microsoft 365 automatically --}}
            <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                <div class="flex items-start gap-4">
                    <svg class="w-9 h-9 shrink-0" viewBox="0 0 23 23" aria-hidden="true"><path fill="#f25022" d="M1 1h10v10H1z"/><path fill="#7fba00" d="M12 1h10v10H12z"/><path fill="#00a4ef" d="M1 12h10v10H1z"/><path fill="#ffb900" d="M12 12h10v10H12z"/></svg>
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">1. Connect your Microsoft 365</h3>
                            @if ($connected)
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Connected</span>
                            @endif
                        </div>
                        @if ($connected)
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Tenant <span class="font-mono text-xs">{{ $tenantId }}</span>, app <span class="font-mono text-xs">{{ $clientId }}</span>.
                                @if ($secretExpires) Its secret is valid until {{ \Carbon\Carbon::parse($secretExpires)->format('F j, Y') }}. @endif
                            </p>
                        @else
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Sign in once as a <strong class="font-semibold text-gray-900 dark:text-gray-100">Global Administrator</strong> of your company's Microsoft 365.
                                {{ config('brand.name') }} creates the app "PFCentral sign-in" in your tenant with the sign-in address, permissions and secret already filled in, and saves everything here. Nothing to copy.
                            </p>
                        @endif
                        @if ($autoReady)
                            <form method="POST" action="{{ route('system.signin.auto') }}" class="mt-4">
                                @csrf
                                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-md text-sm font-semibold shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-sky-500 {{ $connected ? 'border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700' : 'text-white hover:opacity-90' }}"
                                    @unless ($connected) style="background:#0A84BA" @endunless>
                                    <svg class="w-4 h-4" viewBox="0 0 23 23" aria-hidden="true"><path fill="#f25022" d="M1 1h10v10H1z"/><path fill="#7fba00" d="M12 1h10v10H12z"/><path fill="#00a4ef" d="M1 12h10v10H1z"/><path fill="#ffb900" d="M12 12h10v10H12z"/></svg>
                                    {{ $connected ? 'Run the automatic setup again' : 'Set up automatically with Microsoft 365' }}
                                </button>
                            </form>
                        @else
                            <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">Automatic setup needs the CFBTEL setup app: VPN Access → settings → One-click Microsoft 365 setup. You can also set it up by hand below.</p>
                        @endif
                    </div>
                </div>
            </section>

            <form method="POST" action="{{ route('system.signin.save') }}" class="space-y-6">
                @csrf
                {{-- Step 2: who may sign in --}}
                <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">2. Choose who may sign in</h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Everyone in these groups can use "Sign in with Microsoft 365" on the login page and gets the
                                <strong class="font-semibold text-gray-900 dark:text-gray-100">Administrator</strong> role for all companies.
                                Membership is checked at every sign-in, so removing someone from the group stops them.
                            </p>
                        </div>
                        @if ($ready)
                            <span class="shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> On</span>
                        @else
                            <span class="shrink-0 inline-flex px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">Off</span>
                        @endif
                    </div>

                    <div>
                        <span class="{{ $label }}">Groups that may sign in</span>
                        @if ($tenantId)
                            <div class="mt-1">
                                @include('vpn.admin._ms-group-picker', [
                                    'pickerName' => 'ms_groups',
                                    'pickerSelected' => old('ms_groups') ? [] : $groups,
                                    'pickerUrl' => route('system.signin.groups'),
                                    'pickerPlaceholder' => 'Type to search your Microsoft 365 groups',
                                ])
                            </div>
                            <p class="{{ $help }}">Pick one or more groups. Nested groups count.</p>
                        @else
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Connect your Microsoft 365 first (step 1); then you can search and pick groups here.</p>
                            @foreach ($groups as $g)<input type="hidden" name="ms_groups[]" value="{{ $g['id'] }}">@endforeach
                        @endif
                    </div>

                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="enabled" value="1" class="mt-0.5 rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-brand-600 focus:ring-brand-500" @checked(old('enabled', $enabled))>
                        <span><span class="{{ $label }}">Turn on Microsoft 365 sign-in</span><span class="{{ $help }} block">Email-and-password sign-in keeps working for everyone else.</span></span>
                    </label>
                </section>

                {{-- By hand (advanced) --}}
                <details class="group bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg" @if (!$autoReady && !$connected) open @endif>
                    <summary class="cursor-pointer select-none list-none p-6 flex items-center justify-between gap-4">
                        <span>
                            <span class="block text-base font-semibold text-gray-900 dark:text-gray-100">Set up by hand (advanced)</span>
                            <span class="block mt-1 text-sm text-gray-600 dark:text-gray-400">Tenant ID, your own app registration and the redirect address.</span>
                        </span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </summary>
                    <div class="px-6 pb-6 space-y-5" x-data="{ own: @js((bool) old('client_id', $clientId)), copied: false }">
                        <div>
                            <label for="tenant_id" class="{{ $label }}">Microsoft 365 tenant ID</label>
                            <input id="tenant_id" name="tenant_id" value="{{ old('tenant_id', $tenantId) }}" class="{{ $input }} mt-1 font-mono" placeholder="0a1b2c3d-1234-4e5f-8a9b-0123456789ab" autocomplete="off" spellcheck="false">
                            <p class="{{ $help }}">Microsoft Entra admin center → Overview → Tenant ID. Only accounts from this tenant can sign in.</p>
                        </div>
                        <div>
                            <span class="{{ $label }}">Redirect URI (Web platform)</span>
                            <div class="mt-1 flex gap-2">
                                <input readonly value="{{ $redirectUri }}" class="{{ $input }} font-mono text-xs bg-gray-50 dark:bg-gray-900/60" x-ref="uri" aria-label="Redirect URI">
                                <button type="button" class="shrink-0 px-3 py-2 rounded-md border border-gray-300 dark:border-gray-600 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"
                                    @click="navigator.clipboard.writeText($refs.uri.value); copied = true; setTimeout(() => copied = false, 1500)" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                            </div>
                            <p class="{{ $help }}">The app also needs the Microsoft Graph application permission GroupMember.Read.All with admin consent. The automatic setup does all of this for you.</p>
                        </div>
                        <label class="flex items-center gap-3">
                            <input type="checkbox" x-model="own" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-brand-600 focus:ring-brand-500">
                            <span class="{{ $label }}">Use this app registration (otherwise the CFBTEL VPN app@if (!$vpnAppReady), which is not set up yet@endif)</span>
                        </label>
                        <div x-show="own" x-cloak class="grid sm:grid-cols-2 gap-5">
                            <div>
                                <label for="client_id" class="{{ $label }}">Application (client) ID</label>
                                <input id="client_id" name="client_id" :disabled="!own" value="{{ old('client_id', $clientId) }}" class="{{ $input }} mt-1 font-mono" autocomplete="off" spellcheck="false">
                            </div>
                            <div>
                                <label for="client_secret" class="{{ $label }}">Client secret</label>
                                <input id="client_secret" name="client_secret" type="password" :disabled="!own" class="{{ $input }} mt-1" autocomplete="new-password"
                                    placeholder="{{ $hasSecret ? 'Saved. Type a new one to replace it.' : 'Not set' }}">
                                @if ($hasSecret)
                                    <label class="mt-2 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400"><input type="checkbox" name="clear_secret" value="1" class="rounded border-gray-300 text-brand-600"> Remove the saved secret</label>
                                @endif
                            </div>
                        </div>
                    </div>
                </details>

                <div>
                    <button type="submit" class="inline-flex items-center px-4 py-2.5 rounded-md text-white text-sm font-semibold shadow-sm hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-sky-500" style="background:#0A84BA">Save sign-in settings</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
