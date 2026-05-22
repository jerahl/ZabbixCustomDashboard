<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.surveillance.view
 *
 * Renders the Milestone XProtect surveillance NOC view. Server-collected
 * boot data comes from ActionSurveillanceData::collect() and is embedded
 * as window.SURVEILLANCE_BOOT by surveillance.view.php; the on-page
 * surveillance-bridge.jsx then normalises it into the window.MILESTONE /
 * SITES / SERVERS / CAMERAS / VMS_ALARMS globals that nvr-overview.jsx
 * consumes. Fields not yet templated (storage TB, Smart Client sessions,
 * camera bitrate / FPS, …) fall through to the mock baseline in
 * nvr-data.jsx so the UI keeps rendering while the backend grows.
 */
class ActionSurveillance extends ActionBase {

    protected function checkInput(): bool {
        $fields = [
            'view'   => 'string',  // 'overview' (default), 'cameras', 'servers'
            'hostid' => 'string'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $ret;
    }

    protected function doAction(): void {
        $hostid = $this->getInput('hostid', '');
        $boot   = (new ActionSurveillanceData())->collect($hostid);

        $data = [
            'title'  => _('TCS Surveillance NOC'),
            'view'   => $this->getInput('view', 'overview'),
            'hostid' => $hostid,
            'boot'   => $boot
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('TCS Surveillance NOC'));
        $this->setResponse($response);
    }
}
