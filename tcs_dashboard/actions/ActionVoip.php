<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.voip.view
 *
 * Renders the 3CX VoIP NOC page from the mock data in voip-app.jsx. To wire
 * to live data, hit the 3CX Management Console API (/xapi/v1/Trunks, /Calls,
 * /Extensions) and pass the payload as $data['boot'].
 */
class ActionVoip extends ActionBase {

    protected function checkInput(): bool {
        $ret = $this->validateInput([]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $response = new CControllerResponseData([
            'title' => _('TCS VoIP · 3CX')
        ]);
        $response->setTitle(_('TCS VoIP · 3CX'));
        $this->setResponse($response);
    }
}
