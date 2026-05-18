<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\TcsDashboard\Lib\PFClient;

/**
 * GET zabbix.php?action=tcs.dashboard.view[&hostid=NNN]
 *
 * Collects an initial snapshot of host state from the Zabbix API and hands it
 * to the view as $data['boot']. The view inlines this as window.ZBX_BOOT so
 * the React app can render immediately on first paint without a second
 * round-trip. Live updates come from tcs.dashboard.data (JSON).
 */
class ActionDashboard extends ActionBase {

    protected function checkInput(): bool {
        $fields = [
            'hostid' => 'string'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $ret;
    }

    protected function doAction(): void {
        $hostid = $this->getInput('hostid', '');

        $boot = [
            'host'        => null,
            'items'       => new \stdClass(),
            'systemInfo'  => [],
            'networkInfo' => [],
            'events'      => [],
            'alerts'      => [
                'associationFailures' => 0,
                'authFailures'        => 0,
                'networkIssues'       => 0,
                'packetLoss'          => 0,
                'totalClients'        => 0,
                'activeClients'       => 0,
            ],
            // PacketFence is a separate system — populate from your PF API in
            // collectPacketFence() below, or leave empty to let the UI render
            // the empty-state.
            'pfClients'   => [],
            'pfAuthFails' => [],
            'wiredPorts'  => [],
            'ssids'       => [],
            // AP Navigator: every wireless host grouped by school. Always
            // collected (independent of $hostid) so the left rail is
            // populated even when the page is opened without a hostid.
            'apSites'     => $this->collectApSites()
        ];

        if ($hostid !== '') {
            $boot['host']        = $this->collectHost($hostid);
            $boot['items']       = $this->collectItems($hostid);
            $boot['systemInfo']  = $this->collectSystemInfo($hostid);
            $boot['networkInfo'] = $this->collectNetworkInfo($hostid);
            $boot['events']      = $this->collectEvents($hostid);
            $boot['alerts']      = $this->collectAlertsSummary($hostid);
            $boot['wiredPorts']  = $this->collectWiredPorts($hostid);
            $boot['ssids']       = $this->collectSsidList($hostid);

            // Fold per-AP fields from the XIQ fleet host into the host
            // record so device card / page header have clients/location/
            // model/connected without a second backend round trip.
            if ($boot['host']) {
                $fleet = $this->resolveXiqFleetFields($hostid, [
                    'clients', 'building', 'floor', 'location', 'model',
                    'connected', 'configmismatch', 'xiqid', 'mac', 'policy'
                ]);
                $boot['host']['clients']        = (int) ($fleet['clients'] ?? 0);
                $boot['host']['site']           = $fleet['building'] ?? ($boot['host']['site'] ?? '');
                $boot['host']['floor']          = $fleet['floor']    ?? ($boot['host']['floor'] ?? '');
                $boot['host']['location']       = $fleet['location'] ?? '';
                $boot['host']['model']          = $fleet['model']    ?? ($boot['host']['model'] ?? '');
                $boot['host']['xiqConnected']   = $fleet['connected']      !== null ? (int) $fleet['connected']      : null;
                $boot['host']['configMismatch'] = $fleet['configmismatch'] !== null ? (int) $fleet['configmismatch'] : null;
                $boot['host']['xiqId']          = $fleet['xiqid']    ?? '';
                $boot['host']['mac']            = $fleet['mac']      ?? '';
                $boot['host']['policy']         = $fleet['policy']   ?? '';
            }

            [$pfClients, $pfAuthFails] = $this->collectPacketFence($hostid, $boot['host']);
            $boot['pfClients']   = $pfClients;
            $boot['pfAuthFails'] = $pfAuthFails;

            // Fold PF client counts into the alerts summary. activeClients is
            // anything PF currently has in a non-rejected/non-quarantined
            // posture; tweak the predicate once posture taxonomy is firm.
            $boot['alerts']['totalClients']  = count($pfClients);
            $boot['alerts']['activeClients'] = count(array_filter(
                $pfClients,
                fn($c) => !in_array(($c['posture'] ?? ''), ['rejected', 'quarantined', 'non-compliant'], true)
            ));
            $boot['alerts']['authFailures'] += count($pfAuthFails);
        }

        $data = [
            'title' => _('TCS Dashboard'),
            'boot'  => $boot
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('TCS Dashboard'));
        $this->setResponse($response);
    }

    /* --------------------------------------------------------------------- */
    /* Data collectors — each returns the shape the React frontend expects.  */
    /* Item keys below are PLACEHOLDERS. Swap them for the keys actually     */
    /* defined on your Extreme AP / PacketFence / ICMP templates.            */
    /* --------------------------------------------------------------------- */

    private function collectHost(string $hostid): ?array {
        $hosts = API::Host()->get([
            'output'                  => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'proxy_hostid'],
            'selectInterfaces'        => ['ip', 'main', 'type'],
            'selectParentTemplates'   => ['name'],
            'selectHostGroups'        => ['name'],
            'hostids'                 => [$hostid]
        ]);

        if (!$hosts) {
            return null;
        }

        $h = $hosts[0];

        $primary_ip = '';
        foreach ($h['interfaces'] ?? [] as $iface) {
            if ((int) ($iface['main'] ?? 0) === 1) {
                $primary_ip = $iface['ip'];
                break;
            }
        }

        return [
            'hostid'       => $h['hostid'],
            'host'         => $h['host'],
            'visible_name' => $h['name'],
            'ip'           => $primary_ip,
            'status'       => ((int) $h['status'] === 0) ? 'monitored' : 'not monitored',
            'available'    => $this->resolveAvailability($hostid),
            'maintenance'  => (int) ($h['maintenance_status'] ?? 0),
            'proxy'        => $this->resolveProxy($h['proxy_hostid'] ?? '0'),
            'templates'    => array_column($h['parentTemplates'] ?? [], 'name'),
            'groups'       => array_column($h['hostgroups'] ?? [], 'name'),
            'uptime'       => $this->resolveUptime($hostid),
            'lastSeen'     => 'now'
        ];
    }

