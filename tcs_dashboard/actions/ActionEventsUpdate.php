<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;

/**
 * POST zabbix.php?action=tcs.events.update
 *
 * Thin wrapper around API::Event()->acknowledge() so the Events Console can
 * acknowledge, suppress, change severity, add messages, or close events
 * without leaving the page. Returns a small JSON envelope:
 *
 *   { ok: true,  eventids: [...] }
 *   { ok: false, error: "..." }
 *
 * Request body (form-encoded or JSON):
 *   eventids[]       — at least one
 *   action           — bitmask (matches Zabbix ZBX_PROBLEM_UPDATE_* flags):
 *                      1=close, 2=ack, 4=message, 8=severity,
 *                      16=unack, 32=suppress, 64=unsuppress
 *   message          — optional, used when bit 4 is set
 *   severity         — optional 0-5, used when bit 8 is set
 *   suppress_until   — optional unix timestamp, used when bit 32 is set
 */
class ActionEventsUpdate extends ActionDataBase {

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'eventids'       => 'array',
            'action'         => 'int32',
            'message'        => 'string',
            'severity'       => 'int32',
            'suppress_until' => 'int32'
        ]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $eventids = $this->getInput('eventids', []);
        $eventids = array_values(array_filter(array_map('strval', (array) $eventids), fn($v) => $v !== ''));
        $action   = (int) $this->getInput('action', 0);

        if (!$eventids) {
            $this->respond(['ok' => false, 'error' => 'no eventids']);
            return;
        }
        if ($action <= 0) {
            $this->respond(['ok' => false, 'error' => 'no action']);
            return;
        }

        $params = [
            'eventids' => count($eventids) === 1 ? $eventids[0] : $eventids,
            'action'   => $action
        ];

        if ($action & 4) {
            $msg = trim((string) $this->getInput('message', ''));
            if ($msg === '') {
                $this->respond(['ok' => false, 'error' => 'message required']);
                return;
            }
            $params['message'] = $msg;
        }
        if ($action & 8) {
            $sev = (int) $this->getInput('severity', -1);
            if ($sev < 0 || $sev > 5) {
                $this->respond(['ok' => false, 'error' => 'severity must be 0-5']);
                return;
            }
            $params['severity'] = $sev;
        }
        if ($action & 32) {
            $until = (int) $this->getInput('suppress_until', 0);
            if ($until <= time()) {
                $until = time() + 3600;
            }
            $params['suppress_until'] = $until;
        }

        try {
            API::Event()->acknowledge($params);
            $this->respond(['ok' => true, 'eventids' => $eventids]);
        } catch (\Throwable $e) {
            error_log('[tcs] events update failed: '.$e->getMessage());
            $this->respond(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function respond(array $payload): void {
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload)
        ]));
    }
}
