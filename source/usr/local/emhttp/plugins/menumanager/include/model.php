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

/** A page may list several menus ("Utilities Buttons"). Find the first token
 *  that names one of our categories so only that token gets edited. Menus that
 *  start with "$var" or "/file KEY=default" are indirect and left alone. */
function mm_find_token(string $menu, array $groups): ?array {
    $menu = trim($menu);
    if ($menu === '' || $menu[0] === '$' || $menu[0] === '/') return null;
    $tokens = preg_split('/\s+/', $menu);
    foreach ($tokens as $i => $tok) {
        $p = explode(':', $tok, 2);
        if (isset($groups[$p[0]])) return ['index' => $i, 'group' => $p[0], 'rank' => $p[1] ?? '', 'tokens' => $tokens];
    }
    return null;
}

/** Swap (or drop, with null) one token of a multi-menu value. */
function mm_menu_rebuild(array $tokens, int $index, ?string $new): string {
    if ($new === null) unset($tokens[$index]);
    else $tokens[$index] = $new;
    return implode(' ', $tokens);
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
            'menu' => (string)$h['Menu'], 'tokens' => $f['tokens'], 'index' => $f['index'],
        ];
    }
    return ['groups' => $groups, 'tiles' => $tiles];
}
