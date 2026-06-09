<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Lib;

/**
 * MilestoneClient — Milestone XProtect REST API HTTP client.
 *
 * ─── SCOPE ───────────────────────────────────────────────────────────────────
 *
 * Plan-only scaffold (NOT yet wired into any Action). The intent is to let the
 * dashboard talk to Milestone directly from PHP — same pattern as XIQClient
 * and PFClient — instead of routing every lookup through the Python collector
 * snapshot files. Scope-in for this client:
 *
 *   - Recording servers (light call, ~tens of rows)
 *   - Live storage information (one call per storage)
 *   - Camera groups (light call, ~tens of rows)
 *   - Event types + recent events (state-derivation, replaces the
 *     ESS WebSocket collector entirely at small/medium fleets)
 *   - License overview (light call)
 *
 * Explicitly OUT of scope for this client (kept on Python+cron+JSON):
 *
 *   - Per-camera fleet enrichment (/hardware?includeChildren=cameras at
 *     2500+ cameras + MAC settings fan-out + recording-server join). The
 *     cron+snapshot pattern is there specifically to keep dashboard responses
 *     fast AND to feed the per-camera Zabbix items the template ecosystem
 *     depends on. Migrating that path is a bigger refactor (template items
 *     have to come from somewhere) and out of scope here.
 *
 * ─── AUTH STRATEGY ───────────────────────────────────────────────────────────
 *
 * One factory:
 *
 *   MilestoneClient::fromMacros([
 *       'host'  => 'milestone.example.com',
 *       'user'  => 'zbx_monitor',
 *       'pass'  => '...',
 *       'scheme' => 'https',          // optional, default https
 *       'verify_ssl' => true,         // optional, default true
 *       'idp_path' => '/IDP/connect/token',  // optional, default '/IDP/connect/token'
 *                                            // older installs use '/API/IDP/connect/token'
 *       'api_base' => '/api/rest/v1', // optional
 *       'client_id' => 'GrantValidatorClient', // optional
 *   ])
 *
 * POST {idp_path} with grant_type=password returns a bearer access_token good
 * for ~2h. Token is cached in APCu (with /tmp filesystem fallback so workers
 * share it), auto-refreshed once on 401. Pattern matches PFClient.
 *
 * ─── CACHE STRATEGY ──────────────────────────────────────────────────────────
 *
 * APCu primary, /tmp fallback — same as PFClient/XIQClient. Per-resource TTLs
 * tuned to how often each thing actually changes on a Milestone install:
 *
 *   TTL_TOKEN              5400s (~90 min — IDP tokens live 2h; refresh at 90 min)
 *   TTL_RECORDING_SERVERS    60s — RS service state changes; the dashboard
 *                                 polls every 30s and operators expect near-
 *                                 live reads, so 60s is the floor.
 *   TTL_STORAGE_INFO         60s — live used-space; same cadence as RSs.
 *   TTL_CAMERA_GROUPS       300s — groups change on admin edits only.
 *   TTL_EVENT_TYPES        3600s — event-type catalog is effectively static.
 *   TTL_EVENTS               30s — newest events for state derivation; the
 *                                 dashboard's poll cadence is the right floor.
 *   TTL_LICENSE             300s — license counts change on activation.
 *
 * ─── DEPENDENCIES ────────────────────────────────────────────────────────────
 *
 * None — PHP curl only. PHP 8.0+ compatible (uses no 8.1-only syntax).
 *
 * @package Modules\TcsDashboard\Lib
 */
class MilestoneClient {

    private string  $base;          // e.g. 'https://milestone.example.com'
    private string  $user;
    private string  $pass;
    private bool    $verifySsl;
    private string  $idpPath;
    private string  $apiBase;
    private string  $clientId;

    private ?string $token       = null;
    private int     $tokenExpiry = 0;

    /** Per-instance call counter — surfaces in getCallStats() for debug. */
    private int $callCount = 0;

