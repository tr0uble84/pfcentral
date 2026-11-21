<?php
/*
 * PFCentral web interface lock (System → Security), copied to the firewall (/root/.pfcentral/) like the
 * pfBlockerNG helper and run with the PHP CLI of pfSense:
 *
 *   /usr/local/bin/php guilock-<hash>.php <action> [<base64 JSON arguments> | @/tmp/acx_<name>]
 *
 * "lock" adds two floating rules at the top, on the internet-facing interfaces only: pass to the web interface
 * port from the PFCentral_GUI alias, then block everyone else. LAN access is not touched. After a lock the firewall
 * undoes it by itself in 3 minutes unless PFCentral confirms it can still reach it ("confirm"), so a wrong address
 * can't lock PFCentral out for good. Answers are one line: "ACXGL:" + base64 JSON.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

const GL_MARK = 'ACXGL:';
const GL_DIR = '/root/.pfcentral';
const GL_PENDING = GL_DIR . '/guilock-pending';
const GL_ALIAS = 'PFCentral_GUI';
const GL_TAG = 'PFCentral web interface lock';
const GL_REVERT_SECONDS = 180;

function gl_out(array $data): void
{
    echo "\n" . GL_MARK . base64_encode((string) json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n";
    exit(0);
}

function gl_fail(string $message): void
{
    gl_out(['ok' => false, 'error' => $message]);
}

$action = $argv[1] ?? 'status';
$raw = $argv[2] ?? '';
if ($raw !== '' && $raw[0] === '@') {
    $path = substr($raw, 1);
    if (!preg_match('#^/tmp/acx_[A-Za-z0-9_]+$#', $path)) {
        gl_fail('Invalid argument file.');
    }
    $raw = trim((string) @file_get_contents($path));
    @unlink($path);
}
$args = $raw !== '' ? json_decode((string) base64_decode($raw), true) : [];
if (!is_array($args)) {
    gl_fail('Invalid arguments.');
}

require_once 'config.inc';
require_once 'util.inc';
require_once 'filter.inc';

function gl_port(): string
{
    $port = (string) config_get_path('system/webgui/port', '');
    if ($port === '') {
        $port = config_get_path('system/webgui/protocol', 'https') === 'http' ? '80' : '443';
    }
    return $port;
}

function gl_ours(array $rule): bool
{
    return isset($rule['descr']) && strpos((string) $rule['descr'], GL_TAG) === 0;
}

/** Internet-facing interfaces: WAN, and any interface with a gateway or a dynamic address. */
function gl_wans(): array
{
    $out = [];
    foreach ((array) config_get_path('interfaces', []) as $if => $c) {
        if (!is_array($c) || isset($c['enable']) === false && $if !== 'wan') {
            continue;
        }
        $dynamic = in_array($c['ipaddr'] ?? '', ['dhcp', 'pppoe', 'pptp', 'l2tp'], true) || ($c['ipaddrv6'] ?? '') === 'dhcp6';
        if ($if === 'wan' || !empty($c['gateway']) || !empty($c['gatewayv6']) || $dynamic) {
            $out[] = $if;
        }
    }
    return array_values(array_unique($out));
}

