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

    /** APCu key for the parsed master-item snapshot. TTL matches the upstream
     *  poll interval — the Zabbix Script item runs every 5 minutes. */
    private const FLEET_CACHE_TTL = 60;
    private const FLEET_CACHE_KEY = 'tcs_dashboard:xiq_fleet:v5';

    /** Master item key on the fleet host (see template "Extreme XIQ APs by API"). */
    private const MASTER_ITEM_KEY = 'xiq.devices.raw';

    /** Tag on per-AP hosts created by the host prototype. */
    private const AP_HOST_TAG = ['tag' => 'target', 'value' => 'xiq'];

    protected function checkInput(): bool {
        return $this->validateInput([]);
    }

    protected function doAction(): void {
        $payload = self::emptyPayload();

        // Layer 1: Zabbix master-item snapshot — the bulk of the data.
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
                    ?? 'No XIQ master item found in Zabbix. Expected an item with key "' . self::MASTER_ITEM_KEY . '" on the fleet host (template "Extreme XIQ APs by API").';
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

        // Layer 3: XIQ direct call for what Zabbix does NOT collect — per-client
        // OS, PHY standard, and SSID counts.
        $token = self::xiqToken();
        if ($token === null) {
            $payload['sources']['xiq'] = 'no-token';
            $payload['warning']        = $payload['warning']
                ?? 'XIQ direct queries skipped — {$XIQ_TOKEN} macro is not set. Client mix / SSID counts are unavailable.';
        } else {
            try {
                self::overlayXiqClients($payload, $token);
                $payload['sources']['xiq'] = 'live';
            } catch (\Throwable $e) {
                error_log('[tcs_dashboard] xiq.data XIQ overlay: ' . $e->getMessage());
                $payload['sources']['xiq'] = 'error';
                $payload['warning']        = $payload['warning'] ?? ('XIQ direct query failed: ' . $e->getMessage());
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

    /** Three radio bands the design expects, with counts blanked until data arrives. */
    private static function bandShells(): array {
        $zeros = array_fill(0, 24, 0);
        return [
            ['id' => '5',   'label' => '5 GHz',      'aps' => 0, 'clients' => 0, 'util' => 0, 'noise' => 0, 'saturated' => 0, 'color' => 'var(--ext)',  'spark' => $zeros],
            ['id' => '2_4', 'label' => '2.4 GHz',    'aps' => 0, 'clients' => 0, 'util' => 0, 'noise' => 0, 'saturated' => 0, 'color' => 'var(--warn)', 'spark' => $zeros],
            ['id' => '6',   'label' => '6 GHz (6E)', 'aps' => 0, 'clients' => 0, 'util' => 0, 'noise' => 0, 'saturated' => 0, 'color' => 'var(--ok)',   'spark' => $zeros],
        ];
    }

    // ── Layer 1: master-item snapshot ───────────────────────────────────────

    /**
     * Read xiq.devices.raw, decode, and project into the shapes the page wants.
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
        $empty = ['devices' => [], 'apTotals' => self::emptyPayload()['totals']['aps'],
                  'clientTotal' => 0, 'sites' => [], 'firmware' => ['versions' => []],
                  'firmwareTotals' => self::emptyPayload()['totals']['firmware'],
                  'bands' => self::bandShells()];

        // Find the master item. There should only be one in a typical deployment
        // (the fleet host). If there are several we take the freshest.
        $items = API::Item()->get([
            'output'      => ['itemid', 'hostid', 'lastvalue', 'lastclock'],
            'filter'      => ['key_' => self::MASTER_ITEM_KEY],
            'limit'       => 5,
            'sortfield'   => 'lastclock',
            'sortorder'   => 'DESC',
        ]) ?: [];
        if (!$items) return $empty;

        $raw = (string) ($items[0]['lastvalue'] ?? '');
        if ($raw === '') return $empty;

        $devices = json_decode($raw, true);
        if (!is_array($devices) || !$devices) return $empty;

        // ── Totals ──────────────────────────────────────────────────────────
        $total = count($devices);
        $online = 0; $offline = 0; $idle = 0;
        $clientTotal = 0;
        $now = time();
        foreach ($devices as $d) {
            $connected = (int) ($d['connected'] ?? 0);
            $last      = (int) ($d['last_connect'] ?? 0);
            // last_connect can be unix ms — normalize.
            if ($last > 9999999999) $last = (int) ($last / 1000);

            if ($connected === 1) $online++;
            elseif ($connected === 0 && $last > 0 && ($now - $last) < 24 * 3600) $offline++;
            elseif ($connected === 0) $idle++;
            else $idle++;

            $clientTotal += (int) ($d['clients'] ?? 0);
        }
        $apTotals = [
            'total'    => $total,
            'online'   => $online,
            'offline'  => $offline,
            'critical' => 0,            // refined later from problem severities
            'idle'     => $idle,
        ];

        // ── Sites: group by building, count online via connected flag ───────
        $siteAcc = [];
        foreach ($devices as $d) {
            $building = trim((string) ($d['building'] ?? ''));
            if ($building === '') $building = trim((string) ($d['location'] ?? '')); // fall back
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
            if ((int) ($d['connected'] ?? 0) === 1) $siteAcc[$building]['online']++;
            $siteAcc[$building]['clients'] += (int) ($d['clients'] ?? 0);
        }
        // Outage flag — site has ≥25% offline.
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
        usort($siteAcc, fn($a, $b) => self::sevRank($b['sev']) <=> self::sevRank($a['sev']) ?: strcmp($a['name'], $b['name']));
        $sites = array_values($siteAcc);

        // ── Firmware histogram ──────────────────────────────────────────────
        $versionCounts = [];
        foreach ($devices as $d) {
            $v = trim((string) ($d['version'] ?? ''));
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

        // ── Bands: AP counts (every XIQ AP broadcasts at least 5 + 2.4) ─────
        $bands = self::bandShells();
        foreach ($bands as &$b) {
            if ($b['id'] === '5' || $b['id'] === '2_4') {
                $b['aps'] = $total;
            } else {
                // 6 GHz: only newer models. Heuristic — APs whose product_type
                // contains an "X" or starts with AP4/AP5 usually support 6E.
                $b['aps'] = 0;
                foreach ($devices as $d) {
                    $m = strtoupper((string) ($d['model'] ?? ''));
                    if (preg_match('/^AP(4|5)\d+/', $m) || str_ends_with($m, 'X')) $b['aps']++;
                }
            }
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
                ];
            }
        }

        usort($problemAps, fn($a, $b) => $b['_sevr'] <=> $a['_sevr'] ?: $b['_clock'] <=> $a['_clock']);
        $problemAps = array_slice($problemAps, 0, 8);
        foreach ($problemAps as &$p) { unset($p['_clock'], $p['_sevr']); }
        unset($p);

        return ['problemAps' => $problemAps, 'events' => $eventRows, 'criticalCount' => $criticalCount];
    }

    // ── Layer 3: XIQ direct (per-client breakdown only) ─────────────────────

    /** Returns the {$XIQ_TOKEN} global macro value, or null when unset/empty. */
    private static function xiqToken(): ?string {
        // Try the global macro first (per template hint); also accept the
        // older {$XIQ_API_TOKEN} name in case operators set that one.
        foreach (['{$XIQ_TOKEN}', '{$XIQ_API_TOKEN}'] as $name) {
            $rows = API::UserMacro()->get([
                'output'      => ['macro', 'value'],
                'globalmacro' => true,
                'filter'      => ['macro' => $name],
            ]) ?: [];
            $v = trim((string) ($rows[0]['value'] ?? ''));
            if ($v !== '') return $v;
        }
        // Fall back: pull from any host macro on the XIQ fleet host
        // (the template ships {$XIQ_TOKEN} as a host macro, not a global).
        $rows = API::UserMacro()->get([
            'output' => ['macro', 'value', 'hostid'],
            'filter' => ['macro' => '{$XIQ_TOKEN}'],
        ]) ?: [];
        foreach ($rows as $r) {
            $v = trim((string) ($r['value'] ?? ''));
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

        // Bands client split using the PHY buckets we already have.
        foreach ($payload['bands'] as &$b) {
            if ($b['id'] === '5')       $b['clients'] = $std['ax'] + $std['ac'];
            elseif ($b['id'] === '2_4') $b['clients'] = $std['n']  + $std['legacy'];
            // 6 GHz client count is hard to derive from radio_type alone — leave at 0
        }
        unset($b);

        $payload['totals']['controllers']['lastSync'] = 'just now';

        if ($client->isRateLimitLow()) {
            $rem = $client->getRateLimitRemaining();
            $payload['warning'] = "XIQ rate-limit low: $rem requests left this hour.";
        }
    }

    // ── Shared helpers ──────────────────────────────────────────────────────

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
