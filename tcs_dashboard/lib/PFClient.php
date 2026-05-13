<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Lib;

/**
 * PacketFence v15 REST client.
 *
 * Modeled on the apdetail/includes/PFClient.php surface documented in
 * notes/lift-manifest.md §C — same constructor signature, same factory
 * (fromMacros), same call-sites in ActionDashboard. When the verbatim
 * 984-line lift lands, drop this file and keep the same public methods.
 *
 * Features:
 *   - Token login + auto-refresh on 401 (retries once)
 *   - APCu cache for the bearer token with a filesystem fallback so the
 *     same token is shared across PHP requests (configurable TTL)
 *   - Per-call connect/total timeouts and explicit User-Agent
 *   - TLS verification on by default; opt-out via constructor flag
 *   - Helpers for the dashboard surfaces consumed by the React tabs pane:
 *     nodes, locationlog, radius audit, fingerbank category lookup
 *
 * PHP 8.0 carry-forward: include array_is_list polyfill (AP G21) so the
 * file is drop-in compatible with the apdetail lift target.
 */
class PFClient {

    private string $url;
    private string $user;
    private string $pass;
    private bool   $verifySsl;

    private ?string $token = null;
    private int     $tokenExpiry = 0;

    /** Seconds. Tokens are re-used until either expiry or a 401 forces refresh. */
    private const TOKEN_TTL_DEFAULT = 1800;

    private const TIMEOUT_CONNECT = 10;
    private const TIMEOUT_TOTAL   = 30;
    private const UA              = 'TcsDashboard/1.0 (+PFClient)';

    /** Cache namespace for APCu / filesystem fallback. */
    private const CACHE_PREFIX = 'tcs_pf_token::';
    private const CACHE_DIR    = '/tmp/tcs_dashboard_cache';

    public function __construct(
        string $url,
        string $user,
        #[\SensitiveParameter] string $pass,
        bool $verifySsl = true
    ) {
        self::ensureArrayIsListPolyfill();

        $this->url       = rtrim($url, '/');
        $this->user      = $user;
        $this->pass      = $pass;
        $this->verifySsl = $verifySsl;
    }

