<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.zbx.status.view
 *
 * Renders the Zabbix server + proxy health view from mock data in
 * zbx-status-data.jsx. To wire live data, replace the data module with a
 * payload built from Zabbix internal items (zabbix[*]), proxy.get, host.get,
 * and the Zabbix API's queue.get.
 */
class ActionZbxStatus extends ActionBase {

    protected function checkInput(): bool {
        $ret = $this->validateInput([]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $response = new CControllerResponseData([
            'title' => _('TCS Zabbix Server Status')
        ]);
        $response->setTitle(_('TCS Zabbix Server Status'));
        $this->setResponse($response);
    }
}
