<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.camera.view&id=<cameraId>
 *
 * Renders the Milestone XProtect camera deep-dive (live preview tile, stream
 * health rings, 24h sparklines, stream/recording config, network identity,
 * recent events). Currently powered by mock data from nvr-data.jsx. To wire
 * real data, hit Milestone XProtect's REST API for camera and stream state
 * and pass the snapshot to the view as $data['boot']; mirror the data-bridge
 * pattern from AP Detail.
 */
class ActionCamera extends ActionBase {

    protected function checkInput(): bool {
        $fields = [
            'id' => 'string'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $ret;
    }

    protected function doAction(): void {
        $data = [
            'title' => _('TCS Camera Detail'),
            'id'    => $this->getInput('id', '')
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('TCS Camera Detail'));
        $this->setResponse($response);
    }
}
