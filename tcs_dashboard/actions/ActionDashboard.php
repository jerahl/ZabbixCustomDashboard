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
        ];

        if ($hostid !== '') {
            $boot['host']        = $this->collectHost($hostid);
            $boot['items']       = $this->collectItems($hostid);
            $boot['systemInfo']  = $this->collectSystemInfo($hostid);
            $boot['networkInfo'] = $this->collectNetworkInfo($hostid);
            $boot['events']      = $this->collectEvents($hostid);
            $boot['alerts']      = $this->collectAlertsSummary($hostid);
            $boot['wiredPorts']  = $this->collectWiredPorts($hostid);

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

        // 2. Find the fleet host (one with the XIQ-by-API template linked).
        $fleet_hosts = API::Host()->get([
            'output'                => ['hostid'],
            'selectParentTemplates' => ['name'],
            'filter'                => []
        ]) ?: [];
        $fleet_hostid = null;
        foreach ($fleet_hosts as $fh) {
            foreach ($fh['parentTemplates'] ?? [] as $t) {
                if (($t['name'] ?? '') === 'Extreme XIQ APs by API') {
                    $fleet_hostid = $fh['hostid'];
                    break 2;
                }
            }
        }
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
        // Stub. Populate from net.if.* items if/when you discover them.
        return [];
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
