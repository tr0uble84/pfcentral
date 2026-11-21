<?php

namespace App\Http\Controllers;

use App\Models\Firewall;
use App\Services\PfBlockerNgService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Log;

/**
 * Firewall → pfBlockerNG: the same tabs as the pfBlockerNG pages in pfSense
 * (General, IP, DNSBL, Feeds, Update, Reports, Logs, Quick setup).
 */
class FirewallPfBlockerNgController extends Controller implements HasMiddleware
{
    public const TABS = ['overview', 'general', 'ip', 'dnsbl', 'feeds', 'update', 'reports', 'logs', 'setup'];

    public const LOGS = [
        'pfblockerng' => 'pfblockerng.log (updates)',
        'error' => 'error.log',
        'extras' => 'extras.log (MaxMind, ASN)',
        'ip_block' => 'ip_block.log',
        'ip_permit' => 'ip_permit.log',
        'ip_match' => 'ip_match.log',
        'dnsbl' => 'dnsbl.log',
        'dns_reply' => 'dns_reply.log',
        'unified' => 'unified.log',
        'py_error' => 'py_error.log',
        'dnsbl_parse_err' => 'dnsbl_parsed_error.log',
    ];

    public const TYPES = ['ipv4' => 'IPv4', 'ipv6' => 'IPv6', 'dnsbl' => 'DNSBL'];

    public static function middleware(): array
    {
        return [new Middleware('deny.readonly', except: ['index', 'progress', 'groupForm'])];
    }

    protected function service(Firewall $firewall): PfBlockerNgService
    {
        return new PfBlockerNgService($firewall);
    }

    public function index(Request $request, Firewall $firewall)
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';
        $service = $this->service($firewall);
        $status = null;
        $extra = [];
        $error = null;

