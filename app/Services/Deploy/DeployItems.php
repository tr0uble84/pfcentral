<?php

namespace App\Services\Deploy;

/**
 * The kinds of change a deployment can send, their form fields, and how a change is cleaned up and named.
 * Firewall-specific checks (interfaces, aliases, gateways, packages) are done on each firewall by the helper.
 *
 * Field: key, label, type (text|textarea|select|checkbox|number|rows|ranges), id (identifies the item: the only
 * fields used to remove it), required, max, options, placeholder, help, wide, when ([field => [values]]).
 */
final class DeployItems
{
    public const MAX_ITEMS = 100;

    /** Kinds in the order of the "Add" menu, grouped. */
    public const GROUPS = [
        'Firewall' => ['rule', 'floating', 'alias', 'schedule'],
        'NAT' => ['nat', 'outbound', 'onetoone'],
        'DNS and routing' => ['host_override', 'domain_override', 'static_route'],
        'pfBlockerNG' => ['pfb_ipv4', 'pfb_ipv6', 'pfb_dnsbl'],
        'System' => ['dns_servers', 'ntp_servers', 'syslog_remote', 'timezone', 'tunable'],
    ];

    public const KINDS = [
        'rule' => 'Firewall rule',
        'floating' => 'Floating rule',
        'alias' => 'Alias',
        'schedule' => 'Schedule',
        'nat' => 'Port forward',
        'outbound' => 'Outbound NAT rule',
        'onetoone' => '1:1 NAT',
        'host_override' => 'DNS host override',
        'domain_override' => 'DNS domain override',
        'static_route' => 'Static route',
        'pfb_ipv4' => 'pfBlockerNG IPv4 list',
        'pfb_ipv6' => 'pfBlockerNG IPv6 list',
        'pfb_dnsbl' => 'pfBlockerNG DNSBL group',
        'dns_servers' => 'DNS servers',
        'ntp_servers' => 'Time (NTP) servers',
        'syslog_remote' => 'Remote logging (syslog)',
        'timezone' => 'Time zone',
        'tunable' => 'System tunable',
    ];

    /** One setting per firewall: can't be removed, only set. */
    public const SETTINGS = ['dns_servers', 'ntp_servers', 'syslog_remote', 'timezone'];

    /** Packages each kind needs on the firewall. */
    public const PACKAGES = ['pfb_ipv4' => 'pfBlockerNG', 'pfb_ipv6' => 'pfBlockerNG', 'pfb_dnsbl' => 'pfBlockerNG'];

