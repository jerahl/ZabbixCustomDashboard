<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Lib;

/**
 * Fleet-level ExtremeCloud IQ client.
 *
 * Sits alongside XIQClient (which is verbatim from jerahl/ZabbixExtremeIQ and
 * scoped to per-device queries). XIQFleetClient hits the cloud-wide list
 * endpoints — /devices, /clients/active — and handles paging, caching, and
 * rate-limit accounting on its own minimal cURL shim so the upstream client
 * stays unmodified for easy re-syncs.
 *
 * Token-only auth: pass a permanent API token from Zabbix global macro
 * {$XIQ_API_TOKEN}. JWT credential flow lives in XIQClient if needed.
 *
 * Caching: APCu, per-endpoint TTL. Defaults are sized for a ~30s page-refresh
 * cadence — devices change slowly (5min), active clients churn faster (60s).
 *
 * Rate-limit awareness: every response updates getRateLimitRemaining(). The
 * 7,500-req/hr quota is shared across all XIQ integrations on the tenant.
 */
final class XIQFleetClient {

    private const BASE_URL      = 'https://api.extremecloudiq.com';
    private const CACHE_PREFIX  = 'tcs_dashboard:xiq_fleet_client:';
    private const PAGE_LIMIT    = 100;
    private const MAX_PAGES     = 200;       // hard ceiling — defends against runaway pagination
    private const HTTP_TIMEOUT  = 30;
    /** Cap on simultaneous in-flight HTTP requests across curl_multi batches.
     *  XIQ tolerates dozens of concurrent requests easily and the 7,500-req/hr
     *  quota is on TOTAL volume, not concurrency. We cap at 12 to be polite. */
    private const MULTI_CONCURRENCY = 12;

    private string $token;
    private int $rateLimitRemaining = -1;
    private int $rateLimitReset     = 0;

    private function __construct(string $token) {
        $this->token = $token;
    }

    public static function fromToken(string $token): self {
        if ($token === '') {
            throw new \InvalidArgumentException('XIQFleetClient: empty API token');
        }
        return new self($token);
    }

    public function getRateLimitRemaining(): int { return $this->rateLimitRemaining; }
    public function getRateLimitReset(): int     { return $this->rateLimitReset; }
    public function isRateLimitLow(): bool       { return $this->rateLimitRemaining >= 0 && $this->rateLimitRemaining < 500; }

    /**
     * Whole-fleet AP list.
     *
     * Each row is the BASIC view of GET /devices — id, hostname, mac_address,
     * device_function, product_type, network_policy_id, software_version,
     * connected (bool), last_connect_time_ms, ip_address. Use views=FULL only
     * if you need d360 telemetry (much heavier).
     */
    public function getDevices(int $cacheTtl = 300): array {
        return $this->cached('devices', $cacheTtl, function () {
            return $this->getPaged('/devices', ['views' => 'BASIC']);
        });
    }

    /**
     * Whole-fleet active client list. We only consume id / radio_type / os_type
     * / ssid in the dashboard, so request just those fields instead of the
     * FULL view — much smaller pages = faster paging on large client fleets.
     */
    public function getActiveClients(int $cacheTtl = 60): array {
        return $this->cached('clients_active', $cacheTtl, function () {
            return $this->getPaged('/clients/active', ['fields' => 'ID,RADIO_TYPE,OS_TYPE,SSID']);
        });
    }

    /**
     * List of network policies (id + name). Use as the seed for SSID rollups
     * via XIQClient::getPolicySsids($policyId).
     */
    public function getNetworkPolicies(int $cacheTtl = 600): array {
        return $this->cached('policies', $cacheTtl, function () {
            return $this->getPaged('/network-policies', []);
        });
    }

