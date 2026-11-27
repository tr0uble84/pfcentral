<?php

namespace App\Http\Controllers\Vpn;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Vpn\VpnAuditLog;
use App\Models\Vpn\VpnDevice;
use App\Models\Vpn\VpnSupportReport;
use App\Services\Vpn\VpnMailer;
use App\Services\Vpn\VpnManager;
use App\Services\Vpn\VpnSettings;
use App\Services\Vpn\WireGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Called by the CFBTEL VPN app's Diagnostics tab (Windows and Mac). The app proves which device it is with the device's
 * WireGuard public key (from its own tunnel settings); nothing is shown about other devices.
 */
class AppSupportController extends Controller
{
    public const MAX_REPORT = 2_000_000;

    public function __construct(private VpnManager $vpn)
    {
    }

    /** The office firewall as PFCentral sees it, for this device. */
    public function status(Request $request)
    {
        $key = (string) $request->input('public_key', '');
        if (!WireGuard::isKey($key)) {
            return response()->json(['message' => 'Not a WireGuard key.'], 422);
        }
        $device = VpnDevice::with('site.firewall')->where('public_key', $key)->first();
        if (!$device || !$device->site) {
            return response()->json(['message' => 'CFBTEL doesn\'t know this device. It was probably removed — sign in again with "Add an office".'], 404);
        }
        $site = $device->site;
        $out = [
            'office' => $site->firewall?->name,
            'device_active' => $device->revoked_at === null && $device->vpn_user_id !== null,
            'access_allowed' => $device->revoked_at === null && $device->vpn_user_id !== null,
            'firewall_reachable' => false,
            'tunnel_found' => false,
            'tunnel_enabled' => false,
            'listen_port' => 0,
            'peer_present' => false,
            'message' => '',
        ];
        if (!$out['device_active']) {
            $out['message'] = 'This device was removed from the VPN (or its person lost access). Sign in again with "Add an office", or ask your administrator.';
            return response()->json($out);
        }
        if (!$site->isReady()) {
            $out['message'] = 'VPN for this office isn\'t set up in CFBTEL at the moment.';
            return response()->json($out);
        }
        // One look at the firewall per minute per office, however many people press "Run checks".
        $fw = Cache::remember("vpn_fw_status:{$site->id}", 60, function () use ($site) {
            try {
                $driver = $this->vpn->driver($site);
                $tunnel = collect($driver->tunnels())->firstWhere('ref', $site->tunnel);
                $keys = $tunnel ? array_column($driver->peers($site->tunnel), 'public_key') : [];
                return ['ok' => true, 'tunnel' => (bool) $tunnel, 'port' => (int) ($tunnel['listen_port'] ?? 0), 'enabled' => $tunnel ? (bool) ($tunnel['enabled'] ?? true) : false, 'keys' => $keys];
            } catch (\Throwable $e) {
                Log::info('VPN app status: firewall not reachable', ['site' => $site->id, 'error' => $e->getMessage()]);
                return ['ok' => false];
            }
        });
        if (!$fw['ok']) {
            $out['message'] = 'CFBTEL can\'t reach the office firewall right now. The office internet or firewall may be down.';
            return response()->json($out);
        }
        $out['firewall_reachable'] = true;
        $out['tunnel_found'] = $fw['tunnel'];
        $out['tunnel_enabled'] = $fw['enabled'];
        $out['listen_port'] = $fw['port'];
        $out['peer_present'] = in_array($key, $fw['keys'], true);
        if (!$fw['tunnel']) {
            $out['message'] = 'The CFBTEL VPN tunnel is missing on the office firewall.';
        } elseif (!$out['peer_present']) {
            $out['message'] = 'The office firewall doesn\'t have this device yet. It is added within 15 minutes; if it stays like this, contact support.';
        }
        return response()->json($out);
    }

