<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\TcsDashboard\Lib\SwitchClient;

/**
 * GET zabbix.php?action=tcs.switches.view[&switchid=NNN]
 *
 * Collects an initial snapshot of stack / port / PoE state from the Zabbix
 * Item API (via SwitchClient) and hands it to the view as $data['boot'].
 * The view inlines this as window.SWITCH_BOOT so a future switches-bridge.jsx
 * can adapt it into window.SWITCH_SITES / window.ARC_MDF_STACK /
 * window.makePortDetail without changing the React components.
 *
 * Item keys expected on switch hosts (from the lifted EXOS template
 * templates/extreme_exos_by_snmp_with_poe.yaml):
 *   stacking.member[1..8]
 *   net.if.status[ifOperStatus.<member>.<port>]
 *   snmp.interfaces.poe.dstatus[<member>.<port>]
 *   net.if.mac[<member>.<port>]                  (FDB, if discovered)
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
        $switchid = $this->getInput('switchid', '');

        $boot = [
            'host'    => null,
            'members' => [],
            'ports'   => [],
            'poe'     => [],
            'fdb'     => []
        ];

        if ($switchid !== '') {
            $boot['host'] = $this->collectHost($switchid);
            try {
                $snap = (new SwitchClient())->snapshot($switchid);
                $boot = array_merge($boot, $snap);
            }
            catch (\Throwable $e) {
                error_log('[tcs_dashboard] SwitchClient: '.$e->getMessage());
            }
        }

        $data = [
            'title'    => _('TCS Switch Port Status'),
            'switchid' => $switchid,
            'boot'     => $boot
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('TCS Switch Port Status'));
        $this->setResponse($response);
    }

    private function collectHost(string $hostid): ?array {
        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
            'selectInterfaces' => ['ip', 'main', 'type'],
            'hostids'          => [$hostid]
        ]);
        if (!$hosts) return null;

        $h  = $hosts[0];
        $ip = '';
        foreach ($h['interfaces'] ?? [] as $iface) {
            if ((int) ($iface['main'] ?? 0) === 1) {
                $ip = $iface['ip'];
                break;
            }
        }

        return [
            'hostid'       => $h['hostid'],
            'host'         => $h['host'],
            'visible_name' => $h['name'],
            'ip'           => $ip,
            'status'       => ((int) $h['status'] === 0) ? 'monitored' : 'not monitored',
            'maintenance'  => (int) ($h['maintenance_status'] ?? 0)
        ];
    }
}
