<?php
/* Tiny test kit: check(), a fixture docroot that looks like /usr/local/emhttp,
 * and a copy of the bits of webgui's PageBuilder.php that decide what shows up
 * where, so the tests assert against Unraid's own reading of the files. */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/menumanager/include/apply.php';

$GLOBALS['mm_fail'] = 0;
$GLOBALS['mm_pass'] = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    if ($ok) { $GLOBALS['mm_pass']++; return; }
    $GLOBALS['mm_fail']++;
    echo "FAIL: $label" . ($detail !== '' ? "\n      $detail" : '') . "\n";
}

function finish(string $name): void {
    echo "$name: {$GLOBALS['mm_pass']} passed, {$GLOBALS['mm_fail']} failed\n";
    exit($GLOBALS['mm_fail'] ? 1 : 0);
}

function mm_tmpdir(): string {
    $d = sys_get_temp_dir() . '/mm-test-' . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}

function mm_rmtree(string $d): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

/** A docroot shaped like a small real Unraid install. */
function mm_fixture(): string {
    $root = mm_tmpdir();
    $files = [
        'dynamix/Tools.page'           => "Menu=\"Tasks:90\"\nType=\"xmenu\"\nTabs=\"false\"\nCode=\"e909\"\n---\n<?PHP ?>\n",
        'dynamix/Settings.page'        => "Menu=\"Tasks:4\"\nType=\"xmenu\"\nTabs=\"false\"\nCode=\"e924\"\n---\n<?PHP ?>\n",
        'dynamix/Utilities.page'       => "Menu=\"Settings\"\nTitle=\"User Utilities\"\nType=\"menu\"\nTag=\"cogs\"",
        'dynamix/DiskUtilities.page'   => "Menu=\"Tools\"\nTitle=\"Disk Utilities\"\nType=\"menu\"\nTag=\"hdd-o\"",
        'dynamix/SystemInformation.page' => "Menu=\"Tools\"\nTitle=\"System Information\"\nType=\"menu\"\nTag=\"microchip\"",
        'dynamix/Registration.page'    => "Menu=\"SystemInformation:5\"\nTitle=\"Registration\"\n---\nreg\n",
        'foo/FooSettings.page'         => "Menu=\"Utilities\"\nTitle=\"Foo Settings\"\nIcon=\"foo.png\"\n---\nbody\n",
        'foo/FooTab.page'              => "Menu=\"FooSettings:1\"\nTitle=\"Foo Tab\"\n---\ntab\n",
        'bar/BarTool.page'             => "Menu=\"DiskUtilities\"\nTitle=\"Bar Tool\"\nType=\"xmenu\"\nTabs=\"false\"\n---\nbar\n",
        'bar/BarSettings.page'         => "Menu=\"Utilities\"\nTitle=\"Bar Settings\"\n---\nbar\n",
        'baz/BazDash.page'             => "Menu=\"Dashboard:0\"\n---\nwidget\n",
        'baz/BazMulti.page'            => "Menu=\"Utilities Buttons\"\nTitle=\"Baz Multi\"\n---\nx\n",
        'baz/BazDyn.page'              => "Menu=\"/boot/config/plugins/baz/baz.cfg MENU=Utilities\"\nTitle=\"Baz Dyn\"\n---\nx\n",
        'baz/BazVar.page'              => "Menu=\"\$display[nav] Utilities\"\nTitle=\"Baz Var\"\n---\nx\n",
        'baz/BazNone.page'             => "Menu=\"/boot/config/plugins/baz/none.cfg MENU=Elsewhere\"\nTitle=\"Baz None\"\n---\nx\n",
        'qux/QuxOne.page'              => "Menu=\"DiskUtilities:2\"\nTitle=\"Qux One\"\n---\nx\n",
        'menumanager/MenuManager.page' => "Menu=\"Utilities\"\nTitle=\"Menu Manager\"\n---\nx\n",
        'menumanager/MenuManagerHub.page' => "Menu=\"\"\nName=\"Switchboard\"\nTitle=\"Switchboard\"\nCode=\"e909\"\nType=\"xmenu\"\nTabs=\"false\"\n---\nhub\n",
    ];
    foreach ($files as $rel => $content) {
        $path = "$root/plugins/$rel";
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
    return $root;
}

/** Snapshot of every .page file, for byte-exact comparisons. */
function mm_snapshot(string $root): array {
    $out = [];
    foreach (glob("$root/plugins/*/*.page") as $f) $out[substr($f, strlen($root))] = file_get_contents($f);
    ksort($out);
    return $out;
}

/** webgui's build_pages + find_pages, minus Cond. */
function unraid_site(string $root): array {
    $site = [];
    $dirs = glob("$root/plugins/*", GLOB_ONLYDIR);
    usort($dirs, fn($a, $b) => (basename($a) === 'dynamix' ? 0 : 1) <=> (basename($b) === 'dynamix' ? 0 : 1));
    foreach ($dirs as $dir) {
        foreach (glob("$dir/*.page", GLOB_NOSORT) as $entry) {
            $raw = file_get_contents($entry);
            $i = strpos($raw, "\n---\n");
            $header = $i === false ? $raw : substr($raw, 0, $i);
            $page = @parse_ini_string($header);
            if (!$page) continue;
            $page['name'] = basename($entry, '.page');
            $site[$page['name']] = $page;
        }
    }
    return $site;
}

function unraid_find_pages(array $site, string $item): array {
    $pages = [];
    foreach ($site as $page) {
        if (empty($page['Menu'])) continue;
        $menu = strtok($page['Menu'], ' ');
        switch ($menu[0]) {   // get_ini_key / get_file_key; a $var can't be evaluated here, so its default
            case '$': $menu = strtok(' '); break;
            case '/':
                [$key, $default] = array_pad(explode('=', (string)strtok(' '), 2), 2, null);
                $vars = mm_read_ini($menu);
                $menu = is_array($vars) && isset($vars[$key]) ? $vars[$key] : $default;
                break;
        }
        while ($menu !== false && $menu !== null) {
            $parts = explode(':', $menu, 2);
            $rank = $parts[1] ?? '';
            if ($parts[0] == $item) { $pages["$rank{$page['name']}"] = $page; break; }
            $menu = strtok(' ');
        }
    }
    ksort($pages, SORT_NATURAL);
    return array_values($pages);
}

function unraid_names(array $site, string $item): array {
    return array_map(fn($p) => $p['name'], unraid_find_pages($site, $item));
}