    private const TTL_TOKEN              = 5400;
    private const TTL_RECORDING_SERVERS  = 60;
    private const TTL_STORAGE_INFO       = 60;
    private const TTL_CAMERA_GROUPS      = 300;
    private const TTL_EVENT_TYPES        = 3600;
    private const TTL_EVENTS             = 30;
    private const TTL_LICENSE            = 300;

    private const TIMEOUT_CONNECT = 10;
    private const TIMEOUT_TOTAL   = 30;
    private const UA              = 'TcsDashboard/1.0 (+MilestoneClient)';

    private const CACHE_PREFIX = 'tcs_milestone::';
    private const CACHE_DIR    = '/tmp/tcs_dashboard_cache';

    public function __construct(
        string $host,
        string $user,
        #[\SensitiveParameter] string $pass,
        string $scheme = 'https',
        bool   $verifySsl = true,
        string $idpPath = '/IDP/connect/token',
        string $apiBase = '/api/rest/v1',
        string $clientId = 'GrantValidatorClient'
    ) {
        $this->base      = rtrim($scheme . '://' . $host, '/');
        $this->user      = $user;
        $this->pass      = $pass;
        $this->verifySsl = $verifySsl;
        $this->idpPath   = '/' . ltrim($idpPath, '/');
        $this->apiBase   = '/' . trim($apiBase, '/');
        $this->clientId  = $clientId;
    }

    /**
     * @param array{host:string,user:string,pass:string,scheme?:string,
     *              verify_ssl?:bool,idp_path?:string,api_base?:string,
     *              client_id?:string} $cfg
     */
    public static function fromMacros(array $cfg): self {
        return new self(
            (string) ($cfg['host']   ?? ''),
            (string) ($cfg['user']   ?? ''),
            (string) ($cfg['pass']   ?? ''),
            (string) ($cfg['scheme'] ?? 'https'),
            (bool)   ($cfg['verify_ssl'] ?? true),
            (string) ($cfg['idp_path']   ?? '/IDP/connect/token'),
            (string) ($cfg['api_base']   ?? '/api/rest/v1'),
            (string) ($cfg['client_id']  ?? 'GrantValidatorClient'),
        );
    }

    /* ====================================================================== */
    /* Public surface — what the dashboard Actions will call                  */
    /* ====================================================================== */

    /**
     * Recording servers with storages + hardware + cameras embedded via
     * ?includeChildren= so a single round-trip gives the dashboard everything
     * it needs for the RS tiles (no per-RS fan-out).
     *
     * Returns the raw 'array' field from the REST response (a list of RS
     * records). Each record carries .storages[], .hardware[], and each
     * hardware carries its .cameras[]. Callers do their own field extraction —
     * keeping this client thin and the per-Action shaping explicit.
     *
     * Cached under tcs_milestone:rs:list at TTL_RECORDING_SERVERS.
     *
     * @return list<array<string,mixed>>
     */
    public function getRecordingServers(bool $includeDisabled = false): array {
        $key = 'rs:list:' . ($includeDisabled ? '1' : '0');
        return $this->cached($key, self::TTL_RECORDING_SERVERS, function () use ($includeDisabled) {
            $path = '/recordingServers'
                . '?includeChildren=storages,hardware,cameras'
                . ($includeDisabled ? '&disabled' : '');
            $resp = $this->get($path);
            return is_array($resp['array'] ?? null) ? $resp['array'] : [];
        });
    }

    /**
     * Live storage info for one storage GUID (usedSpace MB, lockedUsedSpace MB,
     * isMounted, isAvailable). The /storageInformation/{id} endpoint isn't a
     * valid includeChildren target on /storages, so this is a separate call
     * per storage. Total storages across a fleet is typically small (1-3 per
     * RS, so ~10-30 fleetwide).
     *
     * Returns null when the storage doesn't exist or the API rejects.
     *
     * Cached under tcs_milestone:storageInfo:{id} at TTL_STORAGE_INFO.
     */
    public function getStorageInformation(string $storageId): ?array {
        if ($storageId === '') return null;
        $key = 'storageInfo:' . $storageId;
        return $this->cached($key, self::TTL_STORAGE_INFO, function () use ($storageId) {
            $resp = $this->get('/storageInformation/' . rawurlencode($storageId));
            // /storageInformation/{id} responds with the bare object (data
            // wrapper varies across API versions); pass either shape through.
            if (isset($resp['usedSpace']) || isset($resp['isMounted'])) return $resp;
            if (is_array($resp['data'] ?? null)) return $resp['data'];
            return null;
        });
    }

