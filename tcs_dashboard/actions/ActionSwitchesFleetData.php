<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CControllerResponseData;

/**
 * GET zabbix.php?action=tcs.switches.fleet.data
 *
 * Returns the Site/* / target:exos host fleet as JSON, for the Switches
 * page's HostNavigator. Async-loaded after first paint so the page
 * doesn't block on fleet discovery.
 *
 * Shape: { fleet: [...sites...], ts: <unix> }
 *
 * Backed by the same APCu-cached collectFleet() used by the SSR path,
 * so warm-cache responses are sub-millisecond.
 */
class ActionSwitchesFleetData extends ActionDataBase {

    protected function checkInput(): bool {
        return $this->validateInput([]);
    }

    protected function doAction(): void {
        $view = new ActionSwitches();
        $rc = new \ReflectionClass($view);
        $m  = $rc->getMethod('collectFleet');
        $m->setAccessible(true);

        $fleet = [];
        try {
            $fleet = $m->invoke($view);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] fleet.data: '.$e->getMessage());
        }

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode([
                'fleet' => $fleet,
                'ts'    => time()
            ], JSON_UNESCAPED_SLASHES)
        ]));
    }
}