        if ($service->supported()) {
            try {
                $status = $service->status();
                if ($status['installed'] ?? false) {
                    if ($tab === 'reports') {
                        $extra['reports'] = $service->call('reports', ['lines' => (int) $request->query('lines', 500)]);
                    } elseif ($tab === 'logs') {
                        $log = array_key_exists($request->query('log'), self::LOGS) ? $request->query('log') : 'pfblockerng';
                        $extra['log'] = $service->call('log', ['name' => $log, 'lines' => (int) $request->query('lines', 200)]);
                    } elseif ($tab === 'feeds') {
                        $extra['catalog'] = $service->call('catalog')['catalog'] ?? [];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('pfBlockerNG page failed', ['firewall' => $firewall->id, 'error' => $e->getMessage()]);
                $error = $e->getMessage();
            }
        }

        return view('firewall.pfblockerng.index', [
            'firewall' => $firewall,
            'tab' => $tab,
            'supported' => $service->supported(),
            'status' => $status,
            'extra' => $extra,
            'error' => $error,
            'logs' => self::LOGS,
            'types' => self::TYPES,
        ]);
    }

    public function progress(Firewall $firewall): JsonResponse
    {
        try {
            return response()->json($this->service($firewall)->progress());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    public function install(Firewall $firewall): RedirectResponse
    {
        try {
            $this->service($firewall)->install();

            return $this->back($firewall, 'setup', 'pfBlockerNG is being installed. This can take a few minutes; refresh this page to check.');
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28')) {
                return $this->back($firewall, 'setup', 'The install is still running on the firewall. Give it a few minutes, then refresh this page.');
            }

            return $this->fail($firewall, 'overview', 'Could not install pfBlockerNG: ' . $e->getMessage());
        }
    }

    public function saveGeneral(Request $request, Firewall $firewall): RedirectResponse
    {
        $data = $request->validate([
            'pfb_interval' => 'required|string|max:10',
            'pfb_min' => 'required|string|max:3',
            'pfb_hour' => 'required|string|max:3',
            'pfb_dailystart' => 'required|string|max:3',
            'skipfeed' => 'required|string|max:3',
        ]);
        $data['enable_cb'] = $request->boolean('enable_cb') ? 'on' : '';
        $data['pfb_keep'] = $request->boolean('pfb_keep') ? 'on' : '';

        return $this->save($firewall, 'general', 'save_general', $data, $data['enable_cb']
            ? 'General settings saved. pfBlockerNG is applying them in the background.'
            : 'General settings saved. pfBlockerNG is turned off and its rules are being removed in the background.');
    }

    public function saveIp(Request $request, Firewall $firewall): RedirectResponse
    {
        $data = $request->validate([
            'ip_placeholder' => 'required|ip',
            'maxmind_locale' => 'required|string|max:8',
            'maxmind_account' => 'nullable|string|max:64',
            'maxmind_key' => 'nullable|string|max:128',
            'asn_reporting' => 'required|string|max:12',
            'asn_token' => 'nullable|string|max:128',
            'inbound_interface' => 'array',
            'inbound_interface.*' => 'string|max:32',
            'inbound_deny_action' => 'required|in:block,reject',
            'outbound_interface' => 'array',
            'outbound_interface.*' => 'string|max:32',
            'outbound_deny_action' => 'required|in:block,reject',
            'pass_order' => 'required|string|max:10',
            'autorule_suffix' => 'required|string|max:10',
            'v4suppression' => 'nullable|string|max:100000',
        ]);
        foreach (['enable_dup', 'enable_agg', 'suppression', 'enable_log', 'database_cc', 'enable_float', 'killstates', 'clear_maxmind_key', 'clear_asn_token', 'reload'] as $flag) {
            $data[$flag] = $request->boolean($flag) ? 'on' : '';
        }
        $data['inbound_interface'] = $data['inbound_interface'] ?? [];
        $data['outbound_interface'] = $data['outbound_interface'] ?? [];

        return $this->save($firewall, 'ip', 'save_ip', $data, 'IP settings saved.');
    }

    public function saveDnsbl(Request $request, Firewall $firewall): RedirectResponse
    {
        $data = $request->validate([
            'dnsbl_mode' => 'required|in:dnsbl_unbound,dnsbl_python',
            'pfb_dnsvip4' => 'nullable|string|max:64',
            'pfb_dnsport' => 'required|integer|between:1,65535',
            'pfb_dnsport_ssl' => 'required|integer|between:1,65535',
            'dnsbl_allow_int' => 'array',
            'dnsbl_allow_int.*' => 'string|max:32',
            'global_log' => 'nullable|in:enabled,disabled,disabled_log',
            'suppression' => 'nullable|string|max:200000',
            'tldblacklist' => 'nullable|string|max:200000',
            'tldwhitelist' => 'nullable|string|max:200000',
        ]);
        foreach (['pfb_dnsbl', 'pfb_dnsbl_rule', 'pfb_tld', 'pfb_py_block', 'pfb_hsts', 'pfb_idn', 'pfb_regex', 'pfb_cname', 'pfb_noaaaa', 'reload'] as $flag) {
            $data[$flag] = $request->boolean($flag) ? 'on' : '';
        }
        $data['dnsbl_allow_int'] = $data['dnsbl_allow_int'] ?? [];

        return $this->save($firewall, 'dnsbl', 'save_dnsbl', $data, 'DNSBL settings saved.');
    }

    public function groupForm(Request $request, Firewall $firewall, string $type, ?int $index = null)
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);
        try {
            $status = $this->service($firewall)->status();
        } catch (\Throwable $e) {
            return $this->fail($firewall, 'feeds', $e->getMessage());
        }
        if (!($status['installed'] ?? false)) {
            return $this->fail($firewall, 'overview', 'pfBlockerNG is not installed on this firewall.');
        }

        $group = null;
        if ($index !== null) {
            $group = collect($status['groups'][$type] ?? [])->firstWhere('index', $index);
            if (!$group) {
                return $this->fail($firewall, 'feeds', 'That group no longer exists.');
            }
        }

        return view('firewall.pfblockerng.group', [
            'firewall' => $firewall,
            'type' => $type,
            'typeLabel' => self::TYPES[$type],
            'group' => $group,
            'status' => $status,
        ]);
    }