    /**
     * Camera groups with their cameras embedded via ?includeChildren=cameras.
     * Replaces the milestone_cameras_state.py per-group N+1 walk.
     *
     * Cached under tcs_milestone:cameraGroups:list at TTL_CAMERA_GROUPS.
     *
     * @return list<array<string,mixed>>  Each row: id, name, displayName, cameras[].
     */
    public function getCameraGroups(): array {
        return $this->cached('cameraGroups:list', self::TTL_CAMERA_GROUPS, function () {
            $resp = $this->get('/cameraGroups?includeChildren=cameras');
            return is_array($resp['array'] ?? null) ? $resp['array'] : [];
        });
    }

    /**
     * Event-type catalog with stategroup binding, paginated and flattened.
     * Returns a map { event-type-guid => state-group-guid }.
     *
     * Used by the dashboard to interpret /events responses (REST events
     * don't carry stategroupid; the WS protocol does — to translate, you
     * need this lookup). Same role as
     * milestone_ess_rest_state.fetch_type_to_stategroup().
     *
     * Cached under tcs_milestone:eventTypes:map at TTL_EVENT_TYPES.
     *
     * @return array<string,string>
     */
    public function getEventTypeStategroupMap(): array {
        return $this->cached('eventTypes:map', self::TTL_EVENT_TYPES, function () {
            $out = [];
            $page = 0;
            $size = 2000;
            while (true) {
                $resp = $this->get("/eventTypes?page={$page}&size={$size}");
                $arr  = is_array($resp['array'] ?? null) ? $resp['array']
                      : (is_array($resp['data']  ?? null) ? $resp['data']  : []);
                if (!$arr) break;
                foreach ($arr as $et) {
                    if (!is_array($et)) continue;
                    $tid = (string) ($et['id'] ?? '');
                    if ($tid === '') continue;
                    $sg = $et['stategroup'] ?? $et['stateGroup'] ?? null;
                    $sgid = '';
                    if (is_array($sg)) {
                        $sgid = (string) ($sg['id'] ?? '');
                    } else {
                        $sgid = (string) ($et['stategroupId']  ?? $et['stategroupid'] ?? '');
                    }
                    if ($sgid !== '') $out[$tid] = $sgid;
                }
                if (count($arr) < $size) break;
                $page++;
            }
            return $out;
        });
    }

    /**
     * Events from the last $lookbackHours hours, newest-first. Filters at the
     * server side by `time` and `orderBy`; source filtering happens client-side
     * because /events.source.id only supports equals/oneOf (not startsWith).
     *
     * Cached under tcs_milestone:events:{hours} at TTL_EVENTS.
     *
     * @return list<array<string,mixed>>
     */
    public function getRecentEvents(int $lookbackHours = 24): array {
        $hours = max(1, $lookbackHours);
        $key   = 'events:' . $hours;
        return $this->cached($key, self::TTL_EVENTS, function () use ($hours) {
            $since = gmdate('Y-m-d\TH:i:s.000\Z', time() - ($hours * 3600));
            $time  = rawurlencode("gt:'{$since}'");
            $order = rawurlencode("desc:'time'");
            $out   = [];
            $page  = 0;
            $size  = 2000;
            while (true) {
                $resp = $this->get("/events?time={$time}&orderBy={$order}&page={$page}&size={$size}");
                $arr  = is_array($resp['array'] ?? null) ? $resp['array'] : [];
                if (!$arr) break;
                foreach ($arr as $ev) {
                    if (is_array($ev)) $out[] = $ev;
                }
                if (count($arr) < $size) break;
                $page++;
            }
            return $out;
        });
    }

