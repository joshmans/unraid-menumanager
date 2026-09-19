<?php
/* Discover every .page file and work out which are categories ("groups")
 * and which are tiles inside them, mirroring what webgui's find_pages() does. */

require_once __DIR__ . '/header.php';

const MM_ROOTS    = ['Tools', 'Settings'];
const MM_PLUGIN   = 'menumanager';
const MM_HUB_PAGE = 'MenuManagerHub';

/** "Group:rank" -> [group, rank]. Null for anything that is not a single
 *  plain token (several menus, "$var" or "/file KEY" indirection) because
 *  those are not ours to guess at. */
function mm_menu_parts(string $menu): ?array {
    $menu = trim($menu);
    if ($menu === '' || preg_match('/\s/', $menu) || $menu[0] === '$' || $menu[0] === '/') return null;
    $p = explode(':', $menu, 2);
    return [$p[0], $p[1] ?? ''];
}

/** parse_ini_file, replaceable so the tests don't need a real /boot */
function mm_read_ini(string $file) {
    $hook = $GLOBALS['mm_read_ini_hook'] ?? null;
    return $hook ? $hook($file) : @parse_ini_file($file);
}

/** Split a Menu value into tokens and resolve an indirect first token the way
 *  webgui's find_pages() does:
 *    "/path/file.cfg KEY=default"  reads KEY from the ini file
 *    "$display[x] default"         evaluates a PHP variable (we can't, so the
 *                                  default is used and 'indirect' is "var")
 *  The two tokens of an indirect spec become one resolved token; whatever
 *  follows is kept. */
function mm_resolve_menu(string $menu): array {
    $raw = preg_split('/\s+/', trim($menu), -1, PREG_SPLIT_NO_EMPTY);
    $out = ['raw' => $raw, 'tokens' => $raw, 'indirect' => null];
    if (!$raw || ($raw[0][0] !== '$' && $raw[0][0] !== '/')) return $out;
    $default = $raw[1] ?? '';
    if ($raw[0][0] === '/') {
        $kv = explode('=', $default, 2);
        $vars = mm_read_ini($raw[0]);
        $value = is_array($vars) && isset($vars[$kv[0]]) ? (string)$vars[$kv[0]] : ($kv[1] ?? '');
        $kind = 'file';
    } else {
        $value = $default;
        $kind = 'var';
    }
    $out['tokens'] = array_merge([$value], array_slice($raw, 2));   // an empty value keeps its slot so indexes still line up with $raw
    $out['indirect'] = $kind;
    return $out;
}

/** Which token of a page's Menu names one of our categories. Pages that name
 *  none are not tiles, except indirect ones, which come back with group null
 *  ("not in a category") so the user can still place them. */
function mm_find_token(string $menu, array $groups): ?array {
    $r = mm_resolve_menu($menu);
    foreach ($r['tokens'] as $i => $tok) {
        $p = explode(':', $tok, 2);
        if (isset($groups[$p[0]])) return $r + ['index' => $i, 'group' => $p[0], 'rank' => $p[1] ?? ''];
    }
    return $r['indirect'] ? $r + ['index' => -1, 'group' => null, 'rank' => ''] : null;
}

/** Swap (or drop, with null) the category token of a page's Menu. An indirect
 *  spec that gets replaced is replaced whole: the tile then no longer follows
 *  the plugin's own setting until the layout is reset. */
function mm_menu_rebuild(array $info, ?string $new): string {
    $raw = $info['raw'];
    $i = $info['index'];
    if ($info['indirect'] && $i <= 0) {
        $rest = array_slice($raw, 2);
        return implode(' ', $new === null ? $rest : array_merge([$new], $rest));
    }
    $at = $info['indirect'] ? $i + 1 : $i;
    if ($new === null) unset($raw[$at]);
    else $raw[$at] = $new;
    return implode(' ', $raw);
}

/** The order Unraid lists a menu's children in: ksort(NATURAL) on rank.name */
function mm_sort_key(string $rank, string $name): string { return $rank . $name; }

function mm_natsort(array $items, callable $key): array {
    usort($items, fn($a, $b) => strnatcmp($key($a), $key($b)));
    return $items;
}

/** _(Translated)_ markers are for Unraid, not for people reading a list */
function mm_display_title(string $t): string {
    return trim(preg_replace('/_\((.*?)\)_/', '$1', $t));
}

/** Own pages the model must not treat as tiles */
function mm_is_own_internal(string $name): bool {
    return $name === MM_HUB_PAGE || strncmp($name, 'MM_', 3) === 0;
}

/** Read every page file the way template.php does: dynamix first, then the
 *  other plugin folders in glob order; a later page with the same name wins. */
function mm_scan(string $emhttp): array {
    $dirs = glob("$emhttp/plugins/*", GLOB_ONLYDIR) ?: [];
    usort($dirs, fn($a, $b) => (basename($a) === 'dynamix' ? 0 : 1) <=> (basename($b) === 'dynamix' ? 0 : 1));
    $pages = [];
    foreach ($dirs as $dir) {
        foreach (glob("$dir/*.page") ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) continue;
            $pristine = mm_pristine($raw);
            $header = mm_parse_header(mm_split($pristine)[0]);
            if (!$header) continue;
            $name = basename($file, '.page');
            $pages[$name] = [
                'name'   => $name,
                'file'   => $file,
                'plugin' => basename($dir),
                'raw'    => $raw,
                'header' => $header,
            ];
        }
    }
    return $pages;
}

/** Native categories and the tiles inside them, from the pristine headers. */
function mm_model(array $pages): array {
    $groups = [];
    foreach ($pages as $name => $p) {
        if (mm_is_own_internal($name)) continue;
        $h = $p['header'];
        $m = mm_menu_parts((string)($h['Menu'] ?? ''));
        if ($m && strtolower((string)($h['Type'] ?? '')) === 'menu' && in_array($m[0], MM_ROOTS, true)) {
            $groups[$name] = [
                'name' => $name, 'title' => mm_display_title((string)($h['Title'] ?? $name)),
                'root' => $m[0], 'rank' => $m[1], 'plugin' => $p['plugin'],
                'menu' => (string)$h['Menu'], 'tag' => (string)($h['Tag'] ?? ''),
            ];
        }
    }
    $tiles = [];
    foreach ($pages as $name => $p) {
        if (mm_is_own_internal($name) || isset($groups[$name])) continue;
        $h = $p['header'];
        $f = mm_find_token((string)($h['Menu'] ?? ''), $groups);
        if (!$f) continue;
        $tiles[$name] = [
            'name' => $name, 'title' => mm_display_title((string)($h['Title'] ?? $name)),
            'group' => $f['group'], 'rank' => $f['rank'], 'plugin' => $p['plugin'],
            'menu' => (string)$h['Menu'], 'info' => $f,
        ];
    }
    return ['groups' => $groups, 'tiles' => $tiles];
}
