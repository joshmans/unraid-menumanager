<?php
/* Turn the user's saved layout into concrete header edits.
 *
 * The saved config is declarative ("this category holds these tiles in this
 * order"), and the plan is always computed from the pristine headers, so
 * running it twice gives the same answer and removing an entry from the
 * config puts the original back. */

require_once __DIR__ . '/model.php';

const MM_HUB_DEFAULT_TITLE = 'Control Center';

function mm_default_config(): array {
    return [
        'version'    => 1,
        'groupRoot'  => [],   // native category -> "Tools" | "Settings"
        'groupOrder' => [],   // root -> [category, ...]
        'layout'     => [],   // category -> [tile, ...]
        'titles'     => [],   // page -> replacement title
        'hidden'     => [],   // [page, ...] hidden from every menu
        'custom'     => [],   // "MM_x" -> [title, root, tag]
        'hub'        => [
            'enabled' => false, 'title' => MM_HUB_DEFAULT_TITLE, 'rank' => '88',
            'code' => 'e909', 'hideBuiltin' => false,
        ],
    ];
}

function mm_is_id(mixed $v): bool {
    return is_string($v) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $v) === 1;
}

function mm_str_list(mixed $v): array {
    $out = [];
    foreach ((array)$v as $x) if (mm_is_id($x) && !in_array($x, $out, true)) $out[] = $x;
    return $out;
}

/** Coerce anything (hand-edited JSON, an old version) into a usable config. */
function mm_normalize_config(mixed $in): array {
    $c = mm_default_config();
    if (!is_array($in)) return $c;
    foreach ((array)($in['groupRoot'] ?? []) as $g => $r) {
        if (mm_is_id($g) && in_array($r, MM_ROOTS, true)) $c['groupRoot'][$g] = $r;
    }
    foreach ((array)($in['groupOrder'] ?? []) as $r => $list) {
        if (in_array($r, MM_ROOTS, true)) $c['groupOrder'][$r] = mm_str_list($list);
    }
    foreach ((array)($in['layout'] ?? []) as $g => $list) {
        if (mm_is_id($g)) $c['layout'][$g] = mm_str_list($list);
    }
    foreach ((array)($in['titles'] ?? []) as $p => $t) {
        $t = is_string($t) ? mm_clean_value($t) : '';
        if (mm_is_id($p) && $t !== '') $c['titles'][$p] = $t;
    }
    $c['hidden'] = mm_str_list($in['hidden'] ?? []);
    foreach ((array)($in['custom'] ?? []) as $id => $g) {
        if (!is_string($id) || !preg_match('/^MM_[A-Za-z0-9_]{1,40}$/', $id) || !is_array($g)) continue;
        $title = mm_clean_value((string)($g['title'] ?? ''));
        $root  = in_array($g['root'] ?? '', MM_ROOTS, true) ? $g['root'] : 'Tools';
        $tag   = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($g['tag'] ?? '')) ?: 'th-large';
        if ($title !== '') $c['custom'][$id] = ['title' => $title, 'root' => $root, 'tag' => $tag];
    }
    $h = (array)($in['hub'] ?? []);
    $c['hub']['enabled']     = !empty($h['enabled']);
    $c['hub']['hideBuiltin'] = !empty($h['hideBuiltin']);
    $title = mm_clean_value((string)($h['title'] ?? ''));
    if ($title !== '') $c['hub']['title'] = $title;
    $rank = preg_replace('/\D/', '', (string)($h['rank'] ?? ''));
    if ($rank !== '') $c['hub']['rank'] = $rank;
    $code = preg_replace('/[^0-9a-fA-F]/', '', (string)($h['code'] ?? ''));
    if ($code !== '') $c['hub']['code'] = strtolower($code);
    return $c;
}

function mm_load_config(string $file): array {
    $j = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return mm_normalize_config($j);
}

