<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Lib;

/**
 * Thin PacketFence v15 REST client.
 *
 * Slot for the verbatim 984-line lift of
 * jerahl/ZabbixExtremeIQ/apdetail/includes/PFClient.php (see
 * notes/lift-manifest.md §C). Until that lift lands, this stub exposes the
 * same constructor + the small subset of methods consumed by
 * ActionDashboard::collectPacketFence() — recent clients on a node, and
 * recent auth failures — so the dashboard pipeline can be wired end-to-end
 * without source-repo access during M0.
 *
 * Replacement plan: when the apdetail PFClient.php is lifted, drop this file
 * and keep the same public surface (`fromMacros()`, `clientsForNode()`,
 * `authFailuresForNode()`); call-sites in ActionDashboard won't change.
 *
 * Auth: token endpoint (`/api/v1/login`) returns a bearer used on subsequent
 * `/api/v1/...` calls. Tokens are cached on disk for the lifetime of the
 * PHP request only — no APCu dependency.
 */
class PFClient {

    private string $url;
    private string $user;
    private string $pass;
    private bool   $verifySsl;
    private ?string $token = null;

    private const TIMEOUT_CONNECT = 10;
    private const TIMEOUT_TOTAL   = 30;
    private const UA              = 'TcsDashboard/1.0 (+PFClient)';

    public function __construct(
        string $url,
        string $user,
        #[\SensitiveParameter] string $pass,
        bool $verifySsl = true
    ) {
        $this->url       = rtrim($url, '/');
        $this->user      = $user;
        $this->pass      = $pass;
        $this->verifySsl = $verifySsl;
    }

    /**
     * Convenience constructor from a resolved macro bag — mirrors the shape
     * Config::pf() will return once lib/Config.php lands (integration-plan §4).
     *
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

    /**
     * Recent clients associated to a switch / AP, keyed by MAC. Shape matches
     * the `pfClients` row the React tabs pane expects
     * (assets/tabs.jsx → window.PF_CLIENTS).
     *
     * @return array<int, array<string, mixed>>
     */
    public function clientsForNode(string $deviceId, int $limit = 200): array {
        $rows = $this->get('/api/v1/nodes', [
            'fields'         => 'mac,computername,ip4log.ip,last_seen,status,category_id,locationlog.switch,locationlog.port',
            'limit'          => $limit,
            'sort'           => 'last_seen DESC',
            'locationlog.switch' => $deviceId
        ]);

        $items = $rows['items'] ?? [];
        $out = [];
        foreach ($items as $r) {
            $out[] = [
                'mac'      => (string) ($r['mac'] ?? ''),
                'name'     => (string) ($r['computername'] ?? ''),
                'ip'       => (string) ($r['ip4log.ip'] ?? ''),
                'port'     => (string) ($r['locationlog.port'] ?? ''),
                'status'   => (string) ($r['status'] ?? ''),
                'lastSeen' => (string) ($r['last_seen'] ?? '')
            ];
        }
        return $out;
    }

    /**
     * Recent 802.1X / auth failures for a device, newest-first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function authFailuresForNode(string $deviceId, int $limit = 50): array {
        $rows = $this->get('/api/v1/radius_audit_logs', [
            'fields'    => 'mac,user_name,switch,port,auth_status,reason,created_at',
            'limit'     => $limit,
            'sort'      => 'created_at DESC',
            'switch'    => $deviceId,
            'auth_status' => 'reject'
        ]);

        $items = $rows['items'] ?? [];
        $out = [];
        foreach ($items as $r) {
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

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = []): array {
        $this->ensureToken();
        $url = $this->url . $path;
        if ($query) {
            $url .= (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->request('GET', $url, null, [
            'Authorization: Bearer ' . ($this->token ?? '')
        ]);
    }

    private function ensureToken(): void {
        if ($this->token !== null) return;
        $resp = $this->request('POST', $this->url . '/api/v1/login', [
            'username' => $this->user,
            'password' => $this->pass
        ]);
        $this->token = (string) ($resp['token'] ?? '');
        if ($this->token === '') {
            throw new \RuntimeException('PFClient: login returned no token');
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<int, string> $extraHeaders
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $body = null, array $extraHeaders = []): array {
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
        if ($code >= 400) {
            throw new \RuntimeException("PFClient: HTTP $code for $method $url");
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