    private function collectItems(string $hostid): array {
        // Logical metric → matcher. Match modes:
        //   ['exact',  'system.cpu.util']             — key matches verbatim
        //   ['prefix', 'extremeap.channel[']          — any key starting with prefix
        //   ['suffix', 'extremeap.channel[', '.12]']  — prefix AND suffix
        //
        // These map to items defined in the "Extreme AP via SNMPv2c" template
        // (per-AP SNMP host, auto-created by the XIQ fleet template's host
        // prototype) plus the standard ICMP Ping template.
        //
        // Radio convention (AP305C): ifIndex 12 = wifi0 (2.4 GHz),
        // ifIndex 13 = wifi1 (5 GHz). Override by changing the suffix below
        // if you're running tri-radio hardware.
        $key_map = [
            'cpu'           => ['exact',  'extremeap.cpu.util'],
            'memory'        => ['exact',  'extremeap.mem.util'],
            'firmware'      => ['exact',  'extremeap.firmware.0'],
            'serial'        => ['exact',  'extremeap.serial.0'],
            'uptime'        => ['exact',  'system.uptime'],
            'pingUp'        => ['exact',  'icmpping'],
            'pktLoss'       => ['exact',  'icmppingloss'],
            'latency'       => ['exact',  'icmppingsec'],
            'uplinkIn'      => ['exact',  'net.if.in[ifHCInOctets.10]'],
            'uplinkOut'     => ['exact',  'net.if.out[ifHCOutOctets.10]'],
            'uplinkStatus'  => ['exact',  'net.if.status[ifOperStatus.10]'],
            'uplinkSpeed'   => ['exact',  'net.if.speed[ifSpeed.10]'],
            'noise24'       => ['exact',  'extremeap.noise.wifi0'],
            'noise5'        => ['exact',  'extremeap.noise.wifi1'],
            // Per-radio LLD items — ifIndex baked into the key.
            'channel24'     => ['exact',  'extremeap.channel[12]'],
            'channel5'      => ['exact',  'extremeap.channel[13]'],
            'txpower24'     => ['exact',  'extremeap.txpower[12]'],
            'txpower5'      => ['exact',  'extremeap.txpower[13]'],
            'radioRx24'     => ['exact',  'extremeap.radio.rxbytes[12]'],
            'radioTx24'     => ['exact',  'extremeap.radio.txbytes[12]'],
            'radioRx5'      => ['exact',  'extremeap.radio.rxbytes[13]'],
            'radioTx5'      => ['exact',  'extremeap.radio.txbytes[13]']
        ];

        // Fetch every item on the host once; match in PHP. Cheaper than one
        // item.get per logical metric, and the only way to handle the LLD
        // prefix matches above.
        $items = API::Item()->get([
            'output'   => ['itemid', 'key_', 'lastvalue', 'prevvalue', 'units', 'value_type'],
            'hostids'  => [$hostid],
            'webitems' => true
        ]);

        $now = time();
        $window_from = $now - 24 * 3600; // last 24h for sparklines
        $out = [];

        foreach ($key_map as $logical => $matcher) {
            $found = $this->matchItem($items, $matcher);
            if (!$found) {
                $out[$logical] = [
                    'value'   => null,
                    'unit'    => '',
                    'history' => [],
                    'missing' => true,
                    'key'     => is_array($matcher) ? implode('', array_slice($matcher, 1)) : (string) $matcher
                ];
                continue;
            }

            $it = $found;
            $value_type = (int) $it['value_type'];

            // history.get value_type: 0=float, 1=str, 2=log, 3=uint, 4=text.
            // Only float (0) and uint (3) make sense for sparklines.
            $history = [];
            if ($value_type === 0 || $value_type === 3) {
                $rows = API::History()->get([
                    'output'    => 'extend',
                    'history'   => $value_type,
                    'itemids'   => [$it['itemid']],
                    'time_from' => $window_from,
                    'sortfield' => 'clock',
                    'sortorder' => 'ASC',
                    'limit'     => 48
                ]);
                $history = array_map(static fn($r) => (float) $r['value'], $rows);
            }

            $out[$logical] = [
                'value'   => is_numeric($it['lastvalue']) ? (float) $it['lastvalue'] : $it['lastvalue'],
                'prev'    => is_numeric($it['prevvalue']) ? (float) $it['prevvalue'] : null,
                'unit'    => $it['units'],
                'history' => $history,
                'missing' => false,
                'key'     => $it['key_']
            ];
        }

        return $out;
    }

