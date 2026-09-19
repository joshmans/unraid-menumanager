<?php
require_once __DIR__ . '/lib.php';

$root = mm_fixture();
$pristine = mm_snapshot($root);
$cfgFile = "$root/layout.json";

/* ---- model ---- */
$pages = mm_scan($root);
$model = mm_model($pages);
check('finds the three native categories', array_keys($model['groups']) === ['Utilities', 'DiskUtilities', 'SystemInformation']
    || (($k = array_keys($model['groups'])) && sort($k) === true && $k === ['DiskUtilities', 'SystemInformation', 'Utilities']));
check('categories know their root', $model['groups']['Utilities']['root'] === 'Settings' && $model['groups']['DiskUtilities']['root'] === 'Tools');
check('tiles are found', isset($model['tiles']['FooSettings'], $model['tiles']['BarTool'], $model['tiles']['Registration'], $model['tiles']['QuxOne']));
check('tab pages and dashboard widgets are not tiles', !isset($model['tiles']['FooTab']) && !isset($model['tiles']['BazDash']));
check('an indirect menu resolves to its default when the file is missing',
    ($model['tiles']['BazDyn']['group'] ?? '') === 'Utilities' && $model['tiles']['BazDyn']['info']['indirect'] === 'file');
check('a $var menu resolves to its default', ($model['tiles']['BazVar']['group'] ?? '') === 'Utilities' && $model['tiles']['BazVar']['info']['indirect'] === 'var');
check('an indirect menu that names no category is a tile that is not in one', array_key_exists('BazNone', $model['tiles']) && $model['tiles']['BazNone']['group'] === null);
$GLOBALS['mm_read_ini_hook'] = fn($f) => $f === '/boot/config/plugins/baz/baz.cfg' ? ['MENU' => 'DiskUtilities:3'] : false;
$viaFile = mm_model(mm_scan($root));
check('the setting in the ini file decides where it sits', ($viaFile['tiles']['BazDyn']['group'] ?? '') === 'DiskUtilities' && $viaFile['tiles']['BazDyn']['rank'] === '3');
unset($GLOBALS['mm_read_ini_hook']);
check('a page listed in several menus is a tile through its category token', isset($model['tiles']['BazMulti']) && $model['tiles']['BazMulti']['group'] === 'Utilities');
check('our own hub is not a tile', !isset($model['tiles']['MenuManagerHub']));
check('our settings page is a normal movable tile', isset($model['tiles']['MenuManager']));

/* ---- default config changes nothing ---- */
$r = mm_apply($root, mm_default_config());
check('empty config writes nothing', $r['changed'] === [] && mm_snapshot($root) === $pristine);

/* ---- move a Settings tile into a Tools category, ahead of what is there ---- */
$site = unraid_site($root);
check('baseline: Foo Settings is in User Utilities', in_array('FooSettings', unraid_names($site, 'Utilities'), true));
$cfg = mm_default_config();
$cfg['layout'] = ['DiskUtilities' => ['FooSettings', 'BarTool', 'QuxOne']];
$r = mm_apply($root, $cfg);
$site = unraid_site($root);
check('Unraid now lists Foo Settings under Disk Utilities', unraid_names($site, 'DiskUtilities') === ['FooSettings', 'BarTool', 'QuxOne'], implode(',', unraid_names($site, 'DiskUtilities')));
check('and no longer under User Utilities', !in_array('FooSettings', unraid_names($site, 'Utilities'), true));
check('its Menu is exactly Group:rank', $site['FooSettings']['Menu'] === 'DiskUtilities:10');
check('pages that were not touched are byte identical', file_get_contents("$root/plugins/baz/BazDyn.page") === $pristine['/plugins/baz/BazDyn.page'] && file_get_contents("$root/plugins/baz/BazNone.page") === $pristine['/plugins/baz/BazNone.page']);
check('applying twice changes nothing more', mm_apply($root, $cfg)['changed'] === []);

