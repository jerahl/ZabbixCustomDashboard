<?php declare(strict_types=1);

/**
 * @var CView $this
 * @var array $data
 */

$asset_base = 'modules/tcs_dashboard/assets';

// Cache-bust asset URLs by file mtime so an updated .jsx/.css is never served
// stale from the browser cache (e.g. a stale nvr-overview.jsx loading camera
// thumbnails from a direct https://{ip}/snap.jpg and throwing a silent
// net::ERR_CERT_AUTHORITY_INVALID instead of using the same-origin proxy).
$asset_dir = __DIR__ . '/../assets';
$ver = static fn(string $f): string =>
    'modules/tcs_dashboard/assets/' . $f . '?v=' . ((int) @filemtime($asset_dir . '/' . $f));
?>
<style>
    body > header, body > nav, body > aside, body > footer, body > .menu-main, body > .header-title, .wrapper > footer, footer[role="contentinfo"], .msg-global-footer, #page-footer { display: none !important; }
    body { margin: 0 !important; padding: 0 !important; background: #0d1117 !important; }
    main, .wrapper, .article { padding: 0 !important; margin: 0 !important; max-width: none !important; }
    main { all: revert; }
</style>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $ver('styles.css') ?>">
<link rel="stylesheet" href="<?= $ver('surveillance.css') ?>">

<style>
    html.hide-src-badges .src-badge { display: none !important; }
    .app[data-density="dense"]    .card-b    { padding: 10px; }
    .app[data-density="dense"]    .stat-cell { padding: 10px 12px; }
    .app[data-density="spacious"] .card-b    { padding: 18px; }
    .app[data-density="spacious"] .stat-cell { padding: 18px 16px; }
    @media (max-width: 1500px) {
        .row[style*="1.1fr 1fr 1.4fr"] { grid-template-columns: 1fr 1fr !important; }
        .row[style*="1.1fr 1fr 1.4fr"] > .card:nth-child(3) { grid-column: 1 / -1; }
    }
    @media (max-width: 1280px) {
        .app { grid-template-columns: 64px 1fr; }
        .sidebar .nav-label, .sidebar .brand div:not(.brand-mark),
        .sidebar .nav-item span:not(.nav-count), .sidebar-footer { display: none; }
        .sidebar .nav-item { justify-content: center; }
        .row[style*="1fr 1fr"] { grid-template-columns: 1fr !important; }
    }
</style>

<div id="root"></div>

<script>
    window.SURVEILLANCE_BOOT         = <?= json_encode($data['boot']   ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.SURVEILLANCE_HOSTID       = <?= json_encode($data['hostid'] ?? '',   JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.TCS_SURVEILLANCE_DATA_URL = "zabbix.php?action=tcs.surveillance.data";
</script>

<script src="https://unpkg.com/react@18.3.1/umd/react.production.min.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/@babel/standalone@7.29.0/babel.min.js" crossorigin="anonymous"></script>

<!-- Order matters: tweaks → primitives → live data bridge → shell → overview → tabs → app entry -->
<script type="text/babel" src="<?= $ver('tweaks-panel.jsx') ?>"></script>
<script type="text/babel" src="<?= $ver('primitives.jsx') ?>"></script>
<script type="text/babel" src="<?= $ver('surveillance-bridge.jsx') ?>"></script>
<script type="text/babel" src="<?= $ver('global-nav.jsx') ?>"></script>
<script type="text/babel" src="<?= $ver('nvr-shell.jsx') ?>"></script>
<script type="text/babel" src="<?= $ver('nvr-overview.jsx') ?>"></script>
<script type="text/babel" src="<?= $ver('nvr-tabs.jsx') ?>"></script>
<script type="text/babel" src="<?= $ver('nvr-app.jsx') ?>"></script>