    /**
     * License overview — same payload the Site template's milestone.license.get
     * item carries today (raw JSON with productDisplayName, totalLicensesFor*,
     * activatedLicensesFor*, licensedHardwareDeviceCount).
     *
     * Cached under tcs_milestone:license:overview at TTL_LICENSE.
     *
     * @return array<string,mixed>
     */
    public function getLicenseOverview(): array {
        return $this->cached('license:overview', self::TTL_LICENSE, function () {
            // The endpoint shape is /licenseInformations/{id}/licenseOverviewAll
            // — and licenseInformations almost always has exactly one row per
            // site, so we pick the first id then drill in. Cached behind the
            // license-overview key so consumers don't have to round-trip the
            // licenseInformations index every call.
            $resp = $this->get('/licenseInformations');
            $rows = is_array($resp['array'] ?? null) ? $resp['array'] : [];
            $licId = (string) ($rows[0]['id'] ?? '');
            if ($licId === '') return [];
            $overview = $this->get('/licenseInformations/' . rawurlencode($licId) . '/licenseOverviewAll');
            $arr = is_array($overview['array'] ?? null) ? $overview['array'] : [];
            return $arr[0] ?? [];
        });
    }

    /**
     * Per-call counter for debug/health endpoints. Resets per-instance.
     */
    public function getCallStats(): array {
        return ['calls' => $this->callCount];
    }

    /* ====================================================================== */
    /* HTTP plumbing                                                          */
    /* ====================================================================== */

    /** @return array<string,mixed> */
    private function get(string $path): array {
        return $this->call('GET', $path, null);
    }

    /**
     * Run the call. Refreshes the token once on 401 and retries.
     *
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, ?array $body): array {
        $this->ensureToken();

        [$status, $payload] = $this->raw($method, $path, $body, [
            'Authorization: Bearer ' . ($this->token ?? '')
        ]);

        if ($status === 401) {
            $this->token = null;
            $this->tokenExpiry = 0;
            $this->forgetCachedToken();
            $this->ensureToken();
            [$status, $payload] = $this->raw($method, $path, $body, [
                'Authorization: Bearer ' . ($this->token ?? '')
            ]);
        }

        if ($status >= 400) {
            throw new \RuntimeException("MilestoneClient: HTTP {$status} for {$method} {$path}");
        }
        return $payload;
    }

    private function ensureToken(): void {
        if ($this->token !== null && $this->tokenExpiry > time()) return;

        $cached = $this->readCachedToken();
        if ($cached !== null) {
            $this->token       = $cached['token'];
            $this->tokenExpiry = $cached['expires'];
            return;
        }

        // POST {idp_path} grant_type=password — form-encoded, not JSON.
        // Build form body manually so the raw() helper can stay JSON-only
        // for the API calls themselves.
        $body = http_build_query([
            'grant_type' => 'password',
            'username'   => $this->user,
            'password'   => $this->pass,
            'client_id'  => $this->clientId,
        ]);

        $url = $this->base . $this->idpPath;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('MilestoneClient: curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONNECT,
            CURLOPT_TIMEOUT        => self::TIMEOUT_TOTAL,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $this->callCount++;

        if ($raw === false) {
            throw new \RuntimeException("MilestoneClient: IDP transport error: {$err}");
        }
        if ($code >= 400) {
            throw new \RuntimeException("MilestoneClient: IDP HTTP {$code}");
        }

        $payload = json_decode((string) $raw, true);
        $token = is_array($payload) ? (string) ($payload['access_token'] ?? '') : '';
        if ($token === '') {
            throw new \RuntimeException('MilestoneClient: IDP returned no access_token');
        }

        // Trust the spec's nominal 2h lifetime but refresh proactively at 90m
        // so two parallel workers won't both hit a just-expired token.
        $this->token       = $token;
        $this->tokenExpiry = time() + self::TTL_TOKEN;
        $this->writeCachedToken($token, $this->tokenExpiry);
    }

    /**
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function raw(string $method, string $path, ?array $body, array $extraHeaders): array {
        $url = $this->base . $this->apiBase . $path;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('MilestoneClient: curl_init failed');
        }

        $headers = array_merge([
            'Accept: application/json',
            'Content-Type: application/json',
        ], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONNECT,
            CURLOPT_TIMEOUT        => self::TIMEOUT_TOTAL,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $this->callCount++;

        if ($raw === false) {
            throw new \RuntimeException("MilestoneClient: transport error: {$err}");
        }

        $decoded = json_decode((string) $raw, true);
        return [$code, is_array($decoded) ? $decoded : []];
    }

    /* ====================================================================== */
    /* Response caching                                                       */
    /* ====================================================================== */

