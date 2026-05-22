<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.global.data
 *
 * Returns the live snapshot consumed by global-bridge.jsx. Same shape as
 * the boot block in ActionGlobal::doAction() — totals, sites (host groups
 * matching {$TCS.SITE.GROUP.PREFIX}), domains (wireless/switches/servers/
 * surveillance bucketed by template name match), recent triggers, recent
 * events, 24h problem-open timeline.
 *
 * Everything here uses only Zabbix core APIs: host.get, hostgroup.get,
 * problem.get, event.get, proxy.get, trigger.get. No external systems.
 */
class ActionGlobalData extends ActionDataBase {

    /** Host-group name prefix that marks a "site" rollup group. */
    private const SITE_GROUP_PREFIX = 'Site/';

    /** Aggregator hosts whose problems must not roll up onto the per-site
     *  heatmap — they carry fleet-wide alerts (e.g. one row per AP) that
     *  would collapse onto whichever single tile the aggregator lives in.
     *  These hosts still count in the severity strip and domain cards;
     *  they'll get their own overview tile elsewhere. */
    private const HEATMAP_EXCLUDE_HOSTS = ['XIQ_AP'];

    /** Host groups whose members are excluded from the global dashboard
     *  entirely — totals, sites, domains, problems, the lot. Currently
     *  only the auto-discovered per-camera hosts created by the
     *  Milestone XProtect template's host_prototype:
     *
     *    Each camera has an SNMP interface as its main interface (set
     *    by the host_prototype) but most cameras have no SNMP items
     *    polling that interface (only Bosch models link the SNMP
     *    vendor template). Zabbix marks the interface as unavailable
     *    after a few failed checks, and the global "X / Y hosts up"
     *    counter buckets thousands of cameras as "down".
     *
     *  Per-camera health lives in its own dedicated Surveillance
     *  dashboard (tcs.surveillance.view); the global rollup is for
     *  infrastructure (servers, switches, APs, NVRs) where camera
     *  noise actively misleads. */
    private const EXCLUDE_HOST_GROUPS = [
        'Discovered hosts/Milestone Cameras',
    ];

    /** Template-name substrings that bucket a host into a domain card. */
    private const DOMAIN_PATTERNS = [
        'wireless' => ['XIQ', 'Extreme AP', 'WLC', 'wireless'],
        'switches' => ['EXOS', 'switch', 'IOS', 'Switch'],
        'servers'  => ['Linux', 'Windows', 'iDRAC', 'OS by'],
        'nvr'      => ['Milestone', 'XProtect', 'NVR']
    ];

