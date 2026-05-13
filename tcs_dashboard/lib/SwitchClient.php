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
     * Performance: prior versions made 6 `item.get` calls (one per logical
     * section) — every page load and every navigator click paid all 6. We
     * now do ONE item.get that pulls every item on the host with a single
     * round-trip, then partition by key prefix in PHP. History.get is also
     * batched: items are grouped by `value_type` so we end up with at most
     * 2 history calls instead of 5 (one per KPI). Net result: ~7 fewer
     * API round-trips per request, the dominant cost.
     *
     * @return array{members:array, ports:array, poe:array, fdb:array, kpis:array, history:array, uplinks:array}
     */
    public function snapshot(string $hostid): array {
        $items = API::Item()->get([
            'output'  => ['itemid', 'key_', 'lastvalue', 'value_type', 'units'],
            'hostids' => [$hostid]
        ]) ?: [];

        $members = $this->extractStackMembers($items);
        $ports   = $this->extractPortStatus($items);
        $poe     = $this->extractPoeStatus($items);
        $fdb     = $this->extractFdb($items);
        $kpis    = $this->extractKpis($items);
        // PoE watts is per-port in this template — sum mpower items into a
        // single host-level KPI so the dashboard's "PoE Budget" tile populates.
        $this->derivePoeFromMpower($items, $kpis);

        return [
            'members' => $members,
            'ports'   => array_values($ports),
            'poe'     => array_values($poe),
            'fdb'     => $fdb,
            'kpis'    => $kpis,
            'history' => $this->historyForKpis($kpis),
            'uplinks' => $this->uplinksFromTraffic($traffic = $this->extractTraffic($items)),
            'traffic' => $traffic,
            'speeds'  => $this->extractSpeeds($items),
            'info'    => $this->extractHostInfo($items)
        ];
    }

    /**
     * Host-level identifying / firmware info pulled from the unified item
     * list. Returns a flat map of strings keyed by logical name so the
     * frontend can drop the right value into header pills without per-key
     * negotiation.
     *
     * Template items consumed:
     *   system.hw.firmware                       → firmware
     *   system.hw.model                          → model
     *   system.hw.serialnumber                   → serial
     *   system.hw.version                        → version
     *   system.sw.os[extremePrimarySoftwareRev.0]→ swOs (firmware fallback)
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<string, string>
     */
    private function extractHostInfo(array $items): array {
        // Logical name → ordered list of accepted key forms.
        $map = [
            'firmware' => ['system.hw.firmware'],
            'model'    => ['system.hw.model'],
            'serial'   => ['system.hw.serialnumber'],
            'version'  => ['system.hw.version'],
            'swOs'     => ['system.sw.os[extremePrimarySoftwareRev.0]', 'system.sw.os']
        ];

        $out = [];
        foreach ($map as $logical => $candidates) {
            foreach ($items as $it) {
                $k = (string) $it['key_'];
                $hit = false;
                foreach ($candidates as $cand) {
                    if ($k === $cand || str_starts_with($k, $cand.'[') || str_starts_with($k, $cand)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) continue;
                $v = trim((string) $it['lastvalue']);
                if ($v === '') continue;
                $out[$logical] = $v;
                break;
            }
        }
        return $out;
    }

    /**
     * Per-port link speed in Mbps, keyed by "m.p". Template items:
     *   net.if.speed[ifHighSpeed.<idx>]   — nominally Mbps, but the Extreme
     *                                       EXOS template ships a × 1,000,000
     *                                       preprocessor + units=bps, so the
     *                                       stored value is bps. We have to
     *                                       check the item's units field
     *                                       rather than guess from the key.
     *   net.if.speed[ifSpeed.<idx>]       — bps (per IF-MIB).
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<string, int>
     */
    private function extractSpeeds(array $items): array {
        $out = [];
        foreach ($items as $it) {
            $k = (string) $it['key_'];
            $idx = self::parseMemberPort($k, 'net.if.speed[ifHighSpeed.');
            if ($idx === null) $idx = self::parseMemberPort($k, 'net.if.speed[ifSpeed.');
            if ($idx === null) continue;

            [$member, $port] = $idx;
            $val   = (float) $it['lastvalue'];
            $units = strtolower(trim((string) ($it['units'] ?? '')));

            // Decide the stored unit.
            //   - units explicitly "bps" → divide by 1e6 to get Mbps.
            //   - units explicitly "mbps"/"" with a small value → already Mbps.
            //   - heuristic fallback: anything > 100,000 must be bps (no
            //     realistic interface speed is 100 Tbps in Mbps).
            if ($units === 'bps' || $val > 100_000) {
                $mbps = (int) round($val / 1_000_000);
            } else {
                $mbps = (int) round($val);
            }
            if ($mbps <= 0) continue;
            $out[$member.'.'.$port] = $mbps;
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Partitioners (operate on the unified item list)                    */
    /* ------------------------------------------------------------------ */

    /** @param array<int,array<string,mixed>> $items */
    private function extractStackMembers(array $items): array {
        // Multiple EXOS / generic-SNMP templates use slightly different key
        // names for the stacking LLD prototype. Note that the official
        // Extreme EXOS by SNMP w POE template ships the key
        // `stacking.memeber[…]` (typo, sic) — match it as-is.
        $rxList = [
            '/^(?:extreme\.)?stacking\.member\[(\d+)\]$/',
            '/^(?:extreme\.)?stacking\.memeber\[(\d+)\]$/',
            '/^(?:extreme\.)?stack\.member\[(\d+)\]$/',
            '/^snmp\.(?:stacking|stack)\.(?:member|memeber)\[(\d+)\]$/'
        ];
        $out = [];
        foreach ($items as $it) {
            $k = (string) $it['key_'];
            $idx = null;
            foreach ($rxList as $rx) {
                if (preg_match($rx, $k, $m)) { $idx = (int) $m[1]; break; }
            }
            if ($idx === null || $idx < 1 || $idx > self::STACK_LIMIT) continue;
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

    /** @param array<int,array<string,mixed>> $items */
    private function extractPortStatus(array $items): array {
        // Try the prefixes the various EXOS / generic-SNMP templates use, in
        // order of specificity. parseMemberPort returns null on miss so we
        // fall through to the next candidate.
        $prefixes = [
            'net.if.status[ifOperStatus.',
            'net.if.status[',
            'ifOperStatus[',
            'snmp.interfaces.ifoperstatus[',
            'snmp.interfaces.status[',
            'snmp.interfaces.if.status['
        ];
        $out = [];
        foreach ($items as $it) {
            $k = (string) $it['key_'];
            $idx = null;
            foreach ($prefixes as $p) {
                $idx = self::parseMemberPort($k, $p);
                if ($idx !== null) break;
            }
            if ($idx === null) continue;
            [$member, $port] = $idx;
            $status = (int) $it['lastvalue'];
            $out[$member.'.'.$port] = [
                'member' => $member, 'port' => $port, 'status' => $status,
                'label' => self::ifOperLabel($status),
                'key' => $it['key_'], 'itemid' => (string) $it['itemid']
            ];
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function extractPoeStatus(array $items): array {
        $out = [];
        foreach ($items as $it) {
            $idx = self::parseMemberPort((string) $it['key_'], 'snmp.interfaces.poe.dstatus[');
            if ($idx === null) continue;
            [$member, $port] = $idx;
            $status = (int) $it['lastvalue'];
            $out[$member.'.'.$port] = [
                'member' => $member, 'port' => $port, 'status' => $status,
                'label' => self::poeLabel($status),
                'key' => $it['key_'], 'itemid' => (string) $it['itemid']
            ];
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function extractFdb(array $items): array {
        // Two template variants for the FDB / MAC-learning list:
        //   net.if.mac[<idx>]   (generic SNMP)
        //   port.mac.list[<idx>] (Extreme EXOS by SNMP w POE)
        $prefixes = ['net.if.mac[', 'port.mac.list['];
        $out = [];
        foreach ($items as $it) {
            $k = (string) $it['key_'];
            $idx = null;
            foreach ($prefixes as $p) {
                $idx = self::parseMemberPort($k, $p);
                if ($idx !== null) break;
            }
            if ($idx === null) continue;
            [$member, $port] = $idx;
            $raw = trim((string) $it['lastvalue']);
            if ($raw === '') continue;
            // port.mac.list returns a comma-separated list; explode and emit
            // one FDB row per MAC so the bridge can attach them to the right
            // port detail.
            foreach (preg_split('/[\s,;]+/', $raw) as $tok) {
                $tok = trim((string) $tok);
                if ($tok === '') continue;
                $out[] = [
                    'member' => $member, 'port' => $port,
                    'mac'    => self::normalizeMac($tok),
                    'key'    => $k
                ];
            }
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function extractKpis(array $items): array {
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
                        continue 3;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Extreme EXOS by SNMP w POE doesn't ship a host-level "total PoE watts"
     * item — it has per-port `snmp.interfaces.poe.mpower[<idx>]` (milliwatts).
     * Sum those into a synthetic KPI so the PoE Budget tile populates.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<string,array<string,mixed>> $kpis  modified in place
     */
    private function derivePoeFromMpower(array $items, array &$kpis): void {
        if (isset($kpis['poeWatts'])) return; // template provided one directly

        $milliWatts = 0.0;
        $haveAny = false;
        foreach ($items as $it) {
            $k = (string) $it['key_'];
            if (!str_starts_with($k, 'snmp.interfaces.poe.mpower[')) continue;
            $haveAny = true;
            $milliWatts += (float) $it['lastvalue'];
        }
        if (!$haveAny) return;

        $kpis['poeWatts'] = [
            'itemid'     => '',
            'key'        => 'synthetic:sum(snmp.interfaces.poe.mpower[*])',
            'lastvalue'  => round($milliWatts / 1000.0, 1),  // mW → W
            'value_type' => 0,
            'units'      => 'W'
        ];
    }

    /**
     * Extract per-port traffic + errors from net.if.in/out/errors items.
     * Returns one row per (m.p) key with bps values + error count, so
     * uplinksFromTraffic can pick the top N and makePortDetail can read
     * per-port rates without a second fetch.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<string, array{in:float, out:float, err:int}>
     */
    private function extractTraffic(array $items): array {
        // Per-port rate + error + discard items. Extreme EXOS by SNMP w POE
        // ships in/out variants for each — we keep them separate so the UI
        // can show "in X / out Y" honestly.
        //
        // parseMemberPort handles both single-ifIndex and "m.p" suffixes.
        $prefixes = [
            'in'      => ['net.if.in[ifHCInOctets.',           'net.if.in['],
            'out'     => ['net.if.out[ifHCOutOctets.',         'net.if.out['],
            'errIn'   => ['net.if.in.errors[ifInErrors.',      'net.if.in.errors['],
            'errOut'  => ['net.if.out.errors[ifOutErrors.',    'net.if.out.errors['],
            'discIn'  => ['net.if.in.discards[ifInDiscards.',  'net.if.in.discards['],
            'discOut' => ['net.if.out.discards[ifOutDiscards.','net.if.out.discards[']
        ];

        $by = [];
        foreach ($items as $it) {
            $k = (string) $it['key_'];
            $hit = null; $hitKind = null;
            foreach ($prefixes as $kind => $pfxList) {
                foreach ($pfxList as $p) {
                    $r = self::parseMemberPort($k, $p);
                    if ($r !== null) { $hit = $r; $hitKind = $kind; break 2; }
                }
            }
            if ($hit === null) continue;
            $idx = $hit[0].'.'.$hit[1];
            $by[$idx] ??= [
                'in' => 0.0, 'out' => 0.0,
                'errIn' => 0, 'errOut' => 0,
                'discIn' => 0, 'discOut' => 0
            ];
            $val = (float) $it['lastvalue'];
            if ($hitKind === 'in' || $hitKind === 'out') {
                $by[$idx][$hitKind] = $val;
            } else {
                $by[$idx][$hitKind] = (int) $val;
            }
        }
        return $by;
    }

    /** @param array<string, array<string, int|float>> $traffic */
    private function uplinksFromTraffic(array $traffic, int $limit = 4): array {
        $rows = [];
        foreach ($traffic as $idx => $v) {
            $rows[] = [
                'name'   => str_replace('.', ':', (string) $idx),
                'type'   => '—',
                'peer'   => '—',
                'rxMbps' => round((((float) $v['in'])  * 8) / 1_000_000, 1),
                'txMbps' => round((((float) $v['out']) * 8) / 1_000_000, 1),
                'util'   => 0,
                'errors' => (int) ($v['errIn'] ?? 0) + (int) ($v['errOut'] ?? 0)
            ];
        }
        usort($rows, fn($a, $b) => ($b['rxMbps'] + $b['txMbps']) <=> ($a['rxMbps'] + $a['txMbps']));
        return array_slice($rows, 0, $limit);
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
            // Extreme EXOS template:
            ['prefix',   'system.cpu.util[extremeCpuMonitorTotalUtilization'],
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
            // Extreme EXOS template:
            ['prefix',   'sensor.temp.value[extremeCurrentTemperature'],
            ['prefix',   'sensor.temp.value['],
            ['prefix',   'sensor.temp['],
            ['prefix',   'extreme.temp'],
            ['contains', 'temperature']
        ],
        // poeWatts / poeBudget are not first-class items in the Extreme EXOS
        // template — they're derived in extractKpisDerived() from the per-port
        // mpower values. Leave the matchers here for templates that DO have
        // dedicated host-level items.
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
    /**
     * Pull 24h history for the resolved KPI items, downsampled to 48 buckets
     * (one per 30 min). Items are grouped by `value_type` so we make at
     * most 2 history.get calls (float + uint) instead of one per KPI.
     *
     * @param array<string, array<string, mixed>> $kpis
     * @return array<string, array<int, float>>
     */
    public function historyForKpis(array $kpis): array {
        $now = time();
        $from = $now - 24 * 3600;
        $buckets = 48;
        $bucketSec = (int) (($now - $from) / $buckets);

        // Group resolved KPIs by their history value_type — only float (0)
        // and unsigned-int (3) make sense for sparklines.
        $byType = [];               // value_type => [logical => itemid]
        foreach ($kpis as $logical => $meta) {
            $vt = (int) $meta['value_type'];
            if ($vt !== 0 && $vt !== 3) continue;
            $byType[$vt] ??= [];
            $byType[$vt][$logical] = (string) $meta['itemid'];
        }

        // Pre-populate the result so missing KPIs render flat.
        $out = [];
        foreach (array_keys($kpis) as $logical) $out[$logical] = [];

        foreach ($byType as $vt => $bag) {
            $itemids = array_values($bag);
            $rows = API::History()->get([
                'output'    => ['itemid', 'clock', 'value'],
                'history'   => $vt,
                'itemids'   => $itemids,
                'time_from' => $from,
                'sortfield' => 'clock',
                'sortorder' => 'ASC'
            ]) ?: [];

            // Bucket per itemid in one pass.
            $accum = [];     // itemid => [sum[], cnt[]]
            foreach ($itemids as $iid) {
                $accum[$iid] = [array_fill(0, $buckets, 0.0), array_fill(0, $buckets, 0)];
            }
            foreach ($rows as $r) {
                $iid = (string) $r['itemid'];
                if (!isset($accum[$iid])) continue;
                $i = (int) (((int) $r['clock'] - $from) / max(1, $bucketSec));
                if ($i < 0 || $i >= $buckets) continue;
                $accum[$iid][0][$i] += (float) $r['value'];
                $accum[$iid][1][$i]++;
            }
            foreach ($bag as $logical => $iid) {
                [$sum, $cnt] = $accum[$iid];
                $series = [];
                for ($i = 0; $i < $buckets; $i++) {
                    $series[] = $cnt[$i] > 0 ? round($sum[$i] / $cnt[$i], 2) : 0.0;
                }
                $out[$logical] = $series;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Parse the port identifier out of an item key, accepting either format
     * the wild encounters:
     *
     *   - `<prefix><member>.<port>]`  (older per-stack-port templates)
     *   - `<prefix><ifIndex>]`        (Extreme EXOS by SNMP w POE — uses SNMP
     *                                  ifIndex; ifIndex = 1000 × member + port
     *                                  in stacks, or just port on standalones)
     *
     * Returns [member, port]. Returns null on mismatch.
     *
     * @return array{0:int,1:int}|null
     */
    private static function parseMemberPort(string $key, string $prefix): ?array {
        if (!str_starts_with($key, $prefix)) return null;
        $rest = substr($key, strlen($prefix));
        $rest = rtrim($rest, ']');
        if (($cpos = strpos($rest, ',')) !== false) {
            $rest = substr($rest, 0, $cpos);
        }
        // Dotted "m.p" form: take verbatim.
        if (preg_match('/^(\d+)\.(\d+)$/', $rest, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        // Single ifIndex: derive (member, port) from the standard Extreme
        // EXOS encoding. ifIndex < 1000 → standalone (member 1). >= 1000 →
        // member = ifIndex / 1000, port = ifIndex % 1000. Anything above
        // STACK_LIMIT is rejected — those are usually VLAN / virtual ifs.
        if (preg_match('/^(\d+)$/', $rest, $m)) {
            $idx = (int) $m[1];
            if ($idx <= 0) return null;
            if ($idx < 1000) {
                return [1, $idx];
            }
            $member = intdiv($idx, 1000);
            $port   = $idx % 1000;
            if ($member < 1 || $member > self::STACK_LIMIT || $port <= 0) return null;
            return [$member, $port];
        }
        return null;
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
