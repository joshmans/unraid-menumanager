<?php
/* The unified page: every category from Tools and Settings on one screen,
 * built from the live menu (find_pages) so it always matches what the layout
 * actually is. Hidden tiles have an empty Menu and never show up here. */

function mm_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

function mm_hub_title(string $raw): string {
    $t = preg_replace('/_\((.*?)\)_/', '$1', $raw);
    return function_exists('processTitle') ? processTitle($t) : mm_h($t);
}

function mm_hub_icon(array $pg): string {
    global $docroot, $defaultIcon;
    $default = $defaultIcon ?? '<i class="icon-app PanelIcon"></i>';
    $icon = $pg['Icon'] ?? $default;
    if (function_exists('process_icon') && $docroot) return process_icon($icon, $docroot, $pg['root']);
    return $icon[0] === '<' ? $icon : '<i class="fa fa-th PanelIcon"></i>';
}

function mm_render_hub(): void {
    if (!function_exists('find_pages')) { echo '<p>The unified page needs the Unraid page builder.</p>'; return; }
    $title = (string)($GLOBALS['myPage']['Title'] ?? 'this page');
    $csrf = (string)($GLOBALS['var']['csrf_token'] ?? '');
    $api = '/plugins/menumanager/include/api.php';
    echo '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:0 0 12px">'
       . '<input type="search" id="mm-hub-filter" placeholder="Filter…" style="margin:0;max-width:280px">'
       . '<span style="margin-left:auto;display:inline-flex;gap:12px;align-items:center">'
       . '<a href="/Settings/MenuManager" id="mm-hub-settings"><i class="fa fa-cog"></i> Page Settings</a>'
       . '<button type="button" id="mm-hub-disable" style="margin:0" data-title="' . mm_h($title) . '" data-api="' . mm_h($api) . '" data-csrf="' . mm_h($csrf) . '">Disable this page</button>'
       . '</span></div>';
    echo '<script>document.getElementById("mm-hub-disable").addEventListener("click",function(){'
       . 'var b=this;if(!confirm("Disable "+b.dataset.title+"? The built-in Tools and Settings menus come back if they were hidden. Your layout is kept."))return;'
       . 'fetch(b.dataset.api,{method:"POST",credentials:"same-origin",body:new URLSearchParams({action:"disable_hub",csrf_token:b.dataset.csrf})})'
       . '.then(function(r){if(!r.ok)throw new Error(r.statusText);location.href="/Tools";})'
       . '.catch(function(e){alert("Could not disable: "+e.message);});});</script>';
    $shown = 0;
    foreach (['Tools', 'Settings'] as $root) {
        foreach (find_pages($root) as $group) {
            if (strtolower((string)($group['Type'] ?? '')) !== 'menu') continue;
            $tiles = find_pages($group['name']);
            if (!$tiles) continue;
            $shown++;
            echo '<div class="mm-hub-group"><div class="title"><span class="left"><i class="fa fa-th title"></i>'
               . mm_hub_title((string)($group['Title'] ?? $group['name'])) . '</span></div><div class="Panels">';
            foreach ($tiles as $pg) {
                $title = mm_hub_title((string)($pg['Title'] ?? $pg['name']));
                echo '<div class="Panel"><a href="/' . mm_h($root) . '/' . mm_h($pg['name']) . '"><span>' . mm_hub_icon($pg)
                   . '</span><div class="PanelText">' . (function_exists('_') ? _($title) : $title) . '</div></a></div>';
            }
            echo '</div></div>';
        }
    }
    if (!$shown) echo '<p>Nothing to show yet.</p>';
    echo '<script>document.getElementById("mm-hub-filter").addEventListener("input",function(){'
       . 'var q=this.value.toLowerCase();document.querySelectorAll(".mm-hub-group").forEach(function(g){var any=false;'
       . 'g.querySelectorAll(".Panel").forEach(function(p){var m=p.textContent.toLowerCase().indexOf(q)>-1;p.style.display=m?"":"none";any=any||m;});'
       . 'g.style.display=any?"":"none";});});</script>';
}
