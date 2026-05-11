<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.global.view
 *
 * Renders the unified Global Dashboard (severity strip, 24h trigger trend,
 * site-health heatmap, problems-by-domain, active triggers, top hotspots,
 * recent events). Currently powered by the synthetic data baked into
 * global-data.jsx. To wire to real data, build a global-bridge.jsx that
 * adapts a server snapshot into window.GLOBAL_KPIS / window.GLOBAL_SITES /
 * window.GLOBAL_TRIGGERS, parallel to data-bridge.jsx for AP Detail.
 */
class ActionGlobal extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [];

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
            'title' => _('TCS Global Dashboard')
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('TCS Global Dashboard'));
        $this->setResponse($response);
    }
}
