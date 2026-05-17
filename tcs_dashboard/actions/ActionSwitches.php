<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\TcsDashboard\Lib\SwitchClient;

/**
 * GET zabbix.php?action=tcs.switches.view[&switchid=NNN]
 *
 * Collects an initial snapshot of stack / port / PoE state from the Zabbix
 * Item API (via SwitchClient) and hands it to the view as $data['boot'].
 * The view inlines this as window.SWITCH_BOOT so a future switches-bridge.jsx
 * can adapt it into window.SWITCH_SITES / window.ARC_MDF_STACK /
 * window.makePortDetail without changing the React components.
 *
 * Item keys expected on switch hosts (from the lifted EXOS template
 * templates/extreme_exos_by_snmp_with_poe.yaml):
 *   stacking.member[1..8]
 *   net.if.status[ifOperStatus.<member>.<port>]
 *   snmp.interfaces.poe.dstatus[<member>.<port>]
 *   net.if.mac[<member>.<port>]                  (FDB, if discovered)
 */
class ActionSwitches extends ActionBase {

    protected function checkInput(): bool {
        $fields = [
            'switchid' => 'string'  // hostid of the switch to focus on
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $ret;
    }

    protected function doAction(): void {
        $switchid = $this->getInput('switchid', '');

        // Page load is intentionally minimal — fleet, snapshot, and problems
        // are fetched asynchronously by switches-bridge.jsx after first paint
        // (see tcs.switches.fleet.data + tcs.switches.snapshot.data). Only
        // the host's identity is loaded here so the page header pills render
        // immediately with the right hostname.
        $boot = [
            'host'     => null,
            'members'  => [],
            'ports'    => [],
            'poe'      => [],
            'fdb'      => [],
            'kpis'     => new \stdClass(),
            'history'  => new \stdClass(),
            'uplinks'  => [],
            'problems' => [],
            'fleet'    => [],
            'async'    => true
        ];

        if ($switchid !== '') {
            $boot['host'] = $this->collectHost($switchid);
        }

        $data = [
            'title'    => _('TCS Switch Port Status'),
            'switchid' => $switchid,
            'boot'     => $boot
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('TCS Switch Port Status'));
        $this->setResponse($response);
    }

    private function collectHost(string $hostid): ?array {
        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
            'selectInterfaces' => ['ip', 'main', 'type'],
            'hostids'          => [$hostid]
        ]);
        if (!$hosts) return null;

        $h  = $hosts[0];
        $ip = '';
        foreach ($h['interfaces'] ?? [] as $iface) {
            if ((int) ($iface['main'] ?? 0) === 1) {
                $ip = $iface['ip'];
                break;
            }
        }

