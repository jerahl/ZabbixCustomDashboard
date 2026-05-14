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
     * Whole-fleet active client list (views=FULL — emits rssi, snr, channel,
     * connection_duration, locations[]; without it XIQ returns only 12 fields).
     */
    public function getActiveClients(int $cacheTtl = 60): array {
        return $this->cached('clients_active', $cacheTtl, function () {
            return $this->getPaged('/clients/active', ['views' => 'FULL']);
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
     */
    private function getPaged(string $path, array $query): array {
        $all  = [];
        $page = 1;
        do {
            $resp = $this->getJson($path, $query + ['page' => $page, 'limit' => self::PAGE_LIMIT]);
            $rows = $resp['data'] ?? (array_is_list($resp) ? $resp : []);
            if (!is_array($rows) || !$rows) break;
            foreach ($rows as $r) $all[] = $r;

            $totalPages = (int) ($resp['total_pages'] ?? 0);
            if ($totalPages > 0) {
                if ($page >= $totalPages) break;
            } else {
                // No total_pages — stop when we get a short page.
                if (count($rows) < self::PAGE_LIMIT) break;
            }
            $page++;
            if ($page > self::MAX_PAGES) break;
        } while (true);

        return $all;
    }

    /** @return array<string, mixed> */
    public function getJson(string $path, array $query): array {
        return $this->request('GET', $path, $query, null);
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
}
