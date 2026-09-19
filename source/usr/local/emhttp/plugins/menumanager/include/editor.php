<?php
/* Markup for the settings page; the behaviour lives in js/editor.js. */
$mmBase = '/plugins/menumanager';
$mmDir  = dirname(__DIR__);
$mmVer  = fn(string $f) => @filemtime("$mmDir/$f") ?: 0;
$mmCsrf = $GLOBALS['var']['csrf_token'] ?? '';
$mmApi  = $GLOBALS['mm_api_url'] ?? "$mmBase/include/api.php";
?>
<link rel="stylesheet" href="<?= $mmBase ?>/css/editor.css?v=<?= $mmVer('css/editor.css') ?>">
<div id="mm-app" data-api="<?= htmlspecialchars($mmApi) ?>" data-csrf="<?= htmlspecialchars($mmCsrf) ?>">
  <noscript>The Menu Manager needs JavaScript.</noscript>
</div>
<script src="<?= $mmBase ?>/js/editor.js?v=<?= $mmVer('js/editor.js') ?>"></script>
