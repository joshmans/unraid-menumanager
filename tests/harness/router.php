<?php
/* php -S router: serves the settings page outside Unraid, against a fixture. */
$src = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/menumanager');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') {
    $GLOBALS['var'] = ['csrf_token' => 'test'];
    echo '<!doctype html><meta charset="utf-8"><title>Menu Manager harness</title><body style="font-family:sans-serif;margin:16px"><h2>Menu Manager</h2>';
    require "$src/include/editor.php";
    return true;
}
if (strpos($path, '/plugins/menumanager/') === 0) {
    $f = realpath($src . substr($path, strlen('/plugins/menumanager')));
    if ($f && strpos($f, $src) === 0 && is_file($f)) {
        if (substr($f, -4) === '.php') { require $f; return true; }
        header('Content-Type: ' . (substr($f, -3) === 'css' ? 'text/css' : 'application/javascript'));
        readfile($f);
        return true;
    }
}
http_response_code(404);
