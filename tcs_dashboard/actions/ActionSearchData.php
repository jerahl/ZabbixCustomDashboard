<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use Modules\TcsDashboard\Lib\PFClient;

/**
 * GET zabbix.php?action=tcs.search.data&q=<fragment>
 *
 * Backs the global command palette (⌘K). Returns a flat, ranked list of
 * results spanning two data sources:
 *
 *   1. Zabbix hosts — switches, APs, cameras, servers. Matched by host
 *      technical name, visible name, or interface IP. Each row is
 *      classified into a device kind (so the palette can icon / label it)
 *      and given a deep-link into the matching TCS dashboard page.
 *
 *   2. PacketFence endpoints — the clients themselves. Matched by MAC,
 *      hostname, or owner / 802.1X username via PFClient::searchNodesText().
 *      Each client is enriched with its latest locationlog row (the switch
 *      session info: switch name, port, dot1x username) so the operator can
 *      see *where* on the wired/wireless fabric the client is connected.
 *      Distinct 802.1X usernames are also surfaced as their own "user" rows.
 *
 * Response: { results: [ {type, cat, label, sub, href, icon} ], q, ts }
 */
class ActionSearchData extends ActionDataBase {

    /** Cap per source so a broad query can't balloon the payload. */
    private const HOST_LIMIT   = 20;
    private const CLIENT_LIMIT = 25;

    /** Template-name substrings → device kind. First match wins. */
    private const TEMPLATE_PATTERNS = [
        'switch' => ['EXOS', 'Switch', 'switch', 'IOS'],
        'ap'     => ['XIQ', 'Extreme AP', 'WLC', 'wireless', 'Wireless'],
        'camera' => ['Milestone', 'XProtect', 'NVR', 'Camera'],
        'server' => ['Linux', 'Windows', 'iDRAC', 'OS by'],
    ];

    protected function checkInput(): bool {
        return $this->validateInput(['q' => 'string']);
    }