    /**
     * Whole-fleet wireless usage & capacity grid (POST, paged).
     *
     * Each row exposes the fields we need for the Band Health card:
     *   device_id, hostname, mac_address, site, building, floor,
     *   radio_5g_utilization_score (0–100), wifi1_noise (dBm),
     *   wifi1_interference_score, wifi1_packet_loss, wifi1_retry_score,
     *   healthy_clients, unhealthy_clients,
     *   has_usage_capacity_issue, link_error5g
     *
     * Requires the "Dashboard" API scope on the bearer token. The 7,500-
     * req/hr quota easily absorbs ceil(879/100)=9 paged calls per cache
     * miss; we wrap in the bridge's 5-min APCu cache so steady-state cost
     * is zero.
     */
    public function getUsageCapacityGrid(int $cacheTtl = 0, string $sortField = 'RADIO_5G_UTILIZATION_SCORE'): array {
        $bucket = 'ucGrid_' . $sortField;
        return $this->cached($bucket, $cacheTtl, function () use ($sortField) {
            $path = '/dashboard/wireless/usage-capacity/grid';
            $mkQuery = fn(int $page) => [
                'page'      => $page,
                'limit'     => self::PAGE_LIMIT,
                'sortField' => $sortField,
                'sortOrder' => 'DESC',
            ];

            // Page 1 — also tells us total_pages.
            $first = $this->postJson($path, $mkQuery(1), new \stdClass());
            $firstRows = $first['data'] ?? [];
            if (!is_array($firstRows) || !$firstRows) return [];

            $all = $firstRows;
            $totalPages = (int) ($first['total_pages'] ?? 0);

            if ($totalPages > 1) {
                // Parallel-fetch pages 2..N via curl_multi.
                $last = min($totalPages, self::MAX_PAGES);
                $reqs = [];
                for ($p = 2; $p <= $last; $p++) {
                    $url = self::BASE_URL . $path . '?' . http_build_query($mkQuery($p));
                    $reqs[$p] = ['method' => 'POST', 'url' => $url, 'body' => new \stdClass(), 'label' => 'usage-capacity?page=' . $p];
                }
                foreach ($this->multiJson($reqs) as $resp) {
                    $rows = $resp['data'] ?? [];
                    if (!is_array($rows)) continue;
                    foreach ($rows as $r) $all[] = $r;
                }
                return $all;
            }

            if ($totalPages === 0 && count($firstRows) >= self::PAGE_LIMIT) {
                // No total_pages metadata — sequential fallback.
                $page = 2;
                do {
                    $resp = $this->postJson($path, $mkQuery($page), new \stdClass());
                    $rows = $resp['data'] ?? [];
                    if (!is_array($rows) || !$rows) break;
                    foreach ($rows as $r) $all[] = $r;
                    if (count($rows) < self::PAGE_LIMIT) break;
                    $page++;
                    if ($page > self::MAX_PAGES) break;
                } while (true);
            }

            return $all;
        });
    }

    /**
     * Per-AP current wireless interface snapshot.
     *
     * GET /d360/wireless/interfaces-stats — returns wifi0/wifi1/wifi2, each
     * with channel, channel_utilization, channel_width, number_of_clients,
     * channel_utilization_details. One snapshot value, not a time series.
     *
     * Time window must be at least 10 minutes per XIQ docs (G6); we pass
     * a 15-min trailing window like XIQClient::getWifiStats.
     */
    public function getInterfacesStats(int $deviceId): array {
        $end   = time();
        $start = $end - 900;
        return $this->getJson('/d360/wireless/interfaces-stats', [
            'deviceId'  => $deviceId,
            'startTime' => $start * 1000,
            'endTime'   => $end   * 1000,
        ]);
    }