    /**
     * Match one item out of a host's item list given a matcher tuple from
     * the $key_map in collectItems(). Returns the first match, or null.
     */
    private function matchItem(array $items, array $matcher): ?array {
        [$mode, $a] = [$matcher[0], $matcher[1]];
        $b = $matcher[2] ?? '';
        foreach ($items as $it) {
            $k = $it['key_'];
            $hit = match ($mode) {
                'exact'  => $k === $a,
                'prefix' => str_starts_with($k, $a),
                'suffix' => str_starts_with($k, $a) && str_ends_with($k, $b),
                default  => false
            };
            if ($hit) return $it;
        }
        return null;
    }

    private function collectSystemInfo(string $hostid): array {
        // Each row: [Label, Value, Source]. Source is "zbx" or "ext".
        $hosts = API::Host()->get([
            'output'         => ['name', 'host'],
            'selectInventory'=> ['serialno_a', 'model', 'os', 'tag', 'name', 'type'],
            'hostids'        => [$hostid]
        ]);

        if (!$hosts) {
            return [];
        }

        $h   = $hosts[0];
        $inv = $h['inventory'] ?: [];

        // Pull live SNMP values that override stale inventory data.
        $live = $this->lastValuesByKey($hostid, [
            'extremeap.serial.0',
            'extremeap.firmware.0'
        ]);

        // Pull fleet-side fields if this AP is auto-created by the XIQ
        // template — the {$XIQ_SERIAL} macro tells us which serial to look
        // up on the fleet host.
        $fleet = $this->resolveXiqFleetFields($hostid, ['model', 'building', 'floor', 'location', 'adminstate']);

        return [
            ['Host Name',     $h['host'] ?? '—',                                              'zbx'],
            ['Visible Name',  $h['name'] ?? '—',                                              'zbx'],
            ['Device Model',  $fleet['model']  ?? ($inv['model'] ?? '—'),                     $fleet['model']  ? 'ext' : 'zbx'],
            ['Serial Number', $live['extremeap.serial.0']    ?? ($inv['serialno_a'] ?? '—'),  $live['extremeap.serial.0']    ? 'zbx' : 'zbx'],
            ['Firmware',      $live['extremeap.firmware.0']  ?? ($inv['os']         ?? '—'),  $live['extremeap.firmware.0']  ? 'zbx' : 'zbx'],
            ['Building',      $fleet['building'] ?? '—',                                       'ext'],
            ['Floor',         $fleet['floor']    ?? '—',                                       'ext'],
            ['Admin state',   $fleet['adminstate'] ?? '—',                                     'ext']
        ];
    }

    private function collectNetworkInfo(string $hostid): array {
        $ifaces = API::HostInterface()->get([
            'output'  => ['ip', 'dns', 'main', 'type'],
            'hostids' => [$hostid]
        ]);

        $primary = null;
        foreach ($ifaces as $i) {
            if ((int) $i['main'] === 1) { $primary = $i; break; }
        }

        $live = $this->lastValuesByKey($hostid, [
            'net.if.status[ifOperStatus.10]',
            'net.if.speed[ifSpeed.10]'
        ]);

        $oper_map = [1 => 'up', 2 => 'down', 3 => 'testing', 4 => 'unknown', 5 => 'dormant', 6 => 'notPresent', 7 => 'lowerLayerDown'];
        $oper_raw = $live['net.if.status[ifOperStatus.10]'] ?? null;
        $oper     = $oper_raw !== null ? ($oper_map[(int) $oper_raw] ?? (string) $oper_raw) : '—';

        $speed_raw = $live['net.if.speed[ifSpeed.10]'] ?? null;
        $speed     = $speed_raw !== null ? $this->formatBps((float) $speed_raw) : '—';

        $fleet = $this->resolveXiqFleetFields($hostid, ['mac', 'ip', 'policy']);

        return [
            ['Mgt0 IPv4',     $primary['ip']  ?? '—',     'zbx'],
            ['DNS Name',      $primary['dns'] ?? '—',     'zbx'],
            ['MAC Address',   $fleet['mac']   ?? '—',     'ext'],
            ['Uplink eth0',   "$oper · $speed",           'zbx'],
            ['Network Policy', $fleet['policy'] ?? '—',   'ext']
        ];
    }