/* ---- a plugin update overwrites the file; the next apply puts the layout back ---- */
file_put_contents("$root/plugins/foo/FooSettings.page", $pristine['/plugins/foo/FooSettings.page']);
check('after an update the tile is back where its plugin put it', in_array('FooSettings', unraid_names(unraid_site($root), 'Utilities'), true));
$r = mm_apply($root, $cfg);
check('cron/boot apply re-moves it', unraid_names(unraid_site($root), 'DiskUtilities')[0] === 'FooSettings' && $r['changed'] === ['FooSettings']);

/* ---- revert is exact ---- */
mm_revert($root);
check('revert restores every file byte for byte', mm_snapshot($root) === $pristine);

/* ---- a multi-menu page keeps its other menus ---- */
$cfg = mm_default_config();
$cfg['layout'] = ['DiskUtilities' => ['BazMulti']];
mm_apply($root, $cfg);
$site = unraid_site($root);
check('only the category token of a multi-menu page moves', $site['BazMulti']['Menu'] === 'DiskUtilities:10 Buttons');
check('it is still a button', in_array('BazMulti', unraid_names($site, 'Buttons'), true));
$cfg['hidden'] = ['BazMulti'];
mm_apply($root, $cfg);
$site = unraid_site($root);
check('hiding it drops only that category', $site['BazMulti']['Menu'] === 'Buttons' && !in_array('BazMulti', unraid_names($site, 'DiskUtilities'), true));
mm_revert($root);
check('reverted', mm_snapshot($root) === $pristine);

/* ---- indirect menus: the user decides, the override is reversible ---- */
$cfg = mm_default_config();
$cfg['layout'] = ['DiskUtilities' => ['BazDyn', 'BazVar', 'BazNone']];
mm_apply($root, $cfg);
$site = unraid_site($root);
check('a file-driven tile is moved by replacing the indirection', $site['BazDyn']['Menu'] === 'DiskUtilities:10');
check('a $var tile is moved too', $site['BazVar']['Menu'] === 'DiskUtilities:20');
check('a tile that was in no category can be placed', $site['BazNone']['Menu'] === 'DiskUtilities:30');
check('Unraid lists all three under Disk Utilities, in order', array_values(array_intersect(unraid_names($site, 'DiskUtilities'), ['BazDyn', 'BazVar', 'BazNone'])) === ['BazDyn', 'BazVar', 'BazNone']);
$cfg['hidden'] = ['BazDyn'];
mm_apply($root, $cfg);
check('hiding an indirect tile empties its Menu', unraid_site($root)['BazDyn']['Menu'] === '');
mm_revert($root);
check('reverting brings the indirection back byte for byte', mm_snapshot($root) === $pristine);

$cfg = mm_default_config();
$cfg['layout'] = ['Utilities' => ['BazDyn']];   // placed where it already resolves to, at a rank
mm_apply($root, $cfg);
check('pinning an indirect tile in place makes it static', unraid_site($root)['BazDyn']['Menu'] === 'Utilities:10');
mm_revert($root);

$plan = mm_plan(mm_scan($root), mm_default_config());
$state = mm_state($plan);
check('the state lists tiles that are in no category', array_column($state['unplaced'], 'id') === ['BazNone']);
check('and marks indirect tiles', in_array('file', array_column(array_merge(...array_column($state['roots']['Settings'], 'tiles')), 'indirect'), true));
$state['unplaced'][0]['hidden'] = true;
$cfg = mm_config_from_state($state, mm_scan($root));
check('an unplaced tile can be hidden without being placed', $cfg['hidden'] === ['BazNone'] && !in_array('BazNone', array_merge(...array_values($cfg['layout'])), true));

/* ---- hide, rename ---- */
$cfg = mm_default_config();
$cfg['hidden'] = ['BarSettings'];
$cfg['titles'] = ['DiskUtilities' => 'Storage Tools', 'BarTool' => 'Bar!'];
mm_apply($root, $cfg);
$site = unraid_site($root);
check('a hidden tile is in no menu', !in_array('BarSettings', unraid_names($site, 'Utilities'), true));
check('the page still exists so its URL keeps working', isset($site['BarSettings']));
check('renaming a category changes only its Title', $site['DiskUtilities']['Title'] === 'Storage Tools' && $site['DiskUtilities']['Menu'] === 'Tools');
check('renaming a tile works', $site['BarTool']['Title'] === 'Bar!');
mm_revert($root);