function mm_write_atomic(string $file, string $data): bool {
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) return false;
    $tmp = $file . '.tmp' . getmypid();
    if (file_put_contents($tmp, $data) === false) return false;
    if (is_file($file)) @chmod($tmp, fileperms($file) & 0777);
    return rename($tmp, $file);
}

function mm_save_config(string $file, array $cfg): bool {
    return mm_write_atomic($file, json_encode(mm_normalize_config($cfg), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function mm_menu_value(string $group, string $rank): string {
    return $rank === '' ? $group : "$group:$rank";
}

/** Compute the whole picture: what every category and tile should look like,
 *  the header edits that get there, and any request we had to refuse. */
function mm_plan(array $pages, array $cfg): array {
    $cfg = mm_normalize_config($cfg);
    $model = mm_model($pages);
    $warn = [];

    $groups = [];
    foreach ($model['groups'] as $id => $g) {
        $groups[$id] = $g + ['custom' => false, 'pristineTitle' => $g['title']];
        if (isset($cfg['groupRoot'][$id])) $groups[$id]['root'] = $cfg['groupRoot'][$id];
    }
    foreach ($cfg['custom'] as $id => $c) {
        if (isset($groups[$id])) { $warn[] = "custom category $id clashes with an existing page"; continue; }
        $groups[$id] = ['name' => $id, 'title' => $c['title'], 'root' => $c['root'], 'rank' => '',
                        'plugin' => MM_PLUGIN, 'menu' => '', 'tag' => $c['tag'], 'custom' => true,
                        'pristineTitle' => $c['title']];
    }
    // category order inside each root
    foreach (MM_ROOTS as $root) {
        $i = 0;
        foreach ($cfg['groupOrder'][$root] ?? [] as $id) {
            if (isset($groups[$id]) && $groups[$id]['root'] === $root && !isset($groups[$id]['ordered'])) {
                $groups[$id]['rank'] = (string)(++$i * 10);
                $groups[$id]['ordered'] = true;
            }
        }
    }

    $tiles = $model['tiles'];
    $placed = [];
    foreach ($cfg['layout'] as $gid => $list) {
        if (!isset($groups[$gid])) { $warn[] = "no category '$gid' to place tiles in"; continue; }
        $i = 0;
        foreach ($list as $name) {
            if (isset($placed[$name])) { $warn[] = "'$name' is listed in two categories, keeping the first"; continue; }
            if (!isset($tiles[$name])) {
                if (isset($pages[$name]) && !isset($groups[$name]) && !mm_is_own_internal($name)) {
                    $warn[] = "'$name' isn't a tile in a category (tabs, dashboard widgets and buttons can't be moved)";
                }
                continue;   // a plugin that was uninstalled since the layout was saved
            }
            $placed[$name] = true;
            $tiles[$name]['group'] = $gid;
            $tiles[$name]['rank'] = (string)(++$i * 10);
        }
    }

    $hidden = array_flip($cfg['hidden']);
    $edits = [];
    $intent = function (string $name, string $menu, string $pristineMenu, string $hiddenMenu, string $title, string $pristineTitle)
        use (&$edits, $hidden, $cfg) {
        $e = [];
        if (isset($hidden[$name])) $e['Menu'] = $hiddenMenu;
        elseif ($menu !== $pristineMenu) $e['Menu'] = $menu;
        if (isset($cfg['titles'][$name]) && $cfg['titles'][$name] !== $pristineTitle) $e['Title'] = $cfg['titles'][$name];
        if ($e) $edits[$name] = $e;
    };

    foreach ($groups as $id => &$g) {
        $g['hidden'] = isset($hidden[$id]);
        $g['title']  = $cfg['titles'][$id] ?? $g['title'];
        $g['menu']   = $g['menu'] === '' && $g['custom'] ? mm_menu_value($g['root'], $g['rank']) : $g['menu'];
        if (!$g['custom']) {
            $want = mm_menu_value($g['root'], $g['rank']);
            $intent($id, $want, $g['menu'], '', $g['title'], $g['pristineTitle']);
            $g['menu'] = $want;
        }
    }
    unset($g);
    foreach ($tiles as $id => &$t) {
        $t['hidden'] = isset($hidden[$id]);
        $pristineTitle = $t['title'];
        $t['title'] = $cfg['titles'][$id] ?? $t['title'];
        $want = $t['menu'];   // untouched unless the layout placed it somewhere new
        if ($t['group'] !== null) {
            $token = mm_menu_value($t['group'], $t['rank']);
            $now = $t['info']['tokens'][$t['info']['index']] ?? null;
            if ($token !== $now) $want = mm_menu_rebuild($t['info'], $token);
        }
        $intent($id, $want, $t['menu'], mm_menu_rebuild($t['info'], null), $t['title'], $pristineTitle);
    }
    unset($t);

    // the unified page
    $hub = $cfg['hub'];
    if (isset($pages[MM_HUB_PAGE])) {
        $pristine = $pages[MM_HUB_PAGE]['header'];
        $e = [];
        $want = $hub['enabled'] ? 'Tasks:' . $hub['rank'] : '';
        if ($want !== (string)($pristine['Menu'] ?? '')) $e['Menu'] = $want;
        if ($hub['title'] !== (string)($pristine['Title'] ?? '')) $e['Title'] = $hub['title'];
        if ($hub['code'] !== (string)($pristine['Code'] ?? '')) $e['Code'] = $hub['code'];
        if ($e) $edits[MM_HUB_PAGE] = $e;
    }
    if ($hub['hideBuiltin']) {
        if ($hub['enabled'] && isset($pages[MM_HUB_PAGE])) {
            foreach (MM_ROOTS as $root) if (isset($pages[$root])) $edits[$root] = ['Menu' => ''];
        } else {
            $warn[] = "the built-in Tools and Settings menus stay visible until the unified page is enabled";
        }
    }

    $generated = [];
    foreach ($groups as $id => $g) {
        if (!$g['custom']) continue;
        $menu = isset($hidden[$id]) ? '' : mm_menu_value($g['root'], $g['rank']);
        $generated["$id.page"] = 'Menu="' . mm_clean_value($menu) . "\"\n"
            . 'Title="' . mm_clean_value($g['title']) . "\"\n"
            . 'Tag="' . $g['tag'] . "\"\n"
            . "Type=\"menu\"\n";
    }

    return ['config' => $cfg, 'groups' => $groups, 'tiles' => $tiles, 'edits' => $edits,
            'generated' => $generated, 'warnings' => $warn, 'model' => $model];
}

/** What the settings page draws: roots -> categories -> tiles, in menu order. */
function mm_state(array $plan): array {
    $roots = [];
    foreach (MM_ROOTS as $r) $roots[$r] = [];
    $byGroup = [];
    $unplaced = [];
    foreach ($plan['tiles'] as $t) {
        if ($t['group'] === null) $unplaced[] = $t;
        else $byGroup[$t['group']][] = $t;
    }
    $tileState = fn($t) => ['id' => $t['name'], 'title' => $t['title'], 'hidden' => $t['hidden'], 'plugin' => $t['plugin'],
                            'indirect' => $t['info']['indirect'] ?? null];
    $groups = mm_natsort(array_values($plan['groups']), fn($g) => mm_sort_key($g['rank'], $g['name']));
    foreach ($groups as $g) {
        $tiles = array_map($tileState, mm_natsort($byGroup[$g['name']] ?? [], fn($t) => mm_sort_key($t['rank'], $t['name'])));
        $roots[$g['root']][] = ['id' => $g['name'], 'title' => $g['title'], 'hidden' => $g['hidden'],
                                'custom' => $g['custom'], 'tag' => $g['tag'], 'plugin' => $g['plugin'], 'tiles' => $tiles];
    }
    return ['roots' => $roots, 'unplaced' => array_map($tileState, $unplaced), 'hub' => $plan['config']['hub']];
}

/** The inverse of mm_state(): the settings page posts its whole state back. */
function mm_config_from_state(array $state, array $pages): array {
    $model = mm_model($pages);
    $cfg = mm_default_config();
    $used = [];
    foreach ((array)($state['roots'] ?? []) as $root => $groups) {
        if (!in_array($root, MM_ROOTS, true)) continue;
        $cfg['groupOrder'][$root] = [];
        foreach ((array)$groups as $g) {
            $id = (string)($g['id'] ?? '');
            $title = mm_clean_value((string)($g['title'] ?? ''));
            if (!empty($g['custom'])) {
                if (!preg_match('/^MM_[A-Za-z0-9_]{1,40}$/', $id) || isset($used[$id])) {
                    $slug = preg_replace('/[^A-Za-z0-9]/', '', ucwords($title)) ?: 'Category';
                    $id = 'MM_' . substr($slug, 0, 30);
                    for ($n = 2; isset($used[$id]) || isset($model['groups'][$id]); $n++) $id = 'MM_' . substr($slug, 0, 28) . $n;
                }
                $cfg['custom'][$id] = ['title' => $title ?: 'New category', 'root' => $root,
                                        'tag' => (string)($g['tag'] ?? 'th-large')];
            } elseif (isset($model['groups'][$id])) {
                if ($model['groups'][$id]['root'] !== $root) $cfg['groupRoot'][$id] = $root;
                if ($title !== '' && $title !== $model['groups'][$id]['title']) $cfg['titles'][$id] = $title;
            } else {
                continue;
            }
            $used[$id] = true;
            $cfg['groupOrder'][$root][] = $id;
            if (!empty($g['hidden'])) $cfg['hidden'][] = $id;
            $cfg['layout'][$id] = [];
            foreach ((array)($g['tiles'] ?? []) as $t) {
                $tid = (string)($t['id'] ?? '');
                if (!isset($model['tiles'][$tid])) continue;
                $cfg['layout'][$id][] = $tid;
                $ttitle = mm_clean_value((string)($t['title'] ?? ''));
                if ($ttitle !== '' && $ttitle !== $model['tiles'][$tid]['title']) $cfg['titles'][$tid] = $ttitle;
                if (!empty($t['hidden'])) $cfg['hidden'][] = $tid;
            }
        }
    }
    // Only keep an order that differs from what Unraid would list anyway, so a
    // save never rewrites (or pins) pages in categories the user left alone.
    $natural = [];
    foreach ($model['tiles'] as $t) if ($t['group'] !== null) $natural[$t['group']][] = $t;
    foreach ($cfg['layout'] as $gid => $list) {
        $sorted = array_column(mm_natsort($natural[$gid] ?? [], fn($t) => mm_sort_key($t['rank'], $t['name'])), 'name');
        if ($list === $sorted) unset($cfg['layout'][$gid]);
    }
    foreach (MM_ROOTS as $root) {
        $groups = array_filter($model['groups'], fn($g) => $g['root'] === $root);
        $sorted = array_column(mm_natsort(array_values($groups), fn($g) => mm_sort_key($g['rank'], $g['name'])), 'name');
        if (($cfg['groupOrder'][$root] ?? null) === $sorted) unset($cfg['groupOrder'][$root]);
    }
    foreach ((array)($state['unplaced'] ?? []) as $t) {   // never placed, but renaming and hiding still apply
        $tid = (string)($t['id'] ?? '');
        if (!isset($model['tiles'][$tid])) continue;
        $ttitle = mm_clean_value((string)($t['title'] ?? ''));
        if ($ttitle !== '' && $ttitle !== $model['tiles'][$tid]['title']) $cfg['titles'][$tid] = $ttitle;
        if (!empty($t['hidden'])) $cfg['hidden'][] = $tid;
    }
    $cfg['hub'] = (array)($state['hub'] ?? []);
    return mm_normalize_config($cfg);
}
