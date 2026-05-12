<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;

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
            // $boot['pfClients']   = $this->collectPacketFence($hostid);
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
        //   ['exact',  'system.cpu.util']                — key matches verbatim
        //   ['prefix', 'xiq.ap.cpu.util[']               — any key starting with prefix
        //   ['suffix', 'xiq.ap.channel.util[', ',2.4G]'] — prefix AND suffix (band variants)
        //
        // Lifted XIQ template (jerahl/ZabbixExtremeIQ) discovers per-AP items
        // with {#SERIAL} baked into the key — e.g. xiq.ap.cpu.util[ABC123].
        // We can't filter on the literal LLD-prototype key, so we match by
        // prefix and pick the (sole) item that resolved on this host.
        $key_map = [
            'cpu'           => ['prefix', 'xiq.ap.cpu.util['],
            'memory'        => ['prefix', 'xiq.ap.mem.util['],
            'temp'          => ['prefix', 'xiq.ap.temp['],
            'poeDraw'       => ['prefix', 'xiq.ap.poe.draw['],
            'uplinkIn'      => ['prefix', 'net.if.in['],
            'uplinkOut'     => ['prefix', 'net.if.out['],
            'pktLoss'       => ['exact',  'icmppingloss'],
            'latency'       => ['exact',  'icmppingsec'],
            'noise24'       => ['suffix', 'xiq.ap.noise[',         ',2.4G]'],
            'noise5'        => ['suffix', 'xiq.ap.noise[',         ',5G]'],
            'channelUtil24' => ['suffix', 'xiq.ap.channel.util[',  ',2.4G]'],
            'channelUtil5'  => ['suffix', 'xiq.ap.channel.util[',  ',5G]']
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
        // Pull whatever inventory / item values you store. This is illustrative.
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

        return [
            ['Host Name',     $h['host'] ?? '—',                'zbx'],
            ['Visible Name',  $h['name'] ?? '—',                'zbx'],
            ['Device Model',  $inv['model']     ?? '—',         'ext'],
            ['Function',      $inv['type']      ?? '—',         'ext'],
            ['Serial Number', $inv['serialno_a']?? '—',         'ext'],
            ['OS / Firmware', $inv['os']        ?? '—',         'ext']
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

        return [
            ['Mgt0 IPv4',   $primary['ip']  ?? '—', 'zbx'],
            ['DNS Name',    $primary['dns'] ?? '—', 'zbx']
            // Add gateway/MAC/VLAN rows once you have items or inventory for them.
        ];
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
        $problems = API::Problem()->get([
            'output'  => ['eventid', 'name', 'severity', 'r_eventid'],
            'hostids' => [$hostid],
            'recent'  => false
        ]) ?: [];
        $problems = array_filter(
            $problems,
            fn($p) => empty($p['r_eventid']) || (int) $p['r_eventid'] === 0
        );

        return [
            'associationFailures' => 0,
            'authFailures'        => 0,
            'networkIssues'       => count(array_filter($problems, fn($p) => (int) $p['severity'] >= 3)),
            'packetLoss'          => 0,
            'totalClients'        => 0,
            'activeClients'       => 0
        ];
    }

    private function collectWiredPorts(string $hostid): array {
        // Stub. Populate from net.if.* items if/when you discover them.
        return [];
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
