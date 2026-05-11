<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.servers.view
 *
 * Renders the Servers fleet dashboard (host navigator, fleet tile grid,
 * KPI strip, sidecar, tabs). Currently powered by mock data in
 * servers-data.jsx. To wire to real data:
 *
 *   - Use the host.get / item.get pattern from ActionDashboard against your
 *     server templates (Linux by Zabbix agent, Windows by Zabbix agent,
 *     Template OS Linux SNMP, etc.).
 *   - Build a servers-bridge.jsx parallel to data-bridge.jsx that adapts the
 *     server payload into window.SRV_SITES / window.SRV_HOST / window.SRV_ITEMS.
 */
class ActionServers extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'hostid' => 'string'
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
            'title'  => _('TCS Servers'),
            'hostid' => $this->getInput('hostid', '')
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('TCS Servers'));
        $this->setResponse($response);
    }
}