function gl_status(): array
{
    $rules = 0;
    foreach ((array) config_get_path('filter/rule', []) as $r) {
        if (is_array($r) && gl_ours($r)) {
            $rules++;
        }
    }
    $allowed = [];
    foreach ((array) config_get_path('aliases/alias', []) as $a) {
        if (is_array($a) && ($a['name'] ?? '') === GL_ALIAS) {
            $allowed = preg_split('/\s+/', trim((string) ($a['address'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        }
    }
    return ['locked' => $rules > 0, 'rules' => $rules, 'allowed' => $allowed, 'port' => gl_port(), 'interfaces' => gl_wans(), 'pending' => file_exists(GL_PENDING)];
}

/** Addresses connected to the web interface port right now (PFCentral's own connection is one of them). */
function gl_peers(): array
{
    $port = gl_port();
    $out = [];
    exec('/usr/bin/sockstat -46 -c -p ' . escapeshellarg($port) . ' 2>/dev/null', $lines);
    foreach ($lines as $i => $line) {
        $f = preg_split('/\s+/', trim($line));
        if ($i === 0 || count($f) < 7) {
            continue;
        }
        $foreign = $f[6];
        $ip = preg_replace('/:\d+$/', '', $foreign);
        $ip = trim($ip, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, ['127.0.0.1', '::1'], true)) {
            $out[] = $ip;
        }
    }
    return array_values(array_unique($out));
}

function gl_remove(): void
{
    $rules = array_values(array_filter((array) config_get_path('filter/rule', []), fn ($r) => !(is_array($r) && gl_ours($r))));
    config_set_path('filter/rule', $rules);
    $aliases = array_values(array_filter((array) config_get_path('aliases/alias', []), fn ($a) => !(is_array($a) && ($a['name'] ?? '') === GL_ALIAS)));
    config_set_path('aliases/alias', $aliases);
}

function gl_rule(string $type, array $source, string $descr, array $ifs, string $port): array
{
    return [
        'type' => $type,
        'interface' => implode(',', $ifs),
        'floating' => 'yes',
        'quick' => 'yes',
        'direction' => 'in',
        'ipprotocol' => 'inet46',
        'protocol' => 'tcp',
        'source' => $source,
        'destination' => ['network' => '(self)', 'port' => $port],
        'descr' => GL_TAG . ': ' . $descr,
        'tracker' => (string) (time() . random_int(100, 999)),
        'created' => ['time' => (string) time(), 'username' => 'PFCentral'],
    ];
}

switch ($action) {
    case 'status':
        gl_out(['ok' => true] + gl_status());

    case 'peers':
        gl_out(['ok' => true, 'peers' => gl_peers(), 'port' => gl_port()]);

    case 'lock':
        $allowed = [];
        foreach ((array) ($args['allowed'] ?? []) as $a) {
            $a = trim((string) $a);
            $ip = explode('/', $a)[0];
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $allowed[] = $a;
            }
        }
        if (!$allowed) {
            gl_fail('No allowed addresses.');
        }
        $ifs = gl_wans();
        if (!$ifs) {
            gl_fail('No internet-facing interface found.');
        }
        $port = gl_port();
        gl_remove();
        $aliases = (array) config_get_path('aliases/alias', []);
        $aliases[] = ['name' => GL_ALIAS, 'type' => 'network', 'address' => implode(' ', $allowed), 'descr' => 'PFCentral: may reach the web interface while it is locked', 'detail' => implode('||', array_fill(0, count($allowed), 'PFCentral'))];
        config_set_path('aliases/alias', $aliases);
        $rules = (array) config_get_path('filter/rule', []);
        array_unshift($rules,
            gl_rule('pass', ['address' => GL_ALIAS], 'allow PFCentral', $ifs, $port),
            gl_rule('block', ['any' => ''], 'block everyone else', $ifs, $port)
        );
        config_set_path('filter/rule', $rules);
        write_config('PFCentral: web interface locked to ' . implode(', ', $allowed) . (isset($args['by']) ? ' by ' . substr((string) $args['by'], 0, 80) : ''));
        filter_configure();
        // Undo by itself unless PFCentral confirms it can still reach the firewall.
        @mkdir(GL_DIR, 0700, true);
        file_put_contents(GL_PENDING, (string) time());
        $self = realpath($argv[0]);
        exec('/usr/sbin/daemon -f /bin/sh -c ' . escapeshellarg('sleep ' . GL_REVERT_SECONDS . '; [ -f ' . GL_PENDING . ' ] && /usr/local/bin/php ' . $self . ' unlock-auto'));
        gl_out(['ok' => true] + gl_status());

    case 'confirm':
        @unlink(GL_PENDING);
        gl_out(['ok' => true] + gl_status());

    case 'unlock':
    case 'unlock-auto':
        gl_remove();
        write_config($action === 'unlock-auto' ? 'PFCentral: web interface lock undone automatically (PFCentral did not confirm it could still reach the firewall)' : 'PFCentral: web interface lock removed' . (isset($args['by']) ? ' by ' . substr((string) $args['by'], 0, 80) : ''));
        filter_configure();
        @unlink(GL_PENDING);
        gl_out(['ok' => true] + gl_status());
}

gl_fail('Unknown action.');
