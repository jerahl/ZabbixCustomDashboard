<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.fortigate.view
 *
 * Renders the FortiGate firewall dashboard from the mock data in
 * fortigate-data.jsx. To wire to live data, poll the FortiGate via SNMP
 * (FORTINET-FORTIGATE-MIB) and/or the REST API and pass the payload as
 * $data['boot'].
 */
class ActionFortigate extends ActionBase {

    protected function checkInput(): bool {
        $ret = $this->validateInput([]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $response = new CControllerResponseData([
            'title' => _('TCS FortiGate')
        ]);
        $response->setTitle(_('TCS FortiGate'));
        $this->setResponse($response);
    }
}
