<?php
/*
 * PFCentral deployment helper.
 *
 * PFCentral copies this file to the firewall (/root/.pfcentral/) through the pfSense REST API
 * command prompt and runs it with pfSense's own PHP:
 *
 *   /usr/local/bin/php deploy-<hash>.php <check|apply|undo|export> [<base64 JSON> | @/tmp/acx_<name>]
 *
 * It reads and writes the same config.xml sections the pfSense pages use, so everything it adds is
 * visible and editable in the pfSense web interface. The answer is one line: "ACXDP:" and base64 JSON.
 *
 * Each change ("item") has a kind and a mode: "present" (add, or update to match) or "remove".
 * Lists (one entry each):
 *   alias, rule, floating, nat (port forward, with its pass rule), outbound (outbound NAT), onetoone (1:1 NAT),
 *   schedule, host_override, domain_override, static_route, tunable, pfb_ipv4, pfb_ipv6, pfb_dnsbl
 * Settings (one per firewall): dns_servers, ntp_servers, syslog_remote, timezone
 *
 * apply: checks everything first and changes nothing if any item has a problem. All items are written in one
 * configuration save. If the firewall can't load the new rules, the previous settings are put back. The answer
 * carries what is needed to undo the deployment later.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

const ACX_MARK = 'ACXDP:';
const ACX_PFB_INC = '/usr/local/pkg/pfblockerng/pfblockerng.inc';
const ACX_TAG = 'PFCentral';
const ACX_PFB_SECTIONS = ['pfb_ipv4' => 'pfblockernglistsv4', 'pfb_ipv6' => 'pfblockernglistsv6', 'pfb_dnsbl' => 'pfblockerngdnsbl'];

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

$action = $argv[1] ?? 'check';
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
require_once('filter.inc');
require_once('pkg-utils.inc');
foreach (['interfaces.inc', 'system.inc', 'services.inc', 'unbound.inc', 'gwlb.inc', 'syslog.inc'] as $inc) {
    if (stream_resolve_include_path($inc)) {
        require_once($inc);
    }
}

if (!function_exists('config_get_path')) {
    acx_fail('This pfSense version is too old. pfSense 2.7 or newer is needed.');
}

/* ------------------------------------------------------------------ kinds */

/** path: config list; id: fields that identify an entry; reload: what to apply after saving. */
function acx_kinds(): array
{
    return [
        'alias' => ['path' => 'aliases/alias', 'id' => ['name'], 'reload' => 'filter'],
        'rule' => ['path' => 'filter/rule', 'id' => ['interface', 'descr'], 'reload' => 'filter'],
        'floating' => ['path' => 'filter/rule', 'id' => ['descr'], 'reload' => 'filter'],
        'nat' => ['path' => 'nat/rule', 'id' => ['interface', 'descr'], 'reload' => 'filter'],
        'outbound' => ['path' => 'nat/outbound/rule', 'id' => ['interface', 'descr'], 'reload' => 'filter'],
        'onetoone' => ['path' => 'nat/onetoone', 'id' => ['interface', 'descr'], 'reload' => 'filter'],
        'schedule' => ['path' => 'schedules/schedule', 'id' => ['name'], 'reload' => 'filter'],
        'host_override' => ['path' => 'unbound/hosts', 'id' => ['host', 'domain'], 'reload' => 'unbound'],
        'domain_override' => ['path' => 'unbound/domainoverrides', 'id' => ['domain'], 'reload' => 'unbound'],
        'static_route' => ['path' => 'staticroutes/route', 'id' => ['network'], 'reload' => 'routing'],
        'tunable' => ['path' => 'sysctl/item', 'id' => ['tunable'], 'reload' => 'sysctl'],
        'pfb_ipv4' => ['path' => 'installedpackages/pfblockernglistsv4/config', 'id' => ['aliasname'], 'reload' => 'pfb'],
        'pfb_ipv6' => ['path' => 'installedpackages/pfblockernglistsv6/config', 'id' => ['aliasname'], 'reload' => 'pfb'],
        'pfb_dnsbl' => ['path' => 'installedpackages/pfblockerngdnsbl/config', 'id' => ['aliasname'], 'reload' => 'pfb'],
        // Settings: one value set per firewall.
        'dns_servers' => ['setting' => ['system/dnsserver', 'system/dnsallowoverride'], 'reload' => 'dns'],
        'ntp_servers' => ['setting' => ['system/timeservers'], 'reload' => 'ntp'],
        'syslog_remote' => ['setting' => ['syslog/enable', 'syslog/remoteserver', 'syslog/remoteserver2', 'syslog/remoteserver3', 'syslog/logall', 'syslog/ipproto'], 'reload' => 'syslog'],
        'timezone' => ['setting' => ['system/timezone'], 'reload' => 'timezone'],
        'outbound_mode' => ['setting' => ['nat/outbound/mode'], 'reload' => 'filter'],
    ];
}

/* ------------------------------------------------------------------ facts */

function acx_pfb(): array
{
    foreach (config_get_path('installedpackages/package', []) as $pkg) {
        if (is_array($pkg) && stripos($pkg['name'] ?? '', 'pfblockerng') !== false) {
            $installed = file_exists(ACX_PFB_INC);
            $version = (string) ($pkg['version'] ?? '');
            $compatible = $installed && version_compare(preg_replace('/[^0-9.].*$/', '', $version), '3.0', '>=');
            return ['installed' => $installed, 'name' => (string) $pkg['name'], 'version' => $version, 'compatible' => $compatible];
        }
    }
    return ['installed' => false, 'name' => '', 'version' => '', 'compatible' => false];
}

function acx_interfaces(): array
{
    $out = [];
    foreach (config_get_path('interfaces', []) as $key => $if) {
        if (!is_array($if)) {
            continue;
        }
        $wan = $key === 'wan' || !empty($if['gateway']) || !empty($if['gatewayv6'])
            || in_array($if['ipaddr'] ?? '', ['dhcp', 'pppoe', 'pptp', 'l2tp', 'ppp'], true);
        $out[$key] = ['key' => $key, 'descr' => (string) ($if['descr'] ?? strtoupper($key)), 'enabled' => isset($if['enable']) || $key === 'wan' || $key === 'lan', 'wan' => $wan];
    }
    return $out;
}

function acx_interface(string $name): ?string
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    foreach (acx_interfaces() as $key => $if) {
        if (strcasecmp($key, $name) === 0 || strcasecmp($if['descr'], $name) === 0) {
            return $key;
        }
    }
    foreach (config_get_path('ifgroups/ifgroupentry', []) as $g) {
        if (is_array($g) && strcasecmp($g['ifname'] ?? '', $name) === 0) {
            return $g['ifname'];
        }
    }
    return null;
}

/** "@internal": every enabled interface without a gateway (LAN-type). */
function acx_internal_interfaces(): array
{
    $out = [];
    foreach (acx_interfaces() as $key => $if) {
        if ($if['enabled'] && !$if['wan']) {
            $out[] = $key;
        }
    }
    return $out;
}

function acx_gateways(): array
{
    $names = [];
    if (function_exists('return_gateways_array')) {
        foreach ((array) return_gateways_array() as $name => $gw) {
            $names[strtolower($name)] = $name;
        }
    }
    foreach (['gateways/gateway_item', 'gateways/gateway_group'] as $p) {
        foreach (config_get_path($p, []) as $gw) {
            if (is_array($gw) && isset($gw['name'])) {
                $names[strtolower($gw['name'])] = $gw['name'];
            }
        }
    }
    foreach (acx_interfaces() as $key => $if) {
        if ($if['wan']) {
            $names[strtolower(strtoupper($key) . '_DHCP')] = strtoupper($key) . '_DHCP';
        }
    }
    return $names;
}

function acx_version(): string
{
    return trim((string) @file_get_contents('/etc/version'));
}

/* ------------------------------------------------------- address helpers */

function acx_alias_names(array $items): array
{
    $names = [];
    foreach (config_get_path('aliases/alias', []) as $a) {
        if (is_array($a) && isset($a['name'])) {
            $names[strtolower($a['name'])] = true;
        }
    }
    foreach (['pfblockernglistsv4', 'pfblockernglistsv6'] as $sec) {
        foreach (config_get_path("installedpackages/{$sec}/config", []) as $g) {
            if (is_array($g) && isset($g['aliasname'])) {
                $names[strtolower('pfB_' . $g['aliasname'])] = true;
            }
        }
    }
    foreach ($items as $it) {
        if (($it['mode'] ?? 'present') === 'remove') {
            continue;
        }
        if (($it['kind'] ?? '') === 'alias') {
            $names[strtolower((string) $it['name'])] = true;
        } elseif (in_array($it['kind'] ?? '', ['pfb_ipv4', 'pfb_ipv6'], true)) {
            $names[strtolower('pfB_' . $it['name'])] = true;
        }
    }
    return $names;
}