    /**
     * Batched /d360/wireless/interfaces-stats. One call per device id,
     * dispatched in parallel via curl_multi. Failures per device are stored
     * as the literal string error so the caller can skip them — we don't want
     * one bad AP to take down the whole heatmap.
     *
     * @param array<int|string, int> $deviceIdsByKey
     * @return array<int|string, array|string>  decoded array on success, error string on failure
     */
    public function getInterfacesStatsMulti(array $deviceIdsByKey): array {
        if (!$deviceIdsByKey) return [];
        $end   = time();
        $start = $end - 900;
        $reqs = [];
        foreach ($deviceIdsByKey as $k => $deviceId) {
            $query = http_build_query([
                'deviceId'  => (int) $deviceId,
                'startTime' => $start * 1000,
                'endTime'   => $end   * 1000,
            ]);
            $url = self::BASE_URL . '/d360/wireless/interfaces-stats?' . $query;
            $reqs[$k] = ['method' => 'GET', 'url' => $url, 'label' => 'interfaces-stats[' . $deviceId . ']'];
        }
        return $this->multiJsonLenient($reqs);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @param callable():array $producer */
    private function cached(string $bucket, int $ttl, callable $producer): array {
        $key = self::CACHE_PREFIX . $bucket;
        if ($ttl > 0 && function_exists('apcu_fetch')) {
            $hit = apcu_fetch($key, $ok);
            if ($ok && is_array($hit)) return $hit;
        }
        $value = $producer();
        if ($ttl > 0 && function_exists('apcu_store')) {
            apcu_store($key, $value, $ttl);
        }
        return $value;
    }

    /**
     * Drain a paginated XIQ list endpoint. XIQ list responses follow one of
     * two shapes; we handle both:
     *   { data: [...], total_pages: N, page: M }                 (wrapped)
     *   [ ... ]                                                  (raw)
     *
     * Optimization: fetch page 1 sequentially to learn total_pages, then
     * dispatch pages 2..N in parallel via curl_multi. Falls back to the
     * sequential drain when the response is a raw list (no total_pages).
     */
    private function getPaged(string $path, array $query): array {
        // Page 1 — also tells us total_pages when XIQ wraps the list.
        $first      = $this->getJson($path, $query + ['page' => 1, 'limit' => self::PAGE_LIMIT]);
        $firstRows  = $first['data'] ?? (array_is_list($first) ? $first : []);
        if (!is_array($firstRows) || !$firstRows) return [];

        $all = $firstRows;
        $totalPages = (int) ($first['total_pages'] ?? 0);

        if ($totalPages > 1) {
            // Parallel-fetch the remaining pages.
            $last = min($totalPages, self::MAX_PAGES);
            $reqs = [];
            for ($p = 2; $p <= $last; $p++) {
                $url = self::BASE_URL . $path . '?' . http_build_query($query + ['page' => $p, 'limit' => self::PAGE_LIMIT]);
                $reqs[$p] = ['method' => 'GET', 'url' => $url, 'label' => $path . '?page=' . $p];
            }
            foreach ($this->multiJson($reqs) as $resp) {
                $rows = $resp['data'] ?? (array_is_list($resp) ? $resp : []);
                if (!is_array($rows)) continue;
                foreach ($rows as $r) $all[] = $r;
            }
            return $all;
        }

        if ($totalPages === 0) {
            // No total_pages metadata — fall back to a sequential drain that
            // stops on the first short page.
            if (count($firstRows) < self::PAGE_LIMIT) return $all;
            $page = 2;
            do {
                $resp = $this->getJson($path, $query + ['page' => $page, 'limit' => self::PAGE_LIMIT]);
                $rows = $resp['data'] ?? (array_is_list($resp) ? $resp : []);
                if (!is_array($rows) || !$rows) break;
                foreach ($rows as $r) $all[] = $r;
                if (count($rows) < self::PAGE_LIMIT) break;
                $page++;
                if ($page > self::MAX_PAGES) break;
            } while (true);
        }

        return $all;
    }

    /** @return array<string, mixed> */
    public function getJson(string $path, array $query): array {
        return $this->request('GET', $path, $query, null);
    }

    /**
     * GET with a pre-built `path?query` string. Use when array params need
     * a non-PHP-default encoding (e.g. repeated keys without `[]`).
     */
    public function getRaw(string $pathAndQuery): array {
        $url = self::BASE_URL . $pathAndQuery;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        return $this->execAndParse($ch, $pathAndQuery);
    }

    /** @return array<string, mixed> */
    public function postJson(string $path, array $query, $body): array {
        return $this->request('POST', $path, $query, $body);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $query, $body): array {
        $url = self::BASE_URL . $path . ($query ? '?' . http_build_query($query) : '');
        $ch  = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? new \stdClass(), JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        return $this->execAndParse($ch, $path);
    }

    private function execAndParse($ch, string $path): array {

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("XIQ transport: $err");
        }
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = (string) substr($raw, 0, $headerSize);
        $body    = (string) substr($raw, $headerSize);

        // RateLimit headers are advisory but cheap to track for the warning banner.
        if (preg_match('/^RateLimit-Remaining:\s*(\d+)/im', $headers, $m)) {
            $this->rateLimitRemaining = (int) $m[1];
        }
        if (preg_match('/^RateLimit-Reset:\s*(\d+)/im', $headers, $m)) {
            $this->rateLimitReset = (int) $m[1];
        }

        if ($status === 401) throw new \RuntimeException('XIQ 401 — token revoked or invalid');
        if ($status === 429) throw new \RuntimeException('XIQ 429 — rate limit exceeded');
        if ($status < 200 || $status >= 300) {
            $snip = substr($body, 0, 240);
            throw new \RuntimeException("XIQ HTTP $status on $path — $snip");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('XIQ returned non-JSON body');
        }
        return $decoded;
    }

