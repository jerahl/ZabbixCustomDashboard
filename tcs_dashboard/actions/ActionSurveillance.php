<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.surveillance.view
 *
 * Currently renders the Milestone XProtect surveillance NOC view from the
 * mock data baked into nvr-data.jsx. To wire it to real data, do one of:
 *
 *   - Hit XProtect's REST API (Management Server /api/rest/v1/) and pass the
 *     payload to the view as $data['boot'], parallel to ActionDashboard. Then
 *     write a surveillance bridge (similar to data-bridge.jsx) that adapts
 *     window.SURVEILLANCE_BOOT into the window globals nvr-data.jsx defines.
 *   - Or pull camera-up/down state from Zabbix items templated against your
 *     XProtect recording servers, if you've SNMP-monitored them.
 */
class ActionSurveillance extends ActionBase {

    protected function checkInput(): bool {
        $fields = [
            'view' => 'string'  // 'overview' (default), 'cameras', 'servers'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $ret;
    }

    protected function doAction(): void {
        $data = [
            'title' => _('TCS Surveillance NOC'),
            'view'  => $this->getInput('view', 'overview'),
            // No server-collected boot data yet — view loads nvr-data.jsx
            // which self-populates window.MILESTONE / window.SITES with mock
            // values.
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('TCS Surveillance NOC'));
        $this->setResponse($response);
    }
}