    /**
     * Get last values for a set of exact item keys on a single host.
     * Returns ['key' => lastvalue or null].
     */
    private function lastValuesByKey(string $hostid, array $keys): array {
        if (!$keys) return [];
        $items = API::Item()->get([
            'output'  => ['key_', 'lastvalue'],
            'hostids' => [$hostid],
            'filter'  => ['key_' => $keys]
        ]) ?: [];
        $out = array_fill_keys($keys, null);
        foreach ($items as $it) {
            $out[$it['key_']] = $it['lastvalue'];
        }
        return $out;
    }

    /** Format bits/sec as a short human string (1.0 Gbps / 100 Mbps / …). */
    private function formatBps(float $bps): string {
        if ($bps >= 1e9) return number_format($bps / 1e9, 1).' Gbps';
        if ($bps >= 1e6) return number_format($bps / 1e6, 0).' Mbps';
        if ($bps >= 1e3) return number_format($bps / 1e3, 0).' kbps';
        return number_format($bps, 0).' bps';
    }

    /**
     * Cross-join a per-AP SNMP host into the XIQ fleet template. The host
     * macro {$XIQ_SERIAL} points to the serial; we look up
     * xiq.ap.<field>[<serial>] items on the fleet host (any host with the
     * 'Extreme XIQ APs by API' template linked).
     *
     * @param string[] $fields  Fleet fields to pull, e.g. ['model','building','floor','clients']
     * @return array<string, string|null>
     */
    private function resolveXiqFleetFields(string $hostid, array $fields): array {
        $out = array_fill_keys($fields, null);

        // 1. Read the {$XIQ_SERIAL} macro from the per-AP host.
        $macros = API::UserMacro()->get([
            'output'  => ['macro', 'value'],
            'hostids' => [$hostid],
            'filter'  => ['macro' => ['{$XIQ_SERIAL}']]
        ]) ?: [];
        $serial = '';
        foreach ($macros as $m) {
            if ($m['macro'] === '{$XIQ_SERIAL}') { $serial = (string) $m['value']; break; }
        }
        if ($serial === '') return $out;

        // 2. Find the fleet host (cached for the duration of the request).
        $fleet_hostid = $this->resolveXiqFleetHostId();
        if ($fleet_hostid === null) return $out;

        // 3. Pull the specific xiq.ap.<field>[<serial>] items on the fleet host.
        // Use concatenation — "xiq.ap.$f[$serial]" parses $f[$serial] as
        // array-access on the field-name string, which trips an
        // "Uninitialized string offset" notice for serials that look numeric.
        $keys = array_map(fn($f) => 'xiq.ap.'.$f.'['.$serial.']', $fields);
        $items = API::Item()->get([
            'output'  => ['key_', 'lastvalue'],
            'hostids' => [$fleet_hostid],
            'filter'  => ['key_' => $keys]
        ]) ?: [];
        foreach ($items as $it) {
            if (preg_match('/^xiq\.ap\.([^[]+)\[/', $it['key_'], $m) && isset($out[$m[1]])) {
                $out[$m[1]] = (string) $it['lastvalue'];
            }
        }
        return $out;
    }

    private function collectEvents(string $hostid): array {
        // Zabbix 7.2: sortfield must be 'eventid'; select_acknowledges
        // dropped in favour of selectAcknowledges (camelCase).
        $events = API::Event()->get([
            'output'             => ['eventid', 'name', 'severity', 'clock', 'value'],
            'hostids'            => [$hostid],
            'sortfield'          => ['eventid'],
            'sortorder'          => 'DESC',
            'limit'              => 25,
            'selectAcknowledges' => 'count'
        ]) ?: [];

        $sev_label = [
            0 => 'info', 1 => 'info', 2 => 'warning',
            3 => 'warning', 4 => 'error', 5 => 'error'
        ];

        $out = [];
        foreach ($events as $e) {
            $out[] = [
                'ts'       => date('H:i:s', (int) $e['clock']),
                'severity' => $sev_label[(int) $e['severity']] ?? 'info',
                'source'   => 'Zabbix',
                'obj'      => '',
                'msg'      => $e['name']
            ];
        }
        return $out;
    }

