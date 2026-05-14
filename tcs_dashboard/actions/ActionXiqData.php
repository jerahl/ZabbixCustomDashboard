<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;

/**
 * GET zabbix.php?action=tcs.xiq.data
 *
 * Returns the rollup payload consumed by xiq-bridge.jsx (XIQ_TOTALS, XIQ_SITES,
 * XIQ_BANDS, XIQ_SSIDS, XIQ_PROBLEM_APS, XIQ_CHANNEL_GRID, XIQ_CLIENT_MIX,
 * XIQ_THROUGHPUT, XIQ_FIRMWARE, XIQ_ROAMING, XIQ_EVENTS).
 *
 * Live sections (Zabbix-side, no XIQ token required):
 *   - totals.aps    — host counts from API::Host (tag target=xiq)
 *   - sites         — bucketed by Site/* host group, sev derived from open
 *                     problem severity
 *   - problemAps    — top-N open problems on XIQ hosts
 *   - events        — recent problem events (open + resolved-recent)
 *
 * Synthetic sections (need XIQ API token to populate, deferred):
 *   - totals.{clients,throughput,ssids,rfHealth,firmware,controllers}
 *   - bands, ssids, channelGrid, clientMix, throughput, firmware, roaming
 */
class ActionXiqData extends ActionDataBase {

    /** Site/Wireless/* + target=xiq host discovery is cached this many seconds. */
    private const FLEET_CACHE_TTL = 30;
    private const FLEET_CACHE_KEY = 'tcs_dashboard:xiq_fleet:v4';

    /** Host group prefix used to discover wireless APs and their site bucket. */
    private const SITE_PREFIX = 'Site/Wireless/';

    protected function checkInput(): bool {
        return $this->validateInput([]);
    }

    protected function doAction(): void {
        $payload = self::syntheticPayload();

        // Overlay Zabbix-side facts on top of the synthetic shell. On failure
        // we keep the synthetic numbers so the page never blanks — the error
        // surfaces in the server log instead.
        try {
            $fleet = self::collectFleet();
            if ($fleet['hostids']) {
                $payload['totals']['aps'] = $fleet['apTotals'];
                $payload['sites']         = $fleet['sites'];
                $payload['problemAps']    = $fleet['problemAps'];
                $payload['events']        = self::collectEvents($fleet['hostids'], $fleet['hostNames']);
            }
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] xiq.data: ' . $e->getMessage());
        }

