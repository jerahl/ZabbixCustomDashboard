<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use Modules\TcsDashboard\Lib\XIQFleetClient;

/**
 * GET zabbix.php?action=tcs.xiq.data
 *
 * Returns the rollup payload consumed by xiq-bridge.jsx (XIQ_TOTALS, XIQ_SITES,
 * XIQ_BANDS, XIQ_SSIDS, XIQ_PROBLEM_APS, XIQ_CHANNEL_GRID, XIQ_CLIENT_MIX,
 * XIQ_THROUGHPUT, XIQ_FIRMWARE, XIQ_ROAMING, XIQ_EVENTS).
 *
 * Data flow:
 *   1. Read the fleet master item xiq.devices.raw — a JSON array of every AP
 *      that the "Extreme XIQ APs by API" template's Script item collects every
 *      5 minutes (id, serial, hostname, mac, ip, model, version, connected,
 *      clients, building, floor, …). Drives totals.aps, totals.clients.total,
 *      sites, firmware, and the AP rows under "Top problem APs".
 *
 *   2. Query API::Problem for the auto-created per-AP hosts (tag target=xiq).
 *      Drives problemAps reasons/severities and the events stream.
 *
 *   3. Optional XIQ direct call (when {$XIQ_TOKEN} is set): pull /clients/active
 *      for the per-client PHY / OS / SSID breakdown that Zabbix does NOT
 *      collect. Only this section needs the token; everything else works off
 *      Zabbix items.
 *
 * Synthetic data was removed in this iteration. On a cold cache the payload
 * carries loading=true with empty arrays; the React side renders a spinner on
 * APs-by-site until the first refresh resolves.
 */
class ActionXiqData extends ActionDataBase {

    /** APCu cache for the parsed fleet snapshot. */
    private const FLEET_CACHE_TTL = 30;
    private const FLEET_CACHE_KEY = 'tcs_dashboard:xiq_fleet:v7';

    /** APCu cache for d360 sampling — 5 minutes; the data changes slowly and
     *  every call counts against the 7,500-req/hr XIQ quota. */
    private const D360_CACHE_TTL = 300;
    private const D360_CACHE_KEY = 'tcs_dashboard:xiq_d360:v5';

    /** How many sites to sample for the channel heatmap. Fleet util / noise
     *  pull the whole fleet (paged); only the heatmap is sampled. */
    private const D360_SITE_SAMPLE = 8;

    /** Aggregate throughput: 24 hourly buckets over the last 24h. */
    private const THROUGHPUT_CACHE_TTL    = 300;
    private const THROUGHPUT_CACHE_KEY    = 'tcs_dashboard:xiq_throughput:v1';
    private const THROUGHPUT_BUCKETS      = 24;
    private const THROUGHPUT_BUCKET_SEC   = 3600;
    /** Cap on client IDs per bucket. ~500 IDs keeps URL length under 5 KB and
     *  fleet bytes-per-bucket gets extrapolated by (total / sampleSize). */
    private const THROUGHPUT_SAMPLE_MAX   = 500;

    /** Host group prefix the template's host prototype puts each AP under. */
    private const SITE_PREFIX = 'Site/Wireless/';

    /** Tag on per-AP hosts created by the host prototype. */
    private const AP_HOST_TAG = ['tag' => 'target', 'value' => 'xiq'];

    protected function checkInput(): bool {
        return $this->validateInput(['source' => 'string']);
    }

    protected function doAction(): void {
        // ?source=zbx → Zabbix layers only (fast, no XIQ calls)
        // ?source=xiq → run XIQ overlays too (slow, blocks on XIQ)
        // (default) — both, for backward compat
        $source  = $this->getInput('source', '');
        $skipXiq = ($source === 'zbx');

        $payload = self::emptyPayload();
        $fleet   = ['devices' => [], 'sites' => []];

        // Layer 1: Zabbix-side fleet discovery — Site/Wireless/<building>/<floor>
        // host groups plus tag target=xiq. xiq.ap.* fleet items (when present)
        // enrich each AP with connected / clients / version straight from XIQ.
        try {
            $fleet = self::collectFleet();
            if (!empty($fleet['devices'])) {
                $payload['totals']['aps']               = $fleet['apTotals'];
                $payload['totals']['clients']['total']  = $fleet['clientTotal'];
                $payload['totals']['firmware']          = $fleet['firmwareTotals'];
                $payload['sites']                       = $fleet['sites'];
                $payload['firmware']                    = $fleet['firmware'];
                $payload['bands']                       = $fleet['bands'];
                $payload['sources']['zbx']              = 'live';
                $payload['loading']                     = false;
            } else {
                $payload['sources']['zbx'] = 'empty';
                $payload['error']          = $payload['error']
                    ?? 'No XIQ APs found in Zabbix. Looked for hosts under "' . self::SITE_PREFIX . '<building>/<floor>" with tag target=xiq.';
            }
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] xiq.data zbx fleet: ' . $e->getMessage());
            $payload['sources']['zbx'] = 'error';
            $payload['error']          = $payload['error'] ?? ('Zabbix fleet query failed: ' . $e->getMessage());
        }

