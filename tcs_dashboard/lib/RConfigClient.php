<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Lib;

/**
 * rConfig 7+ API client — currently only the surface needed to deploy a
 * stored snippet against a managed device (PoE-cycle).
 *
 * Lifted from notes/lift-manifest.md §C: corresponds to the documented
 * signature of jerahl/ZabbixSwitchPortWidgets/portdetail/actions/CyclePoe.php.
 * Methods:
 *   - __construct(string $url, #[SensitiveParameter] string $token, bool $verifySsl)
 *   - resolveDeviceId(string $hostId, string $hostName, ?string $hostIp, ?int $deviceIdMacro): int
 *   - deploySnippet(int $deviceId, int $snippetId, array $vars): array
 *
 * Constraints:
 *   - HTTPS only — the constructor rejects http:// URLs
 *   - TLS verification on by default
 *   - 15s connect / 30s total timeouts
 *   - Returns {ok, message, http_status} from deploySnippet()
 */
class RConfigClient {

    private string $url;
    private string $token;
    private bool   $verifySsl;

    private const TIMEOUT_CONNECT = 15;
    private const TIMEOUT_TOTAL   = 30;
    private const UA              = 'TcsDashboard/1.0 (+RConfigClient)';

    public function __construct(
        string $url,
        #[\SensitiveParameter] string $token,
        bool $verifySsl = true
    ) {
        $url = rtrim($url, '/');
        if (!preg_match('#^https://#i', $url)) {
            throw new \InvalidArgumentException('RConfigClient: URL must be https://');
        }
        if ($token === '') {
            throw new \InvalidArgumentException('RConfigClient: token is required');
        }

        $this->url       = $url;
        $this->token     = $token;
        $this->verifySsl = $verifySsl;
    }

    /**
     * Resolve a Zabbix host to its rConfig device id.
     *
     * Strategy, in order:
     *   1. If the operator pinned the id via the {$RCONFIG.DEVICE_ID} macro
     *      we trust it verbatim.
     *   2. Otherwise look up the device by hostname.
     *   3. Otherwise fall back to IP.
     *
     * @throws \RuntimeException when nothing resolves.
     */
    public function resolveDeviceId(
        string $hostId,
        string $hostName,
        ?string $hostIp,
        ?int $deviceIdMacro
    ): int {
        if ($deviceIdMacro !== null && $deviceIdMacro > 0) {
            return $deviceIdMacro;
        }

        if ($hostName !== '') {
            $id = $this->findDeviceIdBy('deviceName', $hostName);
            if ($id !== null) return $id;
        }

        if ($hostIp !== null && $hostIp !== '') {
            $id = $this->findDeviceIdBy('ipAddress', $hostIp);
            if ($id !== null) return $id;
        }

        throw new \RuntimeException(
            "RConfigClient: no device matches host '$hostName' (id $hostId, ip ".($hostIp ?? '—').')'
        );
    }

    /**
     * Deploy a stored snippet against a device with optional variable
     * substitution.
     *
     * @param array<string, scalar> $vars
     * @return array{ok:bool, message:string, http_status:int}
     */
    public function deploySnippet(int $deviceId, int $snippetId, array $vars): array {
        if ($deviceId <= 0) {
            return ['ok' => false, 'message' => 'invalid device id', 'http_status' => 0];
        }
        if ($snippetId <= 0) {
            return ['ok' => false, 'message' => 'invalid snippet id', 'http_status' => 0];
        }

        [$status, $payload] = $this->raw('POST', '/api/v1/snippets/'.$snippetId.'/deploy', [], [
            'deviceId'  => $deviceId,
            'variables' => $vars
        ]);

        $ok = $status >= 200 && $status < 300 && (($payload['status'] ?? '') !== 'error');
        $msg = (string) ($payload['message'] ?? ($ok ? 'queued' : "rConfig HTTP $status"));

        return [
            'ok'          => $ok,
            'message'     => $msg,
            'http_status' => $status
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    private function findDeviceIdBy(string $field, string $value): ?int {
        [$status, $payload] = $this->raw('GET', '/api/v1/devices', [
            $field  => $value,
            'limit' => 1
        ], null);

        if ($status >= 400) return null;
        $items = $payload['items'] ?? $payload['data'] ?? [];
        $first = $items[0] ?? null;
        if (!is_array($first)) return null;
        $id = (int) ($first['id'] ?? $first['deviceId'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function raw(string $method, string $path, array $query, ?array $body): array {
        $url = $this->url . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('RConfigClient: curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer '.$this->token
            ],
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONNECT,
            CURLOPT_TIMEOUT        => self::TIMEOUT_TOTAL,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("RConfigClient: transport error: $err");
        }

        $decoded = json_decode((string) $raw, true);
        return [$code, is_array($decoded) ? $decoded : []];
    }
}