/* ---- swap a whole category between Tools and Settings ---- */
$cfg = mm_default_config();
$cfg['groupRoot'] = ['DiskUtilities' => 'Settings'];
mm_apply($root, $cfg);
$site = unraid_site($root);
check('Disk Utilities now lives under Settings', in_array('DiskUtilities', unraid_names($site, 'Settings'), true) && !in_array('DiskUtilities', unraid_names($site, 'Tools'), true));
check('its tiles came along', in_array('BarTool', unraid_names($site, 'DiskUtilities'), true));
mm_revert($root);

/* ---- reorder categories inside a root ---- */
$cfg = mm_default_config();
$cfg['groupOrder'] = ['Tools' => ['SystemInformation', 'DiskUtilities']];
mm_apply($root, $cfg);
check('categories reorder', array_slice(unraid_names(unraid_site($root), 'Tools'), 0, 2) === ['SystemInformation', 'DiskUtilities']);
mm_revert($root);

/* ---- your own category ---- */
$cfg = mm_default_config();
$cfg['custom'] = ['MM_Media' => ['title' => 'Media', 'root' => 'Tools', 'tag' => 'film']];
$cfg['groupOrder'] = ['Tools' => ['MM_Media', 'DiskUtilities', 'SystemInformation']];
$cfg['layout'] = ['MM_Media' => ['BarTool', 'FooSettings']];
$r = mm_apply($root, $cfg);
$site = unraid_site($root);
check('the category page is written', is_file("$root/plugins/menumanager/MM_Media.page"));
check('Unraid lists it first under Tools', unraid_names($site, 'Tools')[0] === 'MM_Media');
check('it is a menu with the title and icon we asked for', $site['MM_Media']['Type'] === 'menu' && $site['MM_Media']['Title'] === 'Media' && $site['MM_Media']['Tag'] === 'film');
check('its tiles are listed in order', unraid_names($site, 'MM_Media') === ['BarTool', 'FooSettings']);
unset($cfg['custom']['MM_Media'], $cfg['groupOrder'], $cfg['layout']);
mm_apply($root, $cfg);
check('deleting the category removes its page and frees the tiles',
    !is_file("$root/plugins/menumanager/MM_Media.page") && mm_snapshot($root) === $pristine);

/* ---- refusals ---- */
$cfg = mm_default_config();
$cfg['layout'] = ['DiskUtilities' => ['BazDash', 'FooSettings', 'Ghost'], 'Nowhere' => ['BarTool'], 'Utilities' => ['FooSettings']];
$plan = mm_plan(mm_scan($root), $cfg);
$w = implode(' | ', $plan['warnings']);
check('a dashboard widget is refused', str_contains($w, "'BazDash' isn't a tile"));
check('an unknown category is reported', str_contains($w, "'Nowhere'"));
check('a tile in two categories keeps the first', str_contains($w, "'FooSettings' is listed in two") && $plan['tiles']['FooSettings']['group'] === 'DiskUtilities');
check('a plugin that is gone is silently skipped', !str_contains($w, 'Ghost'));

/* ---- the unified page ---- */
$cfg = mm_default_config();
$cfg['hub'] = ['enabled' => true, 'title' => 'Launchpad', 'rank' => '89', 'code' => 'e909', 'hideBuiltin' => true];
mm_apply($root, $cfg);
$site = unraid_site($root);
check('the hub joins the main menu', in_array('MenuManagerHub', unraid_names($site, 'Tasks'), true) && $site['MenuManagerHub']['Menu'] === 'Tasks:89');
check('and takes the chosen name', $site['MenuManagerHub']['Title'] === 'Launchpad');
check('the main menu label (Name=) follows the title, not the file name', $site['MenuManagerHub']['Name'] === 'Launchpad');
check('the built-in Tools and Settings leave the main menu', !in_array('Tools', unraid_names($site, 'Tasks'), true) && !in_array('Settings', unraid_names($site, 'Tasks'), true));
check('their categories still exist for the hub to list', in_array('DiskUtilities', unraid_names($site, 'Tools'), true));
mm_revert($root);
$cfg['hub']['enabled'] = false;
$plan = mm_plan(mm_scan($root), $cfg);
check('the built-ins are never hidden without a unified page', !isset($plan['edits']['Tools']) && !isset($plan['edits']['Settings']));
check('and the user is told', (bool)preg_grep('/unified page is enabled/', $plan['warnings']));

