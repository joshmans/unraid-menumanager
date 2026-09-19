<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/menumanager/include/hub.php';

$root = mm_fixture();
putenv("MM_EMHTTP=$root");
putenv("MM_CONFIG=$root/layout.json");
$pristine = mm_snapshot($root);

/* ---- API ---- */
function api(string $action, array $post = []): array {
    $_POST = $post + ['action' => $action];
    $_GET = [];
    ob_start();
    include __DIR__ . '/../source/usr/local/emhttp/plugins/menumanager/include/api.php';
    $out = ob_get_clean();
    return json_decode($out, true) ?? ['raw' => $out];
}
$r = api('state');
check('state returns both roots', isset($r['state']['roots']['Tools'], $r['state']['roots']['Settings']));
check('state lists a tile', in_array('FooSettings', array_column($r['state']['roots']['Settings'][0]['tiles'] ?? [], 'id'), true));

$state = $r['state'];
// drag Foo Settings into Disk Utilities, rename that category, add a category
foreach ($state['roots']['Settings'] as &$g) {
    if ($g['id'] === 'Utilities') $g['tiles'] = array_values(array_filter($g['tiles'], fn($t) => $t['id'] !== 'FooSettings'));
}
unset($g);
foreach ($state['roots']['Tools'] as &$g) {
    if ($g['id'] === 'DiskUtilities') { array_unshift($g['tiles'], ['id' => 'FooSettings', 'title' => 'Foo Settings', 'hidden' => false]); $g['title'] = 'Storage'; }
}
unset($g);
$state['roots']['Tools'][] = ['id' => 'new1', 'title' => 'Media', 'custom' => true, 'hidden' => false, 'tag' => 'film', 'tiles' => []];
$state['hub'] = ['enabled' => true, 'title' => 'Launchpad', 'rank' => '88', 'code' => 'e909', 'hideBuiltin' => false];
$r = api('save', ['state' => json_encode($state)]);
check('save reports success', !empty($r['saved']) && empty($r['error']), json_encode($r));
$site = unraid_site($root);
check('the tile moved on disk', unraid_names($site, 'DiskUtilities')[0] === 'FooSettings');
check('the category was renamed', $site['DiskUtilities']['Title'] === 'Storage');
check('the new category exists', is_file("$root/plugins/menumanager/MM_Media.page"));
check('the config was saved to flash', is_file("$root/layout.json") && json_decode(file_get_contents("$root/layout.json"), true)['hub']['title'] === 'Launchpad');
check('the returned state reflects the save', ($r['state']['hub']['title'] ?? '') === 'Launchpad');
check('bad input is rejected', api('save', ['state' => 'nope'])['error'] === 'bad state');
check('unknown actions are rejected', api('wat')['error'] === 'unknown action');

/* ---- unified page ---- */
$GLOBALS['site'] = unraid_site($root);
function find_pages($item) { return unraid_find_pages($GLOBALS['site'], $item); }
ob_start();
mm_render_hub();
$html = ob_get_clean();
check('the hub has a link to the settings page', str_contains($html, 'href="/Settings/MenuManager"') && str_contains($html, 'Page Settings'));
check('the hub has a quick disable', str_contains($html, 'id="mm-hub-disable"') && str_contains($html, 'disable_hub'));
check('the hub lists the renamed category', str_contains($html, 'Storage'));
check('the hub links tiles under the right root', str_contains($html, 'href="/Tools/FooSettings"') && str_contains($html, 'href="/Settings/BarSettings"'));
check('the hub lists tiles from both roots', str_contains($html, 'Registration') && str_contains($html, 'Bar Settings'));
check('the hub skips empty categories', !str_contains($html, '>Media<'));
check('hub output is escaped', !str_contains($html, '<script>alert'));

/* ---- quick disable from the unified page ---- */
$state['hub']['hideBuiltin'] = true;
api('save', ['state' => json_encode($state)]);
check('precondition: built-ins hidden and hub on', !in_array('Tools', unraid_names(unraid_site($root), 'Tasks'), true) && in_array('MenuManagerHub', unraid_names(unraid_site($root), 'Tasks'), true));
$r = api('disable_hub');
$site = unraid_site($root);
check('disable turns the unified page off', empty($r['error']) && !in_array('MenuManagerHub', unraid_names($site, 'Tasks'), true));
check('and brings the built-in menus back', in_array('Tools', unraid_names($site, 'Tasks'), true) && in_array('Settings', unraid_names($site, 'Tasks'), true));
check('but keeps the layout', json_decode(file_get_contents("$root/layout.json"), true)['hub']['title'] === 'Launchpad' && unraid_names($site, 'DiskUtilities')[0] === 'FooSettings');

/* ---- reset ---- */
$r = api('reset');
check('reset restores every file', mm_snapshot($root) === $pristine, implode(',', array_keys(array_diff_assoc(mm_snapshot($root), $pristine))));
check('reset clears the layout', json_decode(file_get_contents("$root/layout.json"), true)['layout'] === []);

mm_rmtree($root);
finish('hub_api_test');
