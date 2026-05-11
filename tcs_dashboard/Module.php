<?php declare(strict_types=1);

namespace Modules\TcsDashboard;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

/**
 * Registers TCS Dashboard menu entries under the Monitoring menu and exposes
 * all tcs.* actions defined in manifest.json.
 *
 * Tested against Zabbix 6.0 LTS / 6.4 / 7.0. The menu API has shifted across
 * majors; if you target an older version, see README "Version notes".
 */
class Module extends CModule {

    public function init(): void {
        $main_menu = APP::Component()->get('menu.main');

        if ($main_menu === null) {
            return;
        }

        $monitoring = $main_menu->find(_('Monitoring'));

        if ($monitoring === null) {
            return;
        }

        $submenu = $monitoring->getSubmenu();

        $submenu->add((new CMenuItem(_('TCS Global')))->setAction('tcs.global.view'));
        $submenu->add((new CMenuItem(_('TCS Wireless APs')))->setAction('tcs.dashboard.view'));
        $submenu->add((new CMenuItem(_('TCS Switches')))->setAction('tcs.switches.view'));
        $submenu->add((new CMenuItem(_('TCS Servers')))->setAction('tcs.servers.view'));
        $submenu->add((new CMenuItem(_('TCS Surveillance')))->setAction('tcs.surveillance.view'));
    }
}
