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
            'range' => 'string'
        ]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $payload = $this->collect($this->getInput('range', '24h'));
        $this->setResponse(new CControllerResponseData(['main_block' => json_encode($payload)]));
    }

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

        // Zabbix 7.0+ renamed proxy.host to proxy.name. Use 'name'.
        $proxies = $this->safeGet(fn() => API::Proxy()->get(['output' => ['proxyid', 'name']]));

        // Zabbix 7.2 removed selectHosts from problem.get / event.get. We
        // pull objectid (triggerid) here and resolve the trigger→hosts map
        // in one trigger.get call below.
        $problems = $this->safeGet(fn() => API::Problem()->get([
            'output'    => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged', 'r_eventid'],
            'recent'    => false,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit'     => 200
        ]));
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
        // Index problems per host for fast site rollup.
        $problems_by_host = [];
        $worst_sev_by_host = [];
        foreach ($problems as $p) {
            $sev = (int) $p['severity'];
            // Health map only counts warning+ — info noise (sev 0/1) was
            // dwarfing real signal on big unassigned buckets.
            if ($sev < 2) continue;
            foreach ($p['hosts'] ?? [] as $h) {
                $hid = $h['hostid'];
                $problems_by_host[$hid] = ($problems_by_host[$hid] ?? 0) + 1;
                if ($sev > ($worst_sev_by_host[$hid] ?? -1)) {
                    $worst_sev_by_host[$hid] = $sev;
                }
            }
        }

        // Bucket hosts by site group. Hosts in zero site-prefix groups go
        // to "Unassigned".
        $sites = [];
        foreach ($hosts as $h) {
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
            $sites[$id]['problems'] += $problems_by_host[$h['hostid']] ?? 0;
            $hsev = $worst_sev_by_host[$h['hostid']] ?? -1;
            if ($hsev > $sites[$id]['_sev']) $sites[$id]['_sev'] = $hsev;
        }

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

        // Aggregate problem counts + worst trigger by domain.
        $by_domain = [];
        foreach (array_keys(self::DOMAIN_PATTERNS) as $d) {
            $by_domain[$d] = [
                'total' => 0, 'ok' => 0, 'warn' => 0, 'err' => 0,
                'problems' => 0, 'top' => '', '_top_sev' => -1, 'spark' => array_fill(0, 24, 0)
            ];
        }
        foreach ($hosts as $hid => $h) {
            $d = $host_domain[$hid] ?? null;
            if (!$d) continue;
            $by_domain[$d]['total']++;
            $sev = $this->hostWorstSev($hid, $problems);
            if      ($sev >= 4) $by_domain[$d]['err']++;
            elseif  ($sev >= 2) $by_domain[$d]['warn']++;
            else                $by_domain[$d]['ok']++;
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

        // Domain labels + click-through targets — mirror the mock shape so
        // the existing React tile renderer doesn't need changes.
        static $meta = [
            'wireless' => ['label' => 'Wireless APs',  'icon' => 'wifi',     'href' => 'zabbix.php?action=tcs.dashboard.view'],
            'switches' => ['label' => 'Switches',      'icon' => 'ethernet', 'href' => 'zabbix.php?action=tcs.switches.view'],
            'servers'  => ['label' => 'Servers',       'icon' => 'ap',       'href' => 'zabbix.php?action=tcs.servers.view'],
            'nvr'      => ['label' => 'Surveillance',  'icon' => 'shield',   'href' => 'zabbix.php?action=tcs.surveillance.view']
        ];

        $out = [];
        foreach ($by_domain as $id => $d) {
            unset($d['_top_sev']);
            $out[] = array_merge(['id' => $id], $meta[$id], $d);
        }
        return $out;
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
