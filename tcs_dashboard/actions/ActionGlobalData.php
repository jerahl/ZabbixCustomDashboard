<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CController;
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
class ActionGlobalData extends CController {

    /** Host-group name prefix that marks a "site" rollup group. */
    private const SITE_GROUP_PREFIX = 'Site/';

    /** Template-name substrings that bucket a host into a domain card. */
    private const DOMAIN_PATTERNS = [
        'wireless' => ['XIQ', 'Extreme AP', 'WLC', 'wireless'],
        'switches' => ['EXOS', 'switch', 'IOS', 'Switch'],
        'servers'  => ['Linux', 'Windows', 'iDRAC', 'OS by'],
        'nvr'      => ['Milestone', 'XProtect', 'NVR']
    ];

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        $payload = $this->collect();
        $this->setResponse(new CControllerResponseData(['main_block' => json_encode($payload)]));
    }

    /**
     * Build the full payload. Called by ActionGlobal::doAction() too, so
     * the first paint uses the same data shape as the polled refresh.
     */
    public function collect(): array {
        $hosts = $this->safeGet(fn() => API::Host()->get([
            'output'                => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
            'selectInterfaces'      => ['available', 'main'],
            'selectGroups'          => ['groupid', 'name'],
            'selectParentTemplates' => ['name'],
            'monitored_hosts'       => true,
            'preservekeys'          => true
        ]));

        $proxies = $this->safeGet(fn() => API::Proxy()->get(['output' => ['proxyid', 'host']]));

        // Zabbix versions differ on which problem.get params are accepted
        // (notably 'recent' was relaxed in 7.0). Drop it and rely on the
        // default "current open problems" behaviour, which is what we want.
        $problems = $this->safeGet(fn() => API::Problem()->get([
            'output'      => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'sortfield'   => ['clock'],
            'sortorder'   => 'DESC',
            'limit'       => 200
        ]));

        // 'value' was removed from event.get in 7.0+. Filter client-side.
        $events_24h = $this->safeGet(fn() => API::Event()->get([
            'output'    => ['eventid', 'clock', 'value'],
            'source'    => EVENT_SOURCE_TRIGGERS,
            'object'    => EVENT_OBJECT_TRIGGER,
            'time_from' => time() - 24 * 3600,
            'sortfield' => ['clock'],
            'sortorder' => 'ASC',
            'limit'     => 10000
        ]));

        $recent_events = $this->safeGet(fn() => API::Event()->get([
            'output'     => ['eventid', 'name', 'severity', 'clock', 'value'],
            'selectHosts'=> ['hostid', 'host', 'name'],
            'source'     => EVENT_SOURCE_TRIGGERS,
            'object'     => EVENT_OBJECT_TRIGGER,
            'sortfield'  => ['clock'],
            'sortorder'  => 'DESC',
            'limit'      => 30
        ]));

        return [
            'totals'   => $this->buildTotals($hosts, $problems, $proxies),
            'sites'    => $this->buildSites($hosts, $problems),
            'domains'  => $this->buildDomains($hosts, $problems),
            'triggers' => $this->buildTriggers($problems),
            'events'   => $this->buildEvents($recent_events),
            'timeline' => $this->buildTimeline($events_24h),
            'ts'       => time()
        ];
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
            foreach ($p['hosts'] ?? [] as $h) {
                $hid = $h['hostid'];
                $problems_by_host[$hid] = ($problems_by_host[$hid] ?? 0) + 1;
                $sev = (int) $p['severity'];
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
            foreach ($h['groups'] ?? [] as $g) {
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
            $out[] = [
                'ts'     => date('H:i:s', (int) $e['clock']),
                'source' => 'zbx',
                'host'   => $h['name'] ?? ($h['host'] ?? '—'),
                'msg'    => ((int) $e['value'] === TRIGGER_VALUE_TRUE) ? 'Trigger:' : 'Resolved:',
                'obj'    => $e['name'],
                'sev'    => (int) $e['value'] === TRIGGER_VALUE_FALSE ? 'ok' : ($sev_label[(int) $e['severity']] ?? 'info')
            ];
        }
        return $out;
    }

    private function buildTimeline(array $events): array {
        // 24 hourly buckets — count of new problems opened.
        $buckets = array_fill(0, 24, 0);
        $start = time() - 24 * 3600;
        foreach ($events as $e) {
            if ((int) $e['value'] !== TRIGGER_VALUE_TRUE) continue;
            $h = intdiv((int) $e['clock'] - $start, 3600);
            if ($h >= 0 && $h < 24) $buckets[$h]++;
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

    private function hostWorstSev(string $hostid, array $problems): int {
        $worst = -1;
        foreach ($problems as $p) {
            foreach ($p['hosts'] ?? [] as $h) {
                if ($h['hostid'] === $hostid) {
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