        return [
            'hostid'       => $h['hostid'],
            'host'         => $h['host'],
            'visible_name' => $h['name'],
            'ip'           => $ip,
            'status'       => ((int) $h['status'] === 0) ? 'monitored' : 'not monitored',
            'maintenance'  => (int) ($h['maintenance_status'] ?? 0)
        ];
    }

    /**
     * Recent problems for one switch host, shaped for the React ProblemsWidget
     * (SWITCH_PROBLEMS schema: { ts, sev, host, trig, age, ack }).
     *
     * Includes open and recently-resolved problems from the last 24h so the
     * "last 24h" header above the list is accurate.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectProblems(string $hostid, int $limit = 25): array {
        $sinceClock = time() - 24 * 3600;

        $problems = API::Problem()->get([
            'output'        => ['eventid', 'name', 'severity', 'clock', 'r_eventid', 'r_clock', 'acknowledged'],
            'hostids'       => [$hostid],
            'recent'        => true, // include resolved-recent
            'time_from'     => $sinceClock,
            'sortfield'     => ['eventid'],
            'sortorder'     => 'DESC',
            'limit'         => $limit
        ]) ?: [];

        // Single host.get for the display name.
        $hosts = API::Host()->get([
            'output'  => ['hostid', 'host'],
            'hostids' => [$hostid]
        ]);
        $hostName = $hosts[0]['host'] ?? '';

        // Zabbix severity 0..5 → frontend label expected by the widget.
        $sevLabel = [
            0 => 'info', 1 => 'info', 2 => 'warning',
            3 => 'average', 4 => 'high', 5 => 'disaster'
        ];

        $now = time();
        $out = [];
        foreach ($problems as $p) {
            $clock = (int) $p['clock'];
            $ageSec = max(0, $now - $clock);
            $h = intdiv($ageSec, 3600);
            $m = intdiv($ageSec % 3600, 60);
            $age = sprintf('%02d:%02d', $h, $m);

            $out[] = [
                'ts'   => date('H:i:s', $clock),
                'sev'  => $sevLabel[(int) $p['severity']] ?? 'info',
                'host' => $hostName,
                'trig' => (string) $p['name'],
                'age'  => $age,
                'ack'  => (int) $p['acknowledged'] === 1
            ];
        }
        return $out;
    }

    /**
     * Discover the switch fleet and roll up per-host port/PoE counters in a
     * shape the existing HostNavigator widget consumes (SWITCH_SITES schema).
     *
     * Discovery (in order):
     *   1. Enumerate host groups whose name starts with `Site/` — these are
     *      the operator-curated sites the navigator buckets switches into.
     *   2. Pull hosts in those groups that carry tag `target=exos` (set on
     *      the EXOS template / per-host so non-switch members of a Site/
     *      group don't leak into the switch view).
     *   3. Pull stacking.member items for those hosts so we know stack size.
     *
     * Site grouping: each host's first `Site/<name>` group wins. Hosts in
     * multiple Site/* groups are still listed once, under their first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectFleet(): array {
        // Stale-while-revalidate caching. Fresh hits (<30s) and stale hits
        // (<5min) both return instantly; stale hits also schedule a background
        // refresh after the response is flushed so the next request is fresh.
        // The user sees a sub-millisecond navigator load almost every time.
        $cacheKey = 'tcs_dashboard:switch_fleet:v2';
        $softTtl  = 30;
        $hardTtl  = 300;

        if (function_exists('apcu_fetch')) {
            $hit = apcu_fetch($cacheKey, $ok);
            if ($ok && is_array($hit) && isset($hit['data'], $hit['ts']) && is_array($hit['data'])) {
                $age = time() - (int) $hit['ts'];
                if ($age < $softTtl) {
                    return $hit['data'];
                }
                if ($age < $hardTtl) {
                    $this->scheduleBackgroundFleetRefresh($cacheKey, $hardTtl);
                    return $hit['data'];
                }
            }
        }

        $fleet = $this->collectFleetUncached();
        $this->storeFleetCache($cacheKey, $fleet, $hardTtl);
        return $fleet;
    }

    private function storeFleetCache(string $cacheKey, array $fleet, int $ttl): void {
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, ['data' => $fleet, 'ts' => time()], $ttl);
        }
    }

    /**
     * Kick off a fleet refresh that runs *after* the response is flushed to
     * the browser. Guarded by an apcu_add lock so concurrent stale hits don't
     * all spawn duplicate refreshes (which would just hammer the Zabbix API).
     */
    private function scheduleBackgroundFleetRefresh(string $cacheKey, int $ttl): void {
        $lockKey = $cacheKey . ':refreshing';
        if (function_exists('apcu_add')) {
            if (!apcu_add($lockKey, 1, 60)) return; // refresh already in flight
        }

        register_shutdown_function(function () use ($cacheKey, $ttl, $lockKey) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                $fleet = $this->collectFleetUncached();
                $this->storeFleetCache($cacheKey, $fleet, $ttl);
            } catch (\Throwable $e) {
                error_log('[tcs_dashboard] background fleet refresh failed: '.$e->getMessage());
            } finally {
                if (function_exists('apcu_delete')) {
                    apcu_delete($lockKey);
                }
            }
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function collectFleetUncached(): array {
        // Step 1: Site/* host groups.
        $siteGroups = API::HostGroup()->get([
            'output'      => ['groupid', 'name'],
            'search'      => ['name' => 'Site/'],
            'startSearch' => true
        ]) ?: [];
        if (!$siteGroups) return [];

        $groupids = array_column($siteGroups, 'groupid');

        // Step 2: hosts in those groups, tag target=exos. inheritedTags=true
        // is critical — operators typically put the tag on the EXOS template
        // rather than every host individually, and the default host.get tag
        // filter only inspects host-level tags.
        //
        // Trimmed compared to the original: no selectInventory, no
        // selectTags. The navigator only needs name / ip / site bucket;
        // per-host model + port counters now come from the snapshot endpoint
        // for the actively selected switch (loaded in parallel).
        $taggedHosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status'],
            'selectInterfaces' => ['ip', 'main'],
            'selectHostGroups' => ['groupid', 'name'],
            'groupids'         => $groupids,
            'tags'             => [['tag' => 'target', 'value' => 'exos', 'operator' => 1]],
            'evaltype'         => 0,
            'inheritedTags'    => true,
            'preservekeys'     => true
        ]) ?: [];
        if (!$taggedHosts) return [];

        $hostids = array_keys($taggedHosts);

        // The original implementation issued three more Item.get calls here:
        // stacking.member[…], net.if.status[…], snmp.interfaces.poe.dstatus[…].
        // Across a fleet of N switches with K ports each those returned
        // 2*N*K rows just to compute counters the host navigator never
        // displays — they were only used for the *selected* switch's header
        // pills, which now derive them from the snapshot stack on the client.
        // Removing them is the bulk of the navigator load-time win.

        // Open problem counts. Hosts metadata already came in step 2.
        $problems = API::Problem()->get([
            'output'  => ['eventid', 'severity', 'r_eventid', 'objectid'],
            'hostids' => $hostids,
            'recent'  => false
        ]) ?: [];

        // Problems aren't reported with hostid directly — they reference
        // triggers, and a trigger can span hosts. Map back via item.get on the
        // trigger's objectid is overkill here; for the navigator badge we
        // approximate per-host counts via event.get with selectHosts.
        $problemByHost = [];
        if ($problems) {
            $eventids = array_column($problems, 'eventid');
            $events = API::Event()->get([
                'output'       => ['eventid'],
                'eventids'     => $eventids,
                'selectHosts'  => ['hostid']
            ]) ?: [];
            foreach ($events as $ev) {
                foreach ($ev['hosts'] ?? [] as $h) {
                    $hid = (string) $h['hostid'];
                    if (isset($taggedHosts[$hid])) {
                        $problemByHost[$hid] = ($problemByHost[$hid] ?? 0) + 1;
                    }
                }
            }
        }

        // Step 6: bucket by Site/* host group, build the SWITCH_SITES payload.
        $sites = [];   // siteId => row
        foreach ($hostids as $hid) {
            $h = $taggedHosts[$hid] ?? null;
            if (!$h) continue;

            $ip = '';
            foreach ($h['interfaces'] ?? [] as $iface) {
                if ((int) ($iface['main'] ?? 0) === 1) { $ip = $iface['ip']; break; }
            }

            // Discovery guarantees at least one Site/* group; pick the first.
            $siteName = '';
            foreach ($h['hostgroups'] ?? [] as $g) {
                if (str_starts_with((string) $g['name'], 'Site/')) {
                    $siteName = substr($g['name'], strlen('Site/'));
                    break;
                }
            }
            if ($siteName === '') continue; // shouldn't happen — defensive
            $siteId = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $siteName));

            if (!isset($sites[$siteId])) {
                $sites[$siteId] = [
                    'id'       => $siteId,
                    'name'     => $siteName,
                    'expanded' => false,
                    'problems' => 0,
                    'switches' => []
                ];
            }

            // ports / up / down / poe / model / members are populated by the
            // snapshot endpoint for the active switch; keep zero placeholders
            // here so the React `host` object shape stays stable.
            $row = [
                'id'       => $h['host'],                 // human-readable, shown in UI
                'hostid'   => (string) $h['hostid'],      // numeric, used for navigation
                'name'     => $h['name'],
                'ip'       => $ip,
                'model'    => '—',
                'members'  => 1,
                'ports'    => 0,
                'up'       => 0,
                'down'     => 0,
                'poe'      => 0,
                'cpu'      => 0,
                'mem'      => 0,
                'temp'     => 0,
                'problems' => (int) ($problemByHost[$hid] ?? 0)
            ];

            $sites[$siteId]['switches'][] = $row;
            $sites[$siteId]['problems'] += $row['problems'];
        }

        // Sort: switches alphabetically within each site, sites alphabetically.
        foreach ($sites as &$site) {
            usort($site['switches'], fn($a, $b) => strcmp($a['id'], $b['id']));
        }
        unset($site);

        uasort($sites, fn($a, $b) => strcmp($a['name'], $b['name']));

        return array_values($sites);
    }
}
