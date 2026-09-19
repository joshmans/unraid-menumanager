<?php
/* Write the plan to disk (and undo it). Unraid keeps /usr/local/emhttp in RAM,
 * so nothing here survives a reboot or a plugin update on its own: the config
 * lives on flash and apply() is re-run at boot, on the array "started" event
 * and every minute from cron. It only writes files whose content changed. */

require_once __DIR__ . '/plan.php';

const MM_CONFIG_DEFAULT = '/boot/config/plugins/menumanager/layout.json';
const MM_EMHTTP_DEFAULT = '/usr/local/emhttp';

function mm_paths(): array {
    return [
        'emhttp' => getenv('MM_EMHTTP') ?: MM_EMHTTP_DEFAULT,
        'config' => getenv('MM_CONFIG') ?: MM_CONFIG_DEFAULT,
    ];
}

/** Bring every page file in line with $cfg. Returns what changed. */
function mm_apply(string $emhttp, array $cfg, bool $dry = false): array {
    $pages = mm_scan($emhttp);
    $plan = mm_plan($pages, $cfg);
    $changed = [];
    foreach ($pages as $name => $p) {
        $want = mm_apply_edits($p['raw'], $plan['edits'][$name] ?? []);
        if ($want === $p['raw']) continue;
        if (!$dry && !mm_write_atomic($p['file'], $want)) $plan['warnings'][] = "could not write {$p['file']}";
        $changed[] = $name;
    }
    $own = "$emhttp/plugins/" . MM_PLUGIN;
    foreach ($plan['generated'] as $file => $content) {
        if (is_file("$own/$file") && file_get_contents("$own/$file") === $content) continue;
        if (!$dry) mm_write_atomic("$own/$file", $content);
        $changed[] = $file;
    }
    foreach (glob("$own/MM_*.page") ?: [] as $f) {
        if (isset($plan['generated'][basename($f)])) continue;
        if (!$dry) @unlink($f);
        $changed[] = basename($f) . ' (removed)';
    }
    return ['changed' => $changed, 'warnings' => $plan['warnings'], 'plan' => $plan];
}

/** Put every page back exactly as its plugin shipped it. */
function mm_revert(string $emhttp): array {
    $changed = [];
    foreach (mm_scan($emhttp) as $name => $p) {
        $orig = mm_pristine($p['raw']);
        if ($orig === $p['raw']) continue;
        mm_write_atomic($p['file'], $orig);
        $changed[] = $name;
    }
    foreach (glob("$emhttp/plugins/" . MM_PLUGIN . "/MM_*.page") ?: [] as $f) {
        @unlink($f);
        $changed[] = basename($f) . ' (removed)';
    }
    return ['changed' => $changed];
}