/**
 * "any", "self", "<interface>ip" / "<interface>net", an IP, a network (CIDR) or an alias name, optionally starting
 * with "!". Returns the pfSense config form, or an error string. $netKey: outbound NAT stores addresses under "network".
 */
function acx_address(string $value, array $aliases, string $port = '', string $netKey = 'address')
{
    $value = trim($value);
    $not = false;
    if ($value !== '' && $value[0] === '!') {
        $not = true;
        $value = trim(substr($value, 1));
    }
    $lower = strtolower($value);
    $out = [];
    if ($value === '' || $lower === 'any') {
        $out['any'] = '';
    } elseif (in_array($lower, ['self', '(self)', 'this firewall'], true)) {
        $out['network'] = '(self)';
    } elseif (preg_match('/^([a-z0-9_]+)(ip|net)$/i', $value, $m) && acx_interface($m[1]) !== null) {
        // pfSense stores an interface's address as "<if>ip" and its network as just "<if>".
        $out['network'] = acx_interface($m[1]) . (strtolower($m[2]) === 'ip' ? 'ip' : '');
    } elseif (is_ipaddr($value) || is_subnet($value)) {
        $out[$netKey] = $value;
    } elseif (preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        if (!isset($aliases[$lower])) {
            return "There is no alias named {$value} on this firewall or in this deployment.";
        }
        $out[$netKey] = $value;
    } else {
        return "\"{$value}\" isn't an address, network or alias name.";
    }
    if ($not) {
        $out['not'] = '';
    }
    if ($port !== '') {
        $out['port'] = $port;
    }
    return $out;
}

/** Returns [value, error]. */
function acx_port(string $value, array $aliases): array
{
    $value = trim(str_replace(' ', '', $value));
    if ($value === '') {
        return ['', null];
    }
    if (preg_match('/^\d{1,5}([-:]\d{1,5})?$/', $value)) {
        $value = str_replace('-', ':', $value);
        foreach (explode(':', $value) as $p) {
            if ((int) $p < 1 || (int) $p > 65535) {
                return ['', "Port {$p} is out of range."];
            }
        }
        return [$value, null];
    }
    if (preg_match('/^[A-Za-z0-9_]+$/', $value) && isset($aliases[strtolower($value)])) {
        return [$value, null];
    }
    return ['', "\"{$value}\" isn't a port, port range or port alias."];
}

function acx_created(): array
{
    return ['time' => time(), 'username' => ACX_TAG . ' deployment'];
}

function acx_str($it, string $k, int $max = 250): string
{
    return substr(trim((string) ($it[$k] ?? '')), 0, $max);
}

function acx_list_values($v): array
{
    return array_values(array_filter(array_map('trim', is_array($v) ? $v : preg_split('/[\s,]+/', (string) $v)), 'strlen'));
}

/* -------------------------------------------------- build config entries */

/**
 * The config entries an item stands for (several for "every internal interface").
 *
 * @return array{0: array<int, array>, 1: array<int, string>} [entries, errors]
 */
