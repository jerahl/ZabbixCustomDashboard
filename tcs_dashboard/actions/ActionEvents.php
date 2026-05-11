<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;

/** GET zabbix.php?action=tcs.events.view */
class ActionEvents extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        $boot = (new ActionEventsData())->collect();
        $response = new CControllerResponseData([
            'title' => _('TCS Events Console'),
            'boot'  => $boot
        ]);
        $response->setTitle(_('TCS Events Console'));
        $this->setResponse($response);
    }
}