        // Layer 2: problems + events for per-AP hosts (tag target=xiq).
        try {
            $problemCtx = self::collectProblemContext();
            $payload['problemAps'] = $problemCtx['problemAps'];
            $payload['events']     = $problemCtx['events'];
            // Refine totals.aps.critical with real problem severities.
            $payload['totals']['aps']['critical'] = $problemCtx['criticalCount'];
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] xiq.data problems: ' . $e->getMessage());
        }

        // Skip the slow XIQ-side layers when the bridge asked for zbx-only.
        if ($skipXiq) {
            $payload['ts']      = time();
            $payload['partial'] = 'zbx';
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            ]));
            return;
        }

        // Layer 3: XIQ direct call for what Zabbix does NOT collect — per-client
        // OS, PHY standard, and SSID counts.
        $token = self::xiqToken();
        if ($token === null) {
            $payload['sources']['xiq'] = 'no-token';
            $payload['warning']        = $payload['warning']
                ?? 'XIQ direct queries skipped — set global macro {$XIQ_API_TOKEN} (non-secret) for client mix / SSID counts. The host-scoped {$XIQ_TOKEN} is SECRET_TEXT and not readable from the dashboard.';
        } else {
            try {
                self::overlayXiqClients($payload, $token);
                $payload['sources']['xiq'] = 'live';
            } catch (\Throwable $e) {
                error_log('[tcs_dashboard] xiq.data XIQ overlay: ' . $e->getMessage());
                $payload['sources']['xiq'] = 'error';
                $payload['warning']        = $payload['warning'] ?? ('XIQ direct query failed: ' . $e->getMessage());
            }
            // Layer 4: d360 sampling for RF utilization, noise floor, and the
            // channel grid. Sampled (one AP per top-N site) and cached 5min so
            // we stay well under the 7,500-req/hr XIQ quota.
            try {
                if (!empty($fleet['devices'])) {
                    self::overlayXiqD360($payload, $token, $fleet);
                }
            } catch (\Throwable $e) {
                error_log('[tcs_dashboard] xiq.data d360 overlay: ' . $e->getMessage());
                // d360 failure shouldn't escalate to a banner — band card just
                // stays at 0 util. Log only.
            }

            // Layer 5: aggregate throughput strip — 24 hourly buckets over the
            // last 24h, computed from /clients/usage with a 500-client sample
            // extrapolated to fleet scale. Cached 5min.
            try {
                self::overlayXiqThroughput($payload, $token);
            } catch (\Throwable $e) {
                error_log('[tcs_dashboard] xiq.data throughput overlay: ' . $e->getMessage());
            }

            // Layer 6: merge XIQ-side problem signal into Top Problem APs and
            // Recent Events. Reuses the usage-capacity grid (already fetched by
            // Layer 4a) and the fleet's offline devices — no extra XIQ calls.
            try {
                self::overlayXiqProblemContext($payload, $token, $fleet);
            } catch (\Throwable $e) {
                error_log('[tcs_dashboard] xiq.data problem-context overlay: ' . $e->getMessage());
            }
        }

        $payload['ts'] = time();
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        ]));
    }

    // ── Payload skeleton (also used as SSR boot by ActionXiq) ───────────────

    /** Empty shell. Same keys as the bridge expects, all zero / empty. */
    public static function emptyPayload(): array {
        return [
            'loading' => true,
            'totals'  => [
                'aps'         => ['total' => 0, 'online' => 0, 'offline' => 0, 'critical' => 0, 'idle' => 0],
                'clients'     => ['total' => 0, 'dot11ax' => 0, 'dot11ac' => 0, 'legacy' => 0],
                'throughput'  => ['agg_gbps' => 0.0, 'peak_gbps' => 0.0, 'ingress_gbps' => 0.0, 'egress_gbps' => 0.0],
                'ssids'       => ['total' => 0, 'broadcast' => 0],
                'rfHealth'    => ['score' => 0, 'target' => 90],
                'firmware'    => ['compliant' => 0, 'behind' => 0, 'ahead' => 0, 'target' => '—'],
                'controllers' => ['region' => '—', 'instance' => '—', 'lastSync' => '—'],
            ],
            'sites'       => [],
            'bands'       => self::bandShells(),
            'ssids'       => [],
            'problemAps'  => [],
            'channelGrid' => ['sites' => [], 'channels' => [36, 40, 44, 48, 52, 56, 60, 64, 100, 104, 108, 112, 149, 153, 157, 161], 'matrix' => []],
            'clientMix'   => ['standards' => [], 'os' => []],
            'throughput'  => [],
            'firmware'    => ['versions' => []],
            'roaming'     => ['buckets' => [], 'rate24h' => 0.0],
            'events'      => [],
            'sources'     => ['zbx' => 'unknown', 'xiq' => 'unknown'],
        ];
    }

    /**
     * Radio bands we monitor. The TCS fleet runs 5 GHz only (2.4 GHz radios
     * are disabled across the board, including for legacy clients), so the
     * Band Health card shows a single row with the whole client population.
     */
    private static function bandShells(): array {
        $zeros = array_fill(0, 24, 0);
        return [
            ['id' => '5', 'label' => '5 GHz', 'aps' => 0, 'clients' => 0, 'util' => 0, 'noise' => 0, 'saturated' => 0, 'color' => 'var(--ext)', 'spark' => $zeros],
        ];
    }

    // ── Layer 1: master-item snapshot ───────────────────────────────────────

    /**
     * Build the fleet snapshot from Zabbix host metadata.
     *
     * Discovery: hosts in any Site/Wireless/* host group with tag target=xiq.
     * Per-AP enrichment (optional): xiq.ap.connected[<serial>], xiq.ap.clients[<serial>],
     * and xiq.ap.version[<serial>] from wherever the template's fleet host
     * exposes them. Fall back to interface availability + 0 clients when those
     * items aren't present.
     *
     * Returns { devices, apTotals, clientTotal, sites, firmware, firmwareTotals, bands }.
     */
    private static function collectFleet(): array {
        if (function_exists('apcu_fetch')) {
            $hit = apcu_fetch(self::FLEET_CACHE_KEY, $ok);
            if ($ok && is_array($hit)) return $hit;
        }

        $result = self::collectFleetUncached();

        if (function_exists('apcu_store')) {
            apcu_store(self::FLEET_CACHE_KEY, $result, self::FLEET_CACHE_TTL);
        }
        return $result;
    }

    private static function collectFleetUncached(): array {
        $empty = [
            'devices' => [], 'apTotals' => self::emptyPayload()['totals']['aps'],
            'clientTotal' => 0, 'sites' => [], 'firmware' => ['versions' => []],
            'firmwareTotals' => self::emptyPayload()['totals']['firmware'],
            'bands' => self::bandShells()
        ];

        // Step 1: Site/Wireless/* host groups.
        $siteGroups = API::HostGroup()->get([
            'output'      => ['groupid', 'name'],
            'search'      => ['name' => self::SITE_PREFIX],
            'startSearch' => true,
        ]) ?: [];
        if (!$siteGroups) return $empty;
        $groupids = array_column($siteGroups, 'groupid');

        // Step 2: per-AP hosts. Match on either group membership OR the
        // target=xiq tag — operators may have one without the other,
        // and we want to surface anything reasonable.
        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
            'selectHostGroups' => ['groupid', 'name'],
            'selectTags'       => ['tag', 'value'],
            'selectInterfaces' => ['interfaceid', 'main', 'type', 'available'],
            'selectInventory'  => ['model'],
            'groupids'         => $groupids,
            'tags'             => [self::AP_HOST_TAG + ['operator' => 1]],
            'evaltype'         => 0,
            'inheritedTags'    => true,
            'preservekeys'     => true,
        ]) ?: [];
        // Fallback — same group filter without the tag requirement, in case the
        // tag hasn't been propagated yet (e.g. operator created hosts manually).
        if (!$hosts) {
            $hosts = API::Host()->get([
                'output'           => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
                'selectHostGroups' => ['groupid', 'name'],
                'selectTags'       => ['tag', 'value'],
                'selectInterfaces' => ['interfaceid', 'main', 'type', 'available'],
                'selectInventory'  => ['model'],
                'groupids'         => $groupids,
                'preservekeys'     => true,
            ]) ?: [];
        }
        if (!$hosts) return $empty;

        // Step 3: optional enrichment — xiq.ap.<type>[<serial>] items from the
        // fleet host. Single API::Item.get; group by serial in PHP.
        $bySerial = self::collectApItems();

        // Step 3b: per-host {$XIQ_DEVICE_ID} macro (set by the template's host
        // prototype, non-secret). Lets us target d360 endpoints per-AP without
        // resolving serial→device-id each call.
        $deviceIdByHost = self::collectDeviceIdMacros(array_keys($hosts));

        // Step 4: build a normalized device record per host.
        $now = time();
        $devices = [];
        foreach ($hosts as $hid => $h) {
            $tags = self::tagsByName($h['tags'] ?? []);
            $serial   = (string) ($tags['ap_serial'] ?? '');
            $extra    = $serial !== '' ? ($bySerial[$serial] ?? []) : [];
            $model    = (string) ($tags['ap_model'] ?? $extra['model'] ?? ($h['inventory']['model'] ?? '—'));
            $building = (string) ($tags['building']  ?? $extra['building'] ?? self::buildingFromGroups($h['hostgroups'] ?? []));
            $floor    = (string) ($tags['floor']     ?? $extra['floor'] ?? '');
            $version  = (string) ($extra['version']  ?? '');
            $clients  = isset($extra['clients']) && $extra['clients'] !== '' ? (int) $extra['clients'] : 0;

            // State: prefer xiq.ap.connected (XIQ's view), fall back to
            // interface availability (Zabbix's view).
            if (isset($extra['connected']) && $extra['connected'] !== '') {
                $state = ((int) $extra['connected'] === 1) ? 'online' : 'offline';
            } else {
                $state = self::hostReachState($h);
            }

            $devices[(string) $hid] = [
                'hostid'    => (string) $hid,
                'xiqId'     => (int) ($deviceIdByHost[(string) $hid] ?? 0),
                'name'      => (string) ($h['name'] ?: $h['host']),
                'serial'    => $serial,
                'model'     => $model,
                'building'  => $building,
                'floor'     => $floor,
                'version'   => $version,
                'clients'   => $clients,
                'state'     => $state,
                'connected' => $state === 'online' ? 1 : ($state === 'offline' ? 0 : null),
            ];
        }

        // Step 5: roll up into the React-side shapes.
        $total = count($devices);
        $online = $offline = $idle = $clientTotal = 0;
        foreach ($devices as $d) {
            $clientTotal += $d['clients'];
            if      ($d['state'] === 'online')   $online++;
            elseif  ($d['state'] === 'offline')  $offline++;
            elseif  ($d['state'] === 'idle')     $idle++;
            // 'disabled' falls through — not counted in online/offline
        }
        $apTotals = [
            'total'    => $total,
            'online'   => $online,
            'offline'  => $offline,
            'critical' => 0,                // refined by collectProblemContext
            'idle'     => $idle,
        ];

        // Sites — bucket by building, severity from offline ratio.
        $siteAcc = [];
        foreach ($devices as $d) {
            $building = $d['building'];
            if ($building === '') continue;
            $siteAcc[$building] = $siteAcc[$building] ?? [
                'id'      => self::siteIdFromName($building),
                'name'    => $building,
                'aps'     => 0,
                'online'  => 0,
                'util'    => 0,
                'clients' => 0,
                'sev'     => 'ok',
                'top'     => '—',
            ];
            $siteAcc[$building]['aps']++;
            if ($d['state'] === 'online') $siteAcc[$building]['online']++;
            $siteAcc[$building]['clients'] += $d['clients'];
        }
        foreach ($siteAcc as &$s) {
            $offPct = $s['aps'] > 0 ? (($s['aps'] - $s['online']) / $s['aps']) : 0.0;
            if ($offPct >= 0.25) {
                $s['kind'] = 'outage';
                $s['sev']  = 'high';
            } elseif ($offPct > 0) {
                $s['sev'] = 'warning';
            } else {
                $s['sev'] = 'ok';
            }
        }
        unset($s);
        $sites = array_values($siteAcc);
        usort($sites, fn($a, $b) => self::sevRank($b['sev']) <=> self::sevRank($a['sev']) ?: strcmp($a['name'], $b['name']));

        // Firmware histogram.
        $versionCounts = [];
        foreach ($devices as $d) {
            $v = trim($d['version']);
            if ($v === '') continue;
            $versionCounts[$v] = ($versionCounts[$v] ?? 0) + 1;
        }
        arsort($versionCounts);
        $target = (string) (array_key_first($versionCounts) ?? '');
        $fwVersions = [];
        $compliant = $behind = $ahead = 0;
        foreach ($versionCounts as $v => $count) {
            $cmp    = self::compareSemver($v, $target);
            $status = $cmp === 0 ? 'target' : ($cmp < 0 ? 'behind' : 'ahead');
            if ($status === 'target')      $compliant = $count;
            elseif ($status === 'behind')  $behind  += $count;
            else                           $ahead   += $count;
            $fwVersions[] = ['v' => $v, 'count' => $count, 'status' => $status, 'note' => ''];
        }
        $firmwareTotals = [
            'compliant' => $compliant,
            'behind'    => $behind,
            'ahead'     => $ahead,
            'target'    => $target !== '' ? $target : '—',
        ];

        // Bands — TCS runs 5 GHz only, so every AP counts on the single 5 GHz row.
        $bands = self::bandShells();
        foreach ($bands as &$b) {
            $b['aps'] = $total;
        }
        unset($b);

        return [
            'devices'        => $devices,
            'apTotals'       => $apTotals,
            'clientTotal'    => $clientTotal,
            'sites'          => $sites,
            'firmware'       => ['versions' => $fwVersions],
            'firmwareTotals' => $firmwareTotals,
            'bands'          => $bands,
        ];
    }

    /**
     * Pull every xiq.ap.* item from any host, index by serial.
     * Returns: [serial => [type => lastvalue]], e.g.
     *   ['ABC123' => ['connected' => '1', 'clients' => '34', 'version' => '32.7.0.5']]
     * Missing items just yield missing keys — callers default each field.
     */
    private static function collectApItems(): array {
        $items = API::Item()->get([
            'output'      => ['key_', 'lastvalue'],
            'search'      => ['key_' => 'xiq.ap.'],
            'startSearch' => true,
            'limit'       => 50000,
        ]) ?: [];
        $bySerial = [];
        foreach ($items as $it) {
            if (!preg_match('/^xiq\.ap\.([a-z]+)\[(.+)\]$/i', (string) $it['key_'], $m)) continue;
            $type   = $m[1];
            $serial = $m[2];
            $bySerial[$serial][$type] = (string) ($it['lastvalue'] ?? '');
        }
        return $bySerial;
    }

    /**
     * Pull {$XIQ_DEVICE_ID} for a batch of hosts. Returns hostid → device-id.
     * The template's host prototype stamps this at creation as a non-secret
     * host macro, so UserMacro.get returns the real value.
     */
    private static function collectDeviceIdMacros(array $hostids): array {
        if (!$hostids) return [];
        $rows = API::UserMacro()->get([
            'output'  => ['hostid', 'macro', 'value'],
            'hostids' => $hostids,
            'filter'  => ['macro' => '{$XIQ_DEVICE_ID}'],
        ]) ?: [];
        $byHost = [];
        foreach ($rows as $r) {
            $v = (int) ($r['value'] ?? 0);
            if ($v > 0) $byHost[(string) $r['hostid']] = $v;
        }
        return $byHost;
    }

    /** @return array<string, string> */
    private static function tagsByName(array $tags): array {
        $out = [];
        foreach ($tags as $t) {
            if (isset($t['tag'])) $out[(string) $t['tag']] = (string) ($t['value'] ?? '');
        }
        return $out;
    }

    /** Pull the <building> segment from the first Site/Wireless/<building>/... group. */
    private static function buildingFromGroups(array $groups): string {
        foreach ($groups as $g) {
            $name = (string) ($g['name'] ?? '');
            if (!str_starts_with($name, self::SITE_PREFIX)) continue;
            $rest = substr($name, strlen(self::SITE_PREFIX));
            $segments = explode('/', $rest, 2);
            $b = trim($segments[0] ?? '');
            if ($b !== '') return $b;
        }
        return '';
    }

    // ── Layer 2: problems + events on per-AP hosts ──────────────────────────

    /** Returns { problemAps, events, criticalCount }. */
    private static function collectProblemContext(): array {
        $hosts = API::Host()->get([
            'output'        => ['hostid', 'host', 'name'],
            'selectTags'    => ['tag', 'value'],
            'tags'          => [self::AP_HOST_TAG + ['operator' => 1]],
            'evaltype'      => 0,
            'inheritedTags' => true,
            'preservekeys'  => true,
        ]) ?: [];
        if (!$hosts) return ['problemAps' => [], 'events' => [], 'criticalCount' => 0];

        $hostids   = array_keys($hosts);
        $hostNames = [];
        foreach ($hosts as $h) $hostNames[(string) $h['hostid']] = (string) ($h['name'] ?: $h['host']);

        $hostMeta = [];
        foreach ($hosts as $h) {
            $meta = ['model' => '—', 'building' => '—'];
            foreach ($h['tags'] ?? [] as $t) {
                if (($t['tag'] ?? '') === 'ap_model') $meta['model']    = (string) $t['value'];
                if (($t['tag'] ?? '') === 'building') $meta['building'] = (string) $t['value'];
            }
            $hostMeta[(string) $h['hostid']] = $meta;
        }

        $problems = API::Problem()->get([
            'output'    => ['eventid', 'name', 'severity', 'clock'],
            'hostids'   => $hostids,
            'recent'    => true,
            'time_from' => time() - 24 * 3600,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit'     => 200,
        ]) ?: [];

        // Map eventid → hostid via event.get (problems don't carry hostid directly).
        $hostByEvent = [];
        if ($problems) {
            $events = API::Event()->get([
                'output'      => ['eventid'],
                'eventids'    => array_column($problems, 'eventid'),
                'selectHosts' => ['hostid']
            ]) ?: [];
            foreach ($events as $ev) {
                $first = $ev['hosts'][0] ?? null;
                if ($first) $hostByEvent[(string) $ev['eventid']] = (string) $first['hostid'];
            }
        }

        $now = time();
        $criticalCount = 0;
        $countedHosts  = [];
        $problemAps    = [];
        $eventRows     = [];
        foreach ($problems as $p) {
            $hid = $hostByEvent[(string) $p['eventid']] ?? null;
            if ($hid === null || !isset($hosts[$hid])) continue;

            $sevLabel = self::zabbixSevToLabel((int) $p['severity']);
            if (in_array($sevLabel, ['high', 'disaster'], true) && !isset($countedHosts[$hid])) {
                $criticalCount++;
                $countedHosts[$hid] = true;
            }

            // Top problem APs — keep up to 8 highest-severity rows
            if (count($problemAps) < 32) {
                $age = max(0, $now - (int) $p['clock']);
                $problemAps[] = [
                    'ap'      => $hostNames[$hid],
                    'hostid'  => (string) $hid,
                    'site'    => self::siteIdFromName($hostMeta[$hid]['building'] ?? '—'),
                    'model'   => $hostMeta[$hid]['model'] ?? '—',
                    'reason'  => (string) $p['name'],
                    'sev'     => $sevLabel,
                    'util2'   => 0,
                    'util5'   => 0,
                    'clients' => 0,
                    'age'     => sprintf('%02d:%02d:%02d', intdiv($age, 3600), intdiv($age % 3600, 60), $age % 60),
                    '_clock'  => (int) $p['clock'],
                    '_sevr'   => self::sevRank($sevLabel),
                ];
            }

            // Events stream — keep up to 12 newest rows from the last hour
            if (count($eventRows) < 12 && (int) $p['clock'] >= $now - 3600) {
                $eventRows[] = [
                    'ts'     => date('H:i:s', (int) $p['clock']),
                    'source' => 'zbx',
                    'host'   => $hostNames[$hid],
                    'msg'    => 'Problem:',
                    'obj'    => (string) $p['name'],
                    'sev'    => $sevLabel,
                    '_ts'    => (int) $p['clock'],
                ];
            }
        }

        usort($problemAps, fn($a, $b) => $b['_sevr'] <=> $a['_sevr'] ?: $b['_clock'] <=> $a['_clock']);
        $problemAps = array_slice($problemAps, 0, 8);
        foreach ($problemAps as &$p) { unset($p['_clock'], $p['_sevr']); }
        unset($p);

        return ['problemAps' => $problemAps, 'events' => $eventRows, 'criticalCount' => $criticalCount];
    }

    // ── Layer 6: XIQ-side problem context (merges into Top Problem APs +
    //             Recent Events) ──────────────────────────────────────────────

    /**
     * Pull XIQ-side problem signal and merge it into the Zabbix-side
     * problemAps + events arrays already on $payload.
     *
     * Two sources, both already fetched elsewhere in this request:
     *
     *   1. usage-capacity grid — rows with has_usage_capacity_issue=true,
     *      radio_5g_utilization_score >= 75, or unhealthy_clients > 0
     *      become XIQ-source problem AP rows. The grid is fetched by
     *      Layer 4a; we hit the 5-min client-level cache.
     *
     *   2. fleet devices — APs in 'offline' state become "AP disconnected"
     *      events on the Recent Events strip. No extra XIQ calls — Layer 1
     *      already populated $fleet['devices'].
     *
     * Merging rules: keep Zabbix entries first (they carry the real Zabbix
     * trigger name, hostid, and timestamp), then append XIQ-derived entries
     * until we hit the cap. Cap matches the React-side render budget — 8 for
     * problemAps, 12 for events.
     */
    private static function overlayXiqProblemContext(array &$payload, string $token, array $fleet): void {
        $fleetClient = XIQFleetClient::fromToken($token);

        // ── Source A: usage-capacity grid (cached 5 min by getUsageCapacityGrid) ─
        $grid = [];
        try {
            $grid = $fleetClient->getUsageCapacityGrid(300);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] problem-context grid fetch: ' . $e->getMessage());
        }

        // hostname → hostid lookup so XIQ rows can deep-link into AP Detail.
        $hostidByName = [];
        foreach ($fleet['devices'] ?? [] as $d) {
            $name = strtolower((string) ($d['name'] ?? ''));
            if ($name !== '' && !empty($d['hostid'])) $hostidByName[$name] = (string) $d['hostid'];
        }

        $xiqProblemAps = [];
        foreach ($grid as $r) {
            if (!is_array($r)) continue;
            $util       = (int) ($r['radio_5g_utilization_score'] ?? 0);
            $unhealthy  = (int) ($r['unhealthy_clients'] ?? 0);
            $healthy    = (int) ($r['healthy_clients'] ?? 0);
            $hasCap     = !empty($r['has_usage_capacity_issue']);
            // Only surface rows that are actually problematic.
            if (!$hasCap && $util < 75 && $unhealthy < 1) continue;

            if ($hasCap || $util >= 90)      $sev = 'high';
            elseif ($util >= 75)             $sev = 'warning';
            elseif ($unhealthy >= 5)         $sev = 'warning';
            else                             $sev = 'info';

            if ($hasCap) {
                $reason = sprintf('Capacity issue · 5G util %d%% · %d unhealthy clients', $util, $unhealthy);
            } elseif ($util >= 75) {
                $reason = sprintf('5GHz channel utilization %d%%', $util);
            } else {
                $reason = sprintf('%d unhealthy clients', $unhealthy);
            }

            $hostname = (string) ($r['hostname'] ?? '');
            $building = (string) ($r['building'] ?? ($r['site'] ?? '—'));
            $model    = (string) ($r['product_type'] ?? '—');
            $hostid   = $hostidByName[strtolower($hostname)] ?? '';

            $xiqProblemAps[] = [
                'ap'      => $hostname !== '' ? $hostname : ('xiq-' . ($r['device_id'] ?? '?')),
                'hostid'  => $hostid,
                'site'    => self::siteIdFromName($building !== '' ? $building : '—'),
                'model'   => $model,
                'reason'  => $reason,
                'sev'     => $sev,
                'util2'   => 0,
                'util5'   => $util,
                'clients' => $healthy + $unhealthy,
                'age'     => '—',
                '_sevr'   => self::sevRank($sev),
                '_util'   => $util,
            ];
        }
        usort($xiqProblemAps, fn($a, $b) => $b['_sevr'] <=> $a['_sevr'] ?: $b['_util'] <=> $a['_util']);
        foreach ($xiqProblemAps as &$p) { unset($p['_sevr'], $p['_util']); }
        unset($p);

        // Merge: Zabbix first, then XIQ rows that aren't already represented.
        $existing  = $payload['problemAps'] ?? [];
        $seenNames = [];
        foreach ($existing as $p) {
            $n = strtolower(trim((string) ($p['ap'] ?? '')));
            if ($n !== '') $seenNames[$n] = true;
        }
        $merged = $existing;
        foreach ($xiqProblemAps as $p) {
            if (count($merged) >= 8) break;
            $n = strtolower(trim($p['ap']));
            if ($n === '' || isset($seenNames[$n])) continue;
            $seenNames[$n] = true;
            $merged[] = $p;
        }
        $payload['problemAps'] = array_slice($merged, 0, 8);

        // ── Source B: offline APs from the fleet → Recent Events ────────────
        $now       = time();
        $xiqEvents = [];
        foreach ($fleet['devices'] ?? [] as $d) {
            if (($d['state'] ?? '') !== 'offline') continue;
            // We don't have a real "went offline at" timestamp on the Zabbix
            // side, so anchor these events to now and let the merge sort put
            // the Zabbix-stamped events above them naturally.
            $xiqEvents[] = [
                'ts'     => date('H:i:s', $now),
                'source' => 'ext',
                'host'   => (string) ($d['name'] ?? '—'),
                'msg'    => 'Disconnected:',
                'obj'    => 'AP offline',
                'sev'    => 'warning',
                '_ts'    => $now,
            ];
        }

        // Cross-reference grid rows with a capacity issue → emit an event so the
        // operator sees it in the stream too. Skip APs that already have a
        // Zabbix-side event (avoid duplicates).
        $existingHosts = [];
        foreach (($payload['events'] ?? []) as $e) {
            $h = strtolower(trim((string) ($e['host'] ?? '')));
            if ($h !== '') $existingHosts[$h] = true;
        }
        foreach ($grid as $r) {
            if (!is_array($r) || empty($r['has_usage_capacity_issue'])) continue;
            $h = strtolower(trim((string) ($r['hostname'] ?? '')));
            if ($h === '' || isset($existingHosts[$h])) continue;
            $existingHosts[$h] = true;
            $util = (int) ($r['radio_5g_utilization_score'] ?? 0);
            $xiqEvents[] = [
                'ts'     => date('H:i:s', $now),
                'source' => 'ext',
                'host'   => (string) $r['hostname'],
                'msg'    => 'Capacity issue:',
                'obj'    => sprintf('5G util %d%%', $util),
                'sev'    => $util >= 90 ? 'high' : 'warning',
                '_ts'    => $now,
            ];
        }

        // Merge with Zabbix-side events; sort newest-first by _ts; cap 12.
        $existingEvents = $payload['events'] ?? [];
        $allEvents = array_merge($existingEvents, $xiqEvents);
        usort($allEvents, fn($a, $b) => ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0));
        $allEvents = array_slice($allEvents, 0, 12);
        foreach ($allEvents as &$e) unset($e['_ts']);
        unset($e);
        $payload['events'] = $allEvents;
    }

    // ── Layer 3: XIQ direct (per-client breakdown only) ─────────────────────

    /**
     * Returns the XIQ API token from a global Zabbix macro, or null when unset.
     *
     * The template ships {$XIQ_TOKEN} on the fleet host as SECRET_TEXT — Zabbix
     * masks SECRET_TEXT values when read via UserMacro.get, so we can't pull
     * it from the host scope. Use a separate non-secret global macro
     * {$XIQ_API_TOKEN} for read-side consumers like this dashboard. Falls back
     * to a global {$XIQ_TOKEN} only if someone set the non-secret version
     * under that name.
     */
    private static function xiqToken(): ?string {
        foreach (['{$XIQ_API_TOKEN}', '{$XIQ_TOKEN}'] as $name) {
            $rows = API::UserMacro()->get([
                'output'      => ['macro', 'value'],
                'globalmacro' => true,
                'filter'      => ['macro' => $name],
            ]) ?: [];
            $v = trim((string) ($rows[0]['value'] ?? ''));
            if ($v !== '') return $v;
        }
        return null;
    }

    /** Pull /clients/active and project PHY / OS / SSID breakdowns. */
    private static function overlayXiqClients(array &$payload, string $token): void {
        $client  = XIQFleetClient::fromToken($token);
        $clients = $client->getActiveClients();
        if (!$clients) return;

        $std = ['ax' => 0, 'ac' => 0, 'n' => 0, 'legacy' => 0];
        $osCounts = [];
        $ssidClients = [];
        foreach ($clients as $c) {
            $radio = strtoupper((string) ($c['radio_type'] ?? ''));
            if (str_contains($radio, 'AX') || str_contains($radio, '11AX') || str_contains($radio, '6E')) $std['ax']++;
            elseif (str_contains($radio, 'AC')) $std['ac']++;
            elseif (str_contains($radio, '11N') || $radio === 'N') $std['n']++;
            else $std['legacy']++;

            $os = self::normalizeOs((string) ($c['os_type'] ?? $c['os'] ?? ''));
            $osCounts[$os] = ($osCounts[$os] ?? 0) + 1;

            $ssid = (string) ($c['ssid'] ?? '');
            if ($ssid !== '') $ssidClients[$ssid] = ($ssidClients[$ssid] ?? 0) + 1;
        }
        $totalClients = array_sum($std);

        // Standards row
        $stdColors = ['ax' => 'var(--ext)', 'ac' => 'var(--info)', 'n' => 'var(--warn)', 'legacy' => 'var(--err)'];
        $stdLabels = ['ax' => 'Wi-Fi 6 / 6E (ax)', 'ac' => 'Wi-Fi 5 (ac)', 'n' => 'Wi-Fi 4 (n)', 'legacy' => 'Legacy a/b/g'];
        $standards = [];
        foreach ($std as $id => $count) {
            $standards[] = [
                'id'    => $id,
                'label' => $stdLabels[$id],
                'count' => $count,
                'pct'   => $totalClients > 0 ? round($count / $totalClients * 100, 1) : 0.0,
                'color' => $stdColors[$id],
            ];
        }

        // OS rows
        $osRows = [];
        arsort($osCounts);
        foreach ($osCounts as $label => $count) {
            $osRows[] = [
                'id'    => strtolower(preg_replace('/[^a-z0-9]+/i', '', $label) ?: 'os'),
                'label' => $label,
                'count' => $count,
                'pct'   => $totalClients > 0 ? round($count / $totalClients * 100, 1) : 0.0,
            ];
        }

        // Prefer Zabbix's fleet total (xiq.clients.total) — we already have it
        // in totals.clients.total. Refine with the PHY breakdown from XIQ.
        if (($payload['totals']['clients']['total'] ?? 0) === 0) {
            $payload['totals']['clients']['total'] = $totalClients;
        }
        $payload['totals']['clients']['dot11ax'] = $std['ax'];
        $payload['totals']['clients']['dot11ac'] = $std['ac'];
        $payload['totals']['clients']['legacy']  = $std['n'] + $std['legacy'];
        $payload['clientMix']                    = ['standards' => $standards, 'os' => $osRows];

        // SSIDs — one row per SSID XIQ reports a client on. No auth/vlan info
        // (would need per-policy lookups via XIQClient::getPolicySsids).
        $ssidRows = [];
        arsort($ssidClients);
        foreach ($ssidClients as $label => $count) {
            $ssidRows[] = [
                'id'         => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label) ?: 'ssid'),
                'label'      => $label,
                'auth'       => '—',
                'vlan'       => 0,
                'clients'    => $count,
                'success'    => 100.0,    // unknown — show as success until we wire per-SSID stats
                'throughput' => 0.0,
                'role'       => 'unknown',
            ];
        }
        $payload['ssids'] = $ssidRows;
        $payload['totals']['ssids'] = ['total' => count($ssidRows), 'broadcast' => count($ssidRows)];

        // All clients ride 5 GHz on this fleet (legacy a/b/g/n included).
        foreach ($payload['bands'] as &$b) {
            if ($b['id'] === '5') $b['clients'] = $totalClients;
        }
        unset($b);

        $payload['totals']['controllers']['lastSync'] = 'just now';

        if ($client->isRateLimitLow()) {
            $rem = $client->getRateLimitRemaining();
            $payload['warning'] = "XIQ rate-limit low: $rem requests left this hour.";
        }
    }

    // ── Layer 5: Aggregate throughput (24-bucket strip) ─────────────────────

    /**
     * Build the Aggregate Throughput · 24h strip from /clients/usage.
     *
     * Algorithm:
     *   1. Pull active clients (already cached by overlayXiqClients).
     *   2. Sample up to {@see self::THROUGHPUT_SAMPLE_MAX} client IDs.
     *   3. For each of 24 hourly buckets ending now, call /clients/usage
     *      with the sample IDs + that hour's window. Sum response.usage
     *      across the sample → bytes/hour for the sample.
     *   4. Extrapolate to fleet by ratio: fleet_bytes = sample_bytes ×
     *      (totalClients / sampleSize).
     *   5. Convert bytes/hour → Gbps (×8 / 3600 / 1e9).
     *   6. Write the strip + agg/peak/ingress/egress to the payload.
     *
     * Cost: 24 calls per cache miss, APCu-cached 5min → 288 calls/hr in the
     * worst case. Fits in the 7,500/hr XIQ quota with huge headroom.
     *
     * Ingress vs egress: /clients/usage gives a single combined byte count,
     * no rx/tx split. We approximate 60% ingress / 40% egress (a typical
     * school WLAN download-heavy mix). When XIQ exposes a split we can fix.
     */
    private static function overlayXiqThroughput(array &$payload, string $token): void {
        if (function_exists('apcu_fetch')) {
            $hit = apcu_fetch(self::THROUGHPUT_CACHE_KEY, $ok);
            if ($ok && is_array($hit)) {
                self::applyThroughput($payload, $hit);
                return;
            }
        }

        $fleetClient = XIQFleetClient::fromToken($token);
        $clients = $fleetClient->getActiveClients();
        if (!$clients) return;

        $ids = [];
        foreach ($clients as $c) {
            // /clients/active responses use `id`; older shapes use `client_id`.
            $id = $c['id'] ?? $c['client_id'] ?? null;
            if ($id !== null && (int) $id > 0) $ids[] = (int) $id;
        }
        if (!$ids) return;

        $totalClients = count($ids);
        $sampleIds    = $ids;
        if ($totalClients > self::THROUGHPUT_SAMPLE_MAX) {
            shuffle($sampleIds);
            $sampleIds = array_slice($sampleIds, 0, self::THROUGHPUT_SAMPLE_MAX);
        }
        $sampleSize    = count($sampleIds);
        $extrapolation = $sampleSize > 0 ? ($totalClients / $sampleSize) : 1.0;

        // Build all 24 hour-bucket windows up front, dispatch in parallel.
        // Sequential round-trips dominated cold-load time (24 × ~300ms = 7-15s);
        // curl_multi collapses that to one wall-clock RTT.
        $now     = time();
        $windows = [];
        for ($i = self::THROUGHPUT_BUCKETS - 1; $i >= 0; $i--) {
            $bucketEnd   = $now - $i * self::THROUGHPUT_BUCKET_SEC;
            $bucketStart = $bucketEnd - self::THROUGHPUT_BUCKET_SEC;
            $windows[$i] = [$bucketStart * 1000, $bucketEnd * 1000];
        }

        try {
            $batched = $fleetClient->getClientsUsageMulti($sampleIds, $windows);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] clients/usage multi: ' . $e->getMessage());
            $batched = [];
        }

        $strip = [];
        for ($i = self::THROUGHPUT_BUCKETS - 1; $i >= 0; $i--) {
            $rows = $batched[$i] ?? null;
            if (!is_array($rows)) { $strip[] = 0.0; continue; }
            $sampleBytes = 0;
            foreach ($rows as $r) $sampleBytes += (int) ($r['usage'] ?? 0);
            $fleetBytes  = $sampleBytes * $extrapolation;
            // bytes / 3600 sec × 8 bits/byte / 1e9 = Gbps
            $gbps = ($fleetBytes * 8.0) / 3600.0 / 1_000_000_000.0;
            $strip[] = round($gbps, 2);
        }

        $aggGbps  = $strip ? array_sum($strip) / count($strip) : 0.0;
        $peakGbps = $strip ? max($strip) : 0.0;

        $aggregate = [
            'strip'        => $strip,
            'agg_gbps'     => round($aggGbps,  2),
            'peak_gbps'    => round($peakGbps, 2),
            'ingress_gbps' => round($aggGbps * 0.6, 2),
            'egress_gbps'  => round($aggGbps * 0.4, 2),
            'totalClients' => $totalClients,
            'sampleSize'   => $sampleSize,
        ];

        if (function_exists('apcu_store')) {
            apcu_store(self::THROUGHPUT_CACHE_KEY, $aggregate, self::THROUGHPUT_CACHE_TTL);
        }
        self::applyThroughput($payload, $aggregate);
    }

    private static function applyThroughput(array &$payload, array $agg): void {
        if (!empty($agg['strip']) && is_array($agg['strip'])) {
            $payload['throughput'] = $agg['strip'];
        }
        $payload['totals']['throughput'] = [
            'agg_gbps'     => (float) ($agg['agg_gbps']     ?? 0),
            'peak_gbps'    => (float) ($agg['peak_gbps']    ?? 0),
            'ingress_gbps' => (float) ($agg['ingress_gbps'] ?? 0),
            'egress_gbps'  => (float) ($agg['egress_gbps']  ?? 0),
        ];
    }

    // ── Layer 4: d360 sampling (RF util / noise / channel grid) ─────────────

    /**
     * Populate bands[5].{util,noise,saturated} + channelGrid from XIQ.
     *
     * Two endpoints, two distinct jobs:
     *
     *   1. POST /dashboard/wireless/usage-capacity/grid — paged fleet-wide
     *      AP list (radio_5g_utilization_score, wifi1_noise,
     *      has_usage_capacity_issue, …). Drives band 5 GHz util (mean),
     *      noise (mean), saturated (count > 75%) across the WHOLE fleet,
     *      not a sample. Requires the "Dashboard" API scope.
     *      Cost: ceil(fleet/100) paged calls per cache miss.
     *
     *   2. GET /d360/wireless/interfaces-stats?deviceId=… — per-AP wifi1
     *      snapshot (channel + channel_utilization). Sampled per top-N
     *      site for the channel heatmap (the grid endpoint doesn't return
     *      channel numbers).
     *      Cost: D360_SITE_SAMPLE calls per cache miss.
     *
     * If the token loses Dashboard scope (403), band stats degrade to the
     * sample — same call we use for the heatmap, no extra requests.
     *
     * APCu-cached for 5 min so hot refreshes cost zero XIQ calls.
     */
    private static function overlayXiqD360(array &$payload, string $token, array $fleet): void {
        if (function_exists('apcu_fetch')) {
            $hit = apcu_fetch(self::D360_CACHE_KEY, $ok);
            if ($ok && is_array($hit)) {
                self::applyD360($payload, $hit);
                return;
            }
        }

        $aggregate = ['band5' => null, 'channelGrid' => null];

        // ── Layer 4a: fleet-wide band stats via the Dashboard grid ──────────
        try {
            $fleetClient = XIQFleetClient::fromToken($token);
            // 5-min client-level cache so Layer 6 (problem context) hits it
            // for free when the D360 overlay rolls.
            $rows = $fleetClient->getUsageCapacityGrid(300);
            if ($rows) {
                $aggregate['band5'] = self::aggregateBandFromGrid($rows);
            }
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] usage-capacity grid: ' . $e->getMessage());
        }

        // ── Layer 4b: channel grid via per-site interfaces-stats sampling ───
        try {
            $sample = self::sampleD360($token, $fleet);
            $aggregate['channelGrid'] = $sample['channelGrid'];
            // Grid scope unavailable? Fall back to the sample's band stats.
            if ($aggregate['band5'] === null) {
                $aggregate['band5'] = $sample['band5'];
            }
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] d360 sample: ' . $e->getMessage());
        }

        if (function_exists('apcu_store')) {
            apcu_store(self::D360_CACHE_KEY, $aggregate, self::D360_CACHE_TTL);
        }
        self::applyD360($payload, $aggregate);
    }

    /**
     * Aggregate band 5 GHz health from the full usage-capacity grid.
     * Fields per row (per XIQ spec):
     *   radio_5g_utilization_score: 0–100
     *   wifi1_noise:                dBm (negative)
     *   has_usage_capacity_issue:   bool
     */
    private static function aggregateBandFromGrid(array $rows): array {
        $utilSum  = 0; $utilN  = 0;
        $noiseSum = 0; $noiseN = 0;
        $saturated = 0;
        $issues    = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            if (isset($r['radio_5g_utilization_score'])) {
                $u = (int) $r['radio_5g_utilization_score'];
                $utilSum += $u; $utilN++;
                if ($u > 75) $saturated++;
            }
            if (isset($r['wifi1_noise'])) {
                $n = (int) $r['wifi1_noise'];
                if ($n !== 0) { $noiseSum += $n; $noiseN++; }
            }
            if (!empty($r['has_usage_capacity_issue'])) $issues++;
        }
        return [
            'util'      => $utilN  > 0 ? (int) round($utilSum  / $utilN)  : 0,
            'noise'     => $noiseN > 0 ? (int) round($noiseSum / $noiseN) : 0,
            'saturated' => $saturated,
            'spark'     => array_fill(0, 24, 0),
            'issues'    => $issues,
            'n'         => $utilN,
        ];
    }

    /**
     * Sample one AP per top-N site via /d360/wireless/interfaces-stats.
     * Used by both the channel grid (always) and as a band-stats fallback
     * when the Dashboard grid endpoint is forbidden.
     */
    private static function sampleD360(string $token, array $fleet): array {
        $fleetClient = XIQFleetClient::fromToken($token);

        // Group online APs by site, rank by AP count, take top N.
        $bySite = [];
        foreach ($fleet['devices'] as $d) {
            if (($d['xiqId'] ?? 0) <= 0)             continue;
            if (($d['state'] ?? '') !== 'online')    continue;
            $bySite[$d['building']][] = $d;
        }
        uksort($bySite, fn($a, $b) => count($bySite[$b]) <=> count($bySite[$a]));
        $bySite = array_slice($bySite, 0, self::D360_SITE_SAMPLE, true);

        // Pick one representative AP per site, then dispatch all interfaces-stats
        // calls in parallel (curl_multi). Was: D360_SITE_SAMPLE sequential RTTs.
        $repByBuilding = [];
        $deviceIds     = [];
        foreach ($bySite as $building => $devs) {
            usort($devs, fn($a, $b) => $b['clients'] <=> $a['clients']);
            $rep = $devs[0];
            $repByBuilding[$building] = $rep;
            $deviceIds[$building]     = (int) $rep['xiqId'];
        }

        $responses = $deviceIds ? $fleetClient->getInterfacesStatsMulti($deviceIds) : [];

        $samples = [];
        $loggedRaw = false;
        foreach ($repByBuilding as $building => $rep) {
            $resp = $responses[$building] ?? null;
            if (is_string($resp)) {
                error_log('[tcs_dashboard] interfaces-stats ' . $building . ' (xiqId=' . $rep['xiqId'] . '): ' . $resp);
                continue;
            }
            if (!is_array($resp)) continue;
            if (!$loggedRaw) {
                $loggedRaw = true;
                error_log('[tcs_dashboard] interfaces-stats wifi1 (xiqId=' . $rep['xiqId'] . '): '
                    . json_encode($resp['wifi1'] ?? null, JSON_UNESCAPED_SLASHES));
            }
            $w1 = $resp['wifi1'] ?? null;
            if (is_array($w1)) {
                $samples[] = [
                    'building' => $building,
                    'channel'  => isset($w1['channel'])             ? (int) $w1['channel']             : null,
                    'util'     => isset($w1['channel_utilization']) ? (int) $w1['channel_utilization'] : null,
                ];
            }
        }

        // ── Band 5 GHz aggregate from the samples ───────────────────────────
        $utilSum = 0; $utilN = 0; $sat = 0;
        foreach ($samples as $s) {
            if ($s['util'] === null) continue;
            $utilSum += $s['util']; $utilN++;
            if ($s['util'] > 75) $sat++;
        }
        $band5 = [
            'util'      => $utilN > 0 ? (int) round($utilSum / $utilN) : 0,
            'noise'     => 0,        // not exposed by interfaces-stats
            'saturated' => $sat,
            'spark'     => array_fill(0, 24, 0),
            'n'         => $utilN,
        ];

        // ── Channel grid: actual channels seen, one row per sampled site ────
        $channels = [];
        foreach ($samples as $s) if ($s['channel'] !== null) $channels[$s['channel']] = true;
        $channels = array_keys($channels);
        sort($channels);
        if (!$channels) {
            $channels = [36, 40, 44, 48, 52, 56, 60, 64, 100, 104, 108, 112, 149, 153, 157, 161];
        }
        $sites = []; $matrix = [];
        foreach ($samples as $s) {
            $row = array_fill(0, count($channels), 0);
            if ($s['channel'] !== null && $s['util'] !== null) {
                $ci = array_search($s['channel'], $channels, true);
                if ($ci !== false) $row[$ci] = $s['util'];
            }
            $sites[]  = self::siteIdFromName($s['building']);
            $matrix[] = $row;
        }

        return [
            'band5'       => $band5,
            'channelGrid' => ['sites' => $sites, 'channels' => $channels, 'matrix' => $matrix],
        ];
    }

    /** Merge a d360 aggregate into the payload shape the React side reads. */
    private static function applyD360(array &$payload, array $agg): void {
        $band5 = $agg['band5'] ?? null;
        if (is_array($band5)) {
            foreach ($payload['bands'] as &$b) {
                if ($b['id'] !== '5') continue;
                $b['util']      = (int) ($band5['util']      ?? 0);
                $b['noise']     = (int) ($band5['noise']     ?? 0);
                $b['saturated'] = (int) ($band5['saturated'] ?? 0);
                $sp = $band5['spark'] ?? [];
                if (is_array($sp) && $sp) $b['spark'] = $sp;
            }
            unset($b);

            // rfHealth proxy: 100 - util.
            $util = (int) ($band5['util'] ?? 0);
            $payload['totals']['rfHealth'] = ['score' => max(0, 100 - $util), 'target' => 90];
        }

        $grid = $agg['channelGrid'] ?? null;
        if (is_array($grid) && !empty($grid['matrix'])) {
            $payload['channelGrid'] = $grid;
        }
    }

    // ── Shared helpers ──────────────────────────────────────────────────────

    /**
     * Classify a host as online | offline | idle | disabled from
     * interface availability. Used when xiq.ap.connected[<serial>] isn't
     * available for this AP.
     */
    private static function hostReachState(array $host): string {
        if ((int) ($host['status'] ?? 0) !== 0) return 'disabled';
        if ((int) ($host['maintenance_status'] ?? 0) === 1) return 'online';

        $sawAvailable = false; $sawUnavailable = false;
        foreach ($host['interfaces'] ?? [] as $iface) {
            $a = (int) ($iface['available'] ?? 0);
            if ($a === 1) $sawAvailable = true;
            if ($a === 2) $sawUnavailable = true;
        }
        if ($sawAvailable)   return 'online';
        if ($sawUnavailable) return 'offline';
        return 'idle';
    }

    private static function zabbixSevToLabel(int $sev): string {
        return [0 => 'info', 1 => 'info', 2 => 'warning', 3 => 'warning', 4 => 'high', 5 => 'disaster'][$sev] ?? 'info';
    }

    private static function sevRank(string $label): int {
        return ['ok' => 0, 'info' => 1, 'warning' => 2, 'high' => 3, 'disaster' => 4][$label] ?? 0;
    }

    private static function siteIdFromName(string $name): string {
        $stopwords = ['the', 'of', 'for', 'and', 'a', 'an'];
        $initials = '';
        foreach (preg_split('/[\s\-_\/]+/', $name) as $word) {
            $w = strtolower(trim($word));
            if ($w === '' || in_array($w, $stopwords, true)) continue;
            $initials .= strtoupper(substr($w, 0, 1));
            if (strlen($initials) >= 4) break;
        }
        if (strlen($initials) >= 2) return $initials;
        $alnum = strtoupper(preg_replace('/[^a-z0-9]+/i', '', $name) ?: '');
        return $alnum === '' ? 'SITE' : substr($alnum, 0, 3);
    }

    private static function normalizeOs(string $raw): string {
        $s = strtoupper(trim($raw));
        if ($s === '') return 'Other';
        if (str_contains($s, 'CHROME'))                                                                  return 'ChromeOS';
        if (str_contains($s, 'WIN'))                                                                     return 'Windows';
        if (str_contains($s, 'IPAD') || str_contains($s, 'IPHONE') || str_contains($s, 'IOS'))           return 'iPadOS';
        if (str_contains($s, 'MAC') || str_contains($s, 'OSX'))                                          return 'macOS';
        if (str_contains($s, 'ANDROID'))                                                                 return 'Android';
        if (str_contains($s, 'LINUX'))                                                                   return 'Linux';
        return ucfirst(strtolower($raw));
    }

    private static function compareSemver(string $a, string $b): int {
        if ($a === $b) return 0;
        $pa = array_map('intval', preg_split('/[.\-_]/', $a) ?: []);
        $pb = array_map('intval', preg_split('/[.\-_]/', $b) ?: []);
        $len = max(count($pa), count($pb));
        for ($i = 0; $i < $len; $i++) {
            $x = $pa[$i] ?? 0;
            $y = $pb[$i] ?? 0;
            if ($x !== $y) return $x <=> $y;
        }
        return strcmp($a, $b);
    }
}