        $payload['ts'] = time();
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        ]));
    }

    /**
     * Discover XIQ-tagged hosts, bucket by Site/* group, count up/down, and
     * project into the shape the React widgets consume.
     *
     * Result keys:
     *   hostids   — string[]   for downstream problem/event queries
     *   hostNames — hostid => display name
     *   apTotals  — { total, online, offline, critical, idle }
     *   sites     — array<{ id, name, aps, online, util, clients, sev, top, kind? }>
     *   problemAps— array<{ ap, site, model, reason, sev, util2, util5, clients, age }>
     */
    private static function collectFleet(): array {
        if (function_exists('apcu_fetch')) {
            $hit = apcu_fetch(self::FLEET_CACHE_KEY, $ok);
            if ($ok && is_array($hit)) return $hit;
        }

        $fleet = self::collectFleetUncached();

        if (function_exists('apcu_store')) {
            apcu_store(self::FLEET_CACHE_KEY, $fleet, self::FLEET_CACHE_TTL);
        }
        return $fleet;
    }

    /** @return array<string, mixed> */
    private static function collectFleetUncached(): array {
        $empty = [
            'hostids' => [], 'hostNames' => [], 'apTotals' => ['total' => 0, 'online' => 0, 'offline' => 0, 'critical' => 0, 'idle' => 0],
            'sites' => [], 'problemAps' => []
        ];

        // Step 1: Site/Wireless/<schoolname>/<floor> host groups. APs are
        // bucketed per-floor in this convention, so we roll several groups
        // up under the schoolname segment when projecting to the React side.
        $siteGroups = API::HostGroup()->get([
            'output'      => ['groupid', 'name'],
            'search'      => ['name' => self::SITE_PREFIX],
            'startSearch' => true
        ]) ?: [];
        if (!$siteGroups) return $empty;
        $groupids = array_column($siteGroups, 'groupid');

        // Step 2: hosts in any Site/Wireless/* group. The path prefix already
        // qualifies these as wireless APs — no extra tag filter required.
        // selectInterfaces brings the per-interface `available` flag we need
        // to tell reachable hosts (1) from unreachable (2) — host.status by
        // itself only indicates monitored/disabled, not up/down.
        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'active_available'],
            'selectHostGroups' => ['groupid', 'name'],
            'selectInventory'  => ['model'],
            'selectInterfaces' => ['interfaceid', 'main', 'type', 'available'],
            'groupids'         => $groupids,
            'preservekeys'     => true
        ]) ?: [];
        if (!$hosts) return $empty;

        $hostids   = array_keys($hosts);
        $hostNames = [];
        foreach ($hosts as $h) {
            $hostNames[(string) $h['hostid']] = (string) ($h['name'] ?: $h['host']);
        }

        // Step 3: open problems for these hosts so we can score severity per
        // site and surface the top-N problem APs.
        $problems = self::collectProblemsForHosts($hostids);

        // Map problem.eventid -> hostid via event.get(selectHosts).
        $problemByHost = [];
        if ($problems) {
            $events = API::Event()->get([
                'output'      => ['eventid'],
                'eventids'    => array_column($problems, 'eventid'),
                'selectHosts' => ['hostid']
            ]) ?: [];
            $hostByEvent = [];
            foreach ($events as $ev) {
                $first = $ev['hosts'][0] ?? null;
                if ($first) $hostByEvent[(string) $ev['eventid']] = (string) $first['hostid'];
            }
            foreach ($problems as $p) {
                $hid = $hostByEvent[(string) $p['eventid']] ?? null;
                if ($hid !== null && isset($hosts[$hid])) {
                    $problemByHost[$hid][] = $p;
                }
            }
        }

        // Step 4: bucket hosts by school (the first segment after the
        // Site/Wireless/ prefix). Multiple floors collapse into one site row.
        $sites = [];
        foreach ($hostids as $hid) {
            $h = $hosts[$hid];

            $siteName = self::schoolNameFor($h);
            if ($siteName === '') continue;
            $siteId = self::siteIdFromName($siteName);

            $sites[$siteName] = $sites[$siteName] ?? [
                'id'      => $siteId,
                'name'    => $siteName,
                'aps'     => 0,
                'online'  => 0,
                'util'    => 0,
                'clients' => 0,
                'sev'     => 'ok',
                'top'     => '—',
                '_hostids'=> [],
            ];

            $sites[$siteName]['aps']++;
            if (self::isHostReachable($h)) $sites[$siteName]['online']++;

            $sites[$siteName]['_hostids'][] = $hid;

            // Promote severity per-site to the worst open problem on any host.
            $worst = self::worstSev($problemByHost[$hid] ?? []);
            if (self::sevRank($worst) > self::sevRank($sites[$siteName]['sev'])) {
                $sites[$siteName]['sev'] = $worst;
                // Pick the top reason from this host's worst problem.
                foreach ($problemByHost[$hid] ?? [] as $p) {
                    if (self::zabbixSevToLabel((int) $p['severity']) === $worst) {
                        $sites[$siteName]['top'] = sprintf('%s · %s', $hostNames[$hid], $p['name']);
                        break;
                    }
                }
            }
        }

        // Flag a site as "outage" (pulses in the UI) when ≥25% of its APs are
        // offline AND severity is high/disaster.
        $sitesOut = [];
        foreach ($sites as $s) {
            $offPct = $s['aps'] > 0 ? (($s['aps'] - $s['online']) / $s['aps']) : 0;
            if ($offPct >= 0.25 && in_array($s['sev'], ['high', 'disaster'], true)) {
                $s['kind'] = 'outage';
            }
            unset($s['_hostids']);
            $sitesOut[] = $s;
        }
        usort($sitesOut, fn($a, $b) => self::sevRank($b['sev']) <=> self::sevRank($a['sev']) ?: strcmp($a['name'], $b['name']));

        // Step 5: AP totals.
        //
        //   total    = every AP host in Site/Wireless/* (incl. disabled)
        //   online   = main interface available=1 (or in maintenance)
        //   offline  = main interface available=2 (unreachable)
        //   critical = has open high-or-disaster problem
        //   idle     = enabled but availability unknown (=0), neither up nor down
        //
        // Disabled hosts (status=1) don't contribute to online/offline — they
        // sit in the leftover slot so the totals still add up to `total`.
        $total = count($hostids);
        $online = $offline = $critical = $idle = 0;
        foreach ($hostids as $hid) {
            $h = $hosts[$hid];
            $state = self::hostReachState($h);
            if ($state === 'online')       $online++;
            elseif ($state === 'offline')  $offline++;
            elseif ($state === 'idle')     $idle++;
            // 'disabled' falls through — not counted

            $w = self::worstSev($problemByHost[$hid] ?? []);
            if (in_array($w, ['high', 'disaster'], true)) $critical++;
        }
        $apTotals = [
            'total'    => $total,
            'online'   => $online,
            'offline'  => $offline,
            'critical' => $critical,
            'idle'     => $idle,
        ];

        // Step 6: top problem APs — flatten, sort by severity then age (newest
        // worst first), take 8.
        $problemAps = [];
        foreach ($hostids as $hid) {
            foreach ($problemByHost[$hid] ?? [] as $p) {
                $clock = (int) $p['clock'];
                $age   = max(0, time() - $clock);
                $problemAps[] = [
                    'ap'      => $hostNames[$hid],
                    'hostid'  => (string) $hid,
                    'site'    => self::siteIdFor($hosts[$hid]),
                    'model'   => (string) ($hosts[$hid]['inventory']['model'] ?? '—'),
                    'reason'  => (string) $p['name'],
                    'sev'     => self::zabbixSevToLabel((int) $p['severity']),
                    'util2'   => 0,
                    'util5'   => 0,
                    'clients' => 0,
                    'age'     => sprintf('%02d:%02d:%02d', intdiv($age, 3600), intdiv($age % 3600, 60), $age % 60),
                    '_clock'  => $clock,
                    '_sevr'   => self::sevRank(self::zabbixSevToLabel((int) $p['severity'])),
                ];
            }
        }
        usort($problemAps, fn($a, $b) => $b['_sevr'] <=> $a['_sevr'] ?: $b['_clock'] <=> $a['_clock']);
        $problemAps = array_slice($problemAps, 0, 8);
        foreach ($problemAps as &$p) { unset($p['_clock'], $p['_sevr']); }
        unset($p);

        return [
            'hostids'    => $hostids,
            'hostNames'  => $hostNames,
            'apTotals'   => $apTotals,
            'sites'      => $sitesOut,
            'problemAps' => $problemAps,
        ];
    }

    /** Open + recently-resolved problems across a list of XIQ host IDs. */
    private static function collectProblemsForHosts(array $hostids): array {
        if (!$hostids) return [];
        return API::Problem()->get([
            'output'    => ['eventid', 'name', 'severity', 'clock', 'r_eventid', 'r_clock', 'acknowledged'],
            'hostids'   => $hostids,
            'recent'    => true,
            'time_from' => time() - 24 * 3600,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit'     => 200,
        ]) ?: [];
    }

    /** Recent events for the events stream (top 12, newest first). */
    private static function collectEvents(array $hostids, array $hostNames): array {
        if (!$hostids) return [];
        $problems = API::Problem()->get([
            'output'    => ['eventid', 'name', 'severity', 'clock'],
            'hostids'   => $hostids,
            'recent'    => true,
            'time_from' => time() - 3600,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit'     => 50,
        ]) ?: [];
        if (!$problems) return [];

        $events = API::Event()->get([
            'output'      => ['eventid'],
            'eventids'    => array_column($problems, 'eventid'),
            'selectHosts' => ['hostid']
        ]) ?: [];
        $hostByEvent = [];
        foreach ($events as $ev) {
            $first = $ev['hosts'][0] ?? null;
            if ($first) $hostByEvent[(string) $ev['eventid']] = (string) $first['hostid'];
        }

        $out = [];
        foreach ($problems as $p) {
            $hid = $hostByEvent[(string) $p['eventid']] ?? null;
            if ($hid === null) continue;
            $out[] = [
                'ts'     => date('H:i:s', (int) $p['clock']),
                'source' => 'zbx',
                'host'   => $hostNames[$hid] ?? $hid,
                'msg'    => 'Problem:',
                'obj'    => (string) $p['name'],
                'sev'    => self::zabbixSevToLabel((int) $p['severity']),
            ];
            if (count($out) >= 12) break;
        }
        return $out;
    }

    private static function zabbixSevToLabel(int $sev): string {
        return [0 => 'info', 1 => 'info', 2 => 'warning', 3 => 'warning', 4 => 'high', 5 => 'disaster'][$sev] ?? 'info';
    }

    private static function sevRank(string $label): int {
        return ['ok' => 0, 'info' => 1, 'warning' => 2, 'high' => 3, 'disaster' => 4][$label] ?? 0;
    }

    private static function worstSev(array $problems): string {
        $worst = 'ok';
        foreach ($problems as $p) {
            $lbl = self::zabbixSevToLabel((int) $p['severity']);
            if (self::sevRank($lbl) > self::sevRank($worst)) $worst = $lbl;
        }
        return $worst;
    }

    /**
     * Classify a host as online | offline | idle | disabled.
     *
     *   online   — main interface available=1, OR host in maintenance
     *              (maintenance suppresses problems; don't show as offline)
     *   offline  — main interface available=2
     *   idle     — enabled but availability unknown (0) on all interfaces
     *   disabled — host.status=1 (operator-disabled)
     *
     * If no interfaces are present (template-only, custom checks) we fall
     * back to enabled→idle / disabled→disabled so the host isn't silently
     * dropped from the total.
     */
    private static function hostReachState(array $host): string {
        if ((int) $host['status'] !== 0) return 'disabled';
        if ((int) ($host['maintenance_status'] ?? 0) === 1) return 'online';

        $sawAvailable = false;
        $sawUnavailable = false;
        foreach ($host['interfaces'] ?? [] as $iface) {
            // Prefer the main interface, but consider all of them — an SNMP
            // AP with a secondary Agent interface should still count online if
            // either is reachable.
            $a = (int) ($iface['available'] ?? 0);
            if ($a === 1) $sawAvailable = true;
            if ($a === 2) $sawUnavailable = true;
        }
        if ($sawAvailable)   return 'online';
        if ($sawUnavailable) return 'offline';
        return 'idle';
    }

    private static function isHostReachable(array $host): bool {
        $s = self::hostReachState($host);
        return $s === 'online';
    }

    private static function siteIdFor(array $host): string {
        $name = self::schoolNameFor($host);
        return $name === '' ? '—' : self::siteIdFromName($name);
    }

    /**
     * Walk the host's groups for one named Site/Wireless/<schoolname>/... and
     * return the schoolname segment. Returns '' if no matching group is found.
     */
    private static function schoolNameFor(array $host): string {
        foreach ($host['hostgroups'] ?? [] as $g) {
            $name = (string) $g['name'];
            if (!str_starts_with($name, self::SITE_PREFIX)) continue;
            $rest = substr($name, strlen(self::SITE_PREFIX));
            $segments = explode('/', $rest, 2);
            $school = trim($segments[0] ?? '');
            if ($school !== '') return $school;
        }
        return '';
    }

    private static function siteIdFromName(string $name): string {
        // Take the leading uppercase letters of significant words (drops "the",
        // "of", "for"); fall back to first 3 alphanumerics. "Bryant High School"
        // → "BHS"; "Tuscaloosa Magnet Elementary" → "TME".
        $stopwords = ['the', 'of', 'for', 'and', 'a', 'an'];
        $initials = '';
        foreach (preg_split('/[\s\-_\/]+/', $name) as $word) {
            $w = strtolower(trim($word));
            if ($w === '' || in_array($w, $stopwords, true)) continue;
            $initials .= strtoupper(substr($w, 0, 1));
            if (strlen($initials) >= 4) break;
        }
        if (strlen($initials) >= 2) return $initials;
        $alnum = strtoupper(preg_replace('/[^a-z0-9]+/i', '', $name) ?: '');
        return $alnum === '' ? 'SITE' : substr($alnum, 0, 3);
    }

    /** Shape consumed by xiq-bridge.jsx — also used by ActionXiq for SSR boot. */
    public static function syntheticPayload(): array {
        return [
            'totals'      => self::totals(),
            'sites'       => self::sites(),
            'bands'       => self::bands(),
            'ssids'       => self::ssids(),
            'problemAps'  => self::problemAps(),
            'channelGrid' => self::channelGrid(),
            'clientMix'   => self::clientMix(),
            'throughput'  => self::throughput(),
            'firmware'    => self::firmware(),
            'roaming'     => self::roaming(),
            'events'      => self::events()
        ];
    }

    public static function totals(): array {
        return [
            'aps'         => ['total' => 1184, 'online' => 1158, 'offline' => 18, 'critical' => 4, 'idle' => 4],
            'clients'     => ['total' => 9264, 'dot11ax' => 6418, 'dot11ac' => 2310, 'legacy' => 536],
            'throughput'  => ['agg_gbps' => 14.62, 'peak_gbps' => 22.41, 'ingress_gbps' => 9.18, 'egress_gbps' => 5.44],
            'ssids'       => ['total' => 8, 'broadcast' => 6],
            'rfHealth'    => ['score' => 86, 'target' => 90],
            'firmware'    => ['compliant' => 1138, 'behind' => 41, 'ahead' => 5, 'target' => '32.7.0.5'],
            'controllers' => ['region' => 'us-east-2', 'instance' => 'xiq-tcs-prod', 'lastSync' => '12s ago']
        ];
    }

    private static function sites(): array {
        return [
            ['id' => 'BHS', 'name' => 'Bryant High School',         'aps' => 96, 'online' => 93, 'util' => 71, 'clients' => 1124, 'sev' => 'warning',  'top' => 'BHS-23-Cafe LAN down'],
            ['id' => 'CHS', 'name' => 'Central High School',        'aps' => 84, 'online' => 83, 'util' => 64, 'clients' =>  982, 'sev' => 'warning',  'top' => 'CHS-LIB-AP-12 roam failures'],
            ['id' => 'NRH', 'name' => 'Northridge High School',     'aps' => 78, 'online' => 78, 'util' => 58, 'clients' =>  844, 'sev' => 'info',     'top' => '—'],
            ['id' => 'PHS', 'name' => 'Paul W. Bryant Middle',      'aps' => 54, 'online' => 53, 'util' => 52, 'clients' =>  612, 'sev' => 'info',     'top' => 'PHS-AP-Lib-03 firmware drift'],
            ['id' => 'ECS', 'name' => 'Eastwood Middle School',     'aps' => 48, 'online' => 48, 'util' => 41, 'clients' =>  481, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'WMS', 'name' => 'Westlawn Middle School',     'aps' => 46, 'online' => 45, 'util' => 47, 'clients' =>  466, 'sev' => 'info',     'top' => '—'],
            ['id' => 'TMS', 'name' => 'Tuscaloosa Magnet Middle',   'aps' => 40, 'online' => 40, 'util' => 38, 'clients' =>  402, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'ALV', 'name' => 'Alberta Elementary',         'aps' => 32, 'online' => 32, 'util' => 44, 'clients' =>  281, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'AED', 'name' => 'Arcadia Elementary',         'aps' => 28, 'online' => 28, 'util' => 35, 'clients' =>  244, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'CRS', 'name' => 'Central Elementary',         'aps' => 32, 'online' => 21, 'util' => 18, 'clients' =>   94, 'sev' => 'disaster', 'top' => '11 APs unreachable · uplink to TCS-CO down', 'kind' => 'outage'],
            ['id' => 'MTV', 'name' => 'Martin Luther King Jr Elem', 'aps' => 26, 'online' => 26, 'util' => 32, 'clients' =>  204, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'OAK', 'name' => 'Oakdale Elementary',         'aps' => 24, 'online' => 24, 'util' => 39, 'clients' =>  198, 'sev' => 'info',     'top' => '—'],
            ['id' => 'RCK', 'name' => 'Rock Quarry Elementary',     'aps' => 26, 'online' => 26, 'util' => 36, 'clients' =>  214, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'SKL', 'name' => 'Skyland Elementary',         'aps' => 22, 'online' => 22, 'util' => 33, 'clients' =>  176, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'STA', 'name' => 'Stafford Elementary',        'aps' => 24, 'online' => 23, 'util' => 49, 'clients' =>  202, 'sev' => 'warning',  'top' => 'STA-AP-Gym-01 high 2.4 GHz noise'],
            ['id' => 'TKM', 'name' => 'Tuscaloosa Magnet Elem',     'aps' => 28, 'online' => 28, 'util' => 41, 'clients' =>  236, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'UPL', 'name' => 'University Place Elem',      'aps' => 26, 'online' => 26, 'util' => 40, 'clients' =>  208, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'VWS', 'name' => 'Verner Elementary',          'aps' => 22, 'online' => 22, 'util' => 31, 'clients' =>  172, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'WDS', 'name' => 'Woodland Forrest Elem',      'aps' => 22, 'online' => 22, 'util' => 37, 'clients' =>  184, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'TCT', 'name' => 'Tuscaloosa Career & Tech',   'aps' => 42, 'online' => 42, 'util' => 48, 'clients' =>  411, 'sev' => 'info',     'top' => '—'],
            ['id' => 'AOL', 'name' => 'Tuscaloosa Online',          'aps' =>  6, 'online' =>  6, 'util' => 12, 'clients' =>   38, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'OAS', 'name' => 'Oak Hill Special Ed',        'aps' => 12, 'online' => 12, 'util' => 28, 'clients' =>   84, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'TCS', 'name' => 'TCS Central Office',         'aps' => 64, 'online' => 63, 'util' => 55, 'clients' =>  584, 'sev' => 'warning',  'top' => 'Auth-server timeout (PF radius)'],
            ['id' => 'TCO', 'name' => 'Operations / Warehouse',     'aps' => 18, 'online' => 18, 'util' => 22, 'clients' =>   96, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'TDC', 'name' => 'Datacenter (CO Annex)',      'aps' =>  8, 'online' =>  8, 'util' => 18, 'clients' =>   41, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'TBS', 'name' => 'Bus Operations',             'aps' => 12, 'online' => 12, 'util' => 26, 'clients' =>   86, 'sev' => 'ok',       'top' => '—'],
        ];
    }

    private static function bands(): array {
        return [
            ['id' => '5',   'label' => '5 GHz',      'aps' => 1184, 'clients' => 6840, 'util' => 58, 'noise' => -91, 'saturated' => 47,  'color' => 'var(--ext)',
                'spark' => [44,46,48,52,55,58,60,61,59,58,57,56,55,57,60,62,63,61,58,55,52,50,48,46]],
            ['id' => '2_4', 'label' => '2.4 GHz',    'aps' => 1184, 'clients' => 1604, 'util' => 71, 'noise' => -84, 'saturated' => 128, 'color' => 'var(--warn)',
                'spark' => [62,64,66,69,71,73,74,75,74,72,71,70,69,71,72,74,75,73,71,68,66,64,62,60]],
            ['id' => '6',   'label' => '6 GHz (6E)', 'aps' => 286,  'clients' =>  820, 'util' => 22, 'noise' => -94, 'saturated' => 0,   'color' => 'var(--ok)',
                'spark' => [12,14,15,17,20,22,24,25,24,23,22,21,20,22,24,26,28,27,25,22,20,18,17,15]],
        ];
    }

    private static function ssids(): array {
        return [
            ['id' => 'tcs-staff',    'label' => 'tcs-staff',    'auth' => '802.1X · EAP-TLS',     'vlan' => 10, 'clients' => 2284, 'success' => 99.7, 'throughput' => 5.84, 'role' => 'faculty'],
            ['id' => 'tcs-students', 'label' => 'tcs-students', 'auth' => '802.1X · PEAP-MSCHAP', 'vlan' => 20, 'clients' => 5102, 'success' => 98.3, 'throughput' => 6.91, 'role' => 'student'],
            ['id' => 'tcs-byod',     'label' => 'tcs-byod',     'auth' => 'PSK · onboarded',      'vlan' => 50, 'clients' => 1184, 'success' => 97.1, 'throughput' => 1.62, 'role' => 'byod'],
            ['id' => 'tcs-guest',    'label' => 'tcs-guest',    'auth' => 'Captive · PF portal',  'vlan' => 60, 'clients' =>  394, 'success' => 94.6, 'throughput' => 0.71, 'role' => 'guest'],
            ['id' => 'tcs-av',       'label' => 'tcs-av',       'auth' => 'PSK · static',         'vlan' => 70, 'clients' =>  142, 'success' => 99.9, 'throughput' => 0.18, 'role' => 'av'],
            ['id' => 'tcs-voice',    'label' => 'tcs-voice',    'auth' => '802.1X · EAP-TLS',     'vlan' => 30, 'clients' =>  118, 'success' => 99.6, 'throughput' => 0.09, 'role' => 'voip'],
            ['id' => 'tcs-iot',      'label' => 'tcs-iot',      'auth' => 'PSK · scoped',         'vlan' => 80, 'clients' =>   34, 'success' => 99.1, 'throughput' => 0.02, 'role' => 'byod'],
            ['id' => 'tcs-mgmt',     'label' => 'tcs-mgmt',     'auth' => '802.1X · cert',        'vlan' =>  4, 'clients' =>    6, 'success' => 100.0,'throughput' => 0.01, 'role' => 'av', 'hidden' => true],
        ];
    }

    private static function problemAps(): array {
        return [
            ['ap' => 'BHS-23-Cafe',    'site' => 'BHS', 'model' => 'AP4000',  'reason' => 'LAN uplink down',                  'sev' => 'high',     'util2' => 0,  'util5' => 0,  'clients' => 0,  'age' => '00:14:33'],
            ['ap' => 'CRS-01-Office',  'site' => 'CRS', 'model' => 'AP3000x', 'reason' => 'Unreachable via cloud broker',     'sev' => 'disaster', 'util2' => 0,  'util5' => 0,  'clients' => 0,  'age' => '00:04:11'],
            ['ap' => 'CRS-04-Hall',    'site' => 'CRS', 'model' => 'AP3000x', 'reason' => 'Unreachable via cloud broker',     'sev' => 'disaster', 'util2' => 0,  'util5' => 0,  'clients' => 0,  'age' => '00:04:11'],
            ['ap' => 'CRS-Gym-Center', 'site' => 'CRS', 'model' => 'AP4000',  'reason' => 'Unreachable via cloud broker',     'sev' => 'disaster', 'util2' => 0,  'util5' => 0,  'clients' => 0,  'age' => '00:04:11'],
            ['ap' => 'BHS-56-Hallway', 'site' => 'BHS', 'model' => 'AP4000',  'reason' => '5 GHz util > 75% (sustained 12m)', 'sev' => 'warning',  'util2' => 38, 'util5' => 81, 'clients' => 64, 'age' => '00:42:18'],
            ['ap' => 'CHS-LIB-AP-12',  'site' => 'CHS', 'model' => 'AP4000',  'reason' => 'Client roam failure rate > 4%',    'sev' => 'warning',  'util2' => 41, 'util5' => 62, 'clients' => 48, 'age' => '00:48:09'],
            ['ap' => 'STA-AP-Gym-01',  'site' => 'STA', 'model' => 'AP410C',  'reason' => '2.4 GHz noise floor -78 dBm',      'sev' => 'warning',  'util2' => 88, 'util5' => 44, 'clients' => 22, 'age' => '01:08:42'],
            ['ap' => 'PHS-AP-Lib-03',  'site' => 'PHS', 'model' => 'AP410C',  'reason' => 'Firmware drift (32.7.0.5 avail)',  'sev' => 'info',     'util2' => 32, 'util5' => 41, 'clients' => 28, 'age' => '01:38:02'],
        ];
    }

    private static function channelGrid(): array {
        return [
            'sites'    => ['BHS','CHS','NRH','PHS','TCS','TCT','CRS','STA'],
            'channels' => [36, 40, 44, 48, 52, 56, 60, 64, 100, 104, 108, 112, 149, 153, 157, 161],
            'matrix'   => [
                [62, 71, 58, 64, 48, 41, 52, 55, 28, 31, 33, 34, 67, 72, 64, 58],
                [54, 62, 51, 57, 41, 38, 44, 48, 22, 24, 26, 28, 61, 64, 58, 52],
                [44, 51, 42, 47, 33, 31, 36, 38, 18, 19, 21, 22, 49, 52, 47, 42],
                [38, 44, 36, 40, 28, 26, 31, 32, 14, 16, 18, 19, 42, 45, 40, 36],
                [48, 56, 46, 52, 38, 34, 41, 42, 19, 21, 24, 25, 54, 58, 52, 47],
                [42, 49, 40, 45, 32, 29, 35, 36, 16, 18, 20, 22, 47, 50, 45, 40],
                [ 0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0],
                [72, 68, 64, 71, 51, 44, 56, 58, 32, 34, 36, 38, 74, 78, 71, 64],
            ]
        ];
    }

    private static function clientMix(): array {
        return [
            'standards' => [
                ['id' => 'ax',     'label' => 'Wi-Fi 6 / 6E (ax)', 'count' => 6418, 'pct' => 69.3, 'color' => 'var(--ext)'],
                ['id' => 'ac',     'label' => 'Wi-Fi 5 (ac)',      'count' => 2310, 'pct' => 24.9, 'color' => 'var(--info)'],
                ['id' => 'n',      'label' => 'Wi-Fi 4 (n)',       'count' =>  428, 'pct' =>  4.6, 'color' => 'var(--warn)'],
                ['id' => 'legacy', 'label' => 'Legacy a/b/g',      'count' =>  108, 'pct' =>  1.2, 'color' => 'var(--err)'],
            ],
            'os' => [
                ['id' => 'chrome',  'label' => 'ChromeOS',  'count' => 4862, 'pct' => 52.5],
                ['id' => 'win',     'label' => 'Windows',   'count' => 1718, 'pct' => 18.5],
                ['id' => 'ios',     'label' => 'iPadOS',    'count' => 1284, 'pct' => 13.9],
                ['id' => 'macos',   'label' => 'macOS',     'count' =>  642, 'pct' =>  6.9],
                ['id' => 'android', 'label' => 'Android',   'count' =>  482, 'pct' =>  5.2],
                ['id' => 'other',   'label' => 'Other',     'count' =>  276, 'pct' =>  3.0],
            ]
        ];
    }

    private static function throughput(): array {
        return [
            2.1, 1.8, 1.4, 1.2, 1.1, 1.3, 2.6, 5.8, 11.4, 17.2, 19.8, 21.4,
            22.1, 18.6, 16.4, 19.1, 21.8, 14.8, 9.4, 6.2, 4.4, 3.6, 2.8, 2.2
        ];
    }

    private static function firmware(): array {
        return [
            'versions' => [
                ['v' => '32.7.0.7', 'count' =>   84, 'status' => 'ahead',  'note' => 'early-ring (BHS)'],
                ['v' => '32.7.0.5', 'count' => 1054, 'status' => 'target', 'note' => 'fleet target'],
                ['v' => '32.7.0.3', 'count' =>   34, 'status' => 'behind', 'note' => 'scheduled May 18'],
                ['v' => '32.6.4.1', 'count' =>    7, 'status' => 'behind', 'note' => 'needs window'],
                ['v' => '—',        'count' =>    5, 'status' => 'ahead',  'note' => 'lab / spare'],
            ]
        ];
    }

    private static function roaming(): array {
        return [
            'buckets' => [
                ['range' => '< 20 ms', 'count' => 7124, 'color' => 'var(--ok)'],
                ['range' => '20–50',   'count' => 1284, 'color' => 'var(--ok)'],
                ['range' => '50–120',  'count' =>  514, 'color' => 'var(--warn)'],
                ['range' => '120–250', 'count' =>  198, 'color' => 'var(--warn)'],
                ['range' => '250+',    'count' =>   86, 'color' => 'var(--err)'],
                ['range' => 'Failed',  'count' =>   58, 'color' => 'var(--err)'],
            ],
            'rate24h' => 0.62
        ];
    }

    private static function events(): array {
        return [
            ['ts' => '10:14:08', 'source' => 'ext', 'host' => 'BHS-23-Cafe',       'msg' => 'Device disconnected:', 'obj' => 'no LAN keepalive (12s)',           'sev' => 'high'],
            ['ts' => '10:13:51', 'source' => 'ext', 'host' => 'CRS-04-Hall',       'msg' => 'Device unreachable:',  'obj' => 'broker timeout (60s)',             'sev' => 'disaster'],
            ['ts' => '10:13:22', 'source' => 'pf',  'host' => 'F4:5C:89:0B:32:71', 'msg' => 'RADIUS reject:',       'obj' => 'unknown CA on tcs-staff',          'sev' => 'warning'],
            ['ts' => '10:12:08', 'source' => 'ext', 'host' => 'STA-AP-Gym-01',     'msg' => 'RF event:',            'obj' => '2.4 GHz noise floor -78 dBm (12m)','sev' => 'warning'],
            ['ts' => '10:11:47', 'source' => 'ext', 'host' => 'BHS-56-Hallway',    'msg' => 'Channel change:',      'obj' => '5 GHz 149 → 157 (CCA 81%)',        'sev' => 'info'],
            ['ts' => '10:10:24', 'source' => 'ext', 'host' => 'CHS-LIB-AP-12',     'msg' => 'Roam anomaly:',        'obj' => '13 clients · 4.2% fail rate',      'sev' => 'warning'],
            ['ts' => '10:09:08', 'source' => 'ext', 'host' => 'NRH-ACC-04',        'msg' => 'Client joined:',       'obj' => 'iPad · tcs-staff · -54 dBm',       'sev' => 'ok'],
            ['ts' => '10:08:13', 'source' => 'ext', 'host' => 'PHS-AP-Lib-03',     'msg' => 'Firmware drift:',      'obj' => '32.7.0.5 → 32.7.0.7 available',    'sev' => 'info'],
            ['ts' => '10:07:42', 'source' => 'ext', 'host' => 'TCS-AD-Conf-A',     'msg' => 'Capacity:',            'obj' => '76 clients on single radio (5 GHz)','sev' => 'info'],
            ['ts' => '10:06:31', 'source' => 'ext', 'host' => 'CRS-CORE-01',       'msg' => 'Upstream:',            'obj' => 'uplink Te1/49 to TCS-CO down',     'sev' => 'disaster'],
            ['ts' => '10:05:18', 'source' => 'ext', 'host' => 'BHS-Gym-N-02',      'msg' => 'Mesh formed:',         'obj' => 'backup link 5 GHz · -68 dBm',      'sev' => 'ok'],
            ['ts' => '10:04:02', 'source' => 'pf',  'host' => 'k.davis@tcs',       'msg' => 'EAP-TLS success:',     'obj' => 'BHS-56-Hallway · -52 dBm',         'sev' => 'ok'],
        ];
    }
}
