<?php
/* JSON endpoint for the settings page. POSTs carry Unraid's csrf_token, which
 * local_prepend.php checks before this file runs. */
require_once __DIR__ . '/apply.php';

if (!function_exists('mm_handle')) {
    /** @return array [http status, body] */
    function mm_handle(string $action, array $req, array $paths): array {
        $view = function (array $extra, array $cfg) use ($paths): array {
            $plan = mm_plan(mm_scan($paths['emhttp']), $cfg);
            return $extra + ['state' => mm_state($plan), 'warnings' => $plan['warnings']];
        };
        switch ($action) {
            case 'state':
                return [200, $view([], mm_load_config($paths['config']))];
            case 'save':
                $state = json_decode((string)($req['state'] ?? ''), true);
                if (!is_array($state)) return [400, ['error' => 'bad state']];
                $cfg = mm_config_from_state($state, mm_scan($paths['emhttp']));
                if (!mm_save_config($paths['config'], $cfg)) return [500, ['error' => 'could not write ' . $paths['config']]];
                $r = mm_apply($paths['emhttp'], $cfg);
                return [200, $view(['saved' => true, 'changed' => $r['changed']], $cfg)];
            case 'disable_hub':   // the escape hatch on the unified page itself
                $cfg = mm_load_config($paths['config']);
                $cfg['hub']['enabled'] = false;
                $cfg['hub']['hideBuiltin'] = false;
                if (!mm_save_config($paths['config'], $cfg)) return [500, ['error' => 'could not write ' . $paths['config']]];
                $r = mm_apply($paths['emhttp'], $cfg);
                return [200, $view(['saved' => true, 'changed' => $r['changed']], $cfg)];
            case 'reset':
                mm_revert($paths['emhttp']);
                mm_save_config($paths['config'], mm_default_config());
                return [200, $view(['saved' => true], mm_default_config())];
        }
        return [400, ['error' => 'unknown action']];
    }
}

/* Only a POST can change anything: that is where csrf_token is checked. A GET
 * always reads, whatever action it names. */
$mmAction = $_POST['action'] ?? 'state';
try {
    [$mmStatus, $mmBody] = mm_handle($mmAction, $_POST, mm_paths());
} catch (Throwable $e) {
    [$mmStatus, $mmBody] = [500, ['error' => $e->getMessage()]];
}
http_response_code($mmStatus);
header('Content-Type: application/json');
echo json_encode($mmBody);
