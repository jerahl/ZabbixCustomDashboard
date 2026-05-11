<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CController;
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
class ActionCamera extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

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

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
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