    private function collectAlertsSummary(string $hostid): array {
        // Currently-open problems on this host. Zabbix returns resolved
        // problems too unless we filter on r_eventid; do it in PHP so the
        // call shape matches what other actions use.
        $problems = API::Problem()->get([
            'output'  => ['eventid', 'name', 'severity', 'r_eventid'],
            'hostids' => [$hostid],
            'recent'  => false
        ]) ?: [];
        $problems = array_filter(
            $problems,
            fn($p) => empty($p['r_eventid']) || (int) $p['r_eventid'] === 0
        );

        // Bucket each open problem by name keyword. A single problem can
        // only fall into one bucket (first match wins) so the counts on the
        // Overview tab line up with the trigger list.
        $assoc = 0;
        $auth  = 0;
        $loss  = 0;
        $other = 0;
        foreach ($problems as $p) {
            $name = strtolower((string) ($p['name'] ?? ''));
            if (str_contains($name, 'associat')) {
                $assoc++;
            }
            elseif (str_contains($name, 'auth') || str_contains($name, 'radius') || str_contains($name, 'eap')) {
                $auth++;
            }
            elseif (str_contains($name, 'packet loss') || str_contains($name, 'icmp') || str_contains($name, 'unreachable')) {
                $loss++;
            }
            elseif ((int) $p['severity'] >= 3) {
                $other++;
            }
        }

        // 24h packet-loss event count: events that ever fired from a packet
        // loss trigger in the window, resolved or not. Lets the Overview
        // tile show a meaningful number when current loss is 0%.
        $loss_events_24h = 0;
        $events = API::Event()->get([
            'output'    => ['name', 'value'],
            'hostids'   => [$hostid],
            'time_from' => time() - 86400,
            'value'     => 1, // PROBLEM events only
            'limit'     => 200
        ]) ?: [];
        foreach ($events as $e) {
            $n = strtolower((string) ($e['name'] ?? ''));
            if (str_contains($n, 'packet loss') || str_contains($n, 'icmp') || str_contains($n, 'unreachable')) {
                $loss_events_24h++;
            }
        }

        return [
            'associationFailures' => $assoc,
            'authFailures'        => $auth,
            'networkIssues'       => $assoc + $auth + $loss + $other,
            'packetLoss'          => $loss_events_24h,
            'totalClients'        => 0, // populated from PacketFence in caller if wired
            'activeClients'       => 0
        ];
    }

    private function collectWiredPorts(string $hostid): array {
        // For an Extreme AP this is just eth0 (ifIndex 10). Pull the four
        // uplink items in one Item.get; if none exist this isn't an
        // Extreme-AP-templated host and we return empty.
        $live = $this->lastValuesByKey($hostid, [
            'net.if.status[ifOperStatus.10]',
            'net.if.speed[ifSpeed.10]',
            'net.if.in[ifHCInOctets.10]',
            'net.if.out[ifHCOutOctets.10]'
        ]);
        if (!array_filter($live, fn($v) => $v !== null)) {
            return [];
        }

        $oper_raw = $live['net.if.status[ifOperStatus.10]'] ?? null;
        $state = match ((int) ($oper_raw ?? 0)) {
            1 => 'ok',
            2 => 'down',
            default => 'warn'
        };

        $speed_raw = $live['net.if.speed[ifSpeed.10]'] ?? null;
        $speed_str = $speed_raw !== null ? $this->formatBps((float) $speed_raw) : '—';

        $in_bps  = $live['net.if.in[ifHCInOctets.10]']  ?? null;
        $out_bps = $live['net.if.out[ifHCOutOctets.10]'] ?? null;
        $fmt = fn($v) => $v !== null ? $this->formatBps((float) $v) : '—';

        return [[
            'name'     => 'eth0',
            'state'    => $state,
            'speed'    => $speed_str,
            'duplex'   => '—',  // not exposed by the Extreme AP SNMP template
            'in'       => $fmt($in_bps),
            'out'      => $fmt($out_bps),
            'err'      => '—',  // add when net.if.errors[ifInErrors.10] is templated
            'neighbor' => ''    // LLDP neighbor not collected
        ]];
    }

