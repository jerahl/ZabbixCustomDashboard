<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CControllerResponseData;
use CControllerResponseFatal;
use Modules\TcsDashboard\Lib\SwitchClient;

/**
 * GET zabbix.php?action=tcs.switches.snapshot.data&switchid=NNN
 *
 * Returns the per-host port / PoE / KPI / history / uplinks / FDB / problems
 * payload as JSON. Loaded async by the Switches page after first paint so
 * the React shell can render immediately and tiles fill in as the response
 * arrives. Identical shape to the SSR boot payload (minus `fleet`).
 */
class ActionSwitchesSnapshotData extends ActionDataBase {

    protected function checkInput(): bool {
        $ret = $this->validateInput(['switchid' => 'required|string']);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $hostid = (string) $this->getInput('switchid');

        // Reuse ActionSwitches' private collectHost/collectProblems via
        // reflection so we don't duplicate the API queries.
        $view = new ActionSwitches();
        $rc = new \ReflectionClass($view);
        $invoke = function (string $method, array $args) use ($rc, $view) {
            $m = $rc->getMethod($method);
            $m->setAccessible(true);
            return $m->invokeArgs($view, $args);
        };

        $payload = [
            'host'     => null,
            'members'  => [],
            'ports'    => [],
            'poe'      => [],
            'fdb'      => [],
            'kpis'     => new \stdClass(),
            'history'  => new \stdClass(),
            'uplinks'  => [],
            'problems' => [],
            'ts'       => time()
        ];

        try {
            $payload['host'] = $invoke('collectHost', [$hostid]);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] snapshot.data collectHost: '.$e->getMessage());
        }

        try {
            $snap = (new SwitchClient())->snapshot($hostid);
            $payload = array_merge($payload, $snap);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] snapshot.data snapshot: '.$e->getMessage());
        }

        try {
            $payload['problems'] = $invoke('collectProblems', [$hostid, 25]);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] snapshot.data problems: '.$e->getMessage());
        }

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES)
        ]));
    }
}