    public function groupSave(Request $request, Firewall $firewall, string $type, ?int $index = null): RedirectResponse
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);
        $data = $request->validate([
            'aliasname' => ['required', 'string', 'max:' . ($type === 'dnsbl' ? 64 : 24), 'regex:/^\w+$/'],
            'description' => 'nullable|string|max:250',
            'action' => 'required|string|max:20',
            'cron' => 'required|string|max:10',
            'dow' => 'nullable|string|max:1',
            'aliaslog' => 'nullable|in:enabled,disabled',
            'logging' => 'nullable|in:enabled,disabled,disabled_log',
            'rows' => 'array|max:200',
            'rows.*.state' => 'nullable|string|max:10',
            'rows.*.format' => 'nullable|string|max:10',
            'rows.*.url' => 'nullable|string|max:1000',
            'rows.*.header' => 'nullable|string|max:64',
            'custom' => 'nullable|string|max:200000',
        ], [
            'aliasname.regex' => 'The name can only use letters, numbers and underscores (no spaces).',
        ]);
        $data['type'] = $type;
        $data['index'] = $index;
        $data['rows'] = array_values($data['rows'] ?? []);
        $data['update'] = $request->boolean('update') ? 'on' : '';

        try {
            $result = $this->service($firewall)->call('group_save', $data);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $this->back($firewall, 'feeds', "Group {$result['aliasname']} saved."
            . (!empty($result['update']) ? ' A Force Update is running to download it.' : ' Run Update to download the lists.'));
    }

    public function groupDelete(Request $request, Firewall $firewall, string $type, int $index): RedirectResponse
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);
        $data = $request->validate(['aliasname' => 'required|string|max:64']);

        return $this->save($firewall, 'feeds', 'group_delete', [
            'type' => $type, 'index' => $index, 'aliasname' => $data['aliasname'], 'reload' => 'on',
        ], "Group {$data['aliasname']} removed. A reload is running to take its rules and lists away.");
    }

    public function catalogAdd(Request $request, Firewall $firewall): RedirectResponse
    {
        $data = $request->validate([
            'type' => 'required|in:ipv4,ipv6,dnsbl',
            'key' => 'required|string|max:64',
            'action' => 'required|string|max:20',
        ]);
        $data['update'] = $request->boolean('update') ? 'on' : '';

        return $this->save($firewall, 'feeds', 'catalog_add', $data, "Feed group {$data['key']} added."
            . ($data['update'] ? ' A Force Update is running to download it.' : ''));
    }

    public function run(Request $request, Firewall $firewall): RedirectResponse
    {
        $data = $request->validate([
            'task' => 'required|in:update,cron,reload',
            'reload' => 'nullable|in:All,IP,DNSBL',
        ]);

        return $this->save($firewall, 'update', 'run', $data, 'Started. The log below shows the progress.');
    }

    public function whitelist(Request $request, Firewall $firewall): RedirectResponse
    {
        $data = $request->validate(['domain' => 'required|string|max:253']);
        $data['subdomains'] = $request->boolean('subdomains') ? 'on' : '';
        $data['reload'] = 'on';

        return $this->save($firewall, 'reports', 'whitelist_domain', $data, "{$data['domain']} added to the DNSBL whitelist. A DNSBL reload is running.");
    }

    public function quickSetup(Request $request, Firewall $firewall): RedirectResponse
    {
        $data = $request->validate([
            'inbound_interface' => 'required|array|min:1',
            'inbound_interface.*' => 'string|max:32',
            'outbound_interface' => 'required|array|min:1',
            'outbound_interface.*' => 'string|max:32',
            'vip_address' => 'required|ipv4',
            'pfb_dnsport' => 'required|integer|between:1,65535',
            'pfb_dnsport_ssl' => 'required|integer|between:1,65535',
            'confirm' => 'accepted',
        ], [
            'confirm.accepted' => 'Tick the box to confirm that Quick setup replaces the current pfBlockerNG settings.',
        ]);
        $data['dnsbl_whitelist'] = $request->boolean('dnsbl_whitelist') ? 'on' : '';

        try {
            $result = $this->service($firewall)->call('quick_setup', $data);
        } catch (\Throwable $e) {
            return $this->fail($firewall, 'setup', $e->getMessage());
        }

        return $this->back($firewall, 'update', 'pfBlockerNG is set up'
            . (!empty($result['created_vip']) ? " (DNSBL virtual IP {$result['created_vip']} added on Localhost)" : '')
            . '. The first download is running now.');
    }

    /* ---------------------------------------------------------------- helpers */

    protected function save(Firewall $firewall, string $tab, string $action, array $data, string $message): RedirectResponse
    {
        try {
            $result = $this->service($firewall)->call($action, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        foreach (['note'] as $key) {
            if (!empty($result[$key])) {
                $message .= ' ' . $result[$key];
            }
        }
        if (!empty($result['reload']['started']) && !str_contains($message, 'reload')) {
            $message .= ' ' . $result['reload']['started'] . ' is running.';
        }

        return $this->back($firewall, $tab, $message);
    }

    protected function back(Firewall $firewall, string $tab, string $message): RedirectResponse
    {
        return redirect()->route('firewall.pfblockerng.index', ['firewall' => $firewall, 'tab' => $tab])->with('success', $message);
    }

    protected function fail(Firewall $firewall, string $tab, string $message): RedirectResponse
    {
        return redirect()->route('firewall.pfblockerng.index', ['firewall' => $firewall, 'tab' => $tab])->with('error', $message);
    }
}