    /**
     * Pull the per-SSID LLD items defined by "Extreme AP via SNMPv2c" and
     * fold them into one row per SSID for the Wireless tab.
     *
     * Item keys (per the template):
     *   extremeap.ssid.name[<ifIndex>]    — SSID broadcast name
     *   extremeap.ssid.ifname[<ifIndex>]  — subinterface name e.g. wifi0.1
     *   extremeap.ssid.rxbytes[<ifIndex>] — RX bytes/sec (Change/sec preprocessing)
     *   extremeap.ssid.txbytes[<ifIndex>] — TX bytes/sec
     *
     * Band is derived from the ifname prefix: wifi0.* = 2.4 GHz, wifi1.* = 5 GHz.
     * VLAN, auth, encryption, role, and per-SSID client counts are NOT in
     * SNMP and would need an XIQ d360 API call — left null here so the UI
     * renders an em-dash.
     */
    private function collectSsidList(string $hostid): array {
        $items = API::Item()->get([
            'output'      => ['key_', 'lastvalue'],
            'hostids'     => [$hostid],
            'search'      => ['key_' => 'extremeap.ssid.'],
            'startSearch' => true
        ]) ?: [];

        $by_idx = [];
        foreach ($items as $it) {
            if (!preg_match('/^extremeap\.ssid\.(name|ifname|rxbytes|txbytes)\[(\d+)\]$/', $it['key_'], $m)) {
                continue;
            }
            $by_idx[$m[2]][$m[1]] = $it['lastvalue'];
        }

        // Derive band per radio from its current channel — the wifi0/wifi1
        // ifname prefix isn't a reliable band hint (this fleet runs dual-5 GHz
        // AP305Cs). Channels 1–14 = 2.4 GHz, ≥36 = 5 GHz.
        $radio_channels = $this->lastValuesByKey($hostid, [
            'extremeap.channel[12]',
            'extremeap.channel[13]'
        ]);
        $bandOf = function ($ch) {
            if ($ch === null || $ch === '') return null;
            $n = (int) $ch;
            if ($n >= 1 && $n <= 14) return '2.4 GHz';
            if ($n >= 36) return '5 GHz';
            return null;
        };
        $radio_band = [
            'wifi0' => $bandOf($radio_channels['extremeap.channel[12]'] ?? null),
            'wifi1' => $bandOf($radio_channels['extremeap.channel[13]'] ?? null)
        ];

        $out = [];
        foreach ($by_idx as $idx => $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') continue;
            $ifname = (string) ($row['ifname'] ?? '');
            $band = null;
            foreach ($radio_band as $radio => $b) {
                if ($b !== null && str_starts_with($ifname, $radio)) {
                    $band = $b;
                    break;
                }
            }

            $rx_bps = isset($row['rxbytes']) ? (float) $row['rxbytes'] * 8 : null;
            $tx_bps = isset($row['txbytes']) ? (float) $row['txbytes'] * 8 : null;

            $out[] = [
                'id'         => $idx,
                'name'       => $name,
                'ifname'     => $ifname,
                'band'       => $band ?? '—',
                'rxMbps'     => $rx_bps !== null ? round($rx_bps / 1e6, 2) : null,
                'txMbps'     => $tx_bps !== null ? round($tx_bps / 1e6, 2) : null,
                'vlan'       => null,
                'auth'       => null,
                'encryption' => null,
                'clients'    => null,
                'role'       => null
            ];
        }
        usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Enumerate every wireless AP under host groups named
     *   Site/Wireless/<school>/<floor>
     * and return them bucketed by school, ready for window.AP_SITES.
     *
     * Each AP record carries hostid, id (visible name), ip, model, floor,
     * status (ok/warn/down), problems count, and clients (from XIQ fleet
     * if available).
     */
    private function collectApSites(): array {
        $groups = API::HostGroup()->get([
            'output'      => ['groupid', 'name'],
            'search'      => ['name' => 'Site/Wireless/'],
            'startSearch' => true
        ]) ?: [];

        // Keep only "Site/Wireless/<school>/..." groups — startSearch is a
        // prefix match so this is mostly belt-and-braces.
        $valid_groupids = [];
        foreach ($groups as $g) {
            if (str_starts_with($g['name'], 'Site/Wireless/')) {
                $valid_groupids[] = $g['groupid'];
            }
        }
        if (!$valid_groupids) return [];

        // Hosts in any matching group. Pull the groups back per-host so we
        // can read the school/floor segments from the host's actual group
        // membership rather than guessing from the group list.
        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name'],
            'selectInterfaces' => ['ip', 'main'],
            'selectHostGroups' => ['groupid', 'name'],
            'groupids'         => $valid_groupids
        ]) ?: [];
        if (!$hosts) return [];

        $hostids = array_column($hosts, 'hostid');

        // Active-trigger count per host. Problem.get in Zabbix 7 doesn't
        // accept selectHosts, so route the per-host bridge through
        // Trigger.get instead — only_true=true + filter.value=1 gives the
        // currently-firing triggers, which is what "problems" means here.
        $prob_count = [];
        if ($hostids) {
            $triggers = API::Trigger()->get([
                'output'      => ['triggerid'],
                'hostids'     => $hostids,
                'selectHosts' => ['hostid'],
                'filter'      => ['value' => 1, 'status' => 0],
                'only_true'   => true,
                'monitored'   => true,
                'skipDependent' => true
            ]) ?: [];
            foreach ($triggers as $t) {
                foreach ($t['hosts'] ?? [] as $th) {
                    $hid = $th['hostid'];
                    $prob_count[$hid] = ($prob_count[$hid] ?? 0) + 1;
                }
            }
        }

