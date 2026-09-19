<?php
/* php apply.php [apply|revert|status] [--dry-run] [--quiet]
 * MM_EMHTTP and MM_CONFIG override the Unraid paths (used by the tests). */
require_once __DIR__ . '/../include/apply.php';

$cmd = 'apply';
$dry = $quiet = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    elseif ($a === '--quiet') $quiet = true;
    else $cmd = $a;
}
$paths = mm_paths();
switch ($cmd) {
    case 'apply':
    case 'status':
        $r = mm_apply($paths['emhttp'], mm_load_config($paths['config']), $dry || $cmd === 'status');
        if (!$quiet) {
            foreach ($r['changed'] as $c) echo ($dry || $cmd === 'status' ? 'would change ' : 'changed ') . "$c\n";
            foreach ($r['warnings'] as $w) echo "warning: $w\n";
            if (!$r['changed']) echo "nothing to do\n";
        }
        break;
    case 'revert':
        $r = mm_revert($paths['emhttp']);
        if (!$quiet) echo $r['changed'] ? 'restored ' . implode(', ', $r['changed']) . "\n" : "already original\n";
        break;
    default:
        fwrite(STDERR, "usage: apply.php [apply|revert|status] [--dry-run] [--quiet]\n");
        exit(2);
}
