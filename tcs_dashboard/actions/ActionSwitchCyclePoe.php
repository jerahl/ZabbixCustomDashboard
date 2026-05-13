<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CWebUser;
use Modules\TcsDashboard\Lib\RConfigClient;

/**
 * POST zabbix.php?action=tcs.switch.cyclepoe
 *
 * Operator-triggered PoE-cycle for a switch port. Lifted from
 * jerahl/ZabbixSwitchPortWidgets/portdetail/actions/CyclePoe.php and adapted
 * to this module's action-controller pattern.
 *
 * Request (JSON or form-encoded):
 *   hostid   — required, Zabbix host id of the switch
 *   port     — required, port number (1..N) — passed to the rConfig snippet
 *              as the `port` variable
 *   member   — optional, stack-member index (1..8). Defaults to 1.
 *
 * Macros consumed (resolved on the host):
 *   {$RCONFIG.URL}            — base URL, https only
 *   {$RCONFIG.TOKEN}          — bearer token (Secret text recommended)
 *   {$RCONFIG.POE_SNIPPET_ID} — integer id of the stored "cycle PoE" snippet
 *   {$RCONFIG.DEVICE_ID}      — optional integer; pins device lookup
 *
 * Response envelope:
 *   { ok: true,  message: "...", http_status: 200 }
 *   { ok: false, error: "..."[, http_status: N] }
 *
 * Permissions: ZABBIX_ADMIN minimum. CSRF is disabled because the endpoint
 * is invoked from same-origin fetch() with Content-Type: application/json
 * (the harness rejects cross-site form submissions and JSON bodies require
 * a preflight under CORS, so form-style CSRF doesn't apply). Authentication
 * still goes through CWebUser::isLoggedIn() + the admin gate below.
 */