        // Main-interface availability per host (Zabbix 6+: per-interface).
        $avail_map = [];
        if ($hostids) {
            $ifaces = API::HostInterface()->get([
                'output'  => ['hostid', 'available'],
                'hostids' => $hostids,
                'filter'  => ['main' => 1]
            ]) ?: [];
            foreach ($ifaces as $i) {
                $hid = $i['hostid'];
                $av  = (int) $i['available'];
                // If any main iface is up, treat host as up.
                if (!isset($avail_map[$hid]) || $av === 1) {
                    $avail_map[$hid] = $av;
                }
            }
        }

        // Bulk XIQ fleet lookup: gather every host's {$XIQ_SERIAL} macro in
        // one call, then one Item.get on the fleet host for all the
        // xiq.ap.clients[<serial>] + xiq.ap.model[<serial>] keys we need.
        $serial_by_host = [];
        if ($hostids) {
            $macros = API::UserMacro()->get([
                'output'  => ['hostid', 'macro', 'value'],
                'hostids' => $hostids,
                'filter'  => ['macro' => ['{$XIQ_SERIAL}']]
            ]) ?: [];
            foreach ($macros as $m) {
                if ($m['macro'] === '{$XIQ_SERIAL}' && !empty($m['value'])) {
                    $serial_by_host[$m['hostid']] = (string) $m['value'];
                }
            }
        }
        $fleet_by_serial = [];
        $fleet_hostid = $this->resolveXiqFleetHostId();
        if ($fleet_hostid !== null && $serial_by_host) {
            $wanted_keys = [];
            foreach ($serial_by_host as $serial) {
                $wanted_keys[] = 'xiq.ap.clients['.$serial.']';
                $wanted_keys[] = 'xiq.ap.model['.$serial.']';
            }
            $items = API::Item()->get([
                'output'  => ['key_', 'lastvalue'],
                'hostids' => [$fleet_hostid],
                'filter'  => ['key_' => $wanted_keys]
            ]) ?: [];
            foreach ($items as $it) {
                if (preg_match('/^xiq\.ap\.([^[]+)\[(.+)\]$/', $it['key_'], $m)) {
                    $fleet_by_serial[$m[2]][$m[1]] = (string) $it['lastvalue'];
                }
            }
        }

        // Build per-school buckets.
        $by_school = [];
        foreach ($hosts as $h) {
            // Pick this host's "Site/Wireless/<school>/<floor>" group(s).
            $school = '';
            $floor  = '';
            foreach ($h['hostgroups'] ?? [] as $hg) {
                $name = $hg['name'] ?? '';
                if (!str_starts_with($name, 'Site/Wireless/')) continue;
                $rest  = substr($name, strlen('Site/Wireless/'));
                $parts = explode('/', $rest);
                // Prefer the most-specific match (one that has both segments).
                if (count($parts) >= 1 && $parts[0] !== '' && $school === '') {
                    $school = $parts[0];
                }
                if (count($parts) >= 2 && $parts[1] !== '') {
                    $floor = $parts[1];
                }
            }
            if ($school === '') continue;

            $primary_ip = '';
            foreach ($h['interfaces'] ?? [] as $iface) {
                if ((int) ($iface['main'] ?? 0) === 1) {
                    $primary_ip = (string) ($iface['ip'] ?? '');
                    break;
                }
            }

            $avail = $avail_map[$h['hostid']] ?? 0;
            $prob  = $prob_count[$h['hostid']] ?? 0;
            $status = match (true) {
                $avail === 2          => 'down',
                $prob > 0             => 'warn',
                $avail === 1          => 'ok',
                default               => 'warn'
            };

            $serial  = $serial_by_host[$h['hostid']] ?? '';
            $fleet   = $serial !== '' ? ($fleet_by_serial[$serial] ?? []) : [];
            $clients = isset($fleet['clients']) ? (int) $fleet['clients'] : 0;
            $model   = $fleet['model'] ?? '';

            if (!isset($by_school[$school])) {
                $by_school[$school] = [
                    'id'       => $school,
                    'name'     => $school,
                    // Collapsed by default — the frontend expands the
                    // section containing the active AP at mount time.
                    'expanded' => false,
                    'problems' => 0,
                    'aps'      => []
                ];
            }
            $by_school[$school]['aps'][] = [
                'hostid'   => $h['hostid'],
                'id'       => $h['name'] ?: $h['host'],
                'ip'       => $primary_ip,
                'model'    => $model,
                'floor'    => $floor !== '' ? $floor : '—',
                'status'   => $status,
                'clients'  => $clients,
                'problems' => $prob,
                'serial'   => $serial
            ];
            $by_school[$school]['problems'] += $prob;
        }

