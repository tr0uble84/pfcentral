<?php
/*
 * PFCentral pfBlockerNG bridge.
 *
 * PFCentral copies this file to the firewall (/root/.pfcentral/) through the
 * pfSense REST API command prompt and runs it with the PHP CLI that ships with pfSense:
 *
 *   /usr/local/bin/php pfbng-<hash>.php <action> [<base64 JSON arguments> | @/tmp/acx_<name>]
 *
 * It reads and writes the same config.xml sections the pfBlockerNG pages use, so
 * everything stays visible and editable in the pfSense web interface too. The answer
 * is printed as one line: "ACXPFB:" followed by base64 encoded JSON.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

const ACX_MARK = 'ACXPFB:';
const ACX_PFB_INC = '/usr/local/pkg/pfblockerng/pfblockerng.inc';

function acx_out(array $data): void
{
    $json = json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    echo "\n" . ACX_MARK . base64_encode((string) $json) . "\n";
    exit(0);
}

function acx_fail(string $message, array $extra = []): void
{
    acx_out(['ok' => false, 'error' => $message] + $extra);
}

$action = $argv[1] ?? 'status';
$raw = $argv[2] ?? '';
if ($raw !== '' && $raw[0] === '@') {
    $path = substr($raw, 1);
    if (!preg_match('#^/tmp/acx_[A-Za-z0-9_]+$#', $path)) {
        acx_fail('Invalid argument file.');
    }
    $raw = trim((string) @file_get_contents($path));
    @unlink($path);
}
$args = $raw === '' ? [] : json_decode((string) base64_decode($raw), true);
if (!is_array($args)) {
    $args = [];
}

require_once('globals.inc');
require_once('config.inc');
require_once('functions.inc');
require_once('util.inc');
require_once('pkg-utils.inc');
require_once('services.inc');
require_once('interfaces.inc');

if (!function_exists('config_get_path')) {
    acx_fail('This pfSense version is too old. pfSense 2.7 or newer is needed.');
}

$acx_installed = file_exists(ACX_PFB_INC);
if ($acx_installed) {
    require_once(ACX_PFB_INC);
    pfb_global();
}

global $pfb;

/* ---------------------------------------------------------------- helpers */

const ACX_LISTS = [
    'ipv4' => 'pfblockernglistsv4',
    'ipv6' => 'pfblockernglistsv6',
    'dnsbl' => 'pfblockerngdnsbl',
];

const ACX_LOGS = [
    'pfblockerng' => 'pfblockerng.log',
    'error' => 'error.log',
    'extras' => 'extras.log',
    'ip_block' => 'ip_block.log',
    'ip_permit' => 'ip_permit.log',
    'ip_match' => 'ip_match.log',
    'dnsbl' => 'dnsbl.log',
    'dns_reply' => 'dns_reply.log',
    'unified' => 'unified.log',
    'py_error' => 'py_error.log',
    'dnsbl_parse_err' => 'dnsbl_parsed_error.log',
];

function acx_require_installed(): void
{
    global $acx_installed;
    if (!$acx_installed) {
        acx_fail('pfBlockerNG is not installed on this firewall.');
    }
}

function acx_logdir(): string
{
    global $pfb;
    return $pfb['logdir'] ?? '/var/log/pfblockerng';
}

function acx_package(): array
{
    foreach (config_get_path('installedpackages/package', []) as $pkg) {
        if (is_array($pkg) && stripos($pkg['name'] ?? '', 'pfblockerng') !== false) {
            return ['name' => $pkg['name'], 'version' => $pkg['version'] ?? ''];
        }
    }
    return [];
}

function acx_running(): bool
{
    $ps = [];
    exec('/bin/ps -wwx', $ps);
    return (bool) preg_grep('/pfblockerng[.]php\s+?(cron|update|updateip|updatednsbl)/', $ps);
}

function acx_tail(string $file, int $lines): array
{
    if (!is_file($file)) {
        return [];
    }
    $lines = max(1, min($lines, 2000));
    $out = [];
    exec('/usr/bin/tail -n ' . $lines . ' ' . escapeshellarg($file), $out);
    return $out;
}

function acx_line_counts(array $files): array
{
    $counts = [];
    foreach (array_chunk(array_values(array_filter($files, 'is_file')), 200) as $chunk) {
        $out = [];
        exec('/usr/bin/wc -l ' . implode(' ', array_map('escapeshellarg', $chunk)) . ' 2>/dev/null', $out);
        foreach ($out as $line) {
            if (preg_match('/^\s*(\d+)\s+(.+)$/', $line, $m) && $m[2] !== 'total') {
                $counts[$m[2]] = (int) $m[1];
            }
        }
    }
    return $counts;
}

function acx_on($value): string
{
    return ($value === true || $value === 'on' || $value === '1' || $value === 1) ? 'on' : '';
}

function acx_pick($value, array $allowed, $default)
{
    return array_key_exists((string) $value, $allowed) ? (string) $value : $default;
}

function acx_b64_text(?string $value): string
{
    return $value ? (string) base64_decode($value) : '';
}

function acx_text($value, int $max = 200000): string
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $text = substr($text, 0, $max);
    // pfBlockerNG splits these lists on Windows line endings.
    return str_replace("\n", "\r\n", $text);
}

function acx_interfaces(): array
{
    if (function_exists('pfb_build_if_list')) {
        return pfb_build_if_list(true, false);
    }
    return get_configured_interface_with_descr();
}

function acx_vips(): ?array
{
    if (!function_exists('pfb_get_vip_options')) {
        return null; // Older pfBlockerNG: the DNSBL VIP is a plain IP address.
    }
    $options = [];
    foreach (pfb_get_vip_options(AF_INET) as $id => $label) {
        if ($id !== 'none') {
            $options[$id] = $label;
        }
    }
    return $options;
}

