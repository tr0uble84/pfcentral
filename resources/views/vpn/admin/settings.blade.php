@php
    $btn = 'inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700';
    $card = 'bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100';
    $input = 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
    $th = 'px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider';
    $td = 'px-3 py-2 text-sm';
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">VPN Access</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('vpn.admin._tabs')
            @include('vpn.admin._notices')

            <div class="{{ $card }}">
                <h3 class="text-lg font-bold mb-3">Companies</h3>
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead><tr><th class="{{ $th }}">Company</th><th class="{{ $th }}">Microsoft 365</th><th class="{{ $th }}">VPN firewalls</th><th class="{{ $th }}">People</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($companies as $row)
                        <tr>
                            <td class="{{ $td }}"><a class="text-indigo-600 dark:text-indigo-400 font-medium" href="{{ route('vpn.company', $row['company']) }}">{{ $row['company']->name }}</a></td>
                            <td class="{{ $td }}">{{ $row['tenant'] ?? '—' }}</td>
                            <td class="{{ $td }}">{{ $row['sites'] }}</td>
                            <td class="{{ $td }}">{{ $row['people'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="{{ $card }}">
                <h3 class="text-lg font-bold mb-1">Settings</h3>
                <p class="text-sm text-gray-500 mb-4">
                    <strong>CFBTEL's shared Microsoft app</strong> (optional — with one-click setup below, each company gets its own app instead).
                    One multi-tenant app registration in CFBTEL's Microsoft Entra ("Accounts in any organizational directory").
                    Redirect URIs (Web): <code>{{ $redirects[0] }}</code> and <code>{{ $redirects[1] }}</code>.
                    API permissions (Microsoft Graph): delegated <code>openid</code>, <code>profile</code>, <code>email</code>, <code>User.Read</code>; application <code>User.Read.All</code> and <code>GroupMember.Read.All</code>, then grant admin consent.
                </p>
                <form method="POST" action="{{ route('vpn.settings.update') }}" class="space-y-4 max-w-xl">@csrf
                    <label class="{{ $label }}">Application (client) ID<input name="ms_client_id" value="{{ old('ms_client_id', $clientId) }}" placeholder="00000000-0000-0000-0000-000000000000" class="{{ $input }}" autocomplete="off"></label>
                    <label class="{{ $label }}">Client secret {{ $hasSecret ? '(saved — leave blank to keep it)' : '' }}<input name="ms_client_secret" type="password" class="{{ $input }}" autocomplete="new-password"></label>
                    <label class="{{ $label }}">Staff VPN page host name<input name="portal_host" value="{{ old('portal_host', $portalHost) }}" placeholder="vpn.example.com" class="{{ $input }}"></label>
                    <p class="text-xs text-gray-500">Only the VPN pages are served on this name; the PFCentral console isn't. Point its DNS and reverse proxy at this server.</p>
                    <label class="{{ $label }}">Reverse proxy IPs in front of the staff page (optional)<input name="proxy_ips" value="{{ old('proxy_ips', $proxyIps) }}" placeholder="203.0.113.81" class="{{ $input }}"></label>
                    <p class="text-xs text-gray-500">Only needed when the staff page is reached through a proxy (e.g. Coolify): its forwarded visitor address is then used for sign-in limits.</p>
                    <label class="{{ $label }}">Devices per person<input name="max_devices" value="{{ old('max_devices', $maxDevices) }}" inputmode="numeric" class="{{ $input }}"></label>
                    <button class="{{ $btn }}">Save</button>
                </form>
            </div>

            <div class="{{ $card }}">
                <h3 class="text-lg font-bold mb-1">One-click Microsoft 365 setup</h3>
                <p class="text-sm text-gray-500 mb-3">
                    Lets a company connect with one click: its Global Administrator signs in, and PFCentral creates the company's own "CFBTEL VPN" app in its Microsoft 365 with all permissions approved.
                    It needs one app registration in <strong>CFBTEL's</strong> Microsoft Entra, created once:
                </p>
                <ol class="text-xs text-gray-600 dark:text-gray-300 list-decimal ml-5 space-y-1 mb-4 max-w-3xl">
                    <li>Entra admin center → App registrations → New registration → name <em>CFBTEL VPN Setup</em>, <em>Accounts in any organizational directory (Multitenant)</em>.</li>
                    <li>Redirect URI, platform <em>Web</em>: <code>{{ $setupRedirect }}</code></li>
                    <li>API permissions → Microsoft Graph → <strong>Delegated</strong>: <code>Application.ReadWrite.All</code>, <code>AppRoleAssignment.ReadWrite.All</code>, <code>DelegatedPermissionGrant.ReadWrite.All</code> (plus the default <code>User.Read</code>). No application permissions, and no admin consent needed in CFBTEL's tenant.</li>
                    <li>Certificates &amp; secrets → New client secret → paste its <em>Value</em> below, together with the Application (client) ID.</li>
                </ol>
                <p class="text-xs text-gray-500 mb-4">PFCentral only gets these permissions for the minutes of a setup, as the admin who signs in, and deletes the setup app from the company's tenant when done. The setup sign-in is never saved.</p>
                <form method="POST" action="{{ route('vpn.settings.setup') }}" class="space-y-4 max-w-xl">@csrf
                    <label class="{{ $label }}">Setup app — Application (client) ID<input name="setup_client_id" value="{{ old('setup_client_id', $setupClientId) }}" placeholder="00000000-0000-0000-0000-000000000000" class="{{ $input }}" autocomplete="off"></label>
                    <label class="{{ $label }}">Setup app — client secret {{ $setupHasSecret ? '(saved — leave blank to keep it)' : '' }}<input name="setup_client_secret" type="password" class="{{ $input }}" autocomplete="new-password"></label>
                    <button class="{{ $btn }}">Save</button>
                    @if($setupClientId && $setupHasSecret)<span class="text-sm text-green-700 dark:text-green-400 ml-2">✓ Ready: companies show "Connect Microsoft 365 automatically".</span>@endif
                </form>
            </div>

            <div class="{{ $card }}">
                <h3 class="text-lg font-bold mb-1">Email (invitations, password links, support tickets)</h3>
                @if(\App\Services\Vpn\VpnMailer::usesMandrill())
                    <p class="text-sm mb-4 p-3 rounded bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200">All VPN emails go through <strong>Mandrill</strong> (System Settings → Email Configuration) from <strong>{{ config('mail.from.address') }}</strong>. The SMTP server and From address below are only used if Mandrill is turned off.</p>
                @else
                    <p class="text-sm text-gray-500 mb-4">Set PFCentral's mail to Mandrill (System Settings → Email Configuration) and every VPN email uses it, from its verified address. Until then: the SMTP server below, or PFCentral's own mail setup if it's empty.</p>
                @endif
                <form method="POST" action="{{ route('vpn.settings.email') }}" class="space-y-4 max-w-2xl">@csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <label class="{{ $label }} md:col-span-2">SMTP server<input name="smtp_host" value="{{ old('smtp_host', $mail['smtp_host']) }}" placeholder="smtp.office365.com" class="{{ $input }}" autocomplete="off"></label>
                        <label class="{{ $label }}">Port<input name="smtp_port" value="{{ old('smtp_port', $mail['smtp_port']) }}" placeholder="587" inputmode="numeric" class="{{ $input }}"></label>
                        <label class="{{ $label }}">Encryption<select name="smtp_encryption" class="{{ $input }}">
                            @foreach(['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None'] as $k => $l)<option value="{{ $k }}" @selected(old('smtp_encryption', $mail['smtp_encryption']) === $k)>{{ $l }}</option>@endforeach
                        </select></label>
                        <label class="{{ $label }}">Username<input name="smtp_username" value="{{ old('smtp_username', $mail['smtp_username']) }}" class="{{ $input }}" autocomplete="off"></label>
                        <label class="{{ $label }}">Password {{ $mail['has_password'] ? '(saved — blank keeps it)' : '' }}<input name="smtp_password" type="password" class="{{ $input }}" autocomplete="new-password"></label>
                        <label class="{{ $label }} md:col-span-2">From address<input name="mail_from_address" value="{{ old('mail_from_address', $mail['mail_from_address']) }}" placeholder="vpn@cfbtel.com" class="{{ $input }}"></label>
                        <label class="{{ $label }}">From name<input name="mail_from_name" value="{{ old('mail_from_name', $mail['mail_from_name']) }}" placeholder="CFBTEL VPN" class="{{ $input }}"></label>
                    </div>
                    <label class="{{ $label }}">Support tickets from the Windows app go to<input name="support_email" value="{{ old('support_email', $mail['support_email']) }}" placeholder="help@cfbtel.com" class="{{ $input }}"></label>
                    <label class="{{ $label }}">Invitation subject<input name="invite_subject" value="{{ old('invite_subject', $mail['invite_subject']) }}" class="{{ $input }}"></label>
                    <label class="{{ $label }}">Invitation text<textarea name="invite_body" rows="10" class="{{ $input }} font-mono">{{ old('invite_body', $mail['invite_body']) }}</textarea></label>
                    <p class="text-xs text-gray-500">Placeholders: <code>{name}</code> <code>{email}</code> <code>{company}</code> <code>{link}</code> (required) <code>{expires}</code> <code>{portal}</code>.</p>
                    <button class="{{ $btn }}">Save email settings</button>
                </form>
                <form method="POST" action="{{ route('vpn.settings.email.test') }}" class="mt-3">@csrf
                    <button class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest hover:bg-gray-50">Send test email to {{ auth()->user()->email }}</button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