        // Sort schools alphabetically; sort APs within each school by id.
        ksort($by_school);
        foreach ($by_school as &$s) {
            usort($s['aps'], fn($a, $b) => strnatcasecmp($a['id'], $b['id']));
        }
        unset($s);

        return array_values($by_school);
    }

    /**
     * Find the XIQ fleet host (the one with the 'Extreme XIQ APs by API'
     * template linked). Cached in a static so multiple calls within the
     * same request only hit the API once. Returns hostid or null.
     */
    private function resolveXiqFleetHostId(): ?string {
        static $cached = false;
        static $value  = null;
        if ($cached) return $value;
        $cached = true;

        $hosts = API::Host()->get([
            'output'                => ['hostid'],
            'selectParentTemplates' => ['name']
        ]) ?: [];
        foreach ($hosts as $h) {
            foreach ($h['parentTemplates'] ?? [] as $t) {
                if (($t['name'] ?? '') === 'Extreme XIQ APs by API') {
                    $value = (string) $h['hostid'];
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * Pull recent PacketFence clients + auth failures for the given host.
     * Returns [[clients], [authFails]] — empty arrays on any failure so the
     * UI renders its empty-state instead of breaking.
     *
     * Reads PF endpoint creds from host- or template-level user macros:
     *   {$PF.URL}        — base URL, e.g. https://pf.example/api
     *   {$PF.USER}       — API username
     *   {$PF.PASSWORD}   — API password (Secret text recommended)
     *   {$PF.VERIFY.SSL} — "0" to disable TLS verify; anything else verifies
     *
     * Device key passed to PF is the host's `host` field (typically the
     * switch hostname or AP MAC, which PF stores in locationlog.switch).
     */
    private function collectPacketFence(string $hostid, ?array $host): array {
        $macros = $this->resolvePfMacros($hostid);
        if ($macros === null) {
            return [[], []];
        }

        try {
            $pf = PFClient::fromMacros($macros);
            $deviceId = (string) ($host['host'] ?? '');
            if ($deviceId === '') return [[], []];

            return [
                $pf->clientsForNode($deviceId),
                $pf->authFailuresForNode($deviceId)
            ];
        }
        catch (\Throwable $e) {
            error_log('[tcs_dashboard] PFClient: '.$e->getMessage());
            return [[], []];
        }
    }

    /**
     * @return array{url:string,user:string,pass:string,verify_ssl:bool}|null
     */
    private function resolvePfMacros(string $hostid): ?array {
        $rows = API::UserMacro()->get([
            'output'  => ['macro', 'value'],
            'hostids' => [$hostid],
            'filter'  => ['macro' => ['{$PF.URL}', '{$PF.USER}', '{$PF.PASSWORD}', '{$PF.VERIFY.SSL}']]
        ]) ?: [];

        $bag = [];
        foreach ($rows as $r) {
            $bag[$r['macro']] = (string) $r['value'];
        }

        $url  = $bag['{$PF.URL}']  ?? '';
        $user = $bag['{$PF.USER}'] ?? '';
        $pass = $bag['{$PF.PASSWORD}'] ?? '';
        if ($url === '' || $user === '' || $pass === '') {
            return null;
        }

        return [
            'url'        => $url,
            'user'       => $user,
            'pass'       => $pass,
            'verify_ssl' => ($bag['{$PF.VERIFY.SSL}'] ?? '1') !== '0'
        ];
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                               */
    /* --------------------------------------------------------------------- */

    private function resolveAvailability(string $hostid): int {
        // Zabbix 6.0+: availability is per-interface. Treat the host as
        // "available" if at least one main interface is up.
        $ifaces = API::HostInterface()->get([
            'output'  => ['available'],
            'hostids' => [$hostid],
            'filter'  => ['main' => 1]
        ]);
        foreach ($ifaces as $i) {
            if ((int) $i['available'] === 1) return 1;
        }
        return 2;
    }

    private function resolveProxy(string $proxy_hostid): string {
        if ($proxy_hostid === '0' || $proxy_hostid === '') return '';
        $proxies = API::Proxy()->get([
            'output'   => ['host'],
            'proxyids' => [$proxy_hostid]
        ]);
        return $proxies[0]['host'] ?? '';
    }

    private function resolveUptime(string $hostid): int {
        $items = API::Item()->get([
            'output'  => ['lastvalue'],
            'hostids' => [$hostid],
            'filter'  => ['key_' => 'system.uptime']
        ]);
        return $items ? (int) $items[0]['lastvalue'] : 0;
    }
}