    /**
     * cached(key, ttl, fn): APCu hit-or-compute. Filesystem fallback so the
     * dashboard's 30s poll across multiple PHP workers shares results.
     *
     * @template T
     * @param  callable():T $compute
     * @return T
     */
    private function cached(string $key, int $ttl, callable $compute) {
        $ck = self::CACHE_PREFIX . sha1($this->base . '|' . $this->user . '|' . $key);

        if (function_exists('apcu_fetch')) {
            $ok = false;
            /** @var mixed $hit */
            $hit = apcu_fetch($ck, $ok);
            if ($ok) return $hit;
        }

        $fsPath = self::CACHE_DIR . '/' . $ck;
        if (is_file($fsPath)) {
            $raw = @file_get_contents($fsPath);
            if ($raw !== false) {
                $row = json_decode($raw, true);
                if (is_array($row) && (int) ($row['expires'] ?? 0) > time()) {
                    return $row['value'] ?? null;
                }
            }
        }

        $value = $compute();

        if (function_exists('apcu_store')) {
            apcu_store($ck, $value, max(1, $ttl));
        } else {
            if (!is_dir(self::CACHE_DIR)) {
                @mkdir(self::CACHE_DIR, 0700, true);
            }
            @file_put_contents(
                $fsPath,
                json_encode(['expires' => time() + $ttl, 'value' => $value],
                            JSON_UNESCAPED_SLASHES)
            );
        }
        return $value;
    }

    /* ====================================================================== */
    /* Token caching: APCu when available, /tmp fallback otherwise            */
    /* ====================================================================== */

    private function tokenCacheKey(): string {
        return self::CACHE_PREFIX . 'token::' . sha1($this->base . '|' . $this->user);
    }

    /** @return array{token:string, expires:int}|null */
    private function readCachedToken(): ?array {
        $key = $this->tokenCacheKey();

        if (function_exists('apcu_fetch')) {
            $ok = false;
            /** @var mixed $hit */
            $hit = apcu_fetch($key, $ok);
            if ($ok && is_array($hit) && isset($hit['token'], $hit['expires'])
                && (int) $hit['expires'] > time()) {
                return ['token' => (string) $hit['token'], 'expires' => (int) $hit['expires']];
            }
        }

        $path = self::CACHE_DIR . '/' . $key;
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $row = json_decode($raw, true);
        if (!is_array($row) || !isset($row['token'], $row['expires'])) return null;
        if ((int) $row['expires'] <= time()) return null;
        return ['token' => (string) $row['token'], 'expires' => (int) $row['expires']];
    }

    private function writeCachedToken(string $token, int $expires): void {
        $key = $this->tokenCacheKey();
        $payload = ['token' => $token, 'expires' => $expires];

        if (function_exists('apcu_store')) {
            apcu_store($key, $payload, max(1, $expires - time()));
            return;
        }

        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0700, true);
        }
        @file_put_contents(
            self::CACHE_DIR . '/' . $key,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
    }

    private function forgetCachedToken(): void {
        $key = $this->tokenCacheKey();
        if (function_exists('apcu_delete')) {
            apcu_delete($key);
        }
        $path = self::CACHE_DIR . '/' . $key;
        if (is_file($path)) @unlink($path);
    }
}
