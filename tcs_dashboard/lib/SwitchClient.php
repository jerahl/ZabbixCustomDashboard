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
     * @return array{members:array, ports:array, poe:array, fdb:array}
     */
    public function snapshot(string $hostid): array {
        return [
            'members' => $this->stackMembers($hostid),
            'ports'   => array_values($this->portStatus($hostid)),
            'poe'     => array_values($this->poeStatus($hostid)),
            'fdb'     => $this->fdbTable($hostid)
        ];
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