    /** Range key → window in seconds (used for both timeline + recent events). */
    private const RANGES = [
        '1h'  =>     3600,
        '6h'  =>  6 * 3600,
        '24h' => 24 * 3600,
        '7d'  =>  7 * 86400
    ];

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'range' => 'string',
            'debug' => 'string'
        ]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $payload = $this->collect($this->getInput('range', '24h'));
        if ($this->getInput('debug', '') !== '') {
            $payload['_debug'] = $this->lastDebug;
        }
        $this->setResponse(new CControllerResponseData(['main_block' => json_encode($payload)]));
    }

    /** Populated by collect() so doAction() can attach it when ?debug=1. */
    private array $lastDebug = [];

    /**
     * Build the full payload. Called by ActionGlobal::doAction() too, so
     * the first paint uses the same data shape as the polled refresh.
     */
    public function collect(string $range = '24h'): array {
        $window_secs = self::RANGES[$range] ?? self::RANGES['24h'];
        $hosts = $this->safeGet(fn() => API::Host()->get([
            'output'                => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
            'selectInterfaces'      => ['available', 'main'],
            'selectHostGroups'      => ['groupid', 'name'],
            'selectParentTemplates' => ['name'],
            'monitored_hosts'       => true,
            'preservekeys'          => true
        ]));

        // Drop hosts belonging to any EXCLUDE_HOST_GROUPS group before
        // anything downstream sees them. The discovered Milestone
        // camera hosts in particular flood the host-availability
        // counters with thousands of "down" SNMP interfaces that
        // operators monitor via the dedicated Surveillance dashboard.
        if (self::EXCLUDE_HOST_GROUPS) {
            $hosts = array_filter($hosts, function ($h) {
                foreach ($h['hostgroups'] ?? [] as $g) {
                    if (in_array($g['name'] ?? '', self::EXCLUDE_HOST_GROUPS, true)) {
                        return false;
                    }
                }
                return true;
            });
        }

        // Zabbix 7.0+ renamed proxy.host to proxy.name. Use 'name'.
        $proxies = $this->safeGet(fn() => API::Proxy()->get(['output' => ['proxyid', 'name']]));

        // Build the hostids whitelist for problem.get. Aggregator hosts
        // (XIQ_AP, etc.) host fleet-wide problems that would otherwise
        // dominate the 200-row budget and starve real per-host alerts.
        // Filtering at the API level rather than in PHP means the 200
        // slots are spent entirely on per-host problems.
        $heatmap_hostids = [];
        foreach ($hosts as $h) {
            if (in_array($h['host'] ?? '', self::HEATMAP_EXCLUDE_HOSTS, true)) continue;
            $heatmap_hostids[] = $h['hostid'];
        }

        // Zabbix 7.2 removed selectHosts from problem.get / event.get. We
        // pull objectid (triggerid) here and resolve the trigger→hosts map
        // in one trigger.get call below. suppressed=false drops problems
        // currently silenced by a maintenance window so they don't inflate
        // the health-map tiles.
        $problem_args = [
            'output'     => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged', 'r_eventid'],
            'recent'     => false,
            'suppressed' => false,
            'sortfield'  => ['eventid'],
            'sortorder'  => 'DESC',
            'limit'      => 200
        ];
        if ($heatmap_hostids) $problem_args['hostids'] = $heatmap_hostids;
        $problems = $this->safeGet(fn() => API::Problem()->get($problem_args));
        // Strip resolved rows so totals / sites / domains never double-count
        // recently-recovered problems as still open.
        $problems = array_values(array_filter(
            $problems,
            fn($p) => empty($p['r_eventid']) || (int) $p['r_eventid'] === 0
        ));

        $events_24h = $this->safeGet(fn() => API::Event()->get([
            'output'    => ['eventid', 'clock', 'value'],
            'source'    => EVENT_SOURCE_TRIGGERS,
            'object'    => EVENT_OBJECT_TRIGGER,
            'time_from' => time() - $window_secs,
            'sortfield' => ['eventid'],
            'sortorder' => 'ASC',
            'limit'     => 10000
        ]));

        $recent_events = $this->safeGet(fn() => API::Event()->get([
            'output'    => ['eventid', 'objectid', 'name', 'severity', 'clock', 'value', 'r_eventid'],
            'source'    => EVENT_SOURCE_TRIGGERS,
            'object'    => EVENT_OBJECT_TRIGGER,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit'     => 30
        ]));

        // Resolve triggerid → hosts via trigger.get and graft a synthetic
        // 'hosts' field onto each problem/event row so the rest of this
        // controller can stay shape-compatible with pre-7.2 expectations.
        $trigger_ids = array_unique(array_merge(
            array_column($problems, 'objectid'),
            array_column($recent_events, 'objectid')
        ));
        $trigger_hosts = $this->resolveTriggerHosts($trigger_ids);
        foreach ($problems as &$p)      { $p['hosts'] = $trigger_hosts[$p['objectid']] ?? []; }
        foreach ($recent_events as &$e) { $e['hosts'] = $trigger_hosts[$e['objectid']] ?? []; }
        unset($p, $e);

        return [
            'totals'   => $this->buildTotals($hosts, $problems, $proxies),
            'sites'    => $this->buildSites($hosts, $problems),
            'domains'  => $this->buildDomains($hosts, $problems),
            'triggers' => $this->buildTriggers($problems),
            'events'   => $this->buildEvents($recent_events),
            'timeline' => $this->buildTimeline($events_24h, $window_secs),
            'range'    => $range,
            'ts'       => time()
        ];
    }

    /** triggerid → [{hostid, host, name}, ...] using one trigger.get call. */
    private function resolveTriggerHosts(array $trigger_ids): array {
        if (!$trigger_ids) return [];
        $triggers = $this->safeGet(fn() => API::Trigger()->get([
            'output'      => ['triggerid'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'triggerids'  => array_values($trigger_ids),
            'preservekeys'=> true
        ]));
        $out = [];
        foreach ($triggers as $t) {
            $out[(string) $t['triggerid']] = $t['hosts'] ?? [];
        }
        return $out;
    }

    /** Coerce any API::*->get() result to an array, swallowing thrown
     *  exceptions and false returns so a single broken call doesn't 500
     *  the whole dashboard. */
    private function safeGet(callable $fn): array {
        try {
            $r = $fn();
            return is_array($r) ? $r : [];
        } catch (\Throwable $e) {
            error_log('[tcs] API call failed: '.$e->getMessage());
            return [];
        }
    }

    /* --------------------------------------------------------------------- */

    private function buildTotals(array $hosts, array $problems, array $proxies): array {
        $down = $up = $unknown = 0;
        foreach ($hosts as $h) {
            $availability = $this->hostAvailable($h);
            if ($availability === 1) $up++;
            elseif ($availability === 2) $down++;
            else $unknown++;
        }

        $sev_counts = [0, 0, 0, 0, 0, 0];
        $ack = 0;
        foreach ($problems as $p) {
            $sev = (int) $p['severity'];
            if ($sev >= 0 && $sev <= 5) $sev_counts[$sev]++;
            if ((int) $p['acknowledged'] === 1) $ack++;
        }

        return [
            'hosts'    => [
                'total'   => count($hosts),
                'up'      => $up,
                'down'    => $down,
                'unknown' => $unknown
            ],
            'problems' => [
                // sev 5=disaster, 4=high, 2-3=warning/avg, 0-1=info
                'disaster' => $sev_counts[5],
                'high'     => $sev_counts[4],
                'warning'  => $sev_counts[3] + $sev_counts[2],
                'info'     => $sev_counts[1] + $sev_counts[0],
                'ack'      => $ack
            ],
            'proxies'  => [
                'total'  => count($proxies),
                'online' => count($proxies) // proxy "lastaccess" check is optional polish
            ],
            'sla'       => ['value' => null, 'target' => 99.5],
            'devices'   => ['total' => null, 'online' => null, 'quarantine' => null, 'byod' => null],
            'templates' => ['total' => null, 'version' => '—']
        ];
    }

    private function buildSites(array $hosts, array $problems): array {
        // Bucket hosts by site group. Hosts in zero site-prefix groups go
        // to "Unassigned". Aggregator hosts (XIQ_AP, etc.) never enter the
        // map at all — their problems are filtered below.
        $host_to_site = [];
        $excluded_hostids = [];
        $sites = [];
        foreach ($hosts as $h) {
            if (in_array($h['host'] ?? '', self::HEATMAP_EXCLUDE_HOSTS, true)) {
                $excluded_hostids[(string) $h['hostid']] = true;
                continue;
            }
            $site_group = null;
            foreach ($h['hostgroups'] ?? [] as $g) {
                if (str_starts_with($g['name'], self::SITE_GROUP_PREFIX)) {
                    $site_group = $g;
                    break;
                }
            }
            $id   = $site_group['groupid'] ?? 'unassigned';
            $name = $site_group
                ? substr($site_group['name'], strlen(self::SITE_GROUP_PREFIX))
                : 'Unassigned';

            if (!isset($sites[$id])) {
                $sites[$id] = [
                    'id'       => $id,
                    'name'     => $name,
                    'hosts'    => 0,
                    'problems' => 0,
                    '_sev'     => -1
                ];
            }
            $sites[$id]['hosts']++;
            $host_to_site[(string) $h['hostid']] = $id;
        }

        // Count each problem once per site it touches. A trigger whose
        // expression spans N hosts in the same site must not inflate that
        // site's tile by N.
        $debug_by_site = [];
        $excluded_problems = 0;
        $unmapped_problems = 0;
        foreach ($problems as $p) {
            $sev = (int) $p['severity'];
            $hosts_on_trigger = $p['hosts'] ?? [];
            // Match by host technical name too — the aggregator may not be
            // in host.get if it's filtered out by monitored_hosts, in which
            // case the hostid-based excluded_hostids map is empty.
            $all_excluded = $hosts_on_trigger !== [] && array_reduce(
                $hosts_on_trigger,
                fn($acc, $h) => $acc
                    && (isset($excluded_hostids[(string) $h['hostid']])
                        || in_array($h['host'] ?? '', self::HEATMAP_EXCLUDE_HOSTS, true)),
                true
            );
            if ($all_excluded) {
                $excluded_problems++;
                continue;
            }
            $touched = [];
            foreach ($hosts_on_trigger as $h) {
                $sid = $host_to_site[(string) $h['hostid']] ?? null;
                if ($sid !== null) $touched[$sid] = true;
            }
            // Trigger references hosts that host.get didn't return (template
            // host, disabled, suppressed, etc.). Surface them under the
            // Unassigned tile rather than silently dropping — the operator
            // needs to see the alert, not lose it.
            if (!$touched) {
                $unmapped_problems++;
                if (!isset($sites['unassigned'])) {
                    $sites['unassigned'] = [
                        'id'       => 'unassigned',
                        'name'     => 'Unassigned',
                        'hosts'    => 0,
                        'problems' => 0,
                        '_sev'     => -1
                    ];
                }
                $touched['unassigned'] = true;
            }
            foreach (array_keys($touched) as $sid) {
                $sites[$sid]['problems']++;
                if ($sev > $sites[$sid]['_sev']) $sites[$sid]['_sev'] = $sev;
                $hostnames = [];
                foreach ($p['hosts'] ?? [] as $h) {
                    if (($host_to_site[(string) $h['hostid']] ?? null) === $sid) {
                        $hostnames[] = $h['name'] ?? ($h['host'] ?? $h['hostid']);
                    }
                }
                $debug_by_site[$sid][] = [
                    'eventid'       => $p['eventid'],
                    'triggerid'     => $p['objectid'],
                    'severity'      => $sev,
                    'name'          => $p['name'],
                    'r_eventid'     => $p['r_eventid'] ?? null,
                    'acknowledged'  => $p['acknowledged'] ?? null,
                    'clock'         => (int) $p['clock'],
                    'age_h'         => round((time() - (int) $p['clock']) / 3600, 1),
                    'hosts'         => $hostnames
                ];
            }
        }
        $this->lastDebug = [
            'problem_get_args' => [
                'recent'     => false,
                'suppressed' => false,
                'limit'      => 200,
                'hostids'    => 'non-aggregator only'
            ],
            'problem_get_total_rows' => count($problems),
            'aggregator_excluded'    => [
                'hosts'        => self::HEATMAP_EXCLUDE_HOSTS,
                'api_filtered' => true,
                'problems'     => $excluded_problems
            ],
            'unmapped_to_unassigned' => $unmapped_problems,
            'by_site'                => $debug_by_site
        ];

        $out = [];
        foreach ($sites as $s) {
            $s['sev'] = $this->sevLabel($s['_sev']);
            $s['sla'] = null;
            unset($s['_sev']);
            $out[] = $s;
        }
        usort($out, fn($a, $b) => $b['problems'] <=> $a['problems'] ?: $b['hosts'] <=> $a['hosts']);
        return $out;
    }

    private function buildDomains(array $hosts, array $problems): array {
        // Bucket each host into one domain based on its templates.
        $host_domain = [];
        foreach ($hosts as $hid => $h) {
            $template_names = array_column($h['parentTemplates'] ?? [], 'name');
            $template_blob = implode(' ', $template_names);
            foreach (self::DOMAIN_PATTERNS as $domain => $needles) {
                foreach ($needles as $needle) {
                    if (stripos($template_blob, $needle) !== false) {
                        $host_domain[$hid] = $domain;
                        break 2;
                    }
                }
            }
        }

        // Aggregate problem counts + worst trigger + host availability split
        // by domain. host_avail per domain becomes the basis for the
        // "<up> / <total>" KPI in the snapshot tiles.
        $by_domain = [];
        foreach (array_keys(self::DOMAIN_PATTERNS) as $d) {
            $by_domain[$d] = [
                'total'    => 0,
                'ok'       => 0, 'warn' => 0, 'err' => 0,
                'up'       => 0, 'down' => 0, 'unknown' => 0,
                'hosts_with_problems' => 0,
                'problems' => 0,
                'top'      => '',
                '_top_sev' => -1,
                'spark'    => array_fill(0, 24, 0)
            ];
        }
        foreach ($hosts as $hid => $h) {
            $d = $host_domain[$hid] ?? null;
            if (!$d) continue;
            $by_domain[$d]['total']++;
            $avail = $this->hostAvailable($h);
            if      ($avail === 1) $by_domain[$d]['up']++;
            elseif  ($avail === 2) $by_domain[$d]['down']++;
            else                   $by_domain[$d]['unknown']++;

            $sev = $this->hostWorstSev($hid, $problems);
            if      ($sev >= 4) $by_domain[$d]['err']++;
            elseif  ($sev >= 2) $by_domain[$d]['warn']++;
            else                $by_domain[$d]['ok']++;
            if ($sev >= 2) $by_domain[$d]['hosts_with_problems']++;
        }

        foreach ($problems as $p) {
            foreach ($p['hosts'] ?? [] as $h) {
                $d = $host_domain[$h['hostid']] ?? null;
                if (!$d) continue;
                $by_domain[$d]['problems']++;
                $sev = (int) $p['severity'];
                if ($sev > $by_domain[$d]['_top_sev']) {
                    $by_domain[$d]['_top_sev'] = $sev;
                    $by_domain[$d]['top'] = ($h['name'] ?? $h['host']).': '.$p['name'];
                }
            }
        }

        // Wireless enrichment — pull xiq.ap.connected[*] and xiq.ap.clients[*]
        // items once, sum across the fleet. Same item shape ActionXiqData
        // reads, so this stays cheap (one API::Item.get) and consistent with
        // the wireless dashboard's totals.
        $wireless = $this->collectWirelessFleetKpis();

        // Surveillance enrichment — pull milestone.cam.status[*] across all
        // Milestone Site hosts. The per-camera Zabbix hosts are filtered out
        // by EXCLUDE_HOST_GROUPS above (their SNMP-interface state would
        // otherwise mark thousands as "down"); these status items live on
        // the Site host and carry the combined-status calc per camera.
        $surveillance = $this->collectSurveillanceFleetKpis();

        // Domain labels + click-through targets — mirror the design's
        // SystemSnapshot tiles so the React renderer doesn't need changes.
        static $meta = [
            'wireless' => [
                'label' => 'Wireless · XIQ',         'sub'        => 'ExtremeCloud IQ',
                'icon'  => 'wifi',                   'src'        => 'ext',
                'href'  => 'zabbix.php?action=tcs.xiq.view',
                'sparkColor' => 'var(--ext)',        'sparkLabel' => 'Connected clients · 24h'
            ],
            'switches' => [
                'label' => 'Switches',                'sub'        => 'Extreme Universal',
                'icon'  => 'ethernet',                'src'        => 'zbx',
                'href'  => 'zabbix.php?action=tcs.switches.view',
                'sparkColor' => 'var(--zbx)',         'sparkLabel' => 'Hosts up · 24h'
            ],
            'servers'  => [
                'label' => 'Servers',                 'sub'        => 'Linux / Windows · VM + physical',
                'icon'  => 'ap',                      'src'        => 'zbx',
                'href'  => 'zabbix.php?action=tcs.servers.view',
                'sparkColor' => 'var(--zbx)',         'sparkLabel' => 'Hosts up · 24h'
            ],
            'nvr'      => [
                'label' => 'Surveillance · Milestone','sub'        => 'XProtect',
                'icon'  => 'shield',                  'src'        => 'ext',
                'href'  => 'zabbix.php?action=tcs.surveillance.view',
                'sparkColor' => 'var(--ext)',         'sparkLabel' => 'Cameras online · 24h'
            ]
        ];

        $out = [];
        foreach ($by_domain as $id => $d) {
            unset($d['_top_sev']);
            // For the NVR domain, swap the host-based totals (which are 0
            // by design since per-camera hosts are excluded) for the
            // Milestone-side camera fleet counts before status / kpis
            // are computed. Keeps status-colour logic honest.
            if ($id === 'nvr' && $surveillance['total'] !== null) {
                $d['total'] = $surveillance['total'];
                $d['up']    = $surveillance['online'];
                $d['down']  = max(0, $surveillance['total'] - $surveillance['online']);
            }
            $d['status'] = $this->domainStatus($d);
            $d['kpis']   = $this->buildDomainKpis($id, $d, $wireless, $surveillance);
            // Live wireless: surface the current client total as a flat
            // sparkline so the labelled "Connected clients" graph isn't a
            // dead line of zeros until proper history wiring lands.
            if ($id === 'wireless' && $wireless['clients_total'] !== null) {
                $d['spark'] = array_fill(0, 24, $wireless['clients_total']);
            }
            // NVR sparkline: flat at current online count, same treatment.
            if ($id === 'nvr' && $surveillance['online'] !== null) {
                $d['spark'] = array_fill(0, 24, $surveillance['online']);
            }
            $tileMeta = $meta[$id] ?? [];
            // Refine the subtitle with a live host count if we have one.
            // NVR uses camera count instead of host count since per-camera
            // hosts don't appear in $d['total'] anymore.
            if ($id === 'nvr' && $surveillance['total']) {
                $tileMeta['sub'] = trim(($tileMeta['sub'] ?? '').' · '.number_format($surveillance['total']).' cameras');
            } elseif ($d['total'] > 0 && isset($tileMeta['sub'])) {
                $tileMeta['sub'] = trim($tileMeta['sub'].' · '.number_format($d['total']).' hosts');
            }
            $out[] = array_merge(['id' => $id], $tileMeta, $d);
        }
        return $out;
    }

    /** Worst-severity-aware status label, used to colour the tile header. */
    private function domainStatus(array $d): string {
        if ($d['err']  > 0) return 'high';
        if ($d['warn'] > 0) return 'warning';
        if ($d['down'] > 0) return 'warning';
        return 'ok';
    }

    /**
     * Build the 3-KPI tile content shown in the System Snapshot.
     * Each KPI is { label, value, unit?, note? } — same shape the JSX renders.
     *
     * @param array{
     *     online: ?int, total: ?int,
     *     critical: ?int, offline: ?int,
     *     clients_total: ?int, rf_health: ?int
     * } $wireless
     * @param array{
     *     online: ?int, total: ?int, faulted: ?int, disabled: ?int
     * } $surveillance
     */
    private function buildDomainKpis(string $domain, array $d, array $wireless, array $surveillance = []): array {
        $fmt = fn(?int $n) => $n === null ? '—' : number_format($n);
        $up = $d['up']; $total = $d['total']; $down = $d['down'];
        $withProblems = $d['hosts_with_problems'];

        if ($domain === 'wireless') {
            // Live xiq.ap.* item totals beat the template-bucketed host
            // counts because per-AP hosts may not match DOMAIN_PATTERNS yet.
            $apsOnline = $wireless['online'] ?? $up;
            $apsTotal  = $wireless['total']  ?? $total;
            $clients   = $wireless['clients_total'];
            $rf        = $wireless['rf_health'];
            $apsKpiNote = $withProblems > 0
                ? $fmt($withProblems).' APs with problems'
                : 'no AP problems';
            $clientsNote = $apsOnline > 0 && $clients !== null
                ? 'avg '.number_format(round($clients / max(1, $apsOnline), 1), 1).' / AP'
                : '';
            return [
                ['label' => 'APs online',        'value' => $fmt($apsOnline).' / '.$fmt($apsTotal), 'note' => $apsKpiNote],
                ['label' => 'Connected clients', 'value' => $clients === null ? '—' : $fmt($clients), 'note' => $clientsNote],
                ['label' => 'RF health',         'value' => $rf === null ? '—' : (string) $rf, 'unit' => '/100', 'note' => 'target ≥ 90'],
            ];
        }

        if ($domain === 'switches') {
            return [
                ['label' => 'Switches up', 'value' => $fmt($up).' / '.$fmt($total), 'note' => $down > 0 ? $fmt($down).' unreachable' : 'all reachable'],
                ['label' => 'With problems', 'value' => $fmt($withProblems), 'note' => $d['err'] > 0 ? $fmt($d['err']).' critical' : ''],
                ['label' => 'Open alerts', 'value' => $fmt($d['problems']), 'note' => $d['err'] > 0 ? 'inc. '.$fmt($d['err']).' critical' : ''],
            ];
        }

        if ($domain === 'servers') {
            return [
                ['label' => 'Servers up',    'value' => $fmt($up).' / '.$fmt($total), 'note' => $down > 0 ? $fmt($down).' down' : 'all reachable'],
                ['label' => 'With problems', 'value' => $fmt($withProblems), 'note' => $d['err'] > 0 ? $fmt($d['err']).' critical' : ''],
                ['label' => 'Open alerts',   'value' => $fmt($d['problems']), 'note' => $d['err'] > 0 ? 'inc. '.$fmt($d['err']).' critical' : ''],
            ];
        }

        // nvr / surveillance — prefer Milestone-side fleet counts (from
        // milestone.cam.status[*]) over Zabbix host counts. The per-
        // camera hosts are filtered out of $hosts by EXCLUDE_HOST_GROUPS;
        // $up / $total in $d are normally zero on the NVR card. The
        // caller in buildDomains() also swaps these out of $d, so this
        // branch can just read $up / $total directly and the numbers
        // come out right either way.
        $camsOnline   = $surveillance['online']   ?? $up;
        $camsTotal    = $surveillance['total']    ?? $total;
        $camsFaulted  = $surveillance['faulted']  ?? null;
        $camsDisabled = $surveillance['disabled'] ?? null;
        $camsDown     = max(0, $camsTotal - $camsOnline);
        $disabledNote = $camsDisabled !== null && $camsDisabled > 0
            ? $fmt($camsDisabled).' disabled in XProtect'
            : '';
        return [
            ['label' => 'Cameras online', 'value' => $fmt($camsOnline).' / '.$fmt($camsTotal), 'note' => $camsDown > 0 ? $fmt($camsDown).' offline / faulted' : 'all online'],
            ['label' => 'With faults',    'value' => $fmt($camsFaulted), 'note' => $disabledNote],
            ['label' => 'Open alerts',    'value' => $fmt($d['problems']), 'note' => $d['err'] > 0 ? 'inc. '.$fmt($d['err']).' critical' : ''],
        ];
    }

    /**
     * Single API::Item.get over xiq.ap.connected[*] and xiq.ap.clients[*] to
     * derive: how many APs are online, total connected clients, and a simple
     * RF-health score (online ratio capped at 100). Returns all-null shape
     * if no items exist so the tile prints dashes rather than crashing.
     *
     * @return array{online: ?int, total: ?int, offline: ?int, clients_total: ?int, rf_health: ?int}
     */
    private function collectWirelessFleetKpis(): array {
        $items = $this->safeGet(fn() => API::Item()->get([
            'output'      => ['key_', 'lastvalue', 'lastclock'],
            'search'      => ['key_' => 'xiq.ap.'],
            'startSearch' => true,
            'monitored'   => true,
            'limit'       => 50000
        ]));
        if (!$items) {
            return ['online' => null, 'total' => null, 'offline' => null, 'clients_total' => null, 'rf_health' => null];
        }
        $online = 0; $total = 0; $clients = 0;
        $sawConnected = false; $sawClients = false;
        foreach ($items as $it) {
            if (!preg_match('/^xiq\.ap\.([a-z]+)\[(.+)\]$/i', (string) $it['key_'], $m)) continue;
            $type = $m[1];
            $val  = (string) ($it['lastvalue'] ?? '');
            if ($type === 'connected') {
                $sawConnected = true;
                $total++;
                if ((int) $val === 1) $online++;
            } elseif ($type === 'clients') {
                $sawClients = true;
                if ($val !== '') $clients += (int) $val;
            }
        }
        $offline  = $sawConnected ? max(0, $total - $online) : null;
        $rfHealth = $sawConnected && $total > 0 ? (int) round($online / $total * 100) : null;
        return [
            'online'        => $sawConnected ? $online  : null,
            'total'         => $sawConnected ? $total   : null,
            'offline'       => $offline,
            'clients_total' => $sawClients   ? $clients : null,
            'rf_health'     => $rfHealth
        ];
    }

    /**
     * Pull every milestone.cam.status[<cam_id>] item across all monitored
     * hosts. These live on the Milestone Site host(s), one per LLD-
     * discovered camera. Each carries the bit-summed combined status:
     *
     *    -1  Disabled in XProtect (excluded from "total")
     *     0  OK (counted as "online")
     *    1-7 Various fault combinations (counted as "with problems")
     *
     * We compute online/total here so the global dashboard's NVR tile
     * can show real numbers even though the per-camera Zabbix hosts are
     * filtered out of the host-availability rollup (see
     * EXCLUDE_HOST_GROUPS — they'd otherwise dominate every counter).
     *
     * @return array{online: ?int, total: ?int, faulted: ?int, disabled: ?int}
     */
    private function collectSurveillanceFleetKpis(): array {
        $items = $this->safeGet(fn() => API::Item()->get([
            'output'      => ['key_', 'lastvalue', 'lastclock'],
            'search'      => ['key_' => 'milestone.cam.status['],
            'startSearch' => true,
            'monitored'   => true,
            'limit'       => 50000
        ]));
        if (!$items) {
            return ['online' => null, 'total' => null, 'faulted' => null, 'disabled' => null];
        }
        $online = 0; $total = 0; $faulted = 0; $disabled = 0;
        foreach ($items as $it) {
            if (!preg_match('/^milestone\.cam\.status\[/', (string) $it['key_'])) continue;
            $val = (string) ($it['lastvalue'] ?? '');
            if ($val === '') continue;
            $code = (int) $val;
            if ($code === -1) { $disabled++; continue; }
            $total++;
            if      ($code === 0) $online++;
            elseif  ($code > 0)   $faulted++;
        }
        return [
            'online'   => $online,
            'total'    => $total,
            'faulted'  => $faulted,
            'disabled' => $disabled
        ];
    }

    private function buildTriggers(array $problems): array {
        $out = [];
        foreach (array_slice($problems, 0, 30) as $p) {
            $h = ($p['hosts'][0] ?? null);
            $age = max(0, time() - (int) $p['clock']);
            $out[] = [
                'sev'     => $this->sevLabel((int) $p['severity']),
                'age'     => sprintf('%02d:%02d:%02d', intdiv($age, 3600), intdiv($age % 3600, 60), $age % 60),
                'host'    => $h['name'] ?? ($h['host'] ?? '—'),
                'site'    => '',
                'domain'  => '',
                'source'  => 'zbx',
                'trigger' => $p['name'],
                'ack'     => (int) $p['acknowledged'] === 1
            ];
        }
        return $out;
    }

    private function buildEvents(array $events): array {
        $sev_label = [0 => 'info', 1 => 'info', 2 => 'warning', 3 => 'warning', 4 => 'high', 5 => 'disaster'];
        $out = [];
        foreach ($events as $e) {
            $h = ($e['hosts'][0] ?? null);
            $is_firing    = (int) $e['value'] === TRIGGER_VALUE_TRUE;
            $has_recovery = !empty($e['r_eventid']) && (int) $e['r_eventid'] !== 0;
            $still_open   = $is_firing && !$has_recovery;
            $out[] = [
                'ts'     => date('H:i:s', (int) $e['clock']),
                'source' => 'zbx',
                'host'   => $h['name'] ?? ($h['host'] ?? '—'),
                'msg'    => $still_open ? 'Trigger:' : 'Resolved:',
                'obj'    => $e['name'],
                'sev'    => $still_open ? ($sev_label[(int) $e['severity']] ?? 'info') : 'ok'
            ];
        }
        return $out;
    }

    private function buildTimeline(array $events, int $window_secs): array {
        // Always 24 buckets — bucket width = window / 24.
        $buckets = array_fill(0, 24, 0);
        $start = time() - $window_secs;
        $bucket_secs = max(1, intdiv($window_secs, 24));
        foreach ($events as $e) {
            if ((int) $e['value'] !== TRIGGER_VALUE_TRUE) continue;
            $b = intdiv((int) $e['clock'] - $start, $bucket_secs);
            if ($b >= 0 && $b < 24) $buckets[$b]++;
        }
        return $buckets;
    }

    /* --------------------------------------------------------------------- */

    private function hostAvailable(array $host): int {
        $any_main = false;
        foreach ($host['interfaces'] ?? [] as $i) {
            if ((int) ($i['main'] ?? 0) !== 1) continue;
            $any_main = true;
            if ((int) ($i['available'] ?? 0) === 1) return 1;
        }
        return $any_main ? 2 : 0;
    }

    private function hostWorstSev($hostid, array $problems): int {
        $hostid = (string) $hostid;
        $worst = -1;
        foreach ($problems as $p) {
            foreach ($p['hosts'] ?? [] as $h) {
                if ((string) $h['hostid'] === $hostid) {
                    $sev = (int) $p['severity'];
                    if ($sev > $worst) $worst = $sev;
                }
            }
        }
        return $worst;
    }

    private function sevLabel(int $sev): string {
        return match (true) {
            $sev === 5 => 'disaster',
            $sev === 4 => 'high',
            $sev >= 2  => 'warning',
            $sev >= 0  => 'info',
            default    => 'ok'
        };
    }
}
