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
            $payload['pfNodes'] = $this->collectPfNodes($hostid, $payload['fdb'] ?? []);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] snapshot.data pfNodes: '.$e->getMessage());
            $payload['pfNodes'] = new \stdClass();
        }

        // PF admin base URL for the "View in PacketFence" link. The PF
        // admin UI and API typically live on different ports (e.g. API on
        // :9999, admin on :1443), so we use a dedicated {$PF.ADMIN_URL}
        // macro rather than deriving from {$PF.URL}.
        $payload['pfBase'] = $this->resolvePfAdminUrl($hostid);

        // User macros table (Macros · CLI tab) — every macro resolved for the
        // host through global → template → host precedence, tagged with its
        // effective source.
        try {
            $payload['macros'] = $this->collectAllMacros($hostid);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] snapshot.data macros: '.$e->getMessage());
            $payload['macros'] = [];
        }

        // ssheasy connect descriptor for the live CLI console. null when
        // {$SSHEASY.URL} isn't set or the host has no management IP.
        try {
            $payload['ssh'] = $this->collectSshConnect($hostid);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] snapshot.data ssh: '.$e->getMessage());
            $payload['ssh'] = null;
        }

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES)
        ]));
    }

    /**
     * Drive the PF lookup off the switch's own FDB instead of trying to
     * filter PF nodes by `locationlog.switch` (which PF v11+ doesn't
     * support on /api/v1/nodes). The FDB already gives us every MAC the
     * switch has learned + its member.port; we just enrich each MAC with
     * PF's registration / fingerprint / hostname data.
     *
     * @param array<int, array{member:int|string, port:int|string, mac:string}> $fdb
     * @return array<string, array<int, array<string, mixed>>>|\stdClass
     */
    private function collectPfNodes(string $hostid, array $fdb) {
        $macros = $this->resolvePfMacros($hostid);
        if ($macros === null) {
            error_log('[tcs_dashboard] pfNodes: skipped — {$PF.URL/USER/PASSWORD} not set on host '.$hostid);
            return new \stdClass();
        }
        if (!$fdb) {
            error_log('[tcs_dashboard] pfNodes: FDB empty for host '.$hostid.' — nothing to enrich');
            return new \stdClass();
        }

        // Collect unique MACs across the whole FDB so we batch into one
        // PF search call rather than one-per-port. Port-bucketing happens
        // on the way back out.
        $macs = [];
        foreach ($fdb as $row) {
            $m = strtolower(trim((string) ($row['mac'] ?? '')));
            if ($m !== '') $macs[$m] = true;
        }
        $macList = array_keys($macs);
        if (!$macList) {
            error_log('[tcs_dashboard] pfNodes: no MACs in FDB for host '.$hostid);
            return new \stdClass();
        }

        $pf = PFClient::fromMacros($macros);
        $byMac    = $pf->nodesByMac($macList);
        // Locationlog gives us the human role label + 802.1X username,
        // neither of which appear on /nodes. Best-effort: if it fails we
        // still emit the device card without role/user.
        $locByMac = [];
        try {
            $locByMac = $pf->locationsByMac(array_keys($byMac) ?: $macList);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] pfNodes: locationlogs lookup failed: '.$e->getMessage());
        }
        // Resolve numeric role ids → names. Some locationlog rows leave
        // `role` as the numeric category_id and /nodes only carries the
        // id, so without this dictionary the tile shows "252" instead of
        // "Faculty". One call, cached implicitly by APCu via the token.
        $catMap = [];
        try {
            $catMap = $pf->nodeCategories();
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] pfNodes: node_categories lookup failed: '.$e->getMessage());
        }
        $resolveRole = function ($raw) use ($catMap): string {
            $s = trim((string) $raw);
            if ($s === '') return '';
            if (ctype_digit($s) && isset($catMap[$s])) return $catMap[$s];
            return $s;
        };

        $bag = [];
        $hits = 0;
        foreach ($fdb as $row) {
            $m = strtolower(trim((string) ($row['mac'] ?? '')));
            if ($m === '' || !isset($byMac[$m])) continue;
            $member = (int) ($row['member'] ?? 0);
            $port   = (int) ($row['port']   ?? 0);
            if ($member <= 0 || $port <= 0) continue;

            $dev = $byMac[$m];
            $loc = $locByMac[$m] ?? null;
            if ($loc) {
                $locRole = trim((string) ($loc['role'] ?? ''));
                if ($locRole !== '') $dev['role'] = $locRole;
                $dot1x = trim((string) ($loc['dot1x_username'] ?? ''));
                if ($dot1x !== '') $dev['owner'] = $dot1x;
                $dev['ssid']    = (string) ($loc['ssid'] ?? '');
                $dev['vlan']    = (string) ($loc['vlan'] ?? '');
                $dev['ifDesc']  = (string) ($loc['ifDesc'] ?? '');
            }
            // Coerce any leftover numeric role id to its label.
            $dev['role'] = $resolveRole($dev['role'] ?? '');

            $key = $member.'.'.$port;
            $bag[$key] ??= [];
            $bag[$key][] = $dev;
            $hits++;
        }
        error_log(sprintf(
            '[tcs_dashboard] pfNodes: host=%s fdbMacs=%d pfMatched=%d locMatched=%d ports=%d',
            $hostid, count($macList), count($byMac), count($locByMac), count($bag)
        ));
        return $bag ?: new \stdClass();
    }

    /**
     * Read {$PF.*} macros for this hostid, falling through host → linked
     * templates → global. Zabbix's UserMacro API doesn't merge these for
     * us — global macros require an explicit `globalmacro: true` call.
     * Precedence: host wins over template wins over global.
     *
     * @return array{url:string,user:string,pass:string,verify_ssl:bool}|null
     */
    /**
     * Resolve {$PF.ADMIN_URL} through the same host → templates → globals
     * chain. Returns '' if unset.
     */
    private function resolvePfAdminUrl(string $hostid): string {
        $bag = $this->macroChain($hostid, ['{$PF.ADMIN_URL}']);
        return rtrim((string) ($bag['{$PF.ADMIN_URL}'] ?? ''), '/');
    }

    /**
     * Every user macro resolved for the host, with the same global →
     * template → host precedence the macroChain helper uses, but for the
     * full macro set rather than a fixed name list. Each row carries the
     * effective value and a `ctx` label naming where the winning definition
     * lives; host-level definitions get a " (override)" suffix so the
     * frontend renders the "host" pill.
     *
     * Secret/Vault macros (type != 0) never return a value through the API —
     * their value is masked and the row is flagged `sys` so the table dims it.
     *
     * @return array<int, array{k:string, v:string, ctx:string, sys:bool}>
     */
    private function collectAllMacros(string $hostid): array {
        // macro => ['v' => string, 'ctx' => string, 'sys' => bool]
        $bag = [];

        $valueOf = static function (array $r): string {
            $type = (int) ($r['type'] ?? 0);
            if ($type !== 0) return '********';          // secret / vault — never exposed
            return array_key_exists('value', $r) ? (string) $r['value'] : '';
        };
        $isSecret = static fn (array $r): bool => ((int) ($r['type'] ?? 0)) !== 0;

        // 1. Globals (lowest precedence).
        $globals = API::UserMacro()->get([
            'output'      => ['macro', 'value', 'type'],
            'globalmacro' => true
        ]) ?: [];
        foreach ($globals as $r) {
            $bag[$r['macro']] = ['v' => $valueOf($r), 'ctx' => 'Global macro', 'sys' => $isSecret($r)];
        }

        // 2. Template-inherited macros — full ancestry, labelled by template name.
        $templateIds = self::collectTemplateAncestry($hostid);
        if ($templateIds) {
            $tpls = API::Template()->get([
                'output'       => ['templateid', 'name'],
                'templateids'  => $templateIds,
                'preservekeys' => true
            ]) ?: [];
            $tplMacros = API::UserMacro()->get([
                'output'  => ['macro', 'value', 'type', 'hostid'],
                'hostids' => $templateIds
            ]) ?: [];
            foreach ($tplMacros as $r) {
                $tname = (string) ($tpls[$r['hostid']]['name'] ?? 'Template');
                $bag[$r['macro']] = ['v' => $valueOf($r), 'ctx' => $tname, 'sys' => $isSecret($r)];
            }
        }

        // 3. Host-level (highest precedence) — flagged as an override.
        $hosts = API::Host()->get([
            'output'  => ['hostid', 'name'],
            'hostids' => [$hostid]
        ]) ?: [];
        $hostLabel = (string) ($hosts[0]['name'] ?? 'host');
        $hostMacros = API::UserMacro()->get([
            'output'  => ['macro', 'value', 'type'],
            'hostids' => [$hostid]
        ]) ?: [];
        foreach ($hostMacros as $r) {
            $bag[$r['macro']] = [
                'v'   => $valueOf($r),
                'ctx' => $hostLabel.' (override)',
                'sys' => $isSecret($r)
            ];
        }

        ksort($bag, SORT_STRING);
        $out = [];
        foreach ($bag as $k => $info) {
            $out[] = ['k' => $k, 'v' => $info['v'], 'ctx' => $info['ctx'], 'sys' => $info['sys']];
        }
        return $out;
    }

    /**
     * Build the ssheasy auto-connect descriptor for the live CLI console.
     * ssheasy auto-connects from query params on its /connect route; we
     * prefill host/port/user/password from macros so the embedded terminal
     * opens straight into the switch.
     *
     * Macros (resolved host → template → global):
     *   {$SSHEASY.URL}    base URL of the ssheasy server (required)
     *   {$SSH.USER}       SSH username
     *   {$SSH.PASSWORD}   SSH password — must be a TEXT macro; Secret/Vault
     *                     macros are never returned by the API, so a secret
     *                     password yields a manual-auth session instead.
     *   {$SSH.PORT}       SSH port (default 22)
     *
     * The SSH target IP is the address Zabbix actually reaches the switch on:
     * its SNMP-type interface (the polling interface). Falls back to the main
     * interface, then the first interface, if no SNMP interface exists.
     *
     * @return array{url:string, host:string, port:string, user:string}|null
     */
    private function collectSshConnect(string $hostid): ?array {
        $bag  = $this->macroChain($hostid, ['{$SSHEASY.URL}', '{$SSH.USER}', '{$SSH.PASSWORD}', '{$SSH.PORT}']);
        $base = rtrim((string) ($bag['{$SSHEASY.URL}'] ?? ''), '/');
        if ($base === '') return null;

        $ip = $this->resolveSwitchIp($hostid);
        if ($ip === '') return null;

        $port = (string) ($bag['{$SSH.PORT}'] ?? '');
        if ($port === '') $port = '22';
        $user = (string) ($bag['{$SSH.USER}'] ?? '');
        $pass = (string) ($bag['{$SSH.PASSWORD}'] ?? '');

        $q = ['host' => $ip, 'port' => $port];
        if ($user !== '') $q['user']     = $user;
        if ($pass !== '') $q['password'] = $pass;
        $q['connect'] = 'true';

        return [
            'url'  => $base.'/connect?'.http_build_query($q),
            'host' => $ip,
            'port' => $port,
            'user' => $user
        ];
    }

    /**
     * The address Zabbix uses to reach the switch. Prefers the SNMP-type
     * interface (type 2 — the polling interface for these EXOS hosts), then
     * any interface flagged main, then the first interface. Returns '' when
     * the host has no usable interface.
     */
    private function resolveSwitchIp(string $hostid): string {
        $hosts = API::Host()->get([
            'output'           => ['hostid'],
            'selectInterfaces' => ['ip', 'main', 'type'],
            'hostids'          => [$hostid]
        ]);
        if (!$hosts) return '';
        $interfaces = $hosts[0]['interfaces'] ?? [];

        // INTERFACE_TYPE_SNMP = 2.
        foreach ($interfaces as $iface) {
            if ((int) ($iface['type'] ?? 0) === 2 && ($iface['ip'] ?? '') !== '') {
                return (string) $iface['ip'];
            }
        }
        foreach ($interfaces as $iface) {
            if ((int) ($iface['main'] ?? 0) === 1 && ($iface['ip'] ?? '') !== '') {
                return (string) $iface['ip'];
            }
        }
        foreach ($interfaces as $iface) {
            if (($iface['ip'] ?? '') !== '') return (string) $iface['ip'];
        }
        return '';
    }

    /**
     * Walk full template ancestry (parents + parents-of-parents).
     * Zabbix's selectParentTemplates is one hop only.
     *
     * @return array<int, string>
     */
    private static function collectTemplateAncestry(string $hostid): array {
        $hosts = API::Host()->get([
            'output'                => ['hostid'],
            'hostids'               => [$hostid],
            'selectParentTemplates' => ['templateid']
        ]) ?: [];
        $seen  = [];
        $queue = [];
        if ($hosts) {
            foreach (($hosts[0]['parentTemplates'] ?? []) as $t) {
                $queue[] = (string) $t['templateid'];
            }
        }
        while ($queue) {
            $batch = [];
            foreach ($queue as $tid) {
                if (!isset($seen[$tid])) {
                    $seen[$tid] = true;
                    $batch[] = $tid;
                }
            }
            $queue = [];
            if (!$batch) break;
            $rows = API::Template()->get([
                'output'                => ['templateid'],
                'templateids'           => $batch,
                'selectParentTemplates' => ['templateid']
            ]) ?: [];
            foreach ($rows as $t) {
                foreach (($t['parentTemplates'] ?? []) as $p) {
                    $pid = (string) $p['templateid'];
                    if (!isset($seen[$pid])) $queue[] = $pid;
                }
            }
        }
        return array_keys($seen);
    }

    /**
     * Generic version of resolvePfMacros — pull any macro names through
     * the host → templates → globals chain. Host wins.
     *
     * @param array<int, string> $names
     * @return array<string, string>
     */
    private function macroChain(string $hostid, array $names): array {
        $bag = [];

        $globals = API::UserMacro()->get([
            'output'      => ['macro', 'value'],
            'globalmacro' => true,
            'filter'      => ['macro' => $names]
        ]) ?: [];
        foreach ($globals as $r) {
            if (!array_key_exists('value', $r)) continue;
            $bag[$r['macro']] = (string) $r['value'];
        }

        $templateIds = self::collectTemplateAncestry($hostid);
        if ($templateIds) {
            $tplMacros = API::UserMacro()->get([
                'output'  => ['macro', 'value'],
                'hostids' => $templateIds,
                'filter'  => ['macro' => $names]
            ]) ?: [];
            foreach ($tplMacros as $r) {
                if (!array_key_exists('value', $r)) continue;
                $bag[$r['macro']] = (string) $r['value'];
            }
        }

        $hostMacros = API::UserMacro()->get([
            'output'  => ['macro', 'value'],
            'hostids' => [$hostid],
            'filter'  => ['macro' => $names]
        ]) ?: [];
        foreach ($hostMacros as $r) {
            if (!array_key_exists('value', $r)) continue;
            $bag[$r['macro']] = (string) $r['value'];
        }

        return $bag;
    }

    private function resolvePfMacros(string $hostid): ?array {
        $names = ['{$PF.URL}', '{$PF.USER}', '{$PF.PASSWORD}', '{$PF.VERIFY.SSL}'];

        // Build precedence chain: globals first (lowest), overlaid by host
        // (highest). Linked-template macros sit between — pull via the
        // host.get's selectMacros to keep this to two API round-trips.
        $bag = [];

        // 1. Globals.
        $globals = API::UserMacro()->get([
            'output'      => ['macro', 'value'],
            'globalmacro' => true,
            'filter'      => ['macro' => $names]
        ]) ?: [];
        foreach ($globals as $r) {
            $bag[$r['macro']] = (string) $r['value'];
        }

        // 2. Template-inherited macros — recursive ancestry walk.
        $templateIds = self::collectTemplateAncestry($hostid);
        if ($templateIds) {
            $tplMacros = API::UserMacro()->get([
                'output'      => ['macro', 'value'],
                'hostids'     => $templateIds,
                'filter'      => ['macro' => $names]
            ]) ?: [];
            foreach ($tplMacros as $r) {
                $bag[$r['macro']] = (string) $r['value'];
            }
        }

        // 3. Host-level (highest precedence).
        $hostMacros = API::UserMacro()->get([
            'output'  => ['macro', 'value'],
            'hostids' => [$hostid],
            'filter'  => ['macro' => $names]
        ]) ?: [];
        foreach ($hostMacros as $r) {
            $bag[$r['macro']] = (string) $r['value'];
        }

        $url  = $bag['{$PF.URL}']  ?? '';
        $user = $bag['{$PF.USER}'] ?? '';
        $pass = $bag['{$PF.PASSWORD}'] ?? '';
        if ($url === '' || $user === '' || $pass === '') {
            error_log(sprintf(
                '[tcs_dashboard] pfNodes: macros missing — url=%s user=%s pass=%s (checked host %s + %d template(s) + globals)',
                $url !== '' ? 'set' : 'EMPTY',
                $user !== '' ? 'set' : 'EMPTY',
                $pass !== '' ? 'set' : 'EMPTY',
                $hostid, count($templateIds)
            ));
            return null;
        }

        return [
            'url'        => $url,
            'user'       => $user,
            'pass'       => $pass,
            'verify_ssl' => ($bag['{$PF.VERIFY.SSL}'] ?? '1') !== '0'
        ];
    }
}