    /** A support ticket: stored here and emailed to CFBTEL support with the report attached. */
    public function support(Request $request, VpnMailer $mailer)
    {
        $v = $request->validate([
            'public_keys' => ['required', 'array', 'min:1', 'max:20'],
            'public_keys.*' => ['string', 'size:44'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_email' => ['required', 'email', 'max:190'],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
            'computer' => ['nullable', 'string', 'max:64'],
            'device_id' => ['nullable', 'string', 'max:32'],
            'app' => ['nullable', 'string', 'max:40'],
            'problems' => ['nullable', 'integer', 'between:0,999'],
            'warnings' => ['nullable', 'integer', 'between:0,999'],
            'report' => ['required', 'string', 'max:' . self::MAX_REPORT],
        ]);
        $keys = array_values(array_filter($v['public_keys'], fn ($k) => WireGuard::isKey($k)));
        // Any device this app has (removed ones too: people whose access ended need help as well).
        $device = VpnDevice::with(['site.firewall', 'user'])->whereIn('public_key', $keys)->orderByRaw('revoked_at is null desc')->first();
        if (!$device || !$device->site) {
            return response()->json(['error' => 'CFBTEL doesn\'t know this computer\'s VPN.'], 403);
        }
        $limiter = 'vpn_support:' . $device->id;
        if (RateLimiter::tooManyAttempts($limiter, 5)) {
            return response()->json(['error' => 'Too many tickets from this computer in the last hour. Email ' . self::supportAddress() . ' instead.'], 429);
        }
        RateLimiter::hit($limiter, 3600);

        $company = Company::find($device->site->company_id);
        $report = VpnSupportReport::create([
            'reference' => VpnSupportReport::newReference(),
            'company_id' => $device->site->company_id,
            'vpn_device_id' => $device->id,
            'device_name' => $device->name,
            'person' => $device->user?->upn,
            'contact_name' => $v['contact_name'] ?? null,
            'contact_email' => $v['contact_email'],
            'subject' => self::oneLine($v['subject']),
            'message' => $v['message'],
            'computer' => isset($v['computer']) ? self::oneLine($v['computer']) : null,
            'device_id' => $v['device_id'] ?? null,
            'app' => isset($v['app']) ? self::oneLine($v['app']) : null,
            'problems' => $v['problems'] ?? 0,
            'warnings' => $v['warnings'] ?? 0,
            'report' => $v['report'],
            'ip' => $request->ip(),
        ]);
        VpnAuditLog::record($device->site->company_id, 'app', "support_ticket {$report->reference()} from {$v['contact_email']} ({$device->name})");

        $text = "New CFBTEL VPN support ticket {$report->reference()}\n\n"
            . 'Company: ' . ($company?->name ?? '?') . "\n"
            . 'From: ' . trim(($v['contact_name'] ?? '') . " <{$v['contact_email']}>") . "\n"
            . 'VPN person: ' . ($device->user?->upn ?? '(access removed)') . "\n"
            . "Office: {$device->site->firewall?->name}\n"
            . "Device: {$device->name} · computer {$report->computer} · ID {$report->device_id} · {$report->app}\n"
            . "Checks: {$report->problems} problem(s), {$report->warnings} warning(s)\n\n"
            . "Message:\n{$v['message']}\n\n"
            . "The full diagnostic report and VPN log are attached. Reply to this email to answer {$v['contact_email']}.\n"
            . 'In PFCentral: ' . route('vpn.support.show', [$device->site->company_id, $report->id]) . "\n";
        try {
            $mailer->send(self::supportAddress(), "[{$report->reference()}] " . $report->subject . ' — ' . ($company?->name ?? 'CFBTEL VPN'), $text,
                replyTo: $v['contact_email'], attachments: ["{$report->reference()}-diagnostics.txt" => $v['report']]);
            $report->update(['emailed_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('VPN support ticket not emailed', ['ticket' => $report->id, 'error' => $e->getMessage()]);
            $report->update(['email_error' => mb_substr($e->getMessage(), 0, 300)]);
        }
        return response()->json(['reference' => $report->reference(), 'emailed' => $report->emailed_at !== null]);
    }

    public static function supportAddress(): string
    {
        return VpnSettings::get('support_email') ?: 'help@cfbtel.com';
    }

    private static function oneLine(string $s): string
    {
        return trim(preg_replace('/[\r\n\t]+/', ' ', $s));
    }
}
