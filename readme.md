# Zabbix Custom Dashboard

Custom-skinned operations dashboards that install as a Zabbix frontend module. Provides a unified UI across Zabbix monitoring, PacketFence identity, and Milestone XProtect surveillance — with a shared sidebar that cross-links between pages and back to the default Zabbix UI.

The module lives in [tcs_dashboard/](tcs_dashboard) and is dropped into a Zabbix UI install's `modules/` directory.

## Pages

| Page                  | URL                                              | Status                       |
| --------------------- | ------------------------------------------------ | ---------------------------- |
| Global Dashboard      | `zabbix.php?action=tcs.global.view`              | Mock data (synthetic)        |
| AP Detail (Wireless)  | `zabbix.php?action=tcs.dashboard.view&hostid=N`  | Wired to live Zabbix data    |
| Switch Port Status    | `zabbix.php?action=tcs.switches.view`            | Live fleet + snapshot data   |
| Servers               | `zabbix.php?action=tcs.servers.view`             | Mock data (agent wiring)     |
| Zabbix Server Status  | `zabbix.php?action=tcs.zbx.status.view`          | Live (server + proxy health) |
| Problems              | `zabbix.php?action=tcs.problems.view`            | Live trigger data            |
| Events                | `zabbix.php?action=tcs.events.view`              | Live event data              |
| Surveillance NOC      | `zabbix.php?action=tcs.surveillance.view`        | Mock data (Milestone wiring) |
| Camera Detail         | `zabbix.php?action=tcs.camera.view&id=…`         | Mock data                    |
| Recording Server      | `zabbix.php?action=tcs.server.view&id=…`         | Mock data                    |

## Install

1. Copy [tcs_dashboard/](tcs_dashboard) to your Zabbix UI's modules directory (typically `/usr/share/zabbix/modules/` on Linux). The folder name must remain `tcs_dashboard` to match `manifest.json`.
2. In Zabbix go to **Administration → General → Modules**, click **Scan directory**, find "TCS Dashboard", and toggle **Enabled**.
3. Hit any page via its `zabbix.php?action=tcs.*` URL, or use the new entry under **Monitoring**.

Tested against Zabbix **7.4**.

## Project layout

- [tcs_dashboard/](tcs_dashboard) — the Zabbix module itself (PHP controllers, views, JSX assets)
- [tcs_dashboard/README.md](tcs_dashboard/README.md) — detailed install, wiring, and customization notes
- [tcs_dashboard/Project_Plan_v1_0.html](tcs_dashboard/Project_Plan_v1_0.html) — project plan
- [LICENSE](LICENSE)

For controller wiring, item-key mapping, PacketFence integration, air-gapped installs, and per-page customization, see [tcs_dashboard/README.md](tcs_dashboard/README.md).

## License

See [LICENSE](LICENSE).
