<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Lib;

use API;

/**
 * Read-only switch-state collector built on the Zabbix Item API.
 *
 * Re-implements the operationally-interesting logic from
 * jerahl/ZabbixSwitchPortWidgets/switchports/actions/WidgetView.php as
 * instance methods. No external HTTP — every value is sourced from items
 * already populated by the Extreme EXOS by SNMP w POE template
 * (templates/extreme_exos_by_snmp_with_poe.yaml).
 *
 * Item key conventions (per the lifted template):
 *   stacking.member[<n>]                          — stack member presence/role
 *   net.if.status[ifOperStatus.<member>.<port>]   — link state per port
 *   snmp.interfaces.poe.dstatus[<member>.<port>]  — PoE detection status
 *   net.if.mac[<member>.<port>]                   — FDB / MAC-learning rows
 *
 * Valuemap (PoE): 1=Disabled, 2=Searching, 3=DeliveringPower, 4=Fault,
 *                 5=Test, 6=OtherFault.
 */
class SwitchClient {

    /** Max stack members supported by the EXOS template. */
    private const STACK_LIMIT = 8;

    /**
     * Read stacking.member[1..N] for a host. Returns one entry per discovered
     * member, ordered by index. Missing items are skipped.
     *
     * @return array<int, array{index:int, role:string, raw:string, itemid:string}>
     */
    public function stackMembers(string $hostid): array {
        $items = API::Item()->get([
            'output'    => ['itemid', 'key_', 'lastvalue'],
            'hostids'   => [$hostid],
            'search'    => ['key_' => 'stacking.member['],
            'startSearch' => true
        ]) ?: [];

        $out = [];
        foreach ($items as $it) {
            if (!preg_match('/^stacking\.member\[(\d+)\]$/', $it['key_'], $m)) {
                continue;
            }
            $idx = (int) $m[1];
            if ($idx < 1 || $idx > self::STACK_LIMIT) continue;

            $out[$idx] = [
                'index'  => $idx,
                'role'   => self::stackRoleLabel((string) $it['lastvalue']),
                'raw'    => (string) $it['lastvalue'],
                'itemid' => (string) $it['itemid']
            ];
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * Read port operational status for every discovered interface on the host.
     * Returns one entry per port, keyed by "<member>.<port>".
     *
     * @return array<string, array{member:int, port:int, status:int, label:string, key:string, itemid:string}>
     */
    public function portStatus(string $hostid): array {
        $items = API::Item()->get([
            'output'      => ['itemid', 'key_', 'lastvalue'],
            'hostids'     => [$hostid],
            'search'      => ['key_' => 'net.if.status[ifOperStatus.'],
            'startSearch' => true
        ]) ?: [];

        $out = [];
        foreach ($items as $it) {
            $idx = self::parseMemberPort($it['key_'], 'net.if.status[ifOperStatus.');
            if ($idx === null) continue;
            [$member, $port] = $idx;

            $status = (int) $it['lastvalue'];
            $out[$member.'.'.$port] = [
                'member' => $member,
                'port'   => $port,
                'status' => $status,
                'label'  => self::ifOperLabel($status),
                'key'    => $it['key_'],
                'itemid' => (string) $it['itemid']
            ];
        }
        return $out;
    }

    /**
     * Read PoE detection status per port. Keyed by "<member>.<port>".
     *
     * @return array<string, array{member:int, port:int, status:int, label:string, key:string, itemid:string}>
     */
    public function poeStatus(string $hostid): array {
        $items = API::Item()->get([
            'output'      => ['itemid', 'key_', 'lastvalue'],
            'hostids'     => [$hostid],
            'search'      => ['key_' => 'snmp.interfaces.poe.dstatus['],
            'startSearch' => true
        ]) ?: [];

        $out = [];
        foreach ($items as $it) {
            $idx = self::parseMemberPort($it['key_'], 'snmp.interfaces.poe.dstatus[');
            if ($idx === null) continue;
            [$member, $port] = $idx;

            $status = (int) $it['lastvalue'];
            $out[$member.'.'.$port] = [
                'member' => $member,
                'port'   => $port,
                'status' => $status,
                'label'  => self::poeLabel($status),
                'key'    => $it['key_'],
                'itemid' => (string) $it['itemid']
            ];
        }
        return $out;
    }

    /**
     * Read the MAC-learning table for the host, if items exist. Each row is
     * one MAC observed on one port. Returns [] when no FDB items are present
     * (Bridge-MIB walk not templated on this host).
     *
     * @return array<int, array{member:int, port:int, mac:string, key:string}>
     */
    public function fdbTable(string $hostid): array {
        $items = API::Item()->get([
            'output'      => ['itemid', 'key_', 'lastvalue'],
            'hostids'     => [$hostid],
            'search'      => ['key_' => 'net.if.mac['],
            'startSearch' => true
        ]) ?: [];

        $out = [];
        foreach ($items as $it) {
            $idx = self::parseMemberPort($it['key_'], 'net.if.mac[');
            if ($idx === null) continue;
            [$member, $port] = $idx;

            $mac = trim((string) $it['lastvalue']);
            if ($mac === '') continue;

            $out[] = [
                'member' => $member,
                'port'   => $port,
                'mac'    => self::normalizeMac($mac),
                'key'    => $it['key_']
            ];
        }
        return $out;
    }

    /**
     * Aggregate everything the dashboard needs to render the Switches page
     * for a single host, in one pass.
     *
     * @return array{members:array, ports:array, poe:array, fdb:array, kpis:array, history:array, uplinks:array}
     */
    public function snapshot(string $hostid): array {
        $kpis = $this->kpis($hostid);
        return [
            'members' => $this->stackMembers($hostid),
            'ports'   => array_values($this->portStatus($hostid)),
            'poe'     => array_values($this->poeStatus($hostid)),
            'fdb'     => $this->fdbTable($hostid),
            'kpis'    => $kpis,
            'history' => $this->historyForKpis($kpis),
            'uplinks' => $this->uplinks($hostid)
        ];
    }

    /* ------------------------------------------------------------------ */
    /* KPIs + history                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Logical KPI → list of candidate item-key matchers, first match wins.
     * Each matcher is [mode, needle]; modes: 'exact' | 'prefix' | 'contains'.
     * Templates vary across EXOS / generic-SNMP / agent — list the common
     * forms so the dashboard binds whichever the host actually has.
     */
    private const KPI_MATCHERS = [
        'cpu' => [
            ['exact',    'system.cpu.util'],
            ['prefix',   'system.cpu.util['],
            ['prefix',   'extreme.cpu.util'],
            ['contains', 'cpu.util']
        ],
        'mem' => [
            ['exact',    'vm.memory.utilization'],
            ['prefix',   'vm.memory.util'],
            ['prefix',   'extreme.memory.util'],
            ['contains', 'memory.util']
        ],
        'temp' => [
            ['prefix',   'sensor.temp['],
            ['prefix',   'extreme.temp'],
            ['contains', 'temperature']
        ],
        'poeWatts' => [
            ['prefix',   'extreme.poe.watts'],
            ['prefix',   'snmp.poe.total'],
            ['contains', 'poe.power.consumed']
        ],
        'poeBudget' => [
            ['contains', 'poe.power.budget'],
            ['contains', 'poe.power.max']
        ]
    ];

    /**
     * Resolve all KPI items in one pass over the host's item list, returning
     * { logical => {itemid, key, lastvalue, value_type, units} } for matches.
     *
     * @return array<string, array<string, mixed>>
     */
    public function kpis(string $hostid): array {
        $items = API::Item()->get([
            'output'  => ['itemid', 'key_', 'lastvalue', 'value_type', 'units'],
            'hostids' => [$hostid]
        ]) ?: [];

        $out = [];
        foreach (self::KPI_MATCHERS as $logical => $matchers) {
            foreach ($matchers as [$mode, $needle]) {
                foreach ($items as $it) {
                    $k = (string) $it['key_'];
                    $hit = match ($mode) {
                        'exact'    => $k === $needle,
                        'prefix'   => str_starts_with($k, $needle),
                        'contains' => str_contains($k, $needle),
                        default    => false
                    };
                    if ($hit) {
                        $out[$logical] = [
                            'itemid'     => (string) $it['itemid'],
                            'key'        => $k,
                            'lastvalue'  => is_numeric($it['lastvalue']) ? (float) $it['lastvalue'] : $it['lastvalue'],
                            'value_type' => (int) $it['value_type'],
                            'units'      => (string) $it['units']
                        ];
                        continue 3; // next logical
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Pull 24h history for the resolved KPI items, downsampled to 48 buckets
     * (one per 30 min). Returns one array per logical KPI; missing KPIs
     * return an empty array so the React sparkline renders flat.
     *
     * @param array<string, array<string, mixed>> $kpis
     * @return array<string, array<int, float>>
     */
    public function historyForKpis(array $kpis): array {
        $now = time();
        $from = $now - 24 * 3600;
        $buckets = 48;
        $bucketSec = (int) (($now - $from) / $buckets);

        $out = [];
        foreach ($kpis as $logical => $meta) {
            $vt = (int) $meta['value_type'];
            if ($vt !== 0 && $vt !== 3) { // numeric-float / numeric-uint only
                $out[$logical] = [];
                continue;
            }
            $rows = API::History()->get([
                'output'    => ['clock', 'value'],
                'history'   => $vt,
                'itemids'   => [$meta['itemid']],
                'time_from' => $from,
                'sortfield' => 'clock',
                'sortorder' => 'ASC'
            ]) ?: [];

            // Bucket by floor((clock - from) / bucketSec), average per bucket.
            $sum = array_fill(0, $buckets, 0.0);
            $cnt = array_fill(0, $buckets, 0);
            foreach ($rows as $r) {
                $i = (int) (((int) $r['clock'] - $from) / max(1, $bucketSec));
                if ($i < 0 || $i >= $buckets) continue;
                $sum[$i] += (float) $r['value'];
                $cnt[$i]++;
            }
            $series = [];
            for ($i = 0; $i < $buckets; $i++) {
                $series[] = $cnt[$i] > 0 ? round($sum[$i] / $cnt[$i], 2) : 0.0;
            }
            $out[$logical] = $series;
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Uplinks — top-N interfaces by current rate                         */
    /* ------------------------------------------------------------------ */

    /**
     * Top-N uplink ports by current (lastvalue) bytes-per-second. Reads
     * `net.if.in[*]` and `net.if.out[*]` items, pairs them by SNMP index,
     * and returns rows shaped for the UplinkTable widget.
     *
     * Each row: { name "m:p", type, peer, rxMbps, txMbps, util, errors }
     *
     * @return array<int, array<string, mixed>>
     */
    public function uplinks(string $hostid, int $limit = 4): array {
        $items = API::Item()->get([
            'output'      => ['itemid', 'key_', 'lastvalue', 'units'],
            'hostids'     => [$hostid],
            'search'      => ['key_' => 'net.if.'],
            'startSearch' => true
        ]) ?: [];

        // Group bytes-in / bytes-out / errors by parsed SNMP index.
        $by = [];   // "m.p" => ['in'=>bps, 'out'=>bps, 'err'=>n]
        foreach ($items as $it) {
            $k = (string) $it['key_'];
            $idx = null;
            $kind = null;
            if (preg_match('/^net\.if\.in\[[^,\]]*?(\d+\.\d+)/', $k, $m))       { $idx = $m[1]; $kind = 'in'; }
            elseif (preg_match('/^net\.if\.out\[[^,\]]*?(\d+\.\d+)/', $k, $m))  { $idx = $m[1]; $kind = 'out'; }
            elseif (preg_match('/^net\.if\.errors\[[^,\]]*?(\d+\.\d+)/', $k, $m)) { $idx = $m[1]; $kind = 'err'; }
            if ($idx === null) continue;

            $by[$idx] ??= ['in' => 0.0, 'out' => 0.0, 'err' => 0];
            $val = (float) $it['lastvalue'];
            if ($kind === 'err') $by[$idx]['err'] = (int) $val;
            else                 $by[$idx][$kind] = $val;
        }

        // Convert to Mbps (template typically returns bps), rank by total, take top N.
        $rows = [];
        foreach ($by as $idx => $v) {
            $rxMbps = round(($v['in']  * 8) / 1_000_000, 1);
            $txMbps = round(($v['out'] * 8) / 1_000_000, 1);
            $rows[] = [
                'name'   => str_replace('.', ':', (string) $idx),
                'type'   => '—',
                'peer'   => '—',
                'rxMbps' => $rxMbps,
                'txMbps' => $txMbps,
                'util'   => 0,
                'errors' => (int) $v['err']
            ];
        }
        usort($rows, fn($a, $b) => ($b['rxMbps'] + $b['txMbps']) <=> ($a['rxMbps'] + $a['txMbps']));
        return array_slice($rows, 0, $limit);
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Parse "<prefix><member>.<port>]" → [member, port]. Returns null on
     * mismatch.
     *
     * @return array{0:int,1:int}|null
     */
    private static function parseMemberPort(string $key, string $prefix): ?array {
        if (!str_starts_with($key, $prefix)) return null;
        $rest = substr($key, strlen($prefix));
        // Trim trailing ']' and anything after a comma (some template
        // variants include extra dimensions).
        $rest = rtrim($rest, ']');
        if (($cpos = strpos($rest, ',')) !== false) {
            $rest = substr($rest, 0, $cpos);
        }
        if (!preg_match('/^(\d+)\.(\d+)$/', $rest, $m)) return null;
        return [(int) $m[1], (int) $m[2]];
    }

    private static function ifOperLabel(int $status): string {
        // IF-MIB::ifOperStatus
        return match ($status) {
            1 => 'up',
            2 => 'down',
            3 => 'testing',
            4 => 'unknown',
            5 => 'dormant',
            6 => 'notPresent',
            7 => 'lowerLayerDown',
            default => 'unknown'
        };
    }

    private static function poeLabel(int $status): string {
        // POWER-ETHERNET-MIB::pethPsePortDetectionStatus
        return match ($status) {
            1 => 'disabled',
            2 => 'searching',
            3 => 'delivering',
            4 => 'fault',
            5 => 'test',
            6 => 'otherFault',
            default => 'unknown'
        };
    }

    private static function stackRoleLabel(string $raw): string {
        $n = is_numeric($raw) ? (int) $raw : -1;
        return match ($n) {
            1 => 'master',
            2 => 'backup',
            3 => 'standby',
            default => $raw === '' ? 'absent' : $raw
        };
    }

    private static function normalizeMac(string $mac): string {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac) ?? '');
        if (strlen($hex) !== 12) return $mac;
        return implode(':', str_split($hex, 2));
    }
}