    // ── Parallel HTTP (curl_multi) ───────────────────────────────────────────

    /**
     * Run a batch of HTTP requests in parallel.
     *
     * @param array<int|string, array{method?:string,url:string,body?:mixed,label?:string}> $reqs
     * @param int $concurrency  Maximum simultaneous in-flight requests.
     * @return array<int|string, array{status:int,body:string,headers:string,error:?string,label:string}>
     *                                  Keyed identically to $reqs, in input order.
     */
    private function multiRun(array $reqs, int $concurrency = self::MULTI_CONCURRENCY): array {
        if (!$reqs) return [];
        $concurrency = max(1, $concurrency);

        $mh = curl_multi_init();
        $pending = $reqs;          // remaining keys to start
        $inflight = [];            // (int) $ch => ['handle' => ch, 'key' => k, 'label' => str]
        $results  = [];

        $startOne = function () use (&$pending, &$inflight, $mh) {
            if (!$pending) return false;
            $k = array_key_first($pending);
            $r = $pending[$k];
            unset($pending[$k]);

            $ch = curl_init($r['url']);
            $headers = [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ];
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
            ];
            if (($r['method'] ?? 'GET') === 'POST') {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = json_encode($r['body'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES);
                $headers[] = 'Content-Type: application/json';
            }
            $opts[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($ch, $opts);

            curl_multi_add_handle($mh, $ch);
            $inflight[(int) $ch] = ['handle' => $ch, 'key' => $k, 'label' => $r['label'] ?? $r['url']];
            return true;
        };

        // Prime the window.
        for ($i = 0; $i < $concurrency; $i++) {
            if (!$startOne()) break;
        }

        do {
            do { $mrc = curl_multi_exec($mh, $active); } while ($mrc === CURLM_CALL_MULTI_PERFORM);

            if ($active || $pending) {
                // Wait for activity. Older libcurl returns -1 on select; clamp to a short sleep.
                if (curl_multi_select($mh, 1.0) === -1) usleep(50_000);
            }

            while ($info = curl_multi_info_read($mh)) {
                $ch   = $info['handle'];
                $key  = (int) $ch;
                $meta = $inflight[$key] ?? null;
                if ($meta === null) {
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    continue;
                }

                $raw = curl_multi_getcontent($ch);
                $err = null;
                if ($info['result'] !== CURLM_OK) {
                    $err = curl_error($ch) ?: ('cURL multi error ' . $info['result']);
                } elseif ($raw === null || $raw === false) {
                    $err = curl_error($ch) ?: 'empty response';
                }

                $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headersStr = is_string($raw) ? (string) substr($raw, 0, $headerSize) : '';
                $body       = is_string($raw) ? (string) substr($raw, $headerSize) : '';

                // Rate-limit tracking — keep the lowest remaining seen this batch.
                if (preg_match('/^RateLimit-Remaining:\s*(\d+)/im', $headersStr, $m)) {
                    $r = (int) $m[1];
                    if ($this->rateLimitRemaining < 0 || $r < $this->rateLimitRemaining) {
                        $this->rateLimitRemaining = $r;
                    }
                }
                if (preg_match('/^RateLimit-Reset:\s*(\d+)/im', $headersStr, $m)) {
                    $this->rateLimitReset = (int) $m[1];
                }

                $results[$meta['key']] = [
                    'status'  => $statusCode,
                    'body'    => $body,
                    'headers' => $headersStr,
                    'error'   => $err,
                    'label'   => $meta['label'],
                ];

                unset($inflight[$key]);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                $startOne();
            }
        } while ($active || $pending);

        curl_multi_close($mh);

        // Re-order to input order.
        $ordered = [];
        foreach (array_keys($reqs) as $k) {
            if (array_key_exists($k, $results)) $ordered[$k] = $results[$k];
        }
        return $ordered;
    }

    /**
     * Batch of HTTP requests → keyed array of decoded JSON. Throws on any failure
     * (transport, non-2xx, non-JSON body) so callers see the same error contract
     * as the single-request {@see execAndParse}.
     *
     * @param array<int|string, array{method?:string,url:string,body?:mixed,label?:string}> $reqs
     * @return array<int|string, array<string, mixed>>
     */
    private function multiJson(array $reqs): array {
        $out = [];
        foreach ($this->multiRun($reqs) as $k => $r) {
            $label = $r['label'];
            if ($r['error'] !== null)                    throw new \RuntimeException("XIQ transport ($label): {$r['error']}");
            if ($r['status'] === 401)                    throw new \RuntimeException('XIQ 401 — token revoked or invalid');
            if ($r['status'] === 429)                    throw new \RuntimeException('XIQ 429 — rate limit exceeded');
            if ($r['status'] < 200 || $r['status'] >= 300) {
                $snip = substr($r['body'], 0, 240);
                throw new \RuntimeException("XIQ HTTP {$r['status']} on $label — $snip");
            }
            $decoded = json_decode($r['body'], true);
            if (!is_array($decoded)) throw new \RuntimeException("XIQ non-JSON body on $label");
            $out[$k] = $decoded;
        }
        return $out;
    }

    /**
     * Like {@see multiJson} but returns a string error per failed entry instead
     * of throwing. Useful when one bad subrequest shouldn't take down the batch
     * (e.g. one offline AP shouldn't drop the whole channel heatmap).
     *
     * @return array<int|string, array<string, mixed>|string>
     */
    private function multiJsonLenient(array $reqs): array {
        $out = [];
        foreach ($this->multiRun($reqs) as $k => $r) {
            $label = $r['label'];
            if ($r['error'] !== null) {
                $out[$k] = "transport: {$r['error']}";
                continue;
            }
            if ($r['status'] === 401) throw new \RuntimeException('XIQ 401 — token revoked or invalid');
            if ($r['status'] === 429) throw new \RuntimeException('XIQ 429 — rate limit exceeded');
            if ($r['status'] < 200 || $r['status'] >= 300) {
                $out[$k] = "HTTP {$r['status']}: " . substr($r['body'], 0, 200);
                continue;
            }
            $decoded = json_decode($r['body'], true);
            if (!is_array($decoded)) { $out[$k] = 'non-JSON body'; continue; }
            $out[$k] = $decoded;
        }
        return $out;
    }
}