    protected function doAction(): void {
        $q = trim((string) $this->getInput('q', ''));

        $results = [];
        if (mb_strlen($q) >= 2) {
            try {
                $results = array_merge($results, $this->searchHosts($q));
            } catch (\Throwable $e) {
                error_log('[tcs_dashboard] search hosts: '.$e->getMessage());
            }
            try {
                $results = array_merge($results, $this->searchPacketFence($q));
            } catch (\Throwable $e) {
                error_log('[tcs_dashboard] search PF: '.$e->getMessage());
            }
        }

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode(
                ['results' => $results, 'q' => $q, 'ts' => time()],
                JSON_UNESCAPED_SLASHES
            )
        ]));
    }

    /* --------------------------------------------------------------------- */
    /* Zabbix host search                                                    */
    /* --------------------------------------------------------------------- */

    /** @return array<int, array<string, mixed>> */
    private function searchHosts(string $q): array {
        // Match on host technical name + visible name (searchByAny = OR).
        $byName = API::Host()->get([
            'output'                => ['hostid', 'host', 'name', 'status'],
            'selectInterfaces'      => ['ip', 'main', 'type'],
            'selectHostGroups'      => ['name'],
            'selectParentTemplates' => ['name'],
            'selectTags'            => ['tag', 'value'],
            'search'                => ['host' => $q, 'name' => $q],
            'searchByAny'           => true,
            'monitored_hosts'       => true,
            'limit'                 => self::HOST_LIMIT,
            'preservekeys'          => true,
        ]) ?: [];

        // Match on interface IP separately — host.get's `search` doesn't
        // descend into selectInterfaces. Resolve matching hostids first,
        // then fold any not already found into the result set.
        $ifaces = API::HostInterface()->get([
            'output'      => ['hostid', 'ip'],
            'search'      => ['ip' => $q],
            'limit'       => self::HOST_LIMIT,
        ]) ?: [];
        $ipHostids = array_values(array_unique(array_diff(
            array_column($ifaces, 'hostid'),
            array_keys($byName)
        )));
        if ($ipHostids) {
            $byIp = API::Host()->get([
                'output'                => ['hostid', 'host', 'name', 'status'],
                'selectInterfaces'      => ['ip', 'main', 'type'],
                'selectHostGroups'      => ['name'],
                'selectParentTemplates' => ['name'],
                'selectTags'            => ['tag', 'value'],
                'hostids'               => $ipHostids,
                'monitored_hosts'       => true,
                'limit'                 => self::HOST_LIMIT,
                'preservekeys'          => true,
            ]) ?: [];
            $byName += $byIp;
        }

        $out = [];
        foreach ($byName as $h) {
            $kind = $this->classify($h);
            $ip   = $this->mainIp($h);
            $name = (string) ($h['name'] ?? $h['host'] ?? '');
            $tech = (string) ($h['host'] ?? '');
            $meta = $this->kindMeta($kind);

            $subBits = [];
            if ($ip !== '')                 $subBits[] = $ip;
            if ($tech !== '' && $tech !== $name) $subBits[] = $tech;

            $out[] = [
                'type'  => $kind,
                'cat'   => $meta['cat'],
                'icon'  => $meta['icon'],
                'label' => $name !== '' ? $name : $tech,
                'sub'   => implode(' · ', $subBits),
                'href'  => $this->hostHref($kind, (string) $h['hostid']),
            ];
        }
        return $out;
    }

    /** Classify a host into switch / ap / camera / server / host. */
    private function classify(array $h): string {
        foreach ($h['tags'] ?? [] as $t) {
            if (($t['tag'] ?? '') === 'target' && ($t['value'] ?? '') === 'exos') {
                return 'switch';
            }
        }
        foreach ($h['hostgroups'] ?? [] as $g) {
            $gn = (string) ($g['name'] ?? '');
            if (str_starts_with($gn, 'Site/Wireless/'))                 return 'ap';
            if (stripos($gn, 'Milestone') !== false
                || stripos($gn, 'Camera') !== false)                    return 'camera';
        }
        $blob = implode(' ', array_column($h['parentTemplates'] ?? [], 'name'));
        foreach (self::TEMPLATE_PATTERNS as $kind => $needles) {
            foreach ($needles as $needle) {
                if (stripos($blob, $needle) !== false) return $kind;
            }
        }
        return 'host';
    }

    /** @return array{cat:string, icon:string} */
    private function kindMeta(string $kind): array {
        return match ($kind) {
            'switch' => ['cat' => 'Switch', 'icon' => 'ethernet'],
            'ap'     => ['cat' => 'AP',     'icon' => 'wifi'],
            'camera' => ['cat' => 'Camera', 'icon' => 'crosshair'],
            'server' => ['cat' => 'Server', 'icon' => 'ap'],
            default  => ['cat' => 'Host',   'icon' => 'ap'],
        };
    }

    private function hostHref(string $kind, string $hostid): string {
        $id = rawurlencode($hostid);
        return match ($kind) {
            'switch' => 'zabbix.php?action=tcs.switches.view&switchid='.$id,
            'ap'     => 'zabbix.php?action=tcs.dashboard.view&hostid='.$id,
            'camera' => 'zabbix.php?action=tcs.camera.view&id='.$id,
            'server' => 'zabbix.php?action=tcs.server.view&id='.$id,
            // Unknown kinds still navigate somewhere useful — the native
            // Zabbix latest-data view scoped to this host.
            default  => 'zabbix.php?action=latest.view&filter_set=1&hostids%5B%5D='.$id,
        };
    }

    private function mainIp(array $h): string {
        $fallback = '';
        foreach ($h['interfaces'] ?? [] as $i) {
            $ip = (string) ($i['ip'] ?? '');
            if ($ip === '') continue;
            if ((int) ($i['main'] ?? 0) === 1) return $ip;
            if ($fallback === '') $fallback = $ip;
        }
        return $fallback;
    }

    /* --------------------------------------------------------------------- */
    /* PacketFence client / user search                                      */
    /* --------------------------------------------------------------------- */

    /** @return array<int, array<string, mixed>> */
    private function searchPacketFence(string $q): array {
        $macros = $this->resolvePfMacrosGlobal();
        if ($macros === null) return [];

        $pf    = PFClient::fromMacros($macros);
        $nodes = $pf->searchNodesText($q, self::CLIENT_LIMIT);
        if (!$nodes) return [];

        // Enrich with the latest locationlog row per MAC — this is the
        // "switch session info": which switch / port / 802.1X user the
        // client is currently learned on.
        $locs = [];
        try {
            $locs = $pf->locationsByMac(array_keys($nodes));
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] search PF locations: '.$e->getMessage());
        }

        // Numeric category_id → human role label, when available.
        $cats = [];
        try {
            $cats = $pf->nodeCategories();
        } catch (\Throwable $e) {
            // best-effort; fall back to the raw category_id
        }

        $adminBase = $this->resolvePfAdminUrlGlobal();

        $out   = [];
        $users = [];
        foreach ($nodes as $mac => $n) {
            $loc  = $locs[$mac] ?? [];
            $host = (string) ($n['host'] ?? '');
            $user = (string) ($loc['dot1x_username'] ?? '');
            $sw   = (string) ($loc['switch'] ?? ($loc['switch_ip'] ?? ''));
            $port = (string) ($loc['port'] ?? ($loc['ifDesc'] ?? ''));
            $roleId = (string) ($n['role'] ?? '');
            $role   = $cats[$roleId] ?? ($loc['role'] ?? $roleId);

            $subBits = [strtoupper($mac)];
            if ($sw !== '') {
                $subBits[] = $port !== '' ? ($sw.' · '.$port) : $sw;
            }
            if ($user !== '')           $subBits[] = $user;
            elseif ((string) $role !== '') $subBits[] = (string) $role;

            $label = $host !== '' ? $host
                   : ((string) ($n['ip'] ?? '') !== '' ? (string) $n['ip'] : strtoupper($mac));

            $out[] = [
                'type'  => 'client',
                'cat'   => 'Client',
                'icon'  => 'clients',
                'label' => $label,
                'sub'   => implode(' · ', $subBits),
                'href'  => $this->pfNodeHref($adminBase, $mac),
            ];

            if ($user !== '' && !isset($users[strtolower($user)])) {
                $users[strtolower($user)] = true;
                $out[] = [
                    'type'  => 'user',
                    'cat'   => 'User',
                    'icon'  => 'user',
                    'label' => $user,
                    'sub'   => $host !== '' ? ($host.' · '.strtoupper($mac)) : strtoupper($mac),
                    'href'  => $this->pfNodeHref($adminBase, $mac),
                ];
            }
        }
        return $out;
    }

    private function pfNodeHref(string $adminBase, string $mac): string {
        if ($adminBase !== '') {
            return $adminBase.'/admin/#/node/'.rawurlencode($mac);
        }
        return 'zabbix.php?action=tcs.pf.clients.view';
    }

    /**
     * Resolve PF API macros without a host context (the palette is global).
     * Globals first, then any template / host that carries them.
     *
     * @return array{url:string,user:string,pass:string,verify_ssl:bool}|null
     */
    private function resolvePfMacrosGlobal(): ?array {
        $names = ['{$PF.URL}', '{$PF.USER}', '{$PF.PASSWORD}', '{$PF.VERIFY.SSL}'];
        $bag = $this->collectMacroBag($names);

        $url  = $bag['{$PF.URL}']  ?? '';
        $user = $bag['{$PF.USER}'] ?? '';
        $pass = $bag['{$PF.PASSWORD}'] ?? '';
        if ($url === '' || $user === '' || $pass === '') {
            return null;
        }
        return [
            'url'        => $url,
            'user'       => $user,
            'pass'       => $pass,
            'verify_ssl' => ($bag['{$PF.VERIFY.SSL}'] ?? '1') !== '0',
        ];
    }

    private function resolvePfAdminUrlGlobal(): string {
        $bag = $this->collectMacroBag(['{$PF.ADMIN_URL}']);
        return rtrim((string) ($bag['{$PF.ADMIN_URL}'] ?? ''), '/');
    }

    /**
     * Gather the requested macros from global scope, falling back to the
     * first non-empty value found on any template / host. First non-empty
     * value per macro wins.
     *
     * @param array<int, string> $names
     * @return array<string, string>
     */
    private function collectMacroBag(array $names): array {
        $bag = [];

        $globals = $this->safeMacroGet([
            'output'      => ['macro', 'value'],
            'globalmacro' => true,
            'filter'      => ['macro' => $names],
        ]);
        foreach ($globals as $r) {
            $v = (string) ($r['value'] ?? '');
            if ($v !== '' && ($bag[$r['macro']] ?? '') === '') $bag[$r['macro']] = $v;
        }

        // Fill any still-missing macro from template / host scope.
        $missing = array_values(array_filter($names, fn($n) => ($bag[$n] ?? '') === ''));
        if ($missing) {
            $scoped = $this->safeMacroGet([
                'output' => ['macro', 'value'],
                'filter' => ['macro' => $missing],
            ]);
            foreach ($scoped as $r) {
                $v = (string) ($r['value'] ?? '');
                if ($v !== '' && ($bag[$r['macro']] ?? '') === '') $bag[$r['macro']] = $v;
            }
        }
        return $bag;
    }

    /** @return array<int, array<string, mixed>> */
    private function safeMacroGet(array $params): array {
        try {
            $r = API::UserMacro()->get($params);
            return is_array($r) ? $r : [];
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] search macro.get: '.$e->getMessage());
            return [];
        }
    }
}