/* ---- state round trip (what the settings page sees, then posts back) ---- */
$cfg = mm_default_config();
$cfg['layout'] = ['DiskUtilities' => ['FooSettings', 'BarTool']];
$cfg['hidden'] = ['QuxOne'];
$cfg['titles'] = ['BarSettings' => 'Renamed'];
$planA = mm_plan(mm_scan($root), $cfg);
$state = mm_state($planA);
$cfg2 = mm_config_from_state($state, mm_scan($root));
$planB = mm_plan(mm_scan($root), $cfg2);
$listing = function (array $cfg) use ($root) {
    mm_apply($root, $cfg);
    $site = unraid_site($root);
    $out = [];
    foreach (['Tools', 'Settings', 'Tasks', 'DiskUtilities', 'SystemInformation', 'Utilities'] as $m) $out[$m] = unraid_names($site, $m);
    $titles = array_map(fn($p) => $p['Title'] ?? '', $site);
    mm_revert($root);
    return [$out, $titles];
};
check('state -> config -> plan lists the same menus as the original config', $listing($cfg) === $listing($cfg2));
check('the state lists tiles in menu order', array_column($state['roots']['Tools'][0]['tiles'] ?? [], 'id') !== []);

$state['roots']['Tools'][] = ['id' => 'new1', 'title' => 'My Stuff', 'hidden' => false, 'custom' => true, 'tag' => 'star', 'tiles' => [['id' => 'BarSettings', 'title' => 'Bar Settings']]];
$cfg3 = mm_config_from_state($state, mm_scan($root));
check('a new category gets a stable id', isset($cfg3['custom']['MM_MyStuff']) && $cfg3['layout']['MM_MyStuff'] === ['BarSettings']);
mm_apply($root, $cfg3);
check('and it shows up', unraid_names(unraid_site($root), 'MM_MyStuff') === ['BarSettings']);

/* ---- saving without changing anything writes nothing ---- */
mm_revert($root);
check('fixture is pristine again', mm_snapshot($root) === $pristine);
$untouched = mm_config_from_state(mm_state(mm_plan(mm_scan($root), mm_default_config())), mm_scan($root));
check('an unchanged state produces an empty layout', $untouched['layout'] === [] && $untouched['groupOrder'] === [] && $untouched['hidden'] === []);
check('so applying it changes no file', mm_apply($root, $untouched)['changed'] === []);
// only the category that was reordered gets pinned, the indirect tiles elsewhere keep following their setting
$state = mm_state(mm_plan(mm_scan($root), mm_default_config()));
foreach ($state['roots']['Tools'] as &$g) if ($g['id'] === 'DiskUtilities') $g['tiles'] = array_reverse($g['tiles']);
unset($g);
$cfg = mm_config_from_state($state, mm_scan($root));
check('only the reordered category is in the layout', array_keys($cfg['layout']) === ['DiskUtilities']);
mm_apply($root, $cfg);
check('indirect tiles in other categories are left alone', file_get_contents("$root/plugins/baz/BazVar.page") === $pristine['/plugins/baz/BazVar.page']
    && file_get_contents("$root/plugins/baz/BazDyn.page") === $pristine['/plugins/baz/BazDyn.page']);
mm_revert($root);

/* ---- config files ---- */
check('a missing or corrupt config is just the defaults', mm_load_config("$root/nope.json") === mm_default_config());
file_put_contents($cfgFile, '{not json');
check('corrupt json is survivable', mm_load_config($cfgFile) === mm_default_config());
$weird = mm_normalize_config(['layout' => ['../x' => ['a']], 'hidden' => ['ok', '../bad', 5], 'hub' => ['rank' => 'abc', 'code' => 'zz', 'title' => '  ']]);
check('config values are sanitised', $weird['layout'] === [] && $weird['hidden'] === ['ok'] && $weird['hub']['rank'] === '88' && $weird['hub']['code'] === 'e909' && $weird['hub']['title'] === MM_HUB_DEFAULT_TITLE);

mm_rmtree($root);
finish('plan_test');
