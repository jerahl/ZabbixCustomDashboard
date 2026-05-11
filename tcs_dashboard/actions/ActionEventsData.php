<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.events.data
 *   [&severity=disaster,high,warning,info]
 *   [&value=any|firing|resolved]
 *   [&groupids=1,2,3]
 *   [&search=substr]
 *   [&range=1h|6h|24h|7d]
 *
 * Returns a windowed event stream + metrics (rate, timeline, MTTR, top hosts).
 */
class ActionEventsData extends CController {

    private const RANGES = [
        '1h'  =>     3600,
        '6h'  =>  6 * 3600,
        '24h' => 24 * 3600,
        '7d'  =>  7 * 86400
    ];

    private const SEV_LABEL = [
        0 => 'info', 1 => 'info', 2 => 'warning',
        3 => 'warning', 4 => 'high', 5 => 'disaster'
    ];

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'severity' => 'string',
            'value'    => 'string',
            'groupids' => 'string',
            'search'   => 'string',
            'range'    => 'string'
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
        $payload = $this->collect([
            'severity' => $this->getInput('severity', ''),
            'value'    => $this->getInput('value', 'any'),
            'groupids' => $this->getInput('groupids', ''),
            'search'   => $this->getInput('search', ''),
            'range'    => $this->getInput('range', '24h')
        ]);
        $this->setResponse(new CControllerResponseData(['main_block' => json_encode($payload)]));
    }

    public function collect(array $filters = []): array {
        $filters = array_merge([
            'severity' => '', 'value' => 'any', 'groupids' => '',
            'search' => '', 'range' => '24h'
        ], $filters);

        $sev_filter   = array_filter(array_map('trim', explode(',', $filters['severity'])));
        $group_filter = array_filter(array_map('trim', explode(',', $filters['groupids'])));
        $value_filter = $filters['value'];
        $search       = trim($filters['search']);
        $window_secs  = self::RANGES[$filters['range']] ?? self::RANGES['24h'];

        $params = [
            'output'    => ['eventid', 'objectid', 'name', 'severity', 'clock', 'value'],
            'source'    => EVENT_SOURCE_TRIGGERS,
            'object'    => EVENT_OBJECT_TRIGGER,
            'time_from' => time() - $window_secs,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit'     => 1000
        ];
        if ($group_filter) {
            $params['groupids'] = $group_filter;
        }
        $events = $this->safeGet(fn() => API::Event()->get($params));

        $trigger_ids   = array_unique(array_column($events, 'objectid'));
        $trigger_hosts = $this->resolveTriggerHosts($trigger_ids);

        $groups = $this->safeGet(fn() => API::HostGroup()->get([
            'output'             => ['groupid', 'name'],
            'with_monitored_hosts'=> true,
            'sortfield'          => ['name']
        ]));

        $rows = [];
        foreach ($events as $e) {
            $sev_int = (int) $e['severity'];
            $sev = self::SEV_LABEL[$sev_int] ?? 'info';
            $hosts = $trigger_hosts[$e['objectid']] ?? [];
            $host_label = $hosts[0]['name'] ?? ($hosts[0]['host'] ?? '—');
            $host_id    = $hosts[0]['hostid'] ?? '';
            $hostgroups = array_map(fn($g) => $g['name'], $hosts[0]['hostgroups'] ?? []);
            $is_firing  = (int) $e['value'] === TRIGGER_VALUE_TRUE;

            // Resolved events use severity 0 in some Zabbix variants — pull
            // the severity off the original 'PROBLEM' event for display so
            // resolution rows stay coloured. The trigger's own severity is
            // the most consistent fallback.
            if ($value_filter === 'firing'  && !$is_firing) continue;
            if ($value_filter === 'resolved' && $is_firing) continue;
            if ($sev_filter && !in_array($sev, $sev_filter, true)) continue;
            if ($search !== '' && stripos($e['name'].' '.$host_label, $search) === false) continue;

            $rows[] = [
                'eventid'    => $e['eventid'],
                'severity'   => $sev,
                'host'       => $host_label,
                'hostid'     => $host_id,
                'hostgroups' => $hostgroups,
                'name'       => $e['name'],
                'value'      => $is_firing ? 'firing' : 'resolved',
                'clock'      => (int) $e['clock'],
                'ts'         => date('Y-m-d H:i:s', (int) $e['clock'])
            ];
        }

        return [
            'events'  => array_slice($rows, 0, 300),
            'metrics' => $this->buildMetrics($rows, $events, $window_secs),
            'groups'  => array_map(fn($g) => ['id' => $g['groupid'], 'name' => $g['name']], $groups),
            'filters' => $filters,
            'ts'      => time()
        ];
    }

    private function buildMetrics(array $rows, array $raw_events, int $window_secs): array {
        $by_sev   = ['disaster' => 0, 'high' => 0, 'warning' => 0, 'info' => 0];
        $by_host  = [];
        $fired    = 0;
        $resolved = 0;
        foreach ($rows as $r) {
            $by_sev[$r['severity']] = ($by_sev[$r['severity']] ?? 0) + 1;
            $by_host[$r['host']]    = ($by_host[$r['host']] ?? 0) + 1;
            if ($r['value'] === 'firing') $fired++; else $resolved++;
        }
        arsort($by_host);
        $top_hosts = [];
        foreach (array_slice($by_host, 0, 10, true) as $h => $n) $top_hosts[] = ['name' => $h, 'count' => $n];

        // 24 buckets of "fired" event volume; bucket size = window / 24.
        $buckets = array_fill(0, 24, 0);
        $start = time() - $window_secs;
        $bucket_secs = max(1, intdiv($window_secs, 24));
        foreach ($rows as $r) {
            if ($r['value'] !== 'firing') continue;
            $b = intdiv($r['clock'] - $start, $bucket_secs);
            if ($b >= 0 && $b < 24) $buckets[$b]++;
        }

        // Mean time to resolve, in seconds. Pair each "resolved" event to
        // the most-recent earlier "firing" event on the same trigger inside
        // the window. raw_events is sorted DESC by eventid, so iterate
        // ascending for the pairing.
        $mttr = $this->meanTimeToResolve(array_reverse($raw_events));

        return [
            'total'      => count($rows),
            'fired'      => $fired,
            'resolved'   => $resolved,
            'bySeverity' => $by_sev,
            'timeline'   => $buckets,
            'topHosts'   => $top_hosts,
            'mttrSec'    => $mttr,
            'mttrStr'    => $this->fmtAge($mttr)
        ];
    }

    private function meanTimeToResolve(array $events_asc): int {
        $open = []; // triggerid → clock of last firing
        $deltas = [];
        foreach ($events_asc as $e) {
            $tid = $e['objectid'];
            if ((int) $e['value'] === TRIGGER_VALUE_TRUE) {
                $open[$tid] = (int) $e['clock'];
            } else if (isset($open[$tid])) {
                $d = (int) $e['clock'] - $open[$tid];
                if ($d > 0) $deltas[] = $d;
                unset($open[$tid]);
            }
        }
        return $deltas ? (int) round(array_sum($deltas) / count($deltas)) : 0;
    }

    private function resolveTriggerHosts(array $trigger_ids): array {
        if (!$trigger_ids) return [];
        $triggers = $this->safeGet(fn() => API::Trigger()->get([
            'output'       => ['triggerid'],
            'selectHosts'  => ['hostid', 'host', 'name'],
            'triggerids'   => array_values($trigger_ids),
            'preservekeys' => true
        ]));
        $host_ids = [];
        foreach ($triggers as $t) {
            foreach ($t['hosts'] ?? [] as $h) $host_ids[$h['hostid']] = true;
        }
        $hg_map = [];
        if ($host_ids) {
            $hosts = $this->safeGet(fn() => API::Host()->get([
                'output'           => ['hostid'],
                'selectHostGroups' => ['name'],
                'hostids'          => array_keys($host_ids),
                'preservekeys'     => true
            ]));
            foreach ($hosts as $hid => $h) {
                $hg_map[(string) $hid] = $h['hostgroups'] ?? [];
            }
        }
        $out = [];
        foreach ($triggers as $t) {
            $hosts = [];
            foreach ($t['hosts'] ?? [] as $h) {
                $h['hostgroups'] = $hg_map[(string) $h['hostid']] ?? [];
                $hosts[] = $h;
            }
            $out[(string) $t['triggerid']] = $hosts;
        }
        return $out;
    }

    private function fmtAge(int $s): string {
        if ($s <= 0)    return '—';
        if ($s < 60)    return $s.'s';
        if ($s < 3600)  return intdiv($s, 60).'m '.($s % 60).'s';
        if ($s < 86400) return sprintf('%dh %dm', intdiv($s, 3600), intdiv($s % 3600, 60));
        return sprintf('%dd %dh', intdiv($s, 86400), intdiv($s % 86400, 3600));
    }

    private function safeGet(callable $fn): array {
        try {
            $r = $fn();
            return is_array($r) ? $r : [];
        } catch (\Throwable $e) {
            error_log('[tcs] events API call failed: '.$e->getMessage());
            return [];
        }
    }
}
