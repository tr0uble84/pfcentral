<?php

namespace App\Http\Controllers;

use App\Models\Firewall;
use App\Models\FirewallGuiLock;
use App\Models\FirewallGuiSession;
use App\Models\User;
use App\Services\Security\FirewallGui;
use App\Services\Security\GuiLock;
use App\Services\Security\SecuritySettings;
use App\Services\Upgrades\Firmware;
use Illuminate\Http\Request;

/** System → Security (global admins), and opening a firewall's web interface through PFCentral. */
class SecurityController extends Controller
{
    public function show()
    {
        $firewalls = Firewall::with('company')->orderBy('name')->get()->reject(fn ($f) => $f->isOpnSense())->values();
        $locks = FirewallGuiLock::whereIn('firewall_id', $firewalls->pluck('id'))->get()->keyBy('firewall_id');
        $without = User::query()->where(fn ($q) => $q->whereNull('two_factor_confirmed_at')->orWhereNull('two_factor_secret'))
            ->orderBy('name')->get(['id', 'name', 'email', 'ms_oid']);

        return view('system.security', [
            'settings' => [
                'require_2fa' => SecuritySettings::requireTwoFactor(),
                'gui_proxy' => SecuritySettings::get('gui_proxy') === '1',
                'gui_host' => SecuritySettings::get('gui_host', ''),
                'gui_idle' => SecuritySettings::guiIdleMinutes(),
                'lock_allowed' => implode("\n", SecuritySettings::lockAllowed()),
            ],
            'without2fa' => $without,
            'firewalls' => $firewalls->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->name, 'company' => $f->company?->name ?? '—',
                'lock' => ($l = $locks->get($f->id)) ? ['locked' => $l->locked, 'allowed' => $l->allowed ?? [], 'seen' => $l->seen ?? [], 'port' => $l->port, 'by' => $l->changed_by, 'error' => $l->error, 'at' => $l->checked_at?->toIso8601String()] : null,
            ]),
            'sessions' => FirewallGuiSession::with('firewall')->orderByDesc('id')->limit(100)->get()->map(fn ($s) => [
                'id' => $s->id, 'who' => $s->user_email, 'firewall' => $s->firewall?->name ?? '—', 'temp' => $s->temp_user,
                'started' => $s->started_at?->toIso8601String(), 'last' => $s->last_seen_at?->toIso8601String(), 'ended' => $s->ended_at?->toIso8601String(),
                'reason' => $s->end_reason, 'removed' => $s->temp_user_removed, 'requests' => $s->requests, 'actions' => $s->actions ?? [], 'ip' => $s->ip,
            ]),
        ]);
    }

    public function save(Request $request)
    {
        $v = $request->validate([
            'require_2fa' => ['nullable', 'boolean'],
            'gui_proxy' => ['nullable', 'boolean'],
            'gui_host' => ['nullable', 'regex:/^[A-Za-z0-9.-]{1,190}$/'],
            'gui_idle' => ['required', 'integer', 'between:5,240'],
            'lock_allowed' => ['nullable', 'string', 'max:2000'],
        ]);
        $host = strtolower((string) ($v['gui_host'] ?? ''));
        $console = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        $portal = strtolower((string) \App\Services\Vpn\VpnSettings::portalHost());
        if ($host !== '' && ($host === $console || $host === $request->getHost() || $host === $portal)) {
            return back()->withErrors(['gui_host' => 'Use a separate host name for firewall web interfaces, not PFCentral\'s own or the VPN page\'s.'])->withInput();
        }
        if (!empty($v['gui_proxy']) && $host === '') {
            return back()->withErrors(['gui_host' => 'Enter the host name to open firewalls on.'])->withInput();
        }
        $bad = [];
        $allowed = [];
        foreach (preg_split('/[\s,]+/', (string) ($v['lock_allowed'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $a) {
            SecuritySettings::validAddress($a) ? $allowed[] = $a : $bad[] = $a;
        }
        if ($bad) {
            return back()->withErrors(['lock_allowed' => 'Not an IP address or network: ' . implode(', ', $bad)])->withInput();
        }
        SecuritySettings::set('require_2fa', !empty($v['require_2fa']) ? '1' : '0');
        SecuritySettings::set('gui_proxy', !empty($v['gui_proxy']) ? '1' : '0');
        SecuritySettings::set('gui_host', $host);
        SecuritySettings::set('gui_idle_minutes', (string) $v['gui_idle']);
        SecuritySettings::set('lock_allowed', implode(' ', array_unique($allowed)));
        \Illuminate\Support\Facades\Log::info('Security settings saved', ['by' => $request->user()->email, 'require_2fa' => !empty($v['require_2fa']), 'gui_proxy' => !empty($v['gui_proxy'])]);
        return redirect()->route('system.security')->with('success', 'Security settings saved.');
    }

    private function pfsense(Firewall $firewall): Firewall
    {
        abort_if($firewall->isOpnSense(), 422, 'The web interface lock is for pfSense firewalls.');
        return $firewall;
    }

    public function lockStatus(Request $request, Firewall $firewall)
    {
        $lock = new GuiLock($this->pfsense($firewall));
        try {
            $s = $lock->status();
            $peers = $lock->peers();
            FirewallGuiLock::where('firewall_id', $firewall->id)->update(['seen' => $peers]);
            return response()->json(['ok' => true, 'locked' => (bool) $s['locked'], 'allowed' => $s['allowed'] ?? [], 'port' => $s['port'] ?? null, 'seen' => $peers, 'pending' => (bool) ($s['pending'] ?? false)]);
        } catch (\Throwable $e) {
            FirewallGuiLock::updateOrCreate(['firewall_id' => $firewall->id], ['error' => mb_substr($e->getMessage(), 0, 500), 'checked_at' => now()]);
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    public function lock(Request $request, Firewall $firewall)
    {
        try {
            $s = (new GuiLock($this->pfsense($firewall)))->lock(SecuritySettings::lockAllowed(), $request->user()->email);
            return response()->json(['ok' => true, 'message' => "{$firewall->name}: the web interface on its internet side now only answers PFCentral's allowed addresses (port {$s['port']}). LAN access is unchanged.", 'locked' => true, 'allowed' => $s['allowed'] ?? []]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function unlock(Request $request, Firewall $firewall)
    {
        try {
            (new GuiLock($this->pfsense($firewall)))->unlock($request->user()->email);
            return response()->json(['ok' => true, 'message' => "{$firewall->name}: lock removed.", 'locked' => false]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    public function endSession(Request $request, FirewallGuiSession $session)
    {
        $session->loadMissing('firewall');
        FirewallGui::end($session, 'ended by ' . $request->user()->email);
        return response()->json(['ok' => true, 'removed' => $session->fresh()->temp_user_removed]);
    }

    /** "Open web interface" on a firewall's pages. */
    public function openGui(Request $request, Firewall $firewall)
    {
        abort_unless(Firmware::canUpgrade($request->user(), $firewall), 403);
        try {
            return redirect()->away(FirewallGui::open($request->user(), $firewall, (string) $request->ip()));
        } catch (\Throwable $e) {
            return back()->with('error', 'Couldn\'t open the web interface: ' . $e->getMessage());
        }
    }
}