function acx_build(array $it, array $aliases): array
{
    $kind = (string) ($it['kind'] ?? '');
    $remove = ($it['mode'] ?? 'present') === 'remove';

    if ($kind === 'rule' && trim((string) ($it['interface'] ?? '')) === '@internal') {
        $ifs = acx_internal_interfaces();
        if (!$ifs) {
            return [[], ['This firewall has no internal (LAN-type) interfaces.']];
        }
        $entries = [];
        $errors = [];
        foreach ($ifs as $if) {
            [$e, $err] = acx_build(['interface' => $if] + $it, $aliases);
            $entries = array_merge($entries, $e);
            $errors = array_merge($errors, $err);
        }
        return [$entries, array_values(array_unique($errors))];
    }

    $e = [];
    switch ($kind) {
        case 'alias':
            $name = acx_str($it, 'name', 31);
            if (!preg_match('/^[A-Za-z0-9_]{1,31}$/', $name)) {
                $e[] = 'The alias name can use up to 31 letters, numbers and underscores.';
            }
            if ($remove) {
                return [$e ? [] : [['name' => $name]], $e];
            }
            $type = (string) ($it['type'] ?? 'host');
            if (!in_array($type, ['host', 'network', 'port', 'url', 'urltable', 'url_ports', 'urltable_ports'], true)) {
                $e[] = "Unknown alias type {$type}.";
            }
            $entries = acx_list_values($it['entries'] ?? []);
            if (!$entries) {
                $e[] = "Alias {$name} needs at least one entry.";
            }
            foreach ($entries as $x) {
                $ok = match ($type) {
                    'host' => is_ipaddr($x) || is_hostname($x) || is_iprange($x) || isset($aliases[strtolower($x)]),
                    'network' => is_subnet($x) || is_ipaddr($x) || is_hostname($x) || isset($aliases[strtolower($x)]),
                    'port' => (bool) preg_match('/^\d{1,5}(:\d{1,5})?$/', $x) || isset($aliases[strtolower($x)]),
                    default => (bool) preg_match('#^https?://\S+$#i', $x),
                };
                if (!$ok) {
                    $e[] = "Alias {$name}: \"{$x}\" doesn't fit a {$type} alias.";
                }
            }
            if ($e) {
                return [[], $e];
            }
            $entry = ['name' => $name, 'type' => $type, 'descr' => acx_str($it, 'descr'), 'detail' => implode('||', array_fill(0, count($entries), ACX_TAG))];
            if (in_array($type, ['url', 'url_ports'], true)) {
                $entry['url'] = $entries[0];
                $entry['aliasurl'] = $entries;
                $entry['address'] = '';
            } elseif (in_array($type, ['urltable', 'urltable_ports'], true)) {
                $entry['url'] = $entries[0];
                $entry['updatefreq'] = (string) max(1, (int) ($it['updatefreq'] ?? 1));
                $entry['address'] = '';
            } else {
                $entry['address'] = implode(' ', $entries);
            }
            return [[$entry], []];

        case 'rule':
        case 'floating':
            $descr = acx_str($it, 'descr', 200);
            if ($descr === '') {
                $e[] = 'Every rule needs a description: it is how PFCentral finds the rule again.';
            }
            $floating = $kind === 'floating';
            if ($remove && $floating) {
                return [$e ? [] : [['descr' => $descr]], $e];
            }
            $ifs = [];
            foreach (preg_split('/\s*,\s*/', trim((string) ($it['interface'] ?? ''))) as $name) {
                if ($name === '') {
                    continue;
                }
                $if = acx_interface($name);
                if ($if === null) {
                    $e[] = "Interface \"{$name}\" isn't on this firewall.";
                } else {
                    $ifs[] = $if;
                }
            }
            if (!$ifs && !$e) {
                $e[] = 'Pick at least one interface.';
            }
            if (!$floating && count($ifs) > 1) {
                $e[] = 'A rule is on one interface; use "every internal interface", or a floating rule for several.';
            }
            if ($remove) {
                return [$e ? [] : [['interface' => $ifs[0], 'descr' => $descr]], $e];
            }
            $type = in_array($it['action'] ?? 'pass', $floating ? ['pass', 'block', 'reject', 'match'] : ['pass', 'block', 'reject'], true) ? $it['action'] : 'pass';
            $ipp = in_array($it['ipprotocol'] ?? 'inet', ['inet', 'inet6', 'inet46'], true) ? ($it['ipprotocol'] ?? 'inet') : 'inet';
            $proto = in_array($it['protocol'] ?? 'any', ['any', 'tcp', 'udp', 'tcp/udp', 'icmp'], true) ? ($it['protocol'] ?? 'any') : 'any';
            $ports = in_array($proto, ['tcp', 'udp', 'tcp/udp'], true);
            [$sp, $spe] = $ports ? acx_port((string) ($it['source_port'] ?? ''), $aliases) : ['', null];
            [$dp, $dpe] = $ports ? acx_port((string) ($it['destination_port'] ?? ''), $aliases) : ['', null];
            $src = acx_address((string) ($it['source'] ?? 'any'), $aliases, $sp);
            $dst = acx_address((string) ($it['destination'] ?? 'any'), $aliases, $dp);
            foreach ([$spe, $dpe, is_string($src) ? $src : null, is_string($dst) ? $dst : null] as $x) {
                if ($x) {
                    $e[] = $x;
                }
            }
            $sched = acx_str($it, 'schedule', 32);
            if ($sched !== '') {
                $found = false;
                foreach (config_get_path('schedules/schedule', []) as $s) {
                    $found = $found || (is_array($s) && ($s['name'] ?? '') === $sched);
                }
                foreach ((array) ($GLOBALS['acx_items'] ?? []) as $other) {
                    $found = $found || (($other['kind'] ?? '') === 'schedule' && ($other['name'] ?? '') === $sched && ($other['mode'] ?? 'present') !== 'remove');
                }
                if (!$found) {
                    $e[] = "There is no schedule named {$sched} on this firewall or in this deployment.";
                }
            }
            $gw = acx_str($it, 'gateway', 64);
            if ($gw !== '') {
                $gws = acx_gateways();
                if (!isset($gws[strtolower($gw)])) {
                    $e[] = "There is no gateway named {$gw} on this firewall.";
                } else {
                    $gw = $gws[strtolower($gw)];
                }
            }
            if ($e) {
                return [[], $e];
            }
            $rule = ['type' => $type, 'interface' => implode(',', $ifs), 'ipprotocol' => $ipp, 'source' => $src, 'destination' => $dst, 'descr' => $descr];
            if ($proto !== 'any') {
                $rule['protocol'] = $proto;
            }
            if (!empty($it['log'])) {
                $rule['log'] = '';
            }
            if (!empty($it['disabled'])) {
                $rule['disabled'] = '';
            }
            if ($sched !== '') {
                $rule['sched'] = $sched;
            }
            if ($gw !== '') {
                $rule['gateway'] = $gw;
            }
            if ($floating) {
                $rule['floating'] = 'yes';
                $rule['direction'] = in_array($it['direction'] ?? 'any', ['any', 'in', 'out'], true) ? ($it['direction'] ?? 'any') : 'any';
                if (!empty($it['quick'])) {
                    $rule['quick'] = 'yes';
                }
            }
            return [[$rule], []];

        case 'nat':
            $descr = acx_str($it, 'descr', 200);
            if ($descr === '') {
                $e[] = 'Every port forward needs a description: it is how PFCentral finds it again.';
            }
            $if = acx_interface((string) ($it['interface'] ?? 'wan'));
            if ($if === null) {
                $e[] = "Interface \"{$it['interface']}\" isn't on this firewall.";
            }
            if ($remove) {
                return [$e ? [] : [['interface' => $if, 'descr' => $descr]], $e];
            }
            $proto = in_array($it['protocol'] ?? 'tcp', ['tcp', 'udp', 'tcp/udp'], true) ? ($it['protocol'] ?? 'tcp') : 'tcp';
            [$dport, $de] = acx_port((string) ($it['destination_port'] ?? ''), $aliases);
            [$lport, $le] = acx_port((string) ($it['local_port'] ?? ''), $aliases);
            foreach ([$de, $le] as $x) {
                if ($x) {
                    $e[] = $x;
                }
            }
            if ($dport === '' && !$de) {
                $e[] = 'The port forward needs an outside port.';
            }
            $lport = $lport === '' ? explode(':', $dport)[0] : explode(':', $lport)[0];
            $target = acx_str($it, 'target', 60);
            if (!is_ipaddr($target) && !(preg_match('/^[A-Za-z0-9_]+$/', $target) && isset($aliases[strtolower($target)]))) {
                $e[] = "The internal address \"{$target}\" isn't an IP address or alias.";
            }
            $dst = acx_address(acx_str($it, 'destination', 120) ?: ($if ? $if . 'ip' : 'wanip'), $aliases, $dport);
            $src = acx_address((string) ($it['source'] ?? 'any'), $aliases);
            foreach ([$src, $dst] as $a) {
                if (is_string($a)) {
                    $e[] = $a;
                }
            }
            if ($e) {
                return [[], $e];
            }
            return [[['interface' => $if, 'ipprotocol' => 'inet', 'protocol' => $proto, 'source' => $src, 'destination' => $dst,
                'target' => $target, 'local-port' => $lport, 'descr' => $descr]], []];

        case 'outbound':
            $descr = acx_str($it, 'descr', 200);
            if ($descr === '') {
                $e[] = 'Every outbound NAT rule needs a description: it is how PFCentral finds it again.';
            }
            $if = acx_interface((string) ($it['interface'] ?? 'wan'));
            if ($if === null) {
                $e[] = "Interface \"{$it['interface']}\" isn't on this firewall.";
            }
            if ($remove) {
                return [$e ? [] : [['interface' => $if, 'descr' => $descr]], $e];
            }
            $proto = in_array($it['protocol'] ?? 'any', ['any', 'tcp', 'udp', 'tcp/udp'], true) ? ($it['protocol'] ?? 'any') : 'any';
            $src = acx_address(acx_str($it, 'source', 120) ?: 'any', $aliases, '', 'network');
            $dst = acx_address(acx_str($it, 'destination', 120) ?: 'any', $aliases, '', 'network');
            foreach ([$src, $dst] as $a) {
                if (is_string($a)) {
                    $e[] = $a;
                }
            }
            [$dport, $de] = $proto !== 'any' ? acx_port((string) ($it['destination_port'] ?? ''), $aliases) : ['', null];
            if ($de) {
                $e[] = $de;
            }
            $nonat = !empty($it['nonat']);
            $target = acx_str($it, 'target', 60);
            if (!$nonat && $target !== '' && !is_ipaddr($target) && !(preg_match('/^[A-Za-z0-9_]+$/', $target) && isset($aliases[strtolower($target)]))) {
                $e[] = "The translation address \"{$target}\" isn't an IP address or alias.";
            }
            if ($e) {
                return [[], $e];
            }
            if (isset($src['any'])) {
                $src = ['network' => 'any'];
            }
            $rule = ['interface' => $if, 'source' => $src, 'destination' => $dst, 'descr' => $descr, 'target' => $nonat ? '' : $target];
            if (!$nonat && $target !== '') {
                $rule['target_subnet'] = is_ipaddrv6($target) ? '128' : '32';
            }
            if ($proto !== 'any') {
                $rule['protocol'] = $proto;
                $rule['dstport'] = $dport;
            }
            if (in_array($it['ipprotocol'] ?? 'inet', ['inet', 'inet6'], true)) {
                $rule['ipprotocol'] = $it['ipprotocol'] ?? 'inet';
            }
            if ($nonat) {
                $rule['nonat'] = '';
            }
            if (!empty($it['staticnatport']) && $proto !== 'any' && !$nonat) {
                $rule['staticnatport'] = '';
            }
            return [[$rule], []];

        case 'onetoone':
            $descr = acx_str($it, 'descr', 200);
            if ($descr === '') {
                $e[] = 'Every 1:1 NAT entry needs a description: it is how PFCentral finds it again.';
            }
            $if = acx_interface((string) ($it['interface'] ?? 'wan'));
            if ($if === null) {
                $e[] = "Interface \"{$it['interface']}\" isn't on this firewall.";
            }
            if ($remove) {
                return [$e ? [] : [['interface' => $if, 'descr' => $descr]], $e];
            }
            $external = acx_str($it, 'external', 60);
            if (!is_ipaddr($external)) {
                $e[] = "The external address \"{$external}\" isn't an IP address.";
            }
            $internal = acx_address(acx_str($it, 'internal', 120), $aliases);
            $dst = acx_address(acx_str($it, 'destination', 120) ?: 'any', $aliases);
            foreach ([$internal, $dst] as $a) {
                if (is_string($a)) {
                    $e[] = $a;
                }
            }
            if (is_array($internal) && isset($internal['any'])) {
                $e[] = 'The internal address is needed.';
            }
            if ($e) {
                return [[], $e];
            }
            return [[['interface' => $if, 'external' => $external, 'source' => $internal, 'destination' => $dst, 'descr' => $descr]], []];

        case 'schedule':
            $name = acx_str($it, 'name', 32);
            if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $name)) {
                $e[] = 'The schedule name can use up to 32 letters, numbers and underscores.';
            }
            if ($remove) {
                return [$e ? [] : [['name' => $name]], $e];
            }
            $ranges = [];
            $valid = fn ($t) => (bool) preg_match('/^([01]?\d|2[0-3]):(00|15|30|45|59)$/', $t);
            $h = fn ($t) => ((int) explode(':', $t)[0]) . ':' . explode(':', $t)[1];
            foreach ((array) ($it['ranges'] ?? []) as $n => $r) {
                $days = array_values(array_unique(array_filter(array_map('intval', (array) ($r['days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
                sort($days);
                $start = acx_str($r, 'start', 5);
                $stop = acx_str($r, 'stop', 5);
                if (!$days || !$valid($start) || !$valid($stop)) {
                    $e[] = 'Time range ' . ($n + 1) . ': pick days, and start and stop times (minutes 00, 15, 30, 45 or 59).';
                    continue;
                }
                $ranges[] = ['position' => implode(',', $days), 'hour' => $h($start) . '-' . $h($stop), 'rangedescr' => acx_str($r, 'descr', 100)];
            }
            if (!$ranges && !$e) {
                $e[] = 'The schedule needs at least one time range.';
            }
            if ($e) {
                return [[], $e];
            }
            return [[['name' => $name, 'descr' => acx_str($it, 'descr'), 'timerange' => $ranges]], []];

        case 'host_override':
            $host = acx_str($it, 'host', 63);
            $domain = strtolower(acx_str($it, 'domain', 253));
            if ($host !== '' && !preg_match('/^[A-Za-z0-9_]([A-Za-z0-9_-]*[A-Za-z0-9_])?$/', $host)) {
                $e[] = "\"{$host}\" isn't a valid host name.";
            }
            if (!is_domain($domain)) {
                $e[] = "\"{$domain}\" isn't a valid domain.";
            }
            if ($remove) {
                return [$e ? [] : [['host' => $host, 'domain' => $domain]], $e];
            }
            $ips = acx_list_values($it['ip'] ?? '');
            foreach ($ips as $ip) {
                if (!is_ipaddr($ip)) {
                    $e[] = "\"{$ip}\" isn't an IP address.";
                }
            }
            if (!$ips) {
                $e[] = 'The host override needs an IP address.';
            }
            if ($e) {
                return [[], $e];
            }
            return [[['host' => $host, 'domain' => $domain, 'ip' => implode(',', $ips), 'descr' => acx_str($it, 'descr')]], []];

        case 'domain_override':
            $domain = strtolower(acx_str($it, 'domain', 253));
            if (!is_domain($domain)) {
                $e[] = "\"{$domain}\" isn't a valid domain.";
            }
            if ($remove) {
                return [$e ? [] : [['domain' => $domain]], $e];
            }
            $ip = acx_str($it, 'ip', 60);
            if (!is_ipaddr(preg_replace('/@\d+$/', '', $ip))) {
                $e[] = "\"{$ip}\" isn't a DNS server IP (optionally with @port).";
            }
            if ($e) {
                return [[], $e];
            }
            return [[['domain' => $domain, 'ip' => $ip, 'descr' => acx_str($it, 'descr')]], []];

        case 'static_route':
            $network = acx_str($it, 'network', 60);
            if (!is_subnet($network) && !(preg_match('/^[A-Za-z0-9_]+$/', $network) && isset($aliases[strtolower($network)]))) {
                $e[] = "\"{$network}\" isn't a network (like 10.20.0.0/16) or alias.";
            }
            if ($remove) {
                return [$e ? [] : [['network' => $network]], $e];
            }
            $gw = acx_str($it, 'gateway', 64);
            $gws = acx_gateways();
            if (!isset($gws[strtolower($gw)])) {
                $e[] = "There is no gateway named \"{$gw}\" on this firewall.";
            }
            if ($e) {
                return [[], $e];
            }
            $route = ['network' => $network, 'gateway' => $gws[strtolower($gw)], 'descr' => acx_str($it, 'descr')];
            if (!empty($it['disabled'])) {
                $route['disabled'] = '';
            }
            return [[$route], []];

        case 'tunable':
            $tunable = acx_str($it, 'tunable', 120);
            if (!preg_match('/^[a-z0-9_.%-]+$/i', $tunable)) {
                $e[] = "\"{$tunable}\" isn't a valid tunable name.";
            }
            if ($remove) {
                return [$e ? [] : [['tunable' => $tunable]], $e];
            }
            $value = acx_str($it, 'value', 200);
            if ($value === '') {
                $e[] = 'The tunable needs a value.';
            }
            if ($e) {
                return [[], $e];
            }
            return [[['tunable' => $tunable, 'value' => $value, 'descr' => acx_str($it, 'descr')]], []];

        case 'pfb_ipv4':
        case 'pfb_ipv6':
        case 'pfb_dnsbl':
            $dnsbl = $kind === 'pfb_dnsbl';
            $max = $dnsbl ? 64 : 24;
            $name = acx_str($it, 'name', $max);
            if (!preg_match('/^[A-Za-z0-9_]{1,' . $max . '}$/', $name)) {
                $e[] = "The pfBlockerNG name can use up to {$max} letters, numbers and underscores.";
            }
            if ($remove) {
                return [$e ? [] : [['aliasname' => $name]], $e];
            }
            $rows = [];
            $formats = $dnsbl ? ['auto', 'rsync'] : ['auto', 'geoip', 'regex', 'whois', 'asn', 'rsync'];
            foreach ((array) ($it['rows'] ?? []) as $n => $row) {
                $url = acx_str($row, 'url', 1000);
                $header = acx_str($row, 'header', 60);
                if ($url === '' && $header === '') {
                    continue;
                }
                if ($url === '' || !preg_match('/^[A-Za-z0-9_]+$/', $header)) {
                    $e[] = 'Source line ' . ($n + 1) . ': both the source and a header (letters, numbers, underscores) are needed.';
                }
                $rows[] = ['format' => in_array($row['format'] ?? 'auto', $formats, true) ? $row['format'] : 'auto',
                    'state' => in_array($row['state'] ?? 'Enabled', ['Enabled', 'Disabled', 'Hold', 'Flex'], true) ? $row['state'] : 'Enabled', 'url' => $url, 'header' => $header];
            }
            $custom = trim(str_replace("\r", '', (string) ($it['custom'] ?? '')));
            if (!$rows && $custom === '') {
                $e[] = 'The pfBlockerNG list needs at least one source or some custom entries.';
            }
            if ($e) {
                return [[], $e];
            }
            $crons = ['Never', '01hour', '02hours', '03hours', '04hours', '06hours', '08hours', '12hours', 'EveryDay', 'Weekly'];
            $g = [
                'aliasname' => $name,
                'description' => acx_str($it, 'descr'),
                'row' => $rows,
                'custom' => $custom === '' ? '' : base64_encode($custom),
                'cron' => in_array($it['cron'] ?? '', $crons, true) ? $it['cron'] : 'Never',
                'dow' => in_array((string) ($it['dow'] ?? '1'), ['1', '2', '3', '4', '5', '6', '7'], true) ? (string) $it['dow'] : '1',
            ];
            if ($dnsbl) {
                $g['action'] = ($it['action'] ?? 'unbound') === 'Disabled' ? 'Disabled' : 'unbound';
                $g['logging'] = in_array($it['logging'] ?? 'enabled', ['enabled', 'disabled', 'disabled_log'], true) ? ($it['logging'] ?? 'enabled') : 'enabled';
            } else {
                $actions = ['Disabled', 'Deny_Inbound', 'Deny_Outbound', 'Deny_Both', 'Permit_Inbound', 'Permit_Outbound', 'Permit_Both',
                    'Match_Inbound', 'Match_Outbound', 'Match_Both', 'Alias_Deny', 'Alias_Permit', 'Alias_Match', 'Alias_Native'];
                $g['action'] = in_array($it['action'] ?? '', $actions, true) ? $it['action'] : 'Deny_Inbound';
                $g['aliaslog'] = ($it['aliaslog'] ?? 'enabled') === 'disabled' ? 'disabled' : 'enabled';
            }
            return [[$g], []];

        case 'dns_servers':
            $servers = acx_list_values($it['servers'] ?? '');
            foreach ($servers as $s) {
                if (!is_ipaddr($s)) {
                    $e[] = "\"{$s}\" isn't an IP address.";
                }
            }
            if (!$servers) {
                $e[] = 'Enter at least one DNS server.';
            }
            if (count($servers) > 8) {
                $e[] = 'At most 8 DNS servers.';
            }
            return [$e ? [] : [['system/dnsserver' => $servers, 'system/dnsallowoverride' => empty($it['allow_override']) ? null : '']], $e];

        case 'ntp_servers':
            $servers = acx_list_values($it['servers'] ?? '');
            foreach ($servers as $s) {
                if (!is_ipaddr($s) && !is_hostname($s)) {
                    $e[] = "\"{$s}\" isn't an IP address or host name.";
                }
            }
            if (!$servers) {
                $e[] = 'Enter at least one time server.';
            }
            return [$e ? [] : [['system/timeservers' => implode(' ', $servers)]], $e];

        case 'syslog_remote':
            $servers = acx_list_values($it['servers'] ?? '');
            foreach ($servers as $s) {
                $host = preg_replace('/:\d{1,5}$/', '', trim($s, '[]'));
                if (!is_ipaddr($host) && !is_hostname($host)) {
                    $e[] = "\"{$s}\" isn't a host, IP or IP:port.";
                }
            }
            if (count($servers) > 3) {
                $e[] = 'pfSense sends to at most 3 remote log servers.';
            }
            $on = $servers !== [];
            return [$e ? [] : [[
                'syslog/enable' => $on ? '' : null,
                'syslog/remoteserver' => $servers[0] ?? null,
                'syslog/remoteserver2' => $servers[1] ?? null,
                'syslog/remoteserver3' => $servers[2] ?? null,
                'syslog/logall' => $on ? '' : null,
                'syslog/ipproto' => ($it['ipproto'] ?? 'ipv4') === 'ipv6' ? 'ipv6' : 'ipv4',
            ]], $e];

        case 'timezone':
            $tz = acx_str($it, 'timezone', 64);
            if (!in_array($tz, timezone_identifiers_list(), true)) {
                $e[] = "\"{$tz}\" isn't a time zone pfSense knows.";
            }
            return [$e ? [] : [['system/timezone' => $tz]], $e];
    }
    return [[], ["Unknown kind of change \"{$kind}\"."]];
}

/* ------------------------------------------------------ find what exists */

function acx_matches(string $kind, $current, array $entry): bool
{
    if (!is_array($current)) {
        return false;
    }
    if ($kind === 'rule' && (!empty($current['associated-rule-id']) || !empty($current['floating']))) {
        return false;
    }
    if ($kind === 'floating' && empty($current['floating'])) {
        return false;
    }
    foreach (acx_kinds()[$kind]['id'] as $f) {
        $a = (string) ($current[$f] ?? '');
        $b = (string) ($entry[$f] ?? '');
        if (in_array($f, ['name', 'aliasname', 'domain', 'host', 'tunable'], true) ? strcasecmp($a, $b) !== 0 : $a !== $b) {
            return false;
        }
    }
    return true;
}

function acx_find(string $kind, array $entry): ?int
{
    foreach (config_get_path(acx_kinds()[$kind]['path'], []) as $i => $c) {
        if (acx_matches($kind, $c, $entry)) {
            return $i;
        }
    }
    return null;
}

function acx_setting_now(string $kind): array
{
    $now = [];
    foreach (acx_kinds()[$kind]['setting'] as $p) {
        $now[$p] = config_get_path($p, null);
    }
    return $now;
}

/** The fields this deployment sets, to decide "already set". */
function acx_same(string $kind, $current, array $entry): bool
{
    $norm = function ($v) use (&$norm) {
        if (is_array($v)) {
            ksort($v);
            return array_map($norm, $v);
        }
        return $v === null ? null : (string) $v;
    };
    if (isset(acx_kinds()[$kind]['setting'])) {
        foreach ($entry as $p => $v) {
            $c = $current[$p] ?? null;
            if ($norm($c) !== $norm($v)) {
                return false;
            }
        }
        return true;
    }
    foreach ($entry as $k => $v) {
        if (in_array($k, ['detail', 'created', 'updated', 'tracker'], true)) {
            continue;
        }
        if ($norm($current[$k] ?? null) !== $norm($v)) {
            return false;
        }
    }
    if (in_array($kind, ['rule', 'floating', 'nat', 'outbound', 'static_route'], true)) {
        foreach (['disabled', 'log', 'protocol', 'quick', 'nonat', 'staticnatport', 'sched', 'gateway'] as $flag) {
            if (isset($current[$flag]) !== isset($entry[$flag])) {
                return false;
            }
        }
    }
    return true;
}

/* --------------------------------------------------------------- planning */

function acx_plan(array $items): array
{
    $GLOBALS['acx_items'] = $items;
    $pfb = acx_pfb();
    $aliases = acx_alias_names($items);
    $kinds = acx_kinds();
    $plan = [];
    $seen = [];
    foreach (array_values($items) as $n => $it) {
        $kind = (string) ($it['kind'] ?? '');
        $remove = ($it['mode'] ?? 'present') === 'remove';
        $base = ['n' => $n, 'kind' => $kind, 'label' => (string) ($it['label'] ?? ''), 'remove' => $remove, 'status' => 'error', 'messages' => [], 'target' => null];
        if (!isset($kinds[$kind]) || $kind === 'outbound_mode') {
            $plan[] = ['messages' => ["Unknown kind of change \"{$kind}\"."]] + $base;
            continue;
        }
        if (isset(ACX_PFB_SECTIONS[$kind]) && !$remove && (!$pfb['installed'] || !$pfb['compatible'])) {
            $plan[] = ['status' => 'needs_package', 'messages' => [$pfb['installed']
                ? "pfBlockerNG {$pfb['version']} is too old for this; version 3 or newer is needed."
                : 'pfBlockerNG is not installed on this firewall.']] + $base;
            continue;
        }
        [$entries, $errors] = acx_build($it, $aliases);
        if ($errors) {
            $plan[] = ['messages' => $errors] + $base;
            continue;
        }
        $setting = isset($kinds[$kind]['setting']);
        foreach ($entries as $entry) {
            $p = $base;
            $p['target'] = isset($entry['interface']) ? (string) $entry['interface'] : null;
            $key = $setting ? $kind : $kind . '|' . strtolower(json_encode(array_map(fn ($f) => $entry[$f] ?? null, $kinds[$kind]['id'])));
            if (isset($seen[$key])) {
                $p['messages'][] = 'This deployment changes the same thing twice.';
                $plan[] = $p;
                continue;
            }
            $seen[$key] = true;
            if ($setting) {
                $p['status'] = acx_same($kind, acx_setting_now($kind), $entry) ? 'same' : 'update';
            } else {
                $index = acx_find($kind, $entry);
                if ($remove) {
                    $p['status'] = $index === null ? 'same' : 'remove';
                    if ($index === null) {
                        $p['messages'][] = 'Not on this firewall.';
                    }
                } elseif ($index === null) {
                    $p['status'] = 'create';
                } else {
                    $current = config_get_path($kinds[$kind]['path'] . '/' . $index, []);
                    $p['status'] = acx_same($kind, $current, $entry) ? 'same' : 'update';
                }
            }
            $p['entry'] = $entry;
            $plan[] = $p;
        }
    }
    return $plan;
}

function acx_summary(array $plan): array
{
    $out = [];
    foreach ($plan as $p) {
        unset($p['entry']);
        $out[] = $p;
    }
    return $out;
}

/* ------------------------------------------------------------- applying */

function acx_insert_rule(array $rules, array $rule, string $placement): array
{
    $positions = [];
    foreach ($rules as $i => $r) {
        if (!is_array($r)) {
            continue;
        }
        $same = !empty($rule['floating']) ? !empty($r['floating']) : (empty($r['floating']) && ($r['interface'] ?? '') === $rule['interface']);
        if ($same) {
            $positions[] = $i;
        }
    }
    if (!$positions) {
        $rules[] = $rule;
        return array_values($rules);
    }
    $at = $placement === 'bottom' ? end($positions) + 1 : $positions[0];
    array_splice($rules, $at, 0, [$rule]);
    return array_values($rules);
}

function acx_nat_rule(array $nat, string $assoc): array
{
    $dst = ['address' => $nat['target']];
    if (($nat['local-port'] ?? '') !== '') {
        $dst['port'] = $nat['local-port'];
    }
    return ['type' => 'pass', 'interface' => $nat['interface'], 'ipprotocol' => 'inet', 'protocol' => $nat['protocol'],
        'source' => $nat['source'], 'destination' => $dst, 'descr' => 'NAT ' . $nat['descr'], 'associated-rule-id' => $assoc,
        'tracker' => (string) (time() . random_int(100, 999)), 'created' => acx_created()];
}

function acx_find_assoc(string $assoc): ?int
{
    foreach (config_get_path('filter/rule', []) as $i => $r) {
        if (is_array($r) && ($r['associated-rule-id'] ?? '') === $assoc) {
            return $i;
        }
    }
    return null;
}

/** pfB_ aliases used by rules or NAT in this plan that aren't in the firewall's aliases yet. */
function acx_pending_pfb_aliases(array $plan): array
{
    $have = [];
    foreach (config_get_path('aliases/alias', []) as $a) {
        if (is_array($a) && isset($a['name'])) {
            $have[strtolower($a['name'])] = true;
        }
    }
    $need = [];
    foreach ($plan as $p) {
        if (!in_array($p['kind'], ['rule', 'floating', 'nat', 'outbound', 'onetoone'], true) || empty($p['entry']) || $p['remove']) {
            continue;
        }
        $addrs = [(string) ($p['entry']['target'] ?? '')];
        foreach (['source', 'destination'] as $side) {
            $addrs[] = (string) ($p['entry'][$side]['address'] ?? $p['entry'][$side]['network'] ?? '');
        }
        foreach ($addrs as $addr) {
            if (stripos($addr, 'pfB_') === 0 && !isset($have[strtolower($addr)])) {
                $need[strtolower($addr)] = $addr;
            }
        }
    }
    return array_values($need);
}

/** pf can load the generated rules? Returns an error message or null. */
function acx_rules_error(): ?string
{
    if (!is_executable('/sbin/pfctl') || !file_exists('/tmp/rules.debug')) {
        return null;
    }
    $out = [];
    $rc = 0;
    exec('/sbin/pfctl -nf /tmp/rules.debug 2>&1', $out, $rc);
    if ($rc !== 0) {
        return 'The firewall could not load the new rules: ' . substr(trim(implode(' ', $out)), 0, 400);
    }
    return null;
}

function acx_paths_touched(): array
{
    $paths = [];
    foreach (acx_kinds() as $k) {
        foreach ($k['setting'] ?? [$k['path']] as $p) {
            $paths[] = $p;
        }
    }
    return array_values(array_unique($paths));
}

function acx_sections(): array
{
    $out = [];
    foreach (acx_paths_touched() as $p) {
        $out[$p] = config_get_path($p, null);
    }
    return $out;
}

function acx_restore_sections(array $sections): void
{
    foreach ($sections as $path => $value) {
        if ($value === null) {
            config_del_path($path);
        } else {
            config_set_path($path, $value);
        }
    }
}

function acx_set_setting(array $values): void
{
    foreach ($values as $path => $value) {
        if (!in_array($path, acx_paths_touched(), true)) {
            continue;
        }
        if ($value === null) {
            config_del_path($path);
        } else {
            config_set_path($path, $value);
        }
    }
}

function acx_pfb_update(): ?string
{
    if (!file_exists(ACX_PFB_INC)) {
        return null;
    }
    require_once(ACX_PFB_INC);
    if (function_exists('pfb_global')) {
        pfb_global();
    }
    global $pfb;
    $ps = [];
    exec('/bin/ps -wwx', $ps);
    if (preg_grep('/pfblockerng[.]php\s+?(cron|update|updateip|updatednsbl)/', $ps)) {
        return 'A pfBlockerNG update was already running; the changes are picked up by the next update.';
    }
    $log = $pfb['log'] ?? '/var/log/pfblockerng/pfblockerng.log';
    install_cron_job('pfblockerng.php cron', false);
    mwexec_bg('/usr/local/bin/php /usr/local/www/pfblockerng/pfblockerng.php update >> ' . escapeshellarg($log) . ' 2>&1');
    return null;
}

/** Applies what changed. Returns notes for anything that couldn't be applied right away. */
function acx_reload(array $what): array
{
    $notes = [];
    $call = function (string $fn, string $note) use (&$notes) {
        if (function_exists($fn)) {
            $fn();
        } else {
            $notes[] = $note;
        }
    };
    if (isset($what['sysctl'])) {
        $call('system_setup_sysctl', 'Tunables apply after a reboot.');
    }
    if (isset($what['timezone'])) {
        $call('system_timezone_configure', 'The time zone applies after a reboot.');
    }
    if (isset($what['ntp'])) {
        $call('system_ntp_configure', 'The time servers apply after a reboot.');
    }
    if (isset($what['syslog'])) {
        $call('system_syslogd_start', 'Remote logging starts after a reboot.');
    }
    if (isset($what['dns'])) {
        $call('system_resolvconf_generate', 'The DNS servers apply after a reboot.');
        $what['unbound'] = true;
    }
    if (isset($what['routing'])) {
        $call('system_routing_configure', 'Static routes apply after a reboot.');
    }
    if (isset($what['unbound']) && config_get_path('unbound/enable') !== null) {
        if (function_exists('services_unbound_configure')) {
            services_unbound_configure();
            if (function_exists('clear_subsystem_dirty')) {
                clear_subsystem_dirty('unbound');
            }
        } else {
            $notes[] = 'Apply the DNS Resolver changes on the firewall.';
        }
    }
    filter_configure_sync();
    if (function_exists('clear_subsystem_dirty')) {
        foreach (['filter', 'natconf', 'aliases', 'staticroutes', 'sysctl'] as $s) {
            clear_subsystem_dirty($s);
        }
    }
    return $notes;
}

function acx_label(array $args): string
{
    return substr(preg_replace('/[^\w .#:()-]/', '', (string) ($args['label'] ?? 'deployment')), 0, 80);
}

/* --------------------------------------------------------------- export */

function acx_unaddr($a): string
{
    if (!is_array($a) || isset($a['any'])) {
        return 'any';
    }
    $not = isset($a['not']) ? '!' : '';
    if (isset($a['network'])) {
        $n = (string) $a['network'];
        if ($n === '(self)') {
            return $not . 'self';
        }
        if (config_get_path("interfaces/{$n}") !== null) {
            return $not . config_get_path("interfaces/{$n}/descr", strtoupper($n)) . 'net';
        }
        if (preg_match('/^(.+)ip$/', $n, $m) && config_get_path("interfaces/{$m[1]}") !== null) {
            return $not . config_get_path("interfaces/{$m[1]}/descr", strtoupper($m[1])) . 'ip';
        }
        return $not . $n;
    }
    return $not . (string) ($a['address'] ?? 'any');
}

function acx_ifname(string $key): string
{
    return implode(',', array_map(fn ($k) => (string) config_get_path("interfaces/{$k}/descr", strtoupper($k)), array_filter(explode(',', $key), 'strlen')));
}

/** What a firewall has, as deployment items, so it can be copied to others. */
function acx_export(): array
{
    $out = [];
    foreach (config_get_path('aliases/alias', []) as $a) {
        if (!is_array($a) || stripos($a['name'] ?? '', 'pfB_') === 0) {
            continue;
        }
        $type = (string) ($a['type'] ?? 'host');
        $entries = in_array($type, ['url', 'url_ports'], true) ? (array) ($a['aliasurl'] ?? [$a['url'] ?? ''])
            : (in_array($type, ['urltable', 'urltable_ports'], true) ? [(string) ($a['url'] ?? '')] : preg_split('/\s+/', trim((string) ($a['address'] ?? ''))));
        $out[] = ['kind' => 'alias', 'name' => $a['name'], 'type' => $type, 'entries' => array_values(array_filter($entries, 'strlen')), 'descr' => (string) ($a['descr'] ?? ''), 'updatefreq' => (int) ($a['updatefreq'] ?? 1)];
    }
    foreach (config_get_path('filter/rule', []) as $r) {
        if (!is_array($r) || !empty($r['associated-rule-id']) || trim((string) ($r['descr'] ?? '')) === '' || stripos((string) $r['descr'], 'pfB_') === 0) {
            continue;
        }
        $floating = !empty($r['floating']);
        $item = ['kind' => $floating ? 'floating' : 'rule', 'interface' => acx_ifname((string) ($r['interface'] ?? '')), 'action' => (string) ($r['type'] ?? 'pass'),
            'ipprotocol' => (string) ($r['ipprotocol'] ?? 'inet'), 'protocol' => (string) ($r['protocol'] ?? 'any'),
            'source' => acx_unaddr($r['source'] ?? []), 'source_port' => (string) ($r['source']['port'] ?? ''),
            'destination' => acx_unaddr($r['destination'] ?? []), 'destination_port' => (string) ($r['destination']['port'] ?? ''),
            'descr' => (string) $r['descr'], 'log' => isset($r['log']), 'disabled' => isset($r['disabled']), 'schedule' => (string) ($r['sched'] ?? ''),
            'gateway' => (string) ($r['gateway'] ?? ''), 'placement' => 'bottom'];
        if ($floating) {
            $item['direction'] = (string) ($r['direction'] ?? 'any');
            $item['quick'] = isset($r['quick']);
        }
        $out[] = $item;
    }
    foreach (config_get_path('nat/rule', []) as $r) {
        if (!is_array($r) || trim((string) ($r['descr'] ?? '')) === '') {
            continue;
        }
        $dst = $r['destination'] ?? [];
        $dest = acx_unaddr($dst);
        $out[] = ['kind' => 'nat', 'interface' => acx_ifname((string) ($r['interface'] ?? 'wan')), 'protocol' => (string) ($r['protocol'] ?? 'tcp'),
            'source' => acx_unaddr($r['source'] ?? []), 'destination' => preg_match('/ip$/i', $dest) ? '' : $dest,
            'destination_port' => (string) ($dst['port'] ?? ''), 'target' => (string) ($r['target'] ?? ''), 'local_port' => (string) ($r['local-port'] ?? ''), 'descr' => (string) $r['descr']];
    }
    foreach (config_get_path('nat/outbound/rule', []) as $r) {
        if (!is_array($r) || trim((string) ($r['descr'] ?? '')) === '') {
            continue;
        }
        $out[] = ['kind' => 'outbound', 'interface' => acx_ifname((string) ($r['interface'] ?? 'wan')), 'protocol' => (string) ($r['protocol'] ?? 'any'),
            'source' => (string) ($r['source']['network'] ?? 'any'), 'destination' => acx_unaddr($r['destination'] ?? []), 'destination_port' => (string) ($r['dstport'] ?? ''),
            'target' => (string) ($r['target'] ?? ''), 'nonat' => isset($r['nonat']), 'staticnatport' => isset($r['staticnatport']), 'descr' => (string) $r['descr']];
    }
    foreach (config_get_path('nat/onetoone', []) as $r) {
        if (!is_array($r) || trim((string) ($r['descr'] ?? '')) === '') {
            continue;
        }
        $out[] = ['kind' => 'onetoone', 'interface' => acx_ifname((string) ($r['interface'] ?? 'wan')), 'external' => (string) ($r['external'] ?? ''),
            'internal' => acx_unaddr($r['source'] ?? []), 'destination' => acx_unaddr($r['destination'] ?? []), 'descr' => (string) $r['descr']];
    }
    foreach (config_get_path('schedules/schedule', []) as $s) {
        if (!is_array($s)) {
            continue;
        }
        $ranges = [];
        foreach ((array) ($s['timerange'] ?? []) as $t) {
            if (!isset($t['position'])) {
                continue;
            }
            [$a, $b] = array_pad(explode('-', (string) ($t['hour'] ?? '0:00-23:59')), 2, '23:59');
            $pad = fn ($x) => str_pad(explode(':', $x)[0], 2, '0', STR_PAD_LEFT) . ':' . (explode(':', $x)[1] ?? '00');
            $ranges[] = ['days' => array_map('intval', explode(',', (string) $t['position'])), 'start' => $pad($a), 'stop' => $pad($b), 'descr' => (string) ($t['rangedescr'] ?? '')];
        }
        if ($ranges) {
            $out[] = ['kind' => 'schedule', 'name' => $s['name'], 'descr' => (string) ($s['descr'] ?? ''), 'ranges' => $ranges];
        }
    }
    foreach (config_get_path('unbound/hosts', []) as $h) {
        if (is_array($h)) {
            $out[] = ['kind' => 'host_override', 'host' => (string) ($h['host'] ?? ''), 'domain' => (string) ($h['domain'] ?? ''), 'ip' => (string) ($h['ip'] ?? ''), 'descr' => (string) ($h['descr'] ?? '')];
        }
    }
    foreach (config_get_path('unbound/domainoverrides', []) as $d) {
        if (is_array($d)) {
            $out[] = ['kind' => 'domain_override', 'domain' => (string) ($d['domain'] ?? ''), 'ip' => (string) ($d['ip'] ?? ''), 'descr' => (string) ($d['descr'] ?? '')];
        }
    }
    foreach (config_get_path('staticroutes/route', []) as $r) {
        if (is_array($r)) {
            $out[] = ['kind' => 'static_route', 'network' => (string) ($r['network'] ?? ''), 'gateway' => (string) ($r['gateway'] ?? ''), 'descr' => (string) ($r['descr'] ?? ''), 'disabled' => isset($r['disabled'])];
        }
    }
    foreach (config_get_path('sysctl/item', []) as $t) {
        if (is_array($t) && ($t['value'] ?? 'default') !== 'default') {
            $out[] = ['kind' => 'tunable', 'tunable' => (string) ($t['tunable'] ?? ''), 'value' => (string) ($t['value'] ?? ''), 'descr' => (string) ($t['descr'] ?? '')];
        }
    }
    foreach (ACX_PFB_SECTIONS as $kind => $section) {
        foreach (config_get_path("installedpackages/{$section}/config", []) as $g) {
            if (!is_array($g)) {
                continue;
            }
            $item = ['kind' => $kind, 'name' => (string) ($g['aliasname'] ?? ''), 'descr' => (string) ($g['description'] ?? ''),
                'rows' => array_values(array_map(fn ($r) => ['format' => $r['format'] ?? 'auto', 'state' => $r['state'] ?? 'Enabled', 'url' => $r['url'] ?? '', 'header' => $r['header'] ?? ''], array_filter((array) ($g['row'] ?? []), 'is_array'))),
                'action' => (string) ($g['action'] ?? ''), 'cron' => (string) ($g['cron'] ?? 'Never'), 'dow' => (string) ($g['dow'] ?? '1'),
                'custom' => substr((string) base64_decode((string) ($g['custom'] ?? '')), 0, 50000)];
            if ($kind === 'pfb_dnsbl') {
                $item['logging'] = (string) ($g['logging'] ?? 'enabled');
            } else {
                $item['aliaslog'] = (string) ($g['aliaslog'] ?? 'enabled');
            }
            $out[] = $item;
        }
    }
    $dns = array_values(array_filter((array) config_get_path('system/dnsserver', []), 'strlen'));
    if ($dns) {
        $out[] = ['kind' => 'dns_servers', 'servers' => implode("\n", $dns), 'allow_override' => config_get_path('system/dnsallowoverride') !== null];
    }
    if (($ts = trim((string) config_get_path('system/timeservers', ''))) !== '') {
        $out[] = ['kind' => 'ntp_servers', 'servers' => str_replace(' ', "\n", $ts)];
    }
    if (config_get_path('syslog/enable') !== null) {
        $out[] = ['kind' => 'syslog_remote', 'servers' => implode("\n", array_filter([config_get_path('syslog/remoteserver'), config_get_path('syslog/remoteserver2'), config_get_path('syslog/remoteserver3')])), 'ipproto' => (string) config_get_path('syslog/ipproto', 'ipv4')];
    }
    if (($tz = (string) config_get_path('system/timezone', '')) !== '') {
        $out[] = ['kind' => 'timezone', 'timezone' => $tz];
    }
    return $out;
}

/* ------------------------------------------------------------------ main */

switch ($action) {
    case 'check':
        $plan = acx_plan((array) ($args['items'] ?? []));
        acx_out(['ok' => true, 'version' => acx_version(), 'pfblockerng' => acx_pfb(), 'outbound_mode' => (string) config_get_path('nat/outbound/mode', 'automatic'),
            'interfaces' => array_values(acx_interfaces()), 'plan' => acx_summary($plan)]);
        break;

    case 'export':
        acx_out(['ok' => true, 'version' => acx_version(), 'items' => acx_export()]);
        break;

    case 'apply':
        $items = array_values((array) ($args['items'] ?? []));
        $label = acx_label($args);
        $plan = acx_plan($items);
        foreach ($plan as $p) {
            if (in_array($p['status'], ['error', 'needs_package'], true)) {
                acx_fail('Nothing was changed: a change doesn\'t fit this firewall.', ['plan' => acx_summary($plan)]);
            }
        }

        $before = acx_sections();
        $kinds = acx_kinds();
        $undo = [];
        $reload = [];
        $notes = [];
        foreach ($plan as $p) {
            if ($p['status'] === 'same') {
                continue;
            }
            $kind = $p['kind'];
            $entry = $p['entry'];
            $reload[$kinds[$kind]['reload']] = true;

            if (isset($kinds[$kind]['setting'])) {
                $old = acx_setting_now($kind);
                acx_set_setting($entry);
                $undo[] = ['kind' => $kind, 'setting' => true, 'before' => $old];
                continue;
            }

            $path = $kinds[$kind]['path'];
            $index = acx_find($kind, $entry);
            $list = config_get_path($path, []);
            if (!is_array($list)) {
                $list = [];
            }
            $old = $index !== null ? $list[$index] : null;
            $key = array_intersect_key($entry, array_flip($kinds[$kind]['id']));

            if ($p['remove']) {
                $linked = null;
                if ($kind === 'nat' && !empty($old['associated-rule-id'])) {
                    $ri = acx_find_assoc((string) $old['associated-rule-id']);
                    if ($ri !== null) {
                        $rules = config_get_path('filter/rule', []);
                        $linked = $rules[$ri];
                        unset($rules[$ri]);
                        config_set_path('filter/rule', array_values($rules));
                    }
                }
                unset($list[$index]);
                config_set_path($path, array_values($list));
                $undo[] = ['kind' => $kind, 'key' => $key, 'before' => $old, 'index' => $index, 'removed' => true, 'linked' => $linked];
                continue;
            }

            if (in_array($kind, ['rule', 'floating'], true)) {
                $entry['tracker'] = $old['tracker'] ?? (string) (time() . random_int(100, 999));
                $entry['created'] = $old['created'] ?? acx_created();
                if ($old !== null) {
                    $entry['updated'] = acx_created();
                    foreach (['labels', 'force_category', 'statetype', 'os', 'max', 'max-src-nodes'] as $keep) {
                        if (isset($old[$keep])) {
                            $entry[$keep] = $old[$keep];
                        }
                    }
                }
            } elseif ($kind === 'nat') {
                $entry['associated-rule-id'] = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($old['associated-rule-id'] ?? ('nat_' . uniqid('', true))));
                $entry['created'] = $old['created'] ?? acx_created();
            } elseif (in_array($kind, ['outbound', 'onetoone'], true)) {
                $entry['created'] = $old['created'] ?? acx_created();
                if ($old !== null) {
                    $entry['updated'] = acx_created();
                }
            } elseif ($kind === 'schedule') {
                $entry['schedlabel'] = $old['schedlabel'] ?? uniqid();
            } elseif (in_array($kind, ['alias', 'host_override', 'pfb_ipv4', 'pfb_ipv6', 'pfb_dnsbl'], true) && $old !== null) {
                // Keep what pfSense or the package set that this deployment doesn't manage.
                $entry = $entry + $old;
            }

            if ($index === null) {
                if (in_array($kind, ['rule', 'floating'], true)) {
                    $list = acx_insert_rule($list, $entry, (string) ($items[$p['n']]['placement'] ?? 'top'));
                } else {
                    $list[] = $entry;
                }
            } else {
                $list[$index] = $entry;
            }
            if ($kind === 'host_override') {
                usort($list, fn ($a, $b) => strcasecmp($a['host'] ?? '', $b['host'] ?? ''));
            }
            config_set_path($path, array_values($list));

            if ($kind === 'nat') {
                $assoc = $entry['associated-rule-id'];
                $rules = config_get_path('filter/rule', []);
                $ri = acx_find_assoc($assoc);
                $linked = acx_nat_rule($entry, $assoc);
                if ($ri === null) {
                    $rules = acx_insert_rule($rules, $linked, 'top');
                } else {
                    $linked['tracker'] = $rules[$ri]['tracker'] ?? $linked['tracker'];
                    $linked['created'] = $rules[$ri]['created'] ?? $linked['created'];
                    $rules[$ri] = $linked;
                }
                config_set_path('filter/rule', array_values($rules));
            }
            if ($kind === 'outbound') {
                // Outbound NAT rules are ignored in automatic mode; hybrid keeps the automatic rules and adds these.
                $mode = (string) config_get_path('nat/outbound/mode', '');
                if (!in_array($mode, ['hybrid', 'advanced'], true)) {
                    $undo[] = ['kind' => 'outbound_mode', 'setting' => true, 'before' => ['nat/outbound/mode' => $mode === '' ? null : $mode]];
                    config_set_path('nat/outbound/mode', 'hybrid');
                    $notes[] = 'Outbound NAT was switched from ' . ($mode ?: 'automatic') . ' to hybrid so the new rules are used.';
                }
            }

            $undo[] = ['kind' => $kind, 'key' => $key, 'assoc' => $entry['associated-rule-id'] ?? null, 'before' => $old];
        }

        $changedItems = count(array_filter($undo, fn ($u) => $u['kind'] !== 'outbound_mode'));
        if ($undo) {
            foreach (acx_pending_pfb_aliases($plan) as $alias) {
                $aliasesNow = config_get_path('aliases/alias', []);
                $aliasesNow[] = ['name' => $alias, 'type' => 'host', 'address' => '', 'descr' => 'pfBlockerNG list, filled at its next update (' . ACX_TAG . ')'];
                config_set_path('aliases/alias', array_values($aliasesNow));
                $undo[] = ['kind' => 'alias', 'key' => ['name' => $alias], 'assoc' => null, 'before' => null];
            }
        }

        if (!$undo) {
            acx_out(['ok' => true, 'changed' => 0, 'undo' => [], 'plan' => acx_summary($plan), 'message' => 'Everything was already set.']);
        }

        write_config(ACX_TAG . " {$label}: {$changedItems} change(s)");
        $notes = array_merge($notes, acx_reload($reload));
        if ($error = acx_rules_error()) {
            acx_restore_sections($before);
            write_config(ACX_TAG . " {$label}: undone, the firewall could not load the new rules");
            acx_reload($reload);
            acx_fail($error, ['undone' => true, 'plan' => acx_summary($plan)]);
        }
        if (isset($reload['pfb']) && ($n = acx_pfb_update())) {
            $notes[] = $n;
        }
        acx_out(['ok' => true, 'changed' => $changedItems, 'undo' => $undo, 'plan' => acx_summary($plan), 'pfb_update' => isset($reload['pfb']), 'note' => implode(' ', $notes) ?: null]);
        break;

    case 'undo':
        $label = acx_label($args);
        $undo = array_reverse((array) ($args['undo'] ?? []));
        $before = acx_sections();
        $kinds = acx_kinds();
        $done = 0;
        $reload = ['filter' => true];
        foreach ($undo as $u) {
            $kind = (string) ($u['kind'] ?? '');
            if (!isset($kinds[$kind])) {
                continue;
            }
            $reload[$kinds[$kind]['reload']] = true;
            if (!empty($u['setting'])) {
                acx_set_setting((array) ($u['before'] ?? []));
                $done++;
                continue;
            }
            $path = $kinds[$kind]['path'];
            $index = acx_find($kind, (array) ($u['key'] ?? []));
            $list = config_get_path($path, []);
            if (!is_array($list)) {
                $list = [];
            }
            $old = $u['before'] ?? null;

            if (!empty($u['removed'])) {
                // Put back what the deployment removed, where it was.
                if (is_array($old) && $index === null) {
                    $at = min((int) ($u['index'] ?? count($list)), count($list));
                    array_splice($list, $at, 0, [$old]);
                    config_set_path($path, array_values($list));
                    if ($kind === 'nat' && !empty($u['linked'])) {
                        config_set_path('filter/rule', acx_insert_rule(config_get_path('filter/rule', []), $u['linked'], 'top'));
                    }
                    $done++;
                }
                continue;
            }

            if ($old === null) {
                if ($index !== null) {
                    unset($list[$index]);
                    $done++;
                }
            } elseif ($index !== null) {
                $list[$index] = $old;
                $done++;
            } else {
                $list[] = $old;
                $done++;
            }
            config_set_path($path, array_values($list));
            if ($kind === 'nat' && !empty($u['assoc'])) {
                $rules = config_get_path('filter/rule', []);
                $ri = acx_find_assoc((string) $u['assoc']);
                if ($old === null && $ri !== null) {
                    unset($rules[$ri]);
                    config_set_path('filter/rule', array_values($rules));
                } elseif ($old !== null && $ri !== null) {
                    $restored = acx_nat_rule($old, (string) $u['assoc']);
                    $restored['tracker'] = $rules[$ri]['tracker'] ?? $restored['tracker'];
                    $rules[$ri] = $restored;
                    config_set_path('filter/rule', array_values($rules));
                }
            }
        }
        if ($done === 0) {
            acx_out(['ok' => true, 'undone' => 0, 'message' => 'Nothing from this deployment was left to undo.']);
        }
        write_config(ACX_TAG . " {$label}: undone");
        acx_reload($reload);
        if ($error = acx_rules_error()) {
            acx_restore_sections($before);
            write_config(ACX_TAG . " {$label}: undo reverted, the firewall could not load the rules");
            acx_reload($reload);
            acx_fail('Undo was not possible: ' . $error);
        }
        if (isset($reload['pfb'])) {
            acx_pfb_update();
        }
        acx_out(['ok' => true, 'undone' => $done]);
        break;

    default:
        acx_fail('Unknown action.');
}