function acx_options(): array
{
    return [
        'interval' => ['1' => 'Every hour', '2' => 'Every 2 hours', '3' => 'Every 3 hours', '4' => 'Every 4 hours',
            '6' => 'Every 6 hours', '8' => 'Every 8 hours', '12' => 'Every 12 hours', '24' => 'Once a day', 'Disabled' => 'Disabled'],
        'min' => ['0' => '00', '15' => '15', '30' => '30', '45' => '45'],
        'hour' => array_combine(range(0, 23), range(0, 23)),
        'skipfeed' => ['0' => 'No limit', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'],
        'deny_action' => ['block' => 'Block', 'reject' => 'Reject'],
        'pass_order' => [
            'order_0' => 'pfB Pass/Match/Block/Reject, then all other rules (default)',
            'order_1' => 'pfSense Pass/Match, pfB Pass/Match, pfB Block/Reject, pfSense Block/Reject',
            'order_2' => 'pfB Pass/Match, pfSense Pass/Match, pfB Block/Reject, pfSense Block/Reject',
            'order_3' => 'pfB Pass/Match, pfB Block/Reject, pfSense Pass/Match, pfSense Block/Reject',
            'order_4' => 'pfB Pass/Match, pfB Block/Reject, pfSense Block/Reject, pfSense Pass/Match',
        ],
        'autorule_suffix' => ['autorule' => 'auto rule', 'standard' => 'No suffix', 'ar' => 'AR'],
        'asn_reporting' => ['disabled' => 'Disabled', 'week' => 'Cache for 1 week', '24hour' => 'Cache for 24 hours',
            '12hour' => 'Cache for 12 hours', '4hour' => 'Cache for 4 hours', '1hour' => 'Cache for 1 hour'],
        'maxmind_locale' => ['en' => 'English', 'fr' => 'French', 'pt-BR' => 'Brazilian Portuguese', 'de' => 'German',
            'ja' => 'Japanese', 'zh-CN' => 'Simplified Chinese', 'es' => 'Spanish'],
        'dnsbl_mode' => ['dnsbl_unbound' => 'Unbound mode', 'dnsbl_python' => 'Unbound Python mode'],
        'ip_action' => ['Disabled' => 'Disabled', 'Deny_Inbound' => 'Deny Inbound', 'Deny_Outbound' => 'Deny Outbound',
            'Deny_Both' => 'Deny Both', 'Permit_Inbound' => 'Permit Inbound', 'Permit_Outbound' => 'Permit Outbound',
            'Permit_Both' => 'Permit Both', 'Match_Inbound' => 'Match Inbound', 'Match_Outbound' => 'Match Outbound',
            'Match_Both' => 'Match Both', 'Alias_Deny' => 'Alias Deny', 'Alias_Permit' => 'Alias Permit',
            'Alias_Match' => 'Alias Match', 'Alias_Native' => 'Alias Native'],
        'dnsbl_action' => ['Disabled' => 'Disabled', 'unbound' => 'Unbound (block)'],
        'cron' => ['Never' => 'Never', '01hour' => 'Every hour', '02hours' => 'Every 2 hours', '03hours' => 'Every 3 hours',
            '04hours' => 'Every 4 hours', '06hours' => 'Every 6 hours', '08hours' => 'Every 8 hours',
            '12hours' => 'Every 12 hours', 'EveryDay' => 'Once a day', 'Weekly' => 'Weekly'],
        'dow' => ['1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday'],
        'state' => ['Enabled' => 'On', 'Disabled' => 'Off', 'Hold' => 'Hold', 'Flex' => 'Flex'],
        'ip_format' => ['auto' => 'Auto', 'geoip' => 'GeoIP', 'regex' => 'Regex', 'whois' => 'Whois', 'asn' => 'ASN', 'rsync' => 'RSync'],
        'dnsbl_format' => ['auto' => 'Auto', 'rsync' => 'RSync'],
        'aliaslog' => ['enabled' => 'Enabled', 'disabled' => 'Disabled'],
        'dnsbl_logging' => ['enabled' => 'DNSBL web server / VIP', 'disabled' => 'Null blocking (no logging)', 'disabled_log' => 'Null blocking (logging, Python mode)'],
    ];
}

function acx_general(): array
{
    $c = config_get_path('installedpackages/pfblockerng/config/0', []);
    return [
        'configured' => !empty($c),
        'enable_cb' => acx_on($c['enable_cb'] ?? ''),
        'pfb_keep' => isset($c['pfb_keep']) ? acx_on($c['pfb_keep']) : 'on',
        'pfb_interval' => (string) ($c['pfb_interval'] ?? '1') ?: '1',
        'pfb_min' => (string) ($c['pfb_min'] ?? '0') ?: '0',
        'pfb_hour' => (string) ($c['pfb_hour'] ?? '0') ?: '0',
        'pfb_dailystart' => (string) ($c['pfb_dailystart'] ?? '0') ?: '0',
        'skipfeed' => (string) ($c['skipfeed'] ?? '0') ?: '0',
    ];
}

function acx_ip(): array
{
    $c = config_get_path('installedpackages/pfblockerngipsettings/config/0', []);
    return [
        'enable_dup' => acx_on($c['enable_dup'] ?? ''),
        'enable_agg' => acx_on($c['enable_agg'] ?? ''),
        'suppression' => isset($c['suppression']) ? acx_on($c['suppression']) : 'on',
        'enable_log' => acx_on($c['enable_log'] ?? ''),
        'ip_placeholder' => $c['ip_placeholder'] ?? '' ?: '127.1.7.7',
        'maxmind_locale' => $c['maxmind_locale'] ?? '' ?: 'en',
        'database_cc' => acx_on($c['database_cc'] ?? ''),
        'maxmind_account' => $c['maxmind_account'] ?? '',
        'maxmind_key_set' => !empty($c['maxmind_key']),
        'asn_reporting' => $c['asn_reporting'] ?? '' ?: 'disabled',
        'asn_token_set' => !empty($c['asn_token']),
        'inbound_interface' => array_values(array_filter(explode(',', (string) ($c['inbound_interface'] ?? '')))),
        'inbound_deny_action' => $c['inbound_deny_action'] ?? '' ?: 'block',
        'outbound_interface' => array_values(array_filter(explode(',', (string) ($c['outbound_interface'] ?? '')))),
        'outbound_deny_action' => $c['outbound_deny_action'] ?? '' ?: 'reject',
        'enable_float' => acx_on($c['enable_float'] ?? ''),
        'pass_order' => $c['pass_order'] ?? '' ?: 'order_0',
        'autorule_suffix' => $c['autorule_suffix'] ?? '' ?: 'autorule',
        'killstates' => acx_on($c['killstates'] ?? ''),
        'v4suppression' => acx_b64_text($c['v4suppression'] ?? ''),
    ];
}

function acx_dnsbl(): array
{
    $c = config_get_path('installedpackages/pfblockerngdnsblsettings/config/0', []);
    $mode = $c['dnsbl_mode'] ?? '';
    if (isset($c['pfb_python'])) {
        $mode = $c['pfb_python'] === 'on' ? 'dnsbl_python' : 'dnsbl_unbound';
    }
    return [
        'pfb_dnsbl' => acx_on($c['pfb_dnsbl'] ?? ''),
        'dnsbl_mode' => $mode ?: 'dnsbl_unbound',
        'pfb_dnsvip4' => $c['pfb_dnsvip4'] ?? '',
        'pfb_dnsport' => $c['pfb_dnsport'] ?? '' ?: '8081',
        'pfb_dnsport_ssl' => $c['pfb_dnsport_ssl'] ?? '' ?: '8443',
        'dnsbl_interface' => $c['dnsbl_interface'] ?? '' ?: 'lo0',
        'pfb_dnsbl_rule' => acx_on($c['pfb_dnsbl_rule'] ?? ''),
        'dnsbl_allow_int' => array_values(array_filter(explode(',', (string) ($c['dnsbl_allow_int'] ?? '')))),
        'global_log' => $c['global_log'] ?? '',
        'pfb_tld' => acx_on($c['pfb_tld'] ?? ''),
        'pfb_py_block' => isset($c['pfb_py_block']) ? acx_on($c['pfb_py_block']) : 'on',
        'pfb_hsts' => isset($c['pfb_hsts']) ? acx_on($c['pfb_hsts']) : 'on',
        'pfb_idn' => acx_on($c['pfb_idn'] ?? ''),
        'pfb_regex' => acx_on($c['pfb_regex'] ?? ''),
        'pfb_cname' => acx_on($c['pfb_cname'] ?? ''),
        'pfb_noaaaa' => acx_on($c['pfb_noaaaa'] ?? ''),
        'suppression' => acx_b64_text($c['suppression'] ?? ''),
        'tldblacklist' => acx_b64_text($c['tldblacklist'] ?? ''),
        'tldwhitelist' => acx_b64_text($c['tldwhitelist'] ?? ''),
    ];
}

function acx_groups(): array
{
    global $pfb;
    $aliasdir = $pfb['aliasdir'] ?? '/var/db/aliastables';
    $dnsdir = $pfb['dnsdir'] ?? '/var/db/pfblockerng/dnsbl';
    $counts = acx_line_counts(array_merge(glob("{$aliasdir}/pfB_*.txt") ?: [], glob("{$dnsdir}/*.txt") ?: []));

    $groups = [];
    foreach (ACX_LISTS as $type => $section) {
        $groups[$type] = [];
        foreach (config_get_path("installedpackages/{$section}/config", []) as $index => $g) {
            if (!is_array($g)) {
                continue;
            }
            $rows = [];
            foreach ((array) ($g['row'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = [
                        'format' => $row['format'] ?? 'auto',
                        'state' => $row['state'] ?? 'Enabled',
                        'url' => $row['url'] ?? '',
                        'header' => $row['header'] ?? '',
                    ];
                }
            }
            $name = (string) ($g['aliasname'] ?? '');
            $entries = 0;
            if ($type === 'dnsbl') {
                foreach ($rows as $row) {
                    $entries += $counts["{$dnsdir}/{$row['header']}.txt"] ?? 0;
                }
            } else {
                foreach (["pfB_{$name}", "pfB_{$name}_v4", "pfB_{$name}_v6"] as $file) {
                    $entries += $counts["{$aliasdir}/{$file}.txt"] ?? 0;
                }
            }
            $groups[$type][] = [
                'index' => $index,
                'aliasname' => $name,
                'description' => (string) ($g['description'] ?? ''),
                'action' => (string) ($g['action'] ?? 'Disabled'),
                'cron' => (string) ($g['cron'] ?? 'Never'),
                'dow' => (string) ($g['dow'] ?? '1'),
                'aliaslog' => (string) ($g['aliaslog'] ?? 'enabled'),
                'logging' => (string) ($g['logging'] ?? 'enabled'),
                'rows' => $rows,
                'custom' => substr(acx_b64_text($g['custom'] ?? ''), 0, 50000),
                'entries' => $entries,
            ];
        }
    }
    return $groups;
}

function acx_feeds_catalog(): array
{
    global $pfb;
    $file = $pfb['feeds'] ?? '/usr/local/www/pfblockerng/pfblockerng_feeds.json';
    $raw = json_decode((string) @file_get_contents($file), true);
    return is_array($raw) ? $raw : [];
}

function acx_type($value): string
{
    if (!isset(ACX_LISTS[$value])) {
        acx_fail('Unknown feed type.');
    }
    return $value;
}

function acx_counts(): array
{
    global $pfb;
    $aliasdir = $pfb['aliasdir'] ?? '/var/db/aliastables';
    $files = glob("{$aliasdir}/pfB_*.txt") ?: [];
    $counts = acx_line_counts(array_merge($files, ['/var/unbound/pfb_py_data.txt', '/var/unbound/pfb_dnsbl.conf']));
    $ip = 0;
    foreach ($files as $f) {
        $ip += $counts[$f] ?? 0;
    }
    $dnsbl = max($counts['/var/unbound/pfb_py_data.txt'] ?? 0, $counts['/var/unbound/pfb_dnsbl.conf'] ?? 0);

    $logs = [];
    foreach (['ip_block', 'ip_permit', 'ip_match', 'dnsbl'] as $key) {
        $logs[$key] = acx_line_counts([acx_logdir() . '/' . ACX_LOGS[$key]])[acx_logdir() . '/' . ACX_LOGS[$key]] ?? 0;
    }

    return ['ip_entries' => $ip, 'ip_aliases' => count($files), 'dnsbl_domains' => $dnsbl, 'events' => $logs];
}

function acx_run_task(string $task, string $reload = 'All'): array
{
    global $pfb;
    if (!in_array($task, ['update', 'cron', 'reload'], true)) {
        acx_fail('Unknown task.');
    }
    if (acx_running()) {
        acx_fail('A pfBlockerNG update is already running. Wait for it to finish.');
    }
    if (!file_exists($pfb['log'])) {
        touch($pfb['log']);
    }

    $type = $task;
    $label = ['update' => 'Force Update', 'cron' => 'Force Cron', 'reload' => 'Force Reload'][$task];
    if ($task === 'reload') {
        $reload = in_array($reload, ['All', 'IP', 'DNSBL'], true) ? $reload : 'All';
        $label .= " - {$reload}";
        if ($reload === 'IP') {
            $type = 'updateip';
        } elseif ($reload === 'DNSBL') {
            $type = 'updatednsbl';
            rmdir_recursive($pfb['dnsdir']);
        } else {
            $type = 'update';
            rmdir_recursive($pfb['dnsdir']);
        }
    }

    // Same steps as the pfBlockerNG Update page.
    install_cron_job('pfblockerng.php cron', false);
    pfb_logger("\n [ {$label} Task - started from PFCentral ]\n", 1);
    mwexec_bg('/usr/local/bin/php /usr/local/www/pfblockerng/pfblockerng.php ' . escapeshellarg($type) . " >> {$pfb['log']} 2>&1");

    return ['started' => $label];
}

function acx_progress(): array
{
    global $pfb;
    return [
        'running' => acx_running(),
        'log' => acx_tail($pfb['log'] ?? acx_logdir() . '/pfblockerng.log', 60),
        'log_mtime' => @filemtime($pfb['log'] ?? '') ?: null,
        'now' => time(),
    ];
}

/* ----------------------------------------------------------------- actions */

switch ($action) {
    case 'status':
        $result = [
            'ok' => true,
            'installed' => $acx_installed,
            'package' => acx_package(),
            'pfsense' => trim((string) @file_get_contents('/etc/version')),
            'bridge' => basename(__FILE__),
        ];
        if ($acx_installed) {
            $result += [
                'general' => acx_general(),
                'ip' => acx_ip(),
                'dnsbl' => acx_dnsbl(),
                'groups' => acx_groups(),
                'interfaces' => acx_interfaces(),
                'vips' => acx_vips(),
                'options' => acx_options(),
                'counts' => acx_counts(),
                'running' => acx_running(),
                'last_update' => @filemtime($pfb['log']) ?: null,
                'cron' => (bool) array_filter(config_get_path('cron/item', []), function ($item) {
                    return is_array($item) && strpos($item['command'] ?? '', 'pfblockerng.php cron') !== false;
                }),
            ];
        }
        acx_out($result);
        break;

    case 'progress':
        acx_require_installed();
        acx_out(['ok' => true] + acx_progress());
        break;

    case 'save_general':
        acx_require_installed();
        $o = acx_options();
        $c = config_get_path('installedpackages/pfblockerng/config/0', []);
        $c['enable_cb'] = acx_on($args['enable_cb'] ?? '');
        $c['pfb_keep'] = acx_on($args['pfb_keep'] ?? '');
        $c['pfb_interval'] = acx_pick($args['pfb_interval'] ?? '1', $o['interval'], '1');
        $c['pfb_min'] = acx_pick($args['pfb_min'] ?? '0', $o['min'], '0');
        $c['pfb_hour'] = acx_pick($args['pfb_hour'] ?? '0', $o['hour'], '0');
        $c['pfb_dailystart'] = acx_pick($args['pfb_dailystart'] ?? '0', $o['hour'], '0');
        $c['skipfeed'] = acx_pick($args['skipfeed'] ?? '0', $o['skipfeed'], '0');
        unset($c['log_maxlines']);
        config_set_path('installedpackages/pfblockerng/config/0', $c);
        write_config('[pfBlockerNG] save General settings (PFCentral)');

        // The General page runs the full sync after saving; it can take a while, so run it in the background.
        if (!acx_running()) {
            mwexec_bg('/usr/local/bin/php ' . escapeshellarg(__FILE__) . " sync >> {$pfb['log']} 2>&1");
        }
        acx_out(['ok' => true, 'general' => acx_general()]);
        break;

    case 'sync':
        acx_require_installed();
        $pfb['save'] = true;
        sync_package_pfblockerng();
        exit(0);

    case 'save_ip':
        acx_require_installed();
        $o = acx_options();
        $errors = [];
        $placeholder = trim((string) ($args['ip_placeholder'] ?? '127.1.7.7'));
        if (!is_ipaddrv4($placeholder)) {
            $errors[] = 'Placeholder IP must be a valid IPv4 address.';
        } elseif (count(where_is_ipaddr_configured($placeholder, '', true, true, ''))) {
            $errors[] = 'Placeholder IP must be in a range that is not used on your network.';
        }
        foreach (['maxmind_account' => 'MaxMind account ID', 'maxmind_key' => 'MaxMind license key', 'asn_token' => 'IPinfo token'] as $key => $label) {
            if (!empty($args[$key]) && !preg_match('/^[A-Za-z0-9_\-]+$/', (string) $args[$key])) {
                $errors[] = "{$label} has characters that are not allowed.";
            }
        }
        $supp = acx_text($args['v4suppression'] ?? '');
        foreach (explode("\r\n", $supp) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $host = trim(preg_split('/(?=#)/', $line)[0]);
            $mask = strstr($host, '/');
            if (($mask !== '/32' && $mask !== '/24') || !is_subnetv4($host)) {
                $errors[] = "IPv4 suppression: {$host} must be an IPv4 address with /32 or /24.";
            }
        }
        $interfaces = acx_interfaces();
        $in = array_values(array_intersect((array) ($args['inbound_interface'] ?? []), array_keys($interfaces)));
        $out = array_values(array_intersect((array) ($args['outbound_interface'] ?? []), array_keys($interfaces)));
        if ($errors) {
            acx_fail(implode(' ', $errors));
        }

        $c = config_get_path('installedpackages/pfblockerngipsettings/config/0', []);
        $oldLocale = $c['maxmind_locale'] ?? 'en';
        foreach (['enable_dup', 'enable_agg', 'suppression', 'enable_log', 'database_cc', 'enable_float', 'killstates'] as $k) {
            $c[$k] = acx_on($args[$k] ?? '');
        }
        $c['ip_placeholder'] = $placeholder;
        $c['maxmind_locale'] = acx_pick($args['maxmind_locale'] ?? 'en', $o['maxmind_locale'], 'en');
        $c['maxmind_account'] = (string) ($args['maxmind_account'] ?? '');
        // Secrets are only replaced when a new value is typed, or cleared on request.
        if (!empty($args['maxmind_key'])) {
            $c['maxmind_key'] = (string) $args['maxmind_key'];
        } elseif (!empty($args['clear_maxmind_key'])) {
            $c['maxmind_key'] = '';
        }
        if (!empty($args['asn_token'])) {
            $c['asn_token'] = (string) $args['asn_token'];
        } elseif (!empty($args['clear_asn_token'])) {
            $c['asn_token'] = '';
        }
        $c['asn_reporting'] = acx_pick($args['asn_reporting'] ?? 'disabled', $o['asn_reporting'], 'disabled');
        $c['inbound_interface'] = implode(',', $in);
        $c['inbound_deny_action'] = acx_pick($args['inbound_deny_action'] ?? 'block', $o['deny_action'], 'block');
        $c['outbound_interface'] = implode(',', $out);
        $c['outbound_deny_action'] = acx_pick($args['outbound_deny_action'] ?? 'reject', $o['deny_action'], 'reject');
        $c['pass_order'] = acx_pick($args['pass_order'] ?? 'order_0', $o['pass_order'], 'order_0');
        $c['autorule_suffix'] = acx_pick($args['autorule_suffix'] ?? 'autorule', $o['autorule_suffix'], 'autorule');
        $c['v4suppression'] = $supp === '' ? '' : base64_encode($supp);

        config_set_path('installedpackages/pfblockerngipsettings/config/0', $c);
        write_config('[pfBlockerNG] save IP settings (PFCentral)');

        $note = '';
        if ($oldLocale !== $c['maxmind_locale'] && !preg_grep('/pfblockerng[.]php\s+?(uc|gc|ugc)/', (function () { $p = []; exec('/bin/ps -wwx', $p); return $p; })())) {
            mwexec_bg('/usr/local/bin/php /usr/local/www/pfblockerng/pfblockerng.php ugc ' . escapeshellarg($oldLocale) . ' '
                . escapeshellarg($c['maxmind_locale']) . " >> {$pfb['extraslog']} 2>&1");
            $note = 'The MaxMind language is being changed in the background.';
        }
        $reload = !empty($args['reload']) ? acx_run_task('reload', 'IP') : null;
        acx_out(['ok' => true, 'ip' => acx_ip(), 'note' => $note, 'reload' => $reload]);
        break;

    case 'save_dnsbl':
        acx_require_installed();
        $o = acx_options();
        $errors = [];
        $c = config_get_path('installedpackages/pfblockerngdnsblsettings/config/0', []);
        $enabled = acx_on($args['pfb_dnsbl'] ?? '');
        $mode = acx_pick($args['dnsbl_mode'] ?? 'dnsbl_unbound', $o['dnsbl_mode'], 'dnsbl_unbound');
        $vip4 = trim((string) ($args['pfb_dnsvip4'] ?? ''));
        $interface = $c['dnsbl_interface'] ?? '' ?: 'lo0';
        $port = (string) ($args['pfb_dnsport'] ?? '8081');
        $portSsl = (string) ($args['pfb_dnsport_ssl'] ?? '8443');
        if ($enabled === 'on') {
            if (function_exists('pfb_validate_vips')) {
                [$valid, $error] = pfb_validate_vips($interface, $vip4, $c['pfb_dnsvip6'] ?? '');
                if (!$valid) {
                    $errors[] = "DNSBL virtual IP: {$error}. Create an IP alias virtual IP on Localhost first, or use Quick setup.";
                }
            } elseif (!is_ipaddrv4($vip4)) {
                $errors[] = 'DNSBL virtual IP must be a valid IPv4 address.';
            }
            if (!is_port($port) || !is_port($portSsl)) {
                $errors[] = 'DNSBL ports must be valid port numbers.';
            }
        }
        $allowInt = array_values(array_intersect((array) ($args['dnsbl_allow_int'] ?? []), array_keys(acx_interfaces())));
        if ($errors) {
            acx_fail(implode(' ', $errors));
        }

        $note = '';
        $pyBlock = acx_on($args['pfb_py_block'] ?? '');
        $oldMode = isset($c['pfb_python']) ? ($c['pfb_python'] === 'on' ? 'dnsbl_python' : 'dnsbl_unbound') : ($c['dnsbl_mode'] ?? '');
        if ((($c['pfb_py_block'] ?? 'on') !== $pyBlock) || ($oldMode !== $mode && $pyBlock === 'on')) {
            // Same clean-up the DNSBL page does when the blocking mode changes.
            unlink_if_exists("{$pfb['dnsbl_file']}.conf");
            unlink_if_exists($pfb['unbound_py_data']);
            unlink_if_exists($pfb['unbound_py_zone']);
            unlink_if_exists($pfb['unbound_py_wh']);
            unlink_if_exists("{$pfb['dbdir']}/pfbalexawhitelist.txt");
            $note = 'The blocking mode changed, so the DNSBL database was cleared. A DNSBL reload is needed.';
        }
        unset($c['pfb_python']);

        $c['pfb_dnsbl'] = $enabled;
        $c['dnsbl_mode'] = $mode;
        $c['pfb_dnsvip4'] = $vip4;
        $c['pfb_dnsport'] = $port;
        $c['pfb_dnsport_ssl'] = $portSsl;
        $c['dnsbl_interface'] = $interface;
        $c['pfb_dnsbl_rule'] = acx_on($args['pfb_dnsbl_rule'] ?? '');
        $c['dnsbl_allow_int'] = implode(',', $allowInt);
        $c['global_log'] = acx_pick($args['global_log'] ?? '', ['' => '', 'enabled' => '', 'disabled' => '', 'disabled_log' => ''], '');
        foreach (['pfb_tld', 'pfb_hsts', 'pfb_idn', 'pfb_regex', 'pfb_cname', 'pfb_noaaaa'] as $k) {
            $c[$k] = acx_on($args[$k] ?? '');
        }
        $c['pfb_py_block'] = $pyBlock;
        foreach (['suppression', 'tldblacklist', 'tldwhitelist'] as $k) {
            $text = acx_text($args[$k] ?? '');
            $c[$k] = $text === '' ? '' : base64_encode($text);
        }
        config_set_path('installedpackages/pfblockerngdnsblsettings/config/0', $c);
        write_config('[pfBlockerNG] save DNSBL settings (PFCentral)');

        $reload = (!empty($args['reload']) || $note) ? acx_run_task('reload', 'DNSBL') : null;
        acx_out(['ok' => true, 'dnsbl' => acx_dnsbl(), 'note' => $note, 'reload' => $reload]);
        break;

    case 'whitelist_domain':
        acx_require_installed();
        $domain = strtolower(trim((string) ($args['domain'] ?? ''), " .\t\n\r"));
        if (!preg_match('/^([a-z0-9_]([a-z0-9_\-]{0,61}[a-z0-9_])?\.)+[a-z0-9\-]{2,63}$/', $domain)) {
            acx_fail('That is not a valid domain name.');
        }
        $entry = (!empty($args['subdomains']) ? '.' : '') . $domain;
        $c = config_get_path('installedpackages/pfblockerngdnsblsettings/config/0', []);
        $list = acx_b64_text($c['suppression'] ?? '');
        $lines = array_map(function ($l) { return trim(preg_split('/(?=#)/', $l)[0]); }, explode("\n", str_replace("\r", '', $list)));
        if (!in_array($entry, $lines, true)) {
            $list = rtrim($list, "\r\n") . ($list === '' ? '' : "\r\n") . $entry . ' # added from PFCentral';
            $c['suppression'] = base64_encode($list);
            config_set_path('installedpackages/pfblockerngdnsblsettings/config/0', $c);
            write_config("[pfBlockerNG] DNSBL whitelist: added {$entry} (PFCentral)");
        }
        $reload = !empty($args['reload']) ? acx_run_task('reload', 'DNSBL') : null;
        acx_out(['ok' => true, 'entry' => $entry, 'reload' => $reload]);
        break;

    case 'group_save':
        acx_require_installed();
        $type = acx_type($args['type'] ?? '');
        $o = acx_options();
        $section = ACX_LISTS[$type];
        $list = config_get_path("installedpackages/{$section}/config", []);
        $index = isset($args['index']) && $args['index'] !== '' && $args['index'] !== null ? (int) $args['index'] : null;
        if ($index !== null && !isset($list[$index])) {
            acx_fail('That group no longer exists. Reload the page.');
        }
        $errors = [];
        $name = trim((string) ($args['aliasname'] ?? ''));
        if ($name === '' || preg_match('/\W/', $name)) {
            $errors[] = 'Name is required and can only use letters, numbers and underscores.';
        } elseif ($type !== 'dnsbl' && strlen($name) > 24) {
            $errors[] = 'IP group names can be at most 24 characters.';
        }
        foreach ($list as $i => $g) {
            if ($i !== $index && is_array($g) && strcasecmp($g['aliasname'] ?? '', $name) === 0) {
                $errors[] = "There is already a group named {$name}.";
            }
        }
        $formats = $type === 'dnsbl' ? $o['dnsbl_format'] : $o['ip_format'];
        $rows = [];
        foreach ((array) ($args['rows'] ?? []) as $n => $row) {
            $state = acx_pick($row['state'] ?? 'Enabled', $o['state'], 'Enabled');
            $url = trim((string) ($row['url'] ?? ''));
            $header = trim((string) ($row['header'] ?? ''));
            if ($url === '' && $header === '') {
                continue;
            }
            $line = $n + 1;
            if ($state !== 'Disabled' && ($url === '' || $header === '')) {
                $errors[] = "Source line {$line}: both the source and the header are required.";
            }
            if ($header !== '' && preg_match('/\W/', $header)) {
                $errors[] = "Source line {$line}: the header can only use letters, numbers and underscores.";
            }
            $rows[] = ['format' => acx_pick($row['format'] ?? 'auto', $formats, 'auto'), 'state' => $state, 'url' => $url, 'header' => $header];
        }
        $custom = acx_text($args['custom'] ?? '');
        if (!$rows && $custom === '') {
            $errors[] = 'Add at least one source or some custom entries.';
        }
        if ($errors) {
            acx_fail(implode(' ', array_unique($errors)));
        }

        $g = $index !== null ? $list[$index] : [];
        $g['aliasname'] = $name;
        $g['description'] = substr(trim((string) ($args['description'] ?? '')), 0, 250);
        $g['row'] = $rows;
        $g['custom'] = $custom === '' ? '' : base64_encode($custom);
        $g['cron'] = acx_pick($args['cron'] ?? 'Never', $o['cron'], 'Never');
        $g['dow'] = acx_pick($args['dow'] ?? '1', $o['dow'], '1');
        if ($type === 'dnsbl') {
            $g['action'] = acx_pick($args['action'] ?? 'unbound', $o['dnsbl_action'], 'unbound');
            $g['logging'] = acx_pick($args['logging'] ?? 'enabled', $o['dnsbl_logging'], 'enabled');
            $g['order'] = $g['order'] ?? 'default';
        } else {
            $g['action'] = acx_pick($args['action'] ?? 'Deny_Outbound', $o['ip_action'], 'Deny_Outbound');
            $g['aliaslog'] = acx_pick($args['aliaslog'] ?? 'enabled', $o['aliaslog'], 'enabled');
        }
        if ($index === null) {
            $list[] = $g;
        } else {
            $list[$index] = $g;
        }
        config_set_path("installedpackages/{$section}/config", array_values($list));
        write_config("[pfBlockerNG] saved {$type} group {$name} (PFCentral)");

        $run = !empty($args['update']) ? acx_run_task('update') : null;
        acx_out(['ok' => true, 'aliasname' => $name, 'update' => $run]);
        break;

    case 'group_delete':
        acx_require_installed();
        $type = acx_type($args['type'] ?? '');
        $section = ACX_LISTS[$type];
        $list = config_get_path("installedpackages/{$section}/config", []);
        $index = (int) ($args['index'] ?? -1);
        if (!isset($list[$index]) || strcasecmp($list[$index]['aliasname'] ?? '', (string) ($args['aliasname'] ?? '')) !== 0) {
            acx_fail('That group no longer exists. Reload the page.');
        }
        $name = $list[$index]['aliasname'];
        unset($list[$index]);
        config_set_path("installedpackages/{$section}/config", array_values($list));
        write_config("[pfBlockerNG] removed {$type} group {$name} (PFCentral)");
        $run = !empty($args['reload']) ? acx_run_task('reload', $type === 'dnsbl' ? 'DNSBL' : 'IP') : null;
        acx_out(['ok' => true, 'aliasname' => $name, 'reload' => $run]);
        break;

    case 'catalog':
        acx_require_installed();
        $catalog = acx_feeds_catalog();
        $existing = [];
        foreach (ACX_LISTS as $type => $section) {
            foreach (config_get_path("installedpackages/{$section}/config", []) as $g) {
                if (is_array($g)) {
                    $existing[$type][strtolower($g['aliasname'] ?? '')] = true;
                }
            }
        }
        $result = [];
        foreach (array_keys(ACX_LISTS) as $type) {
            foreach ((array) ($catalog[$type] ?? []) as $key => $info) {
                $feeds = [];
                foreach ((array) ($info['feeds'] ?? []) as $feed) {
                    $feeds[] = [
                        'feed' => $feed['feed'] ?? '',
                        'website' => $feed['website'] ?? '',
                        'header' => $feed['header'] ?? '',
                        'url' => $feed['url'] ?? '',
                        'register' => isset($feed['register']),
                        'discontinued' => ($feed['status'] ?? '') === 'discontinued',
                    ];
                }
                $result[$type][] = [
                    'key' => $key,
                    'description' => $info['description'] ?? $key,
                    'info' => str_replace('-LB-', "\n", (string) ($info['info'] ?? '')),
                    'cron' => $info['cron'] ?? 'EveryDay',
                    'feeds' => $feeds,
                    'added' => isset($existing[$type][strtolower($key)]),
                ];
            }
        }
        acx_out(['ok' => true, 'catalog' => $result]);
        break;

    case 'catalog_add':
        acx_require_installed();
        $type = acx_type($args['type'] ?? '');
        $o = acx_options();
        $key = (string) ($args['key'] ?? '');
        $catalog = acx_feeds_catalog();
        $info = $catalog[$type][$key] ?? null;
        if (!is_array($info)) {
            acx_fail('That feed group is not in the pfBlockerNG catalog.');
        }
        $section = ACX_LISTS[$type];
        $list = config_get_path("installedpackages/{$section}/config", []);
        foreach ($list as $g) {
            if (is_array($g) && strcasecmp($g['aliasname'] ?? '', $key) === 0) {
                acx_fail("{$key} is already added.");
            }
        }
        $add = ['aliasname' => $key, 'description' => $info['description'] ?? $key, 'row' => []];
        foreach ((array) ($info['feeds'] ?? []) as $row) {
            // Same rule as the pfBlockerNG wizard: skip feeds that need registration or are discontinued.
            if (isset($row['register']) || (($row['status'] ?? '') === 'discontinued')) {
                continue;
            }
            $add['row'][] = ['format' => 'auto', 'state' => 'Enabled', 'url' => $row['url'], 'header' => $row['header']];
        }
        if (!$add['row']) {
            acx_fail('Every feed in that group needs a registration key. Add it by hand under Feeds instead.');
        }
        $catalogCron = $info['cron'] ?? 'EveryDay';
        $add['cron'] = acx_pick($args['cron'] ?? $catalogCron, $o['cron'], 'EveryDay');
        $add['dow'] = '1';
        if ($type === 'dnsbl') {
            $add['action'] = acx_pick($args['action'] ?? 'unbound', $o['dnsbl_action'], 'unbound');
            $add['logging'] = 'enabled';
            $add['order'] = 'default';
        } else {
            $add['action'] = acx_pick($args['action'] ?? 'Deny_Outbound', $o['ip_action'], 'Deny_Outbound');
            $add['aliaslog'] = 'enabled';
        }
        $list[] = $add;
        config_set_path("installedpackages/{$section}/config", array_values($list));
        write_config("[pfBlockerNG] added {$type} feed group {$key} (PFCentral)");
        $run = !empty($args['update']) ? acx_run_task('update') : null;
        acx_out(['ok' => true, 'aliasname' => $key, 'update' => $run]);
        break;

    case 'run':
        acx_require_installed();
        acx_out(['ok' => true] + acx_run_task((string) ($args['task'] ?? 'update'), (string) ($args['reload'] ?? 'All')));
        break;

    case 'log':
        acx_require_installed();
        $name = (string) ($args['name'] ?? 'pfblockerng');
        if (!isset(ACX_LOGS[$name])) {
            acx_fail('Unknown log.');
        }
        $file = acx_logdir() . '/' . ACX_LOGS[$name];
        acx_out([
            'ok' => true,
            'name' => $name,
            'exists' => is_file($file),
            'size' => is_file($file) ? filesize($file) : 0,
            'mtime' => @filemtime($file) ?: null,
            'lines' => acx_tail($file, (int) ($args['lines'] ?? 200)),
        ]);
        break;

    case 'reports':
        acx_require_installed();
        $limit = max(50, min((int) ($args['lines'] ?? 500), 2000));
        $ip = [];
        foreach (['ip_block' => 'block', 'ip_permit' => 'permit', 'ip_match' => 'match'] as $log => $kind) {
            foreach (acx_tail(acx_logdir() . '/' . ACX_LOGS[$log], $limit) as $line) {
                $f = str_getcsv($line, ',', '"', '');
                if (count($f) < 17) {
                    continue;
                }
                $ip[] = [
                    'kind' => $kind, 'time' => $f[0], 'interface' => $f[3], 'action' => $f[4], 'proto' => $f[7],
                    'src' => $f[8], 'dst' => $f[9], 'sport' => $f[10], 'dport' => $f[11], 'direction' => $f[12],
                    'geoip' => $f[13], 'alias' => $f[14], 'evaluated' => $f[15], 'feed' => $f[16],
                    'host' => $f[17] ?? '', 'client' => $f[18] ?? '', 'asn' => $f[19] ?? '',
                ];
            }
        }
        $dns = [];
        foreach (acx_tail(acx_logdir() . '/' . ACX_LOGS['dnsbl'], $limit) as $line) {
            $f = str_getcsv($line, ',', '"', '');
            if (count($f) < 9) {
                continue;
            }
            $dns[] = [
                'type' => $f[0], 'time' => $f[1], 'domain' => $f[2], 'client' => $f[3], 'p_type' => $f[4],
                'b_type' => $f[5], 'group' => $f[6], 'evaluated' => $f[7], 'feed' => $f[8],
            ];
        }
        $top = function (array $rows, string $field, int $n = 10): array {
            $counts = [];
            foreach ($rows as $r) {
                $v = (string) ($r[$field] ?? '');
                if ($v !== '' && $v !== 'Unknown') {
                    $counts[$v] = ($counts[$v] ?? 0) + 1;
                }
            }
            arsort($counts);
            return array_slice($counts, 0, $n, true);
        };
        $blocked = array_values(array_filter($ip, function ($r) { return $r['kind'] === 'block'; }));
        acx_out([
            'ok' => true,
            'ip' => array_reverse($ip),
            'dnsbl' => array_reverse($dns),
            'top' => [
                'ip_hosts' => $top(array_map(function ($r) { return $r + ['host_ip' => $r['direction'] === 'in' ? $r['src'] : $r['dst']]; }, $blocked), 'host_ip'),
                'ip_feeds' => $top($blocked, 'alias'),
                'ip_countries' => $top($blocked, 'geoip'),
                'dns_domains' => $top($dns, 'evaluated'),
                'dns_clients' => $top($dns, 'client'),
                'dns_groups' => $top($dns, 'group'),
            ],
        ]);
        break;

    case 'quick_setup':
        acx_require_installed();
        if (empty($args['confirm'])) {
            acx_fail('Quick setup replaces the pfBlockerNG settings. Confirm to continue.');
        }
        $interfaces = acx_interfaces();
        $in = array_values(array_intersect((array) ($args['inbound_interface'] ?? []), array_keys($interfaces)));
        $out = array_values(array_intersect((array) ($args['outbound_interface'] ?? []), array_keys($interfaces)));
        if (!$in || !$out) {
            acx_fail('Pick at least one inbound and one outbound interface.');
        }
        $port = (string) ($args['pfb_dnsport'] ?? '8081');
        $portSsl = (string) ($args['pfb_dnsport_ssl'] ?? '8443');
        if (!is_port($port) || !is_port($portSsl)) {
            acx_fail('DNSBL ports must be valid port numbers.');
        }
        $catalog = acx_feeds_catalog();
        if (empty($catalog['ipv4']['PRI1']) || empty($catalog['dnsbl']['ADs_Basic'])) {
            acx_fail('The pfBlockerNG feeds catalog is missing. Reinstall the package.');
        }

        // DNSBL web server virtual IP.
        $vipAddress = trim((string) ($args['vip_address'] ?? '10.10.10.1'));
        if (!is_ipaddrv4($vipAddress)) {
            acx_fail('The DNSBL virtual IP must be a valid IPv4 address.');
        }
        $vip4 = $vipAddress;
        $createdVip = false;
        if (function_exists('pfb_get_vip_options')) {
            $vip4 = '';
            foreach (config_get_path('virtualip/vip', []) as $vip) {
                if (is_array($vip) && ($vip['subnet'] ?? '') === $vipAddress && ($vip['interface'] ?? '') === 'lo0' && !empty($vip['uniqid'])) {
                    $vip4 = "_vip{$vip['uniqid']}";
                }
            }
            if ($vip4 === '') {
                if (count(where_is_ipaddr_configured($vipAddress, '', true, true, ''))) {
                    acx_fail("{$vipAddress} is already used on this firewall. Pick an unused address for the DNSBL virtual IP.");
                }
                $vip = ['mode' => 'ipalias', 'interface' => 'lo0', 'uniqid' => uniqid(), 'descr' => 'pfBlockerNG DNSBL web server',
                    'type' => 'single', 'subnet_bits' => '32', 'subnet' => $vipAddress];
                $vips = config_get_path('virtualip/vip', []);
                $vips[] = $vip;
                config_set_path('virtualip/vip', $vips);
                write_config('[pfBlockerNG] added DNSBL virtual IP on Localhost (PFCentral)');
                if (function_exists('interface_ipalias_configure')) {
                    interface_ipalias_configure($vip);
                }
                $vip4 = "_vip{$vip['uniqid']}";
                $createdVip = true;
            }
        }

        // Same steps as the pfBlockerNG wizard (step 4).
        pfb_remove_config_settings();
        pfb_clear_contents();
        $new = config_get_path('installedpackages');
        $new['pfblockerng']['config'][0] = ['enable_cb' => 'on', 'pfb_keep' => 'on'];
        $new['pfblockerngipsettings']['config'][0] = [
            'enable_dup' => 'on', 'suppression' => 'on',
            'inbound_interface' => implode(',', $in), 'inbound_deny_action' => 'block',
            'outbound_interface' => implode(',', $out), 'outbound_deny_action' => 'reject',
            'pass_order' => 'order_0',
        ];
        $new['pfblockerngdnsblsettings']['config'][0] = [
            'pfb_dnsvip4' => $vip4, 'pfb_dnsvip6' => '', 'pfb_dnsport' => $port, 'pfb_dnsport_ssl' => $portSsl, 'pfb_dnsbl' => 'on',
        ];
        if (!empty($args['dnsbl_whitelist'])) {
            $new['pfblockerngdnsblsettings']['config'][0]['suppression'] = base64_encode(implode("\r\n", [
                's3.amazonaws.com', '.github.com', '.githubusercontent.com', 'github.map.fastly.net', '.gitlab.com',
                '.sourceforge.net', 'google.com', 'www.google.com', 'youtube.com', 'www.youtube.com',
                'youtube-ui.l.google.com', 'stackoverflow.com', 'www.stackoverflow.com', 'dropbox.com',
                'www.dropbox.com', '.pfsense.org', '.netgate.com',
            ]) . "\r\n");
        }
        foreach (['pfblockernglistsv4' => $catalog['ipv4']['PRI1'] + ['_key' => 'PRI1'], 'pfblockerngdnsbl' => $catalog['dnsbl']['ADs_Basic'] + ['_key' => 'ADs_Basic']] as $section => $info) {
            $add = ['aliasname' => $info['_key'], 'description' => $info['description'], 'row' => []];
            foreach ($info['feeds'] as $row) {
                if (isset($row['register']) || (($row['status'] ?? '') === 'discontinued')) {
                    continue;
                }
                $add['row'][] = ['format' => 'auto', 'state' => 'Enabled', 'url' => $row['url'], 'header' => $row['header']];
            }
            if ($section === 'pfblockerngdnsbl') {
                $add += ['action' => 'unbound', 'cron' => 'EveryDay', 'logging' => 'enabled', 'order' => 'default'];
            } else {
                $add += ['action' => 'Deny_Outbound', 'cron' => '01hour', 'aliaslog' => 'enabled'];
            }
            $add['dow'] = '1';
            $new[$section]['config'][] = $add;
        }
        config_set_path('installedpackages', $new);
        write_config('pfBlockerNG: Default Wizard configuration saved (PFCentral)');
        pfb_global();

        pfb_unbound_dnsbl('disabled');
        if (services_unbound_configure() == 0) {
            clear_subsystem_dirty('unbound');
        }
        system_resolvconf_generate();
        system_dhcpleases_configure();

        $run = acx_run_task('reload', 'All');
        acx_out(['ok' => true, 'created_vip' => $createdVip ? $vipAddress : null, 'reload' => $run]);
        break;

    default:
        acx_fail('Unknown action.');
}