class ActionSwitchCyclePoe extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'hostid' => 'required|string',
            'port'   => 'required|int32',
            'member' => 'int32'
        ]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function checkPermissions(): bool {
        if (!CWebUser::isLoggedIn()) {
            http_response_code(401);
            $this->respond(['ok' => false, 'error' => 'unauthenticated']);
            return false;
        }
        if ($this->getUserType() < USER_TYPE_ZABBIX_ADMIN) {
            http_response_code(403);
            $this->respond(['ok' => false, 'error' => 'admin required']);
            return false;
        }
        return true;
    }

    protected function doAction(): void {
        $hostid = (string) $this->getInput('hostid', '');
        $port   = (int) $this->getInput('port', 0);
        $member = (int) $this->getInput('member', 1);

        if ($hostid === '' || $port <= 0) {
            $this->respond(['ok' => false, 'error' => 'hostid and port are required']);
            return;
        }

        $host = $this->loadHost($hostid);
        if ($host === null) {
            $this->respond(['ok' => false, 'error' => 'unknown hostid']);
            return;
        }

        $macros = $this->resolveRConfigMacros($hostid);
        if ($macros === null) {
            $this->respond(['ok' => false, 'error' => 'rConfig macros not configured on host']);
            return;
        }

        try {
            $client = new RConfigClient($macros['url'], $macros['token'], $macros['verify_ssl']);
            $deviceId = $client->resolveDeviceId(
                $hostid,
                (string) ($host['host'] ?? ''),
                $host['ip'] ?? null,
                $macros['device_id']
            );
            $result = $client->deploySnippet($deviceId, $macros['snippet_id'], [
                'port'   => $port,
                'member' => $member
            ]);

            $this->respond([
                'ok'          => (bool) $result['ok'],
                'message'     => (string) $result['message'],
                'http_status' => (int) $result['http_status']
            ]);
        }
        catch (\Throwable $e) {
            error_log('[tcs_dashboard] cyclepoe: '.$e->getMessage());
            $this->respond(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Walk the full template ancestry of a host (direct parents + their
     * parents, etc.) so macro lookups catch inheritance through nested
     * templates. Returns a deduped list of template ids.
     *
     * @return array<int, string>
     */
    private function collectTemplateAncestry(string $hostid): array {
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

    /** @return array{host:string, ip:?string}|null */
    private function loadHost(string $hostid): ?array {
        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host'],
            'selectInterfaces' => ['ip', 'main'],
            'hostids'          => [$hostid]
        ]);
        if (!$hosts) return null;

        $h  = $hosts[0];
        $ip = null;
        foreach ($h['interfaces'] ?? [] as $iface) {
            if ((int) ($iface['main'] ?? 0) === 1) {
                $ip = $iface['ip'];
                break;
            }
        }
        return ['host' => (string) $h['host'], 'ip' => $ip];
    }

    /**
     * Resolve {$RCONFIG.*} macros through the host → linked templates →
     * global chain. Host-level wins, then template-inherited, then
     * globals — matching how Zabbix itself resolves macros at runtime.
     *
     * Secret macros come through fine via output=['macro','value'] as
     * long as the requesting user has read access (admin does).
     *
     * @return array{url:string, token:string, verify_ssl:bool, snippet_id:int, device_id:?int}|null
     */
    private function resolveRConfigMacros(string $hostid): ?array {
        $names = [
            '{$RCONFIG.URL}',
            '{$RCONFIG.TOKEN}',
            '{$RCONFIG.POE_SNIPPET_ID}',
            '{$RCONFIG.DEVICE_ID}',
            '{$RCONFIG.VERIFY.SSL}'
        ];
        $bag = [];

        // 1. Global macros (lowest precedence).
        $globals = API::UserMacro()->get([
            'output'      => ['macro', 'value'],
            'globalmacro' => true,
            'filter'      => ['macro' => $names]
        ]) ?: [];
        foreach ($globals as $r) $bag[$r['macro']] = (string) $r['value'];

        // 2. Template-inherited macros — walk the full ancestry, not just
        // the direct parents. selectParentTemplates is one hop only, so a
        // macro on a base template that a mid-tier template inherits from
        // gets missed without recursion.
        $templateIds = $this->collectTemplateAncestry($hostid);
        if ($templateIds) {
            $tpl = API::UserMacro()->get([
                'output'  => ['macro', 'value'],
                'hostids' => $templateIds,
                'filter'  => ['macro' => $names]
            ]) ?: [];
            foreach ($tpl as $r) $bag[$r['macro']] = (string) $r['value'];
        }

        // 3. Host-level (highest precedence).
        $hostRows = API::UserMacro()->get([
            'output'  => ['macro', 'value'],
            'hostids' => [$hostid],
            'filter'  => ['macro' => $names]
        ]) ?: [];
        foreach ($hostRows as $r) $bag[$r['macro']] = (string) $r['value'];

        $url     = $bag['{$RCONFIG.URL}'] ?? '';
        $token   = $bag['{$RCONFIG.TOKEN}'] ?? '';
        $snippet = (int) ($bag['{$RCONFIG.POE_SNIPPET_ID}'] ?? 0);
        if ($url === '' || $token === '' || $snippet <= 0) {
            error_log(sprintf(
                '[tcs_dashboard] cyclepoe: macros missing — url=%s token=%s snippet=%s (host %s + %d template(s) + globals)',
                $url !== '' ? 'set' : 'EMPTY',
                $token !== '' ? 'set' : 'EMPTY',
                $snippet > 0 ? (string) $snippet : 'EMPTY',
                $hostid, count($templateIds)
            ));
            return null;
        }

        $deviceMacro = isset($bag['{$RCONFIG.DEVICE_ID}']) && $bag['{$RCONFIG.DEVICE_ID}'] !== ''
            ? (int) $bag['{$RCONFIG.DEVICE_ID}']
            : null;

        return [
            'url'        => $url,
            'token'      => $token,
            'verify_ssl' => ($bag['{$RCONFIG.VERIFY.SSL}'] ?? '1') !== '0',
            'snippet_id' => $snippet,
            'device_id'  => $deviceMacro
        ];
    }

    private function respond(array $payload): void {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload);
        $this->setResponse(new CControllerResponseData(['main_block' => '']));
        exit;
    }
}
