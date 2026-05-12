<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.switches.view
 *
 * Currently renders the switch port-status view from the mock data in
 * switches-data.jsx. To wire to real data:
 *
 *   - Use the host.get / item.get pattern from ActionDashboard against your
 *     switch templates. Item keys for port state are typically
 *     ifOperStatus[<index>] from the Generic SNMP / Net.if.SNMP templates.
 *   - PoE state usually comes from POWER-ETHERNET-MIB (pethPsePortDetectionStatus)
 *     — Zabbix's "Generic SNMPv3 PoE" template has this discovered.
 *   - Build a bridge similar to data-bridge.jsx that adapts the server payload
 *     into window.SWITCH_SITES / window.ARC_MDF_STACK / window.makePortDetail.
 */
class ActionSwitches extends ActionBase {

    protected function checkInput(): bool {
        $fields = [
            'switchid' => 'string'  // hostid of the switch to focus on
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $ret;
    }

    protected function doAction(): void {
        $data = [
            'title'    => _('TCS Switch Port Status'),
            'switchid' => $this->getInput('switchid', '')
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('TCS Switch Port Status'));
        $this->setResponse($response);
    }
}