    /**
     * @param array{url:string,user:string,pass:string,verify_ssl?:bool} $cfg
     */
    public static function fromMacros(array $cfg): self {
        return new self(
            (string) ($cfg['url']  ?? ''),
            (string) ($cfg['user'] ?? ''),
            (string) ($cfg['pass'] ?? ''),
            (bool)   ($cfg['verify_ssl'] ?? true)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Public surface — what ActionDashboard / the tabs UI consumes       */
    /* ------------------------------------------------------------------ */

    /**
     * Recent clients on a switch / AP. Result shape matches the rows
     * window.PF_CLIENTS expects (assets/tabs.jsx).
     *
     * @return array<int, array<string, mixed>>
     */
    public function clientsForNode(string $deviceId, int $limit = 200): array {
        $rows = $this->get('/api/v1/nodes', [
            'fields' => 'mac,computername,ip4log.ip,last_seen,status,category_id,'
                       .'locationlog.switch,locationlog.port,locationlog.ssid',
            'limit'  => $limit,
            'sort'   => 'last_seen DESC',
            'locationlog.switch' => $deviceId
        ]);

        $out = [];
        foreach (($rows['items'] ?? []) as $r) {
            $out[] = [
                'mac'      => (string) ($r['mac'] ?? ''),
                'name'     => (string) ($r['computername'] ?? ''),
                'ip'       => (string) ($r['ip4log.ip'] ?? ''),
                'port'     => (string) ($r['locationlog.port'] ?? ''),
                'ssid'     => (string) ($r['locationlog.ssid'] ?? ''),
                'status'   => (string) ($r['status'] ?? ''),
                'category' => (string) ($r['category_id'] ?? ''),
                'lastSeen' => (string) ($r['last_seen'] ?? '')
            ];
        }
        return $out;
    }

    /**
     * Recent 802.1X auth failures (radius_audit_logs filtered to reject).
     *
     * @return array<int, array<string, mixed>>
     */
    public function authFailuresForNode(string $deviceId, int $limit = 50): array {
        $rows = $this->get('/api/v1/radius_audit_logs', [
            'fields'      => 'mac,user_name,switch,port,auth_status,reason,created_at',
            'limit'       => $limit,
            'sort'        => 'created_at DESC',
            'switch'      => $deviceId,
            'auth_status' => 'reject'
        ]);

        $out = [];
        foreach (($rows['items'] ?? []) as $r) {
            $out[] = [
                'mac'    => (string) ($r['mac'] ?? ''),
                'user'   => (string) ($r['user_name'] ?? ''),
                'port'   => (string) ($r['port'] ?? ''),
                'reason' => (string) ($r['reason'] ?? ''),
                'ts'     => (string) ($r['created_at'] ?? '')
            ];
        }
        return $out;
    }

    /**
     * Lookup a single node by MAC. Returns null when the node isn't known.
     *
     * @return array<string, mixed>|null
     */
    public function node(string $mac): ?array {
        $rows = $this->get('/api/v1/nodes/'.rawurlencode($mac), []);
        $item = $rows['item'] ?? $rows;
        return is_array($item) && !empty($item) ? $item : null;
    }

    /**
     * Current location for a MAC (which switch + port it's seen on).
     *
     * @return array<string, mixed>|null
     */
    public function locationFor(string $mac): ?array {
        $rows = $this->get('/api/v1/locationlogs', [
            'mac'   => $mac,
            'limit' => 1,
            'sort'  => 'start_time DESC'
        ]);
        $items = $rows['items'] ?? [];
        return $items ? $items[0] : null;
    }

    /**
     * Fingerbank device-class label for a MAC, if PF has fingerprinted it.
     */
    public function fingerbankCategory(string $mac): ?string {
        try {
            $row = $this->get('/api/v1/fingerbank/local/device/'.rawurlencode($mac), []);
            $name = $row['item']['name'] ?? ($row['name'] ?? null);
            return is_string($name) && $name !== '' ? $name : null;
        }
        catch (\Throwable) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* HTTP plumbing                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array {
        return $this->call('GET', $path, $query, null);
    }

    /**
     * Run the call. Refreshes the token once on 401 and retries.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $query, ?array $body): array {
        $this->ensureToken();

        [$status, $payload] = $this->raw($method, $path, $query, $body, [
            'Authorization: Bearer '.($this->token ?? '')
        ]);

        if ($status === 401) {
            // Token may have been revoked / expired server-side. Force one refresh.
            $this->token = null;
            $this->tokenExpiry = 0;
            $this->forgetCachedToken();
            $this->ensureToken();

            [$status, $payload] = $this->raw($method, $path, $query, $body, [
                'Authorization: Bearer '.($this->token ?? '')
            ]);
        }

        if ($status >= 400) {
            throw new \RuntimeException("PFClient: HTTP $status for $method $path");
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

        [$status, $payload] = $this->raw('POST', '/api/v1/login', [], [
            'username' => $this->user,
            'password' => $this->pass
        ], []);

        if ($status >= 400) {
            throw new \RuntimeException("PFClient: login failed (HTTP $status)");
        }

        $token = (string) ($payload['token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('PFClient: login returned no token');
        }

        $this->token       = $token;
        $this->tokenExpiry = time() + self::TOKEN_TTL_DEFAULT;
        $this->writeCachedToken($token, $this->tokenExpiry);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @param array<int, string> $extraHeaders
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function raw(string $method, string $path, array $query, ?array $body, array $extraHeaders): array {
        $url = $this->url . $path;
        if ($query) {
            $url .= (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('PFClient: curl_init failed');
        }

        $headers = array_merge([
            'Accept: application/json',
            'Content-Type: application/json'
        ], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONNECT,
            CURLOPT_TIMEOUT        => self::TIMEOUT_TOTAL,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("PFClient: transport error: $err");
        }

        $decoded = json_decode((string) $raw, true);
        return [$code, is_array($decoded) ? $decoded : []];
    }

    /* ------------------------------------------------------------------ */
    /* Token caching: APCu when available, /tmp fallback otherwise        */
    /* ------------------------------------------------------------------ */

    private function cacheKey(): string {
        return self::CACHE_PREFIX . sha1($this->url.'|'.$this->user);
    }

    /** @return array{token:string, expires:int}|null */
    private function readCachedToken(): ?array {
        $key = $this->cacheKey();

        if (function_exists('apcu_fetch')) {
            $ok = false;
            /** @var mixed $hit */
            $hit = apcu_fetch($key, $ok);
            if ($ok && is_array($hit) && isset($hit['token'], $hit['expires']) && $hit['expires'] > time()) {
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
        $key = $this->cacheKey();
        $payload = ['token' => $token, 'expires' => $expires];

        if (function_exists('apcu_store')) {
            apcu_store($key, $payload, max(1, $expires - time()));
            return;
        }

        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0700, true);
        }
        @file_put_contents(self::CACHE_DIR . '/' . $key, json_encode($payload));
        @chmod(self::CACHE_DIR . '/' . $key, 0600);
    }

    private function forgetCachedToken(): void {
        $key = $this->cacheKey();
        if (function_exists('apcu_delete')) {
            apcu_delete($key);
        }
        @unlink(self::CACHE_DIR . '/' . $key);
    }

    /* ------------------------------------------------------------------ */
    /* PHP 8.0 polyfill (AP G21 — Zabbix ships PHP 8.0)                   */
    /* ------------------------------------------------------------------ */

    private static function ensureArrayIsListPolyfill(): void {
        if (function_exists('array_is_list')) return;
        eval('function array_is_list(array $a): bool {
            if ($a === []) return true;
            $i = 0;
            foreach ($a as $k => $_) { if ($k !== $i++) return false; }
            return true;
        }');
    }
}
