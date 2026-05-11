<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.servers.data[&hostid=NNN]
 *
 * Returns the live snapshot for the Servers fleet view:
 *   - sites:     fleet bucketed by site host-group
 *   - problems:  recent triggers across the server fleet
 *   - active:    deep detail for ?hostid (history, FS, ifaces, top procs)
 *
 * Host-group convention: we treat any host in groups containing "Server"
 * (case-insensitive) as part of the fleet. Site rollup uses the same
 * "Site/<name>" prefix convention as ActionGlobalData.
 */
class ActionServersData extends CController {

    private const SITE_GROUP_PREFIX = 'Site/';
    private const FLEET_GROUP_NEEDLE = 'Server';

    /** Logical-name → item-key map for fleet tile values. */
    private const FLEET_KEYS = [
        'cpu'     => 'system.cpu.util',
        'mem'     => 'vm.memory.utilization',
        'diskPct' => 'vfs.fs.pused[/]',          // root mount; Windows hosts override below
        'netMbps' => 'net.if.total.bps',         // synthetic if templated; else 0
        'uptime'  => 'system.uptime'
    ];

    /** History keys collected for the "active server" detail. */
    private const HISTORY_KEYS = [
        'cpu1m'    => 'system.cpu.util[,,avg1]',
        'cpu5m'    => 'system.cpu.util[,,avg5]',
        'memUsed'  => 'vm.memory.utilization',
        'diskRead' => 'vfs.dev.read.rate[sda]',
        'diskWrite'=> 'vfs.dev.write.rate[sda]',
        'netIn'    => 'net.if.in[eth0]',
        'netOut'   => 'net.if.out[eth0]',
        'swap'     => 'system.swap.size[,pused]',
        'load1m'   => 'system.cpu.load[,avg1]'
    ];

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'hostid' => 'string'
        ]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        $payload = $this->collect($this->getInput('hostid', ''));
        $this->setResponse(new CControllerResponseData(['main_block' => json_encode($payload)]));
    }

    public function collect(string $active_hostid = ''): array {
        $fleet_groups = $this->safeGet(fn() => API::HostGroup()->get([
            'output' => ['groupid', 'name'],
            'search' => ['name' => self::FLEET_GROUP_NEEDLE],
            'searchByAny' => true
        ]));
        $group_ids = array_column($fleet_groups, 'groupid');

        if (!$group_ids) {
            return [
                'sites'    => [],
                'problems' => [],
                'active'   => null,
                'ts'       => time()
            ];
        }

        $hosts = $this->safeGet(fn() => API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
            'selectInterfaces' => ['ip', 'main', 'available'],
            'selectInventory'  => ['os', 'model', 'hardware', 'serialno_a'],
            'selectGroups'     => ['groupid', 'name'],
            'groupids'         => $group_ids,
            'monitored_hosts'  => true,
            'preservekeys'     => true
        ]));

        if (!$hosts) {
            return [
                'sites'    => [],
                'problems' => [],
                'active'   => null,
                'ts'       => time()
            ];
        }

        $host_ids = array_keys($hosts);
        $items = $this->collectFleetItems($host_ids);
        $problems = $this->collectProblems($host_ids);

        return [
            'sites'    => $this->buildSites($hosts, $items, $problems, $active_hostid),
            'problems' => $problems,
            'active'   => $active_hostid !== '' ? $this->collectActive($active_hostid) : null,
            'ts'       => time()
        ];
    }

    /* --------------------------------------------------------------------- */

    private function collectFleetItems(array $host_ids): array {
        $items = $this->safeGet(fn() => API::Item()->get([
            'output'   => ['itemid', 'hostid', 'key_', 'lastvalue', 'value_type', 'units'],
            'hostids'  => $host_ids,
            'filter'   => ['key_' => array_values(self::FLEET_KEYS)],
            'webitems' => true
        ]));
        // Index by hostid → logical name.
        $by_host = [];
        $key_to_logical = array_flip(self::FLEET_KEYS);
        foreach ($items as $it) {
            $logical = $key_to_logical[$it['key_']] ?? null;
            if (!$logical) continue;
            $by_host[$it['hostid']][$logical] = $it['lastvalue'];
        }
        return $by_host;
    }

    private function collectProblems(array $host_ids): array {
        // Zabbix 7.2 removed selectHosts from problem.get — resolve hosts
        // via trigger.get after the fact.
        $problems = $this->safeGet(fn() => API::Problem()->get([
            'output'    => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged'],
            'hostids'   => $host_ids,
            'sortfield' => ['clock'],
            'sortorder' => 'DESC',
            'limit'     => 50
        ]));
        $trigger_hosts = $this->resolveTriggerHosts(array_column($problems, 'objectid'));
        foreach ($problems as &$p) { $p['hosts'] = $trigger_hosts[$p['objectid']] ?? []; }
        unset($p);
        $sev_label = [0 => 'info', 1 => 'info', 2 => 'warning', 3 => 'warning', 4 => 'high', 5 => 'disaster'];
        $out = [];
        foreach ($problems as $p) {
            $h = $p['hosts'][0] ?? null;
            $age = max(0, time() - (int) $p['clock']);
            $out[] = [
                'ts'   => date('H:i:s', (int) $p['clock']),
                'sev'  => $sev_label[(int) $p['severity']] ?? 'info',
                'host' => $h['name'] ?? ($h['host'] ?? '—'),
                'trig' => $p['name'],
                'age'  => sprintf('%02d:%02d', intdiv($age, 3600), intdiv($age % 3600, 60)),
                'ack'  => (int) $p['acknowledged'] === 1
            ];
        }
        return $out;
    }

    private function buildSites(array $hosts, array $items, array $problems, string $active_hostid): array {
        // collectProblems() flattens the per-host shape away, so re-fetch a
        // tiny version of the problem list keyed by hostid for the row badges.
        $problem_count_by_host = [];
        $worst_sev_by_host = [];
        $raw_problems = $this->safeGet(fn() => API::Problem()->get([
            'output'  => ['objectid', 'severity'],
            'hostids' => array_keys($hosts),
            'limit'   => 1000
        ]));
        $trigger_hosts = $this->resolveTriggerHosts(array_column($raw_problems, 'objectid'));
        foreach ($raw_problems as $p) {
            $hosts_for_trigger = $trigger_hosts[$p['objectid']] ?? [];
            foreach ($hosts_for_trigger as $h) {
                $hid = $h['hostid'];
                $problem_count_by_host[$hid] = ($problem_count_by_host[$hid] ?? 0) + 1;
                $sev = (int) $p['severity'];
                if ($sev > ($worst_sev_by_host[$hid] ?? -1)) $worst_sev_by_host[$hid] = $sev;
            }
        }

        // Bucket hosts by site.
        $sites = [];
        foreach ($hosts as $hid => $h) {
            $site_name = 'Unassigned';
            $site_id   = 'unassigned';
            foreach ($h['groups'] ?? [] as $g) {
                if (str_starts_with($g['name'], self::SITE_GROUP_PREFIX)) {
                    $site_id   = $g['groupid'];
                    $site_name = substr($g['name'], strlen(self::SITE_GROUP_PREFIX));
                    break;
                }
            }
            if (!isset($sites[$site_id])) {
                $sites[$site_id] = [
                    'id'       => $site_id,
                    'name'     => $site_name,
                    'expanded' => true,
                    'problems' => 0,
                    'servers'  => []
                ];
            }

            $primary_ip = '';
            foreach ($h['interfaces'] ?? [] as $i) {
                if ((int) ($i['main'] ?? 0) === 1) { $primary_ip = $i['ip']; break; }
            }

            $inv = $h['inventory'] ?: [];
            $values = $items[$hid] ?? [];
            $worst_sev = $worst_sev_by_host[$hid] ?? -1;
            $sites[$site_id]['servers'][] = [
                'id'       => $h['host'],
                'hostid'   => $hid,
                'fqdn'     => $h['name'],
                'ip'       => $primary_ip,
                'role'     => $inv['type']     ?? '—',
                'os'       => $inv['os']       ?? '—',
                'model'    => $inv['model']    ?? ($inv['hardware'] ?? '—'),
                'cores'    => null,
                'ram'      => null,
                'diskTb'   => null,
                'cpu'      => $this->numOrNull($values['cpu']     ?? null),
                'mem'      => $this->numOrNull($values['mem']     ?? null),
                'diskPct'  => $this->numOrNull($values['diskPct'] ?? null),
                'netMbps'  => $this->numOrNull($values['netMbps'] ?? null, 1_000_000), // bps → Mbps
                'uptimeDays' => isset($values['uptime']) ? (int) floor(((float) $values['uptime']) / 86400) : null,
                'status'   => $worst_sev >= 4 ? 'err' : ($worst_sev >= 2 ? 'warn' : 'ok'),
                'problems' => $problem_count_by_host[$hid] ?? 0,
                'kind'     => (stripos($inv['model'] ?? '', 'VM') !== false) ? 'vm' : 'phys',
                'selected' => $active_hostid !== '' && $active_hostid === (string) $hid
            ];
            $sites[$site_id]['problems'] += $problem_count_by_host[$hid] ?? 0;
        }

        $out = array_values($sites);
        usort($out, fn($a, $b) => $b['problems'] <=> $a['problems'] ?: strcmp($a['name'], $b['name']));
        return $out;
    }

    private function collectActive(string $hostid): ?array {
        $hosts = $this->safeGet(fn() => API::Host()->get([
            'output'  => ['hostid', 'host', 'name'],
            'hostids' => [$hostid]
        ]));
        if (!$hosts) return null;

        $items = $this->safeGet(fn() => API::Item()->get([
            'output'   => ['itemid', 'key_', 'value_type', 'lastvalue'],
            'hostids'  => [$hostid],
            'filter'   => ['key_' => array_values(self::HISTORY_KEYS)],
            'webitems' => true
        ]));
        $by_key = [];
        foreach ($items as $it) $by_key[$it['key_']] = $it;

        $history = [];
        $from = time() - 24 * 3600;
        foreach (self::HISTORY_KEYS as $logical => $key) {
            if (!isset($by_key[$key])) { $history[$logical] = []; continue; }
            $it = $by_key[$key];
            $vt = (int) $it['value_type'];
            if ($vt !== 0 && $vt !== 3) { $history[$logical] = []; continue; }
            $rows = $this->safeGet(fn() => API::History()->get([
                'output'    => 'extend',
                'history'   => $vt,
                'itemids'   => [$it['itemid']],
                'time_from' => $from,
                'sortfield' => 'clock',
                'sortorder' => 'ASC',
                'limit'     => 48
            ]));
            $history[$logical] = array_map(static fn($r) => (float) $r['value'], $rows);
        }

        return [
            'hostid'  => $hostid,
            'history' => $history,
            'fs'      => $this->collectFilesystems($hostid),
            'ifaces'  => $this->collectInterfaces($hostid),
            'procs'   => [], // procs require proc.cpu/mem items per pid — left for v2
            'services'=> [], // service.info[*] discovery — left for v2
            'sessions'=> []  // no Zabbix-native SQL/RDP session item — stays empty
        ];
    }

    private function collectFilesystems(string $hostid): array {
        $items = $this->safeGet(fn() => API::Item()->get([
            'output'   => ['key_', 'lastvalue', 'name'],
            'hostids'  => [$hostid],
            'search'   => ['key_' => 'vfs.fs.size['],
            'startSearch' => true,
            'webitems' => true
        ]));
        // Group by mount: vfs.fs.size[<mount>,total] / vfs.fs.size[<mount>,pused]
        $by_mount = [];
        foreach ($items as $it) {
            if (!preg_match('/^vfs\.fs\.size\[([^,]+),(\w+)\]$/', $it['key_'], $m)) continue;
            $by_mount[$m[1]][$m[2]] = (float) $it['lastvalue'];
        }
        $out = [];
        foreach ($by_mount as $mount => $vals) {
            $total = $vals['total'] ?? null;
            $used_pct = $vals['pused'] ?? null;
            $free = ($total !== null && $used_pct !== null) ? $total * (1 - $used_pct / 100) : null;
            $out[] = [
                'mount'    => $mount,
                'fs'       => '—',
                'sizeGb'   => $total !== null ? round($total / 1024 / 1024 / 1024, 1) : null,
                'usedPct'  => $used_pct !== null ? round($used_pct, 1) : null,
                'freeGb'   => $free !== null ? round($free / 1024 / 1024 / 1024, 1) : null,
                'latMs'    => null,
                'status'   => ($used_pct !== null && $used_pct >= 85) ? 'warn' : 'ok'
            ];
        }
        return $out;
    }

    private function collectInterfaces(string $hostid): array {
        $items = $this->safeGet(fn() => API::Item()->get([
            'output'   => ['key_', 'lastvalue', 'name'],
            'hostids'  => [$hostid],
            'search'   => ['key_' => 'net.if.'],
            'startSearch' => true,
            'webitems' => true
        ]));
        $by_iface = [];
        foreach ($items as $it) {
            if (!preg_match('/^net\.if\.(in|out|speed|status)\[([^\]]+)\]$/', $it['key_'], $m)) continue;
            $by_iface[$m[2]][$m[1]] = (float) $it['lastvalue'];
        }
        $out = [];
        foreach ($by_iface as $name => $vals) {
            $out[] = [
                'name'    => $name,
                'speed'   => isset($vals['speed']) ? (int) ($vals['speed'] / 1_000_000) : null, // bps → Mbps
                'ip'      => '',
                'mac'     => '',
                'inMbps'  => isset($vals['in'])  ? round($vals['in']  / 1_000_000, 1) : null,
                'outMbps' => isset($vals['out']) ? round($vals['out'] / 1_000_000, 1) : null,
                'errs'    => 0,
                'status'  => (int) ($vals['status'] ?? 1) === 1 ? 'up' : 'down'
            ];
        }
        return $out;
    }

    /** triggerid → [{hostid, host, name}, ...] using one trigger.get call. */
    private function resolveTriggerHosts(array $trigger_ids): array {
        if (!$trigger_ids) return [];
        $triggers = $this->safeGet(fn() => API::Trigger()->get([
            'output'      => ['triggerid'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'triggerids'  => array_values(array_unique($trigger_ids))
        ]));
        $out = [];
        foreach ($triggers as $t) {
            $out[(string) $t['triggerid']] = $t['hosts'] ?? [];
        }
        return $out;
    }

    /** Coerce any API::*->get() result to an array, swallowing thrown
     *  exceptions and false returns. */
    private function safeGet(callable $fn): array {
        try {
            $r = $fn();
            return is_array($r) ? $r : [];
        } catch (\Throwable $e) {
            error_log('[tcs] API call failed: '.$e->getMessage());
            return [];
        }
    }

    private function numOrNull($v, float $divisor = 1.0) {
        if ($v === null || !is_numeric($v)) return null;
        return $divisor === 1.0 ? round((float) $v, 1) : round((float) $v / $divisor, 1);
    }
}
