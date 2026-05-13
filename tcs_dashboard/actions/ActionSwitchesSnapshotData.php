<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\TcsDashboard\Lib\PFClient;
use Modules\TcsDashboard\Lib\SwitchClient;

/**
 * GET zabbix.php?action=tcs.switches.snapshot.data&switchid=NNN
 *
 * Returns the per-host port / PoE / KPI / history / uplinks / FDB / problems
 * payload as JSON. Loaded async by the Switches page after first paint so
 * the React shell can render immediately and tiles fill in as the response
 * arrives. Identical shape to the SSR boot payload (minus `fleet`).
 */
class ActionSwitchesSnapshotData extends ActionDataBase {

    protected function checkInput(): bool {
        $ret = $this->validateInput(['switchid' => 'required|string']);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $hostid = (string) $this->getInput('switchid');

        // Reuse ActionSwitches' private collectHost/collectProblems via
        // reflection so we don't duplicate the API queries.
        $view = new ActionSwitches();
        $rc = new \ReflectionClass($view);
        $invoke = function (string $method, array $args) use ($rc, $view) {
            $m = $rc->getMethod($method);
            $m->setAccessible(true);
            return $m->invokeArgs($view, $args);
        };

        $payload = [
            'host'     => null,
            'members'  => [],
            'ports'    => [],
            'poe'      => [],
            'fdb'      => [],
            'kpis'     => new \stdClass(),
            'history'  => new \stdClass(),
            'uplinks'  => [],
            'problems' => [],
            'ts'       => time()
        ];

        try {
            $payload['host'] = $invoke('collectHost', [$hostid]);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] snapshot.data collectHost: '.$e->getMessage());
        }

        try {
            $snap = (new SwitchClient())->snapshot($hostid);
            $payload = array_merge($payload, $snap);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] snapshot.data snapshot: '.$e->getMessage());
        }

        try {
            $payload['problems'] = $invoke('collectProblems', [$hostid, 25]);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] snapshot.data problems: '.$e->getMessage());
        }

        try {
            $payload['pfNodes'] = $this->collectPfNodes($hostid, $payload['host'] ?? null);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] snapshot.data pfNodes: '.$e->getMessage());
            $payload['pfNodes'] = new \stdClass();
        }

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES)
        ]));
    }

    /**
     * Pull PacketFence nodes currently associated with this switch and bucket
     * them by "member.port" so the React port-detail can light up the
     * PacketFence tile from a snapshot read.
     *
     * Returns a map { "m.p" => [device, ...] }. Multiple devices per port are
     * common (uplinks, hubs); the first row is treated as primary, additional
     * rows feed the "N MACS" badge.
     *
     * @return array<string, array<int, array<string, mixed>>>|\stdClass
     */
    private function collectPfNodes(string $hostid, ?array $host) {
        $macros = $this->resolvePfMacros($hostid);
        if ($macros === null) {
            error_log('[tcs_dashboard] pfNodes: skipped — {$PF.URL/USER/PASSWORD} not set on host '.$hostid);
            return new \stdClass();
        }

        // PF stores switches by whatever identifier was set in switches.conf —
        // usually IP, sometimes hostname (or visible_name). OR all three so we
        // hit regardless of how the operator configured it.
        $candidates = array_values(array_unique(array_filter([
            (string) ($host['host']         ?? ''),
            (string) ($host['visible_name'] ?? ''),
            (string) ($host['ip']           ?? ''),
        ], fn($s) => $s !== '')));
        if (!$candidates) {
            error_log('[tcs_dashboard] pfNodes: no switch identifier on host '.$hostid);
            return new \stdClass();
        }

        $pf = PFClient::fromMacros($macros);
        $devices = $pf->devicesOnSwitch($candidates);

        $bag = [];
        $skipped = 0;
        foreach ($devices as $d) {
            $idx = self::parseIfIndex((string) ($d['port'] ?? ''));
            if ($idx === null) { $skipped++; continue; }
            $key = $idx[0].'.'.$idx[1];
            $bag[$key] ??= [];
            $bag[$key][] = $d;
        }
        error_log(sprintf(
            '[tcs_dashboard] pfNodes: host=%s candidates=[%s] devices=%d bucketed=%d skipped=%d',
            $hostid, implode(',', $candidates), count($devices), count($bag), $skipped
        ));
        return $bag ?: new \stdClass();
    }

    /**
     * Decode PF's `locationlog.port` (SNMP ifIndex string) into [member, port]
     * using the Extreme EXOS encoding (idx = 1000 * member + port). Mirrors
     * SwitchClient::parseMemberPort's ifIndex branch.
     *
     * @return array{0:int,1:int}|null
     */
    private static function parseIfIndex(string $port): ?array {
        $port = trim($port);
        if ($port === '') return null;
        if (preg_match('/^(\d+)\.(\d+)$/', $port, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (!preg_match('/^\d+$/', $port)) return null;
        $idx = (int) $port;
        if ($idx <= 0) return null;
        if ($idx < 1000) return [1, $idx];
        $member = intdiv($idx, 1000);
        $p      = $idx % 1000;
        if ($member < 1 || $member > 8 || $p <= 0) return null;
        return [$member, $p];
    }

    /**
     * Read {$PF.*} macros for this hostid. Mirrors ActionDashboard's resolver
     * (kept local so the snapshot action doesn't reach across action classes).
     *
     * @return array{url:string,user:string,pass:string,verify_ssl:bool}|null
     */
    private function resolvePfMacros(string $hostid): ?array {
        $rows = API::UserMacro()->get([
            'output'  => ['macro', 'value'],
            'hostids' => [$hostid],
            'filter'  => ['macro' => ['{$PF.URL}', '{$PF.USER}', '{$PF.PASSWORD}', '{$PF.VERIFY.SSL}']]
        ]) ?: [];

        $bag = [];
        foreach ($rows as $r) {
            $bag[$r['macro']] = (string) $r['value'];
        }

        $url  = $bag['{$PF.URL}']  ?? '';
        $user = $bag['{$PF.USER}'] ?? '';
        $pass = $bag['{$PF.PASSWORD}'] ?? '';
        if ($url === '' || $user === '' || $pass === '') return null;

        return [
            'url'        => $url,
            'user'       => $user,
            'pass'       => $pass,
            'verify_ssl' => ($bag['{$PF.VERIFY.SSL}'] ?? '1') !== '0'
        ];
    }
}