    public static function fields(): array
    {
        $ports = ['protocol' => ['tcp', 'udp', 'tcp/udp']];
        $addrHelp = 'any, an IP or network (10.0.0.0/24), an alias (pfB_… for pfBlockerNG lists), LANnet / WANip, or self. Start with ! for "not".';
        $proto = ['any' => 'Any', 'tcp' => 'TCP', 'udp' => 'UDP', 'tcp/udp' => 'TCP and UDP', 'icmp' => 'ICMP'];
        $ipver = ['inet' => 'IPv4', 'inet6' => 'IPv6', 'inet46' => 'IPv4 and IPv6'];
        $cron = ['Never' => 'Never', '01hour' => 'Every hour', '02hours' => 'Every 2 hours', '03hours' => 'Every 3 hours', '04hours' => 'Every 4 hours', '06hours' => 'Every 6 hours', '08hours' => 'Every 8 hours', '12hours' => 'Every 12 hours', 'EveryDay' => 'Once a day', 'Weekly' => 'Weekly'];
        $dow = ['1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday'];
        $ipActions = ['Deny_Inbound' => 'Deny inbound', 'Deny_Outbound' => 'Deny outbound', 'Deny_Both' => 'Deny both', 'Permit_Inbound' => 'Permit inbound', 'Permit_Outbound' => 'Permit outbound', 'Permit_Both' => 'Permit both', 'Match_Inbound' => 'Match inbound', 'Match_Outbound' => 'Match outbound', 'Match_Both' => 'Match both', 'Alias_Deny' => 'Alias only: deny', 'Alias_Permit' => 'Alias only: permit', 'Alias_Match' => 'Alias only: match', 'Alias_Native' => 'Alias only: native', 'Disabled' => 'Disabled'];
        $pfbList = fn (int $max, array $actions, string $actionDefault, bool $dnsbl) => array_values(array_filter([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'id' => true, 'required' => true, 'max' => $max, 'placeholder' => $dnsbl ? 'Ads' : 'RDPDC0pfblocker', 'help' => $dnsbl ? 'Letters, numbers and underscores.' : "Up to {$max} letters, numbers and underscores. Its alias on the firewall is pfB_ plus this name."],
            ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'max' => 250],
            ['key' => 'rows', 'label' => 'Sources', 'type' => 'rows', 'wide' => true, 'formats' => $dnsbl ? ['auto' => 'Auto', 'rsync' => 'RSync'] : ['auto' => 'Auto', 'rsync' => 'RSync', 'geoip' => 'GeoIP', 'asn' => 'ASN', 'whois' => 'Whois', 'regex' => 'Regex']],
            ['key' => 'action', 'label' => 'Action', 'type' => 'select', 'options' => $actions, 'default' => $actionDefault, 'help' => $dnsbl ? null : '"Alias only" makes the pfB_ alias for your own rules (for example a port forward allowed only from this list).'],
            ['key' => 'cron', 'label' => 'Update frequency', 'type' => 'select', 'options' => $cron, 'default' => '01hour'],
            ['key' => 'dow', 'label' => 'Day of the week', 'type' => 'select', 'options' => $dow, 'default' => '1', 'when' => ['cron' => ['Weekly']]],
            $dnsbl ? ['key' => 'logging', 'label' => 'Logging', 'type' => 'select', 'options' => ['enabled' => 'DNSBL web server / VIP', 'disabled' => 'Null blocking (no logging)', 'disabled_log' => 'Null blocking (logging, Python mode)'], 'default' => 'enabled']
                : ['key' => 'aliaslog', 'label' => 'Logging', 'type' => 'select', 'options' => ['enabled' => 'On', 'disabled' => 'Off'], 'default' => 'enabled'],
            ['key' => 'custom', 'label' => $dnsbl ? 'Custom domains (optional)' : 'Custom list (optional)', 'type' => 'textarea', 'wide' => true, 'max' => 100000, 'placeholder' => $dnsbl ? 'Extra domains, one per line' : 'Extra IPs or networks, one per line'],
        ]));

        return [
            'rule' => [
                ['key' => 'action', 'label' => 'Action', 'type' => 'select', 'options' => ['pass' => 'Pass (allow)', 'block' => 'Block (drop silently)', 'reject' => 'Reject'], 'default' => 'pass'],
                ['key' => 'interface', 'label' => 'Interface', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 40, 'default' => 'WAN', 'list' => 'pfc-ifaces', 'help' => 'WAN, LAN, OPT1, an interface\'s description, or "@internal" for every internal (LAN-type) interface.'],
                ['key' => 'protocol', 'label' => 'Protocol', 'type' => 'select', 'options' => $proto, 'default' => 'tcp'],
                ['key' => 'ipprotocol', 'label' => 'IP version', 'type' => 'select', 'options' => $ipver, 'default' => 'inet'],
                ['key' => 'source', 'label' => 'Source', 'type' => 'text', 'max' => 120, 'default' => 'any', 'help' => $addrHelp],
                ['key' => 'source_port', 'label' => 'Source port', 'type' => 'text', 'max' => 40, 'placeholder' => 'Usually empty', 'when' => $ports],
                ['key' => 'destination', 'label' => 'Destination', 'type' => 'text', 'max' => 120, 'default' => 'any', 'help' => $addrHelp],
                ['key' => 'destination_port', 'label' => 'Destination port', 'type' => 'text', 'max' => 40, 'placeholder' => '3389, 8000-8010 or a port alias', 'when' => $ports],
                ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 200, 'wide' => true, 'placeholder' => 'Allow RDP from the RDP allow list', 'help' => 'Required: PFCentral finds the rule again by its description on that interface.'],
                ['key' => 'placement', 'label' => 'Where in the rule list', 'type' => 'select', 'options' => ['top' => 'At the top (checked first)', 'bottom' => 'At the bottom'], 'default' => 'top', 'help' => 'For a new rule. A rule that is updated keeps its place.'],
                ['key' => 'schedule', 'label' => 'Schedule (optional)', 'type' => 'text', 'max' => 32, 'placeholder' => 'Schedule name', 'help' => 'Only active during this schedule. It must exist on the firewall or be in this deployment.'],
                ['key' => 'gateway', 'label' => 'Gateway (optional)', 'type' => 'text', 'max' => 64, 'placeholder' => 'Default', 'help' => 'Policy routing: send matching traffic through this gateway (for example WAN2_DHCP).'],
                ['key' => 'log', 'label' => 'Log packets that match', 'type' => 'checkbox'],
                ['key' => 'disabled', 'label' => 'Disabled (added, but not active)', 'type' => 'checkbox'],
            ],
            'floating' => [
                ['key' => 'action', 'label' => 'Action', 'type' => 'select', 'options' => ['pass' => 'Pass', 'block' => 'Block', 'reject' => 'Reject', 'match' => 'Match (for traffic shaping)'], 'default' => 'block'],
                ['key' => 'interface', 'label' => 'Interfaces', 'type' => 'text', 'required' => true, 'max' => 200, 'default' => 'WAN', 'help' => 'One or more, separated by commas: WAN, LAN, OPT1.'],
                ['key' => 'direction', 'label' => 'Direction', 'type' => 'select', 'options' => ['any' => 'Any', 'in' => 'In', 'out' => 'Out'], 'default' => 'any'],
                ['key' => 'protocol', 'label' => 'Protocol', 'type' => 'select', 'options' => $proto, 'default' => 'any'],
                ['key' => 'ipprotocol', 'label' => 'IP version', 'type' => 'select', 'options' => $ipver, 'default' => 'inet'],
                ['key' => 'source', 'label' => 'Source', 'type' => 'text', 'max' => 120, 'default' => 'any', 'help' => $addrHelp],
                ['key' => 'source_port', 'label' => 'Source port', 'type' => 'text', 'max' => 40, 'when' => $ports],
                ['key' => 'destination', 'label' => 'Destination', 'type' => 'text', 'max' => 120, 'default' => 'any'],
                ['key' => 'destination_port', 'label' => 'Destination port', 'type' => 'text', 'max' => 40, 'when' => $ports],
                ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 200, 'wide' => true, 'help' => 'Required: PFCentral finds the floating rule again by its description.'],
                ['key' => 'placement', 'label' => 'Where in the floating rules', 'type' => 'select', 'options' => ['top' => 'At the top', 'bottom' => 'At the bottom'], 'default' => 'top'],
                ['key' => 'schedule', 'label' => 'Schedule (optional)', 'type' => 'text', 'max' => 32],
                ['key' => 'quick', 'label' => 'Quick: apply at the first match', 'type' => 'checkbox'],
                ['key' => 'log', 'label' => 'Log packets that match', 'type' => 'checkbox'],
                ['key' => 'disabled', 'label' => 'Disabled', 'type' => 'checkbox'],
            ],
            'alias' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 31, 'placeholder' => 'RDP_Allowed', 'help' => 'Letters, numbers and underscores.'],
                ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => ['host' => 'Hosts', 'network' => 'Networks', 'port' => 'Ports', 'url' => 'URL (IPs, loaded once)', 'urltable' => 'URL table (IPs, refreshed)'], 'default' => 'host'],
                ['key' => 'entries', 'label' => 'Entries', 'type' => 'textarea', 'required' => true, 'wide' => true, 'max' => 200000, 'placeholder' => 'One per line', 'help' => 'IPs, networks, host names, other aliases; ports or ranges like 8000:8010 for a port alias; a URL for URL types.'],
                ['key' => 'updatefreq', 'label' => 'Refresh every (days)', 'type' => 'number', 'min' => 1, 'max' => 365, 'default' => 1, 'when' => ['type' => ['urltable']]],
                ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'max' => 250],
            ],
            'schedule' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 32, 'placeholder' => 'WorkHours', 'help' => 'Letters, numbers and underscores. Rules use it by this name.'],
                ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'max' => 250],
                ['key' => 'ranges', 'label' => 'When it\'s active', 'type' => 'ranges', 'wide' => true],
            ],
            'nat' => [
                ['key' => 'interface', 'label' => 'Interface', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 40, 'default' => 'WAN', 'list' => 'pfc-ifaces'],
                ['key' => 'protocol', 'label' => 'Protocol', 'type' => 'select', 'options' => ['tcp' => 'TCP', 'udp' => 'UDP', 'tcp/udp' => 'TCP and UDP'], 'default' => 'tcp'],
                ['key' => 'destination_port', 'label' => 'Outside port', 'type' => 'text', 'required' => true, 'max' => 40, 'placeholder' => '3389'],
                ['key' => 'destination', 'label' => 'Outside address', 'type' => 'text', 'max' => 120, 'placeholder' => 'The interface\'s address', 'help' => 'Leave empty for the interface\'s own address, or enter a virtual IP.'],
                ['key' => 'target', 'label' => 'Internal address', 'type' => 'text', 'required' => true, 'max' => 60, 'placeholder' => '192.168.1.10'],
                ['key' => 'local_port', 'label' => 'Internal port', 'type' => 'text', 'max' => 20, 'placeholder' => 'Same as outside'],
                ['key' => 'source', 'label' => 'Allowed from', 'type' => 'text', 'max' => 120, 'default' => 'any', 'help' => 'any, an IP or network, or an alias such as a pfBlockerNG list (pfB_…).'],
                ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 200, 'placeholder' => 'RDP to DC01', 'help' => 'Required. A matching pass rule is added and kept with it.'],
            ],
            'outbound' => [
                ['key' => 'interface', 'label' => 'Interface', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 40, 'default' => 'WAN', 'list' => 'pfc-ifaces'],
                ['key' => 'source', 'label' => 'Source', 'type' => 'text', 'required' => true, 'max' => 120, 'placeholder' => '10.0.70.0/24 or an alias'],
                ['key' => 'destination', 'label' => 'Destination', 'type' => 'text', 'max' => 120, 'default' => 'any'],
                ['key' => 'protocol', 'label' => 'Protocol', 'type' => 'select', 'options' => ['any' => 'Any', 'tcp' => 'TCP', 'udp' => 'UDP', 'tcp/udp' => 'TCP and UDP'], 'default' => 'any'],
                ['key' => 'destination_port', 'label' => 'Destination port', 'type' => 'text', 'max' => 40, 'when' => $ports],
                ['key' => 'target', 'label' => 'Translate to', 'type' => 'text', 'max' => 60, 'placeholder' => 'The interface\'s address', 'help' => 'Leave empty for the interface address, or an IP / virtual IP.'],
                ['key' => 'nonat', 'label' => 'Do not NAT (exclude this traffic)', 'type' => 'checkbox'],
                ['key' => 'staticnatport', 'label' => 'Static port', 'type' => 'checkbox'],
                ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 200, 'wide' => true, 'help' => 'Required. If outbound NAT is in automatic mode it is switched to hybrid, which keeps the automatic rules; undo switches it back.'],
            ],
            'onetoone' => [
                ['key' => 'interface', 'label' => 'Interface', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 40, 'default' => 'WAN', 'list' => 'pfc-ifaces'],
                ['key' => 'external', 'label' => 'External IP', 'type' => 'text', 'required' => true, 'max' => 60, 'placeholder' => '203.0.113.10'],
                ['key' => 'internal', 'label' => 'Internal IP', 'type' => 'text', 'required' => true, 'max' => 120, 'placeholder' => '192.168.1.10'],
                ['key' => 'destination', 'label' => 'Destination', 'type' => 'text', 'max' => 120, 'default' => 'any'],
                ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 200, 'wide' => true],
            ],
            'host_override' => [
                ['key' => 'host', 'label' => 'Host', 'type' => 'text', 'id' => true, 'max' => 63, 'placeholder' => 'nas', 'help' => 'Empty for the domain itself.'],
                ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 253, 'placeholder' => 'corp.local'],
                ['key' => 'ip', 'label' => 'IP addresses', 'type' => 'text', 'required' => true, 'max' => 400, 'placeholder' => '10.0.0.20', 'help' => 'Separate several with commas.'],
                ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'max' => 250],
            ],
            'domain_override' => [
                ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 253, 'placeholder' => 'ad.corp.local'],
                ['key' => 'ip', 'label' => 'DNS server', 'type' => 'text', 'required' => true, 'max' => 60, 'placeholder' => '10.0.0.5 or 10.0.0.5@5353'],
                ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'max' => 250],
            ],
            'static_route' => [
                ['key' => 'network', 'label' => 'Network', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 60, 'placeholder' => '10.50.0.0/16'],
                ['key' => 'gateway', 'label' => 'Gateway', 'type' => 'text', 'required' => true, 'max' => 64, 'placeholder' => 'BRANCH_GW', 'help' => 'The gateway\'s name on each firewall (System → Routing → Gateways).'],
                ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'max' => 250],
                ['key' => 'disabled', 'label' => 'Disabled', 'type' => 'checkbox'],
            ],
            'pfb_ipv4' => $pfbList(24, $ipActions, 'Deny_Inbound', false),
            'pfb_ipv6' => $pfbList(24, $ipActions, 'Deny_Inbound', false),
            'pfb_dnsbl' => $pfbList(64, ['unbound' => 'Unbound (block)', 'Disabled' => 'Disabled'], 'unbound', true),
            'dns_servers' => [
                ['key' => 'servers', 'label' => 'DNS servers', 'type' => 'textarea', 'required' => true, 'wide' => true, 'max' => 2000, 'placeholder' => "1.1.1.1\n9.9.9.9", 'help' => 'Replaces the firewall\'s DNS servers (System → General). One per line, up to 8.'],
                ['key' => 'allow_override', 'label' => 'Let the WAN\'s DHCP/PPP override them', 'type' => 'checkbox'],
            ],
            'ntp_servers' => [
                ['key' => 'servers', 'label' => 'Time servers', 'type' => 'textarea', 'required' => true, 'wide' => true, 'max' => 2000, 'placeholder' => "time.cloudflare.com\n0.pool.ntp.org", 'help' => 'Replaces the firewall\'s time servers. One per line.'],
            ],
            'syslog_remote' => [
                ['key' => 'servers', 'label' => 'Remote log servers', 'type' => 'textarea', 'wide' => true, 'max' => 1000, 'placeholder' => '10.0.0.99:514', 'help' => 'Up to 3, one per line (host or IP, optionally :port). Sends all logs. Leave empty to turn remote logging off.'],
                ['key' => 'ipproto', 'label' => 'IP version', 'type' => 'select', 'options' => ['ipv4' => 'IPv4', 'ipv6' => 'IPv6'], 'default' => 'ipv4'],
            ],
            'timezone' => [
                ['key' => 'timezone', 'label' => 'Time zone', 'type' => 'select', 'options' => array_combine(timezone_identifiers_list(), timezone_identifiers_list()), 'default' => 'America/Los_Angeles'],
            ],
            'tunable' => [
                ['key' => 'tunable', 'label' => 'Tunable', 'type' => 'text', 'id' => true, 'required' => true, 'max' => 120, 'placeholder' => 'net.inet.tcp.tso'],
                ['key' => 'value', 'label' => 'Value', 'type' => 'text', 'required' => true, 'max' => 200, 'placeholder' => '0'],
                ['key' => 'descr', 'label' => 'Description', 'type' => 'text', 'max' => 250],
            ],
        ];
    }

    /**
     * @return array{0: array, 1: array<int, string>} [clean items, errors]
     */
    public static function normalize(array $input): array
    {
        $specs = self::fields();
        $items = [];
        $errors = [];
        foreach (array_values($input) as $n => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $no = $n + 1;
            $kind = (string) ($raw['kind'] ?? '');
            if (!isset($specs[$kind])) {
                $errors[] = "Change {$no}: unknown kind of change.";
                continue;
            }
            $remove = ($raw['mode'] ?? 'present') === 'remove' && !in_array($kind, self::SETTINGS, true);
            $item = ['kind' => $kind, 'mode' => $remove ? 'remove' : 'present'];
            foreach ($specs[$kind] as $f) {
                if ($remove && empty($f['id'])) {
                    continue;
                }
                $item[$f['key']] = self::value($f, $raw[$f['key']] ?? null);
                $empty = $item[$f['key']] === '' || $item[$f['key']] === [] || $item[$f['key']] === null;
                if (!empty($f['required']) && $empty) {
                    $errors[] = "Change {$no} (" . self::KINDS[$kind] . "): {$f['label']} is needed.";
                }
            }
            $errors = array_merge($errors, self::extraChecks($item, $no));
            $item['label'] = self::label($item);
            $items[] = $item;
        }
        if (!$items && !$errors) {
            $errors[] = 'Add at least one change.';
        }
        if (count($items) > self::MAX_ITEMS) {
            $errors[] = 'A deployment can have at most ' . self::MAX_ITEMS . ' changes.';
        }
        return [$items, array_values(array_unique($errors))];
    }

    private static function value(array $f, $v)
    {
        switch ($f['type']) {
            case 'checkbox':
                return filter_var($v, FILTER_VALIDATE_BOOLEAN);
            case 'number':
                return max($f['min'] ?? 0, min($f['max'] ?? PHP_INT_MAX, (int) ($v ?? $f['default'] ?? 0)));
            case 'select':
                $v = (string) ($v ?? '');
                return array_key_exists($v, $f['options']) ? $v : (string) ($f['default'] ?? array_key_first($f['options']));
            case 'rows':
                $rows = [];
                foreach (array_slice(is_array($v) ? $v : [], 0, 50) as $row) {
                    $url = mb_substr(trim((string) ($row['url'] ?? '')), 0, 1000);
                    $header = mb_substr(trim((string) ($row['header'] ?? '')), 0, 60);
                    if ($url === '' && $header === '') {
                        continue;
                    }
                    $rows[] = ['format' => array_key_exists($row['format'] ?? '', $f['formats']) ? $row['format'] : 'auto',
                        'state' => in_array($row['state'] ?? '', ['Enabled', 'Disabled', 'Hold', 'Flex'], true) ? $row['state'] : 'Enabled', 'url' => $url, 'header' => $header];
                }
                return $rows;
            case 'ranges':
                $ranges = [];
                foreach (array_slice(is_array($v) ? $v : [], 0, 20) as $r) {
                    $days = array_values(array_unique(array_filter(array_map('intval', (array) ($r['days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
                    sort($days);
                    $ranges[] = ['days' => $days, 'start' => mb_substr((string) ($r['start'] ?? ''), 0, 5), 'stop' => mb_substr((string) ($r['stop'] ?? ''), 0, 5), 'descr' => mb_substr(trim((string) ($r['descr'] ?? '')), 0, 100)];
                }
                return $ranges;
            case 'textarea':
                return mb_substr(str_replace("\r", '', is_array($v) ? implode("\n", $v) : (string) ($v ?? '')), 0, $f['max'] ?? 10000);
            default:
                return mb_substr(trim((string) ($v ?? $f['default'] ?? '')), 0, $f['max'] ?? 250);
        }
    }

    private static function extraChecks(array $i, int $no): array
    {
        $e = [];
        $k = self::KINDS[$i['kind']];
        if (isset($i['name']) && in_array($i['kind'], ['alias', 'schedule', 'pfb_ipv4', 'pfb_ipv6', 'pfb_dnsbl'], true) && $i['name'] !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $i['name'])) {
            $e[] = "Change {$no} ({$k}): the name can only use letters, numbers and underscores.";
        }
        if ($i['mode'] === 'remove') {
            return $e;
        }
        if (in_array($i['kind'], ['pfb_ipv4', 'pfb_ipv6', 'pfb_dnsbl'], true)) {
            if (!$i['rows'] && trim($i['custom']) === '') {
                $e[] = "Change {$no} ({$k}): add at least one source or some custom entries.";
            }
            foreach ($i['rows'] as $r => $row) {
                if ($row['url'] === '' || !preg_match('/^[A-Za-z0-9_]+$/', $row['header'])) {
                    $e[] = "Change {$no} ({$k}), source " . ($r + 1) . ': both the source and a header (letters, numbers, underscores) are needed.';
                }
            }
        }
        if ($i['kind'] === 'schedule') {
            if (!$i['ranges']) {
                $e[] = "Change {$no} (Schedule): add at least one time range.";
            }
            foreach ($i['ranges'] as $r => $range) {
                if (!$range['days'] || !preg_match('/^\d{1,2}:\d{2}$/', $range['start']) || !preg_match('/^\d{1,2}:\d{2}$/', $range['stop'])) {
                    $e[] = "Change {$no} (Schedule), time range " . ($r + 1) . ': pick days and both times.';
                }
            }
        }
        if ($i['kind'] === 'syslog_remote' && count(array_filter(preg_split('/\s+/', trim($i['servers'])))) > 3) {
            $e[] = "Change {$no} (Remote logging): at most 3 servers.";
        }
        return $e;
    }

    public static function label(array $i): string
    {
        $rm = ($i['mode'] ?? 'present') === 'remove' ? 'Remove ' : '';
        $label = match ($i['kind']) {
            'alias' => "alias {$i['name']}" . ($rm ? '' : " ({$i['type']}, " . count(preg_split('/[\s,]+/', trim($i['entries'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)) . ' entries)'),
            'rule' => ($rm ? 'rule' : ucfirst($i['action']) . ' rule') . ' on ' . ($i['interface'] === '@internal' ? 'every internal interface' : $i['interface']) . ": {$i['descr']}",
            'floating' => ($rm ? 'floating rule' : ucfirst($i['action']) . " floating rule on {$i['interface']}") . ": {$i['descr']}",
            'nat' => $rm ? "port forward on {$i['interface']}: {$i['descr']}" : "Port forward on {$i['interface']} {$i['destination_port']} → {$i['target']}" . ($i['local_port'] !== '' ? ":{$i['local_port']}" : '') . ": {$i['descr']}",
            'outbound' => ($rm ? 'outbound NAT rule' : 'Outbound NAT on ' . $i['interface'] . ' for ' . $i['source']) . ": {$i['descr']}",
            'onetoone' => ($rm ? '1:1 NAT' : "1:1 NAT {$i['external']} ↔ {$i['internal']}") . ": {$i['descr']}",
            'schedule' => "schedule {$i['name']}",
            'host_override' => 'DNS host override ' . ltrim(($i['host'] ?? '') . '.' . $i['domain'], '.') . ($rm ? '' : " → {$i['ip']}"),
            'domain_override' => "DNS domain override {$i['domain']}" . ($rm ? '' : " → {$i['ip']}"),
            'static_route' => "static route {$i['network']}" . ($rm ? '' : " via {$i['gateway']}"),
            'tunable' => "tunable {$i['tunable']}" . ($rm ? '' : " = {$i['value']}"),
            'pfb_ipv4', 'pfb_ipv6' => self::KINDS[$i['kind']] . " {$i['name']}" . ($rm ? '' : ' (' . str_replace('_', ' ', $i['action']) . ')'),
            'pfb_dnsbl' => "pfBlockerNG DNSBL group {$i['name']}",
            'dns_servers' => 'DNS servers: ' . implode(', ', preg_split('/\s+/', trim($i['servers']), -1, PREG_SPLIT_NO_EMPTY)),
            'ntp_servers' => 'Time servers: ' . implode(', ', preg_split('/\s+/', trim($i['servers']), -1, PREG_SPLIT_NO_EMPTY)),
            'syslog_remote' => trim($i['servers']) === '' ? 'Remote logging off' : 'Remote logging to ' . implode(', ', preg_split('/\s+/', trim($i['servers']), -1, PREG_SPLIT_NO_EMPTY)),
            'timezone' => "Time zone {$i['timezone']}",
            default => 'Change',
        };
        return $rm ? $rm . $label : (str_starts_with($label, 'pfBlockerNG') ? $label : ucfirst($label));
    }

    /** Packages the deployment needs, for the pre-check summary. */
    public static function packages(array $items): array
    {
        $need = [];
        foreach ($items as $i) {
            if (isset(self::PACKAGES[$i['kind'] ?? '']) && ($i['mode'] ?? 'present') !== 'remove') {
                $need[self::PACKAGES[$i['kind']]] = true;
            }
        }
        return array_keys($need);
    }
}
