<?php
require_once __DIR__ . '/lib.php';

$body = "Menu=\"Utilities\"\nTitle=\"Foo\"\nIcon=\"foo.png\"\n---\n<?PHP echo 1; ?>\nmore\n---\nstill body\n";
$noBody = "Menu=\"Settings\"\nTitle=\"User Utilities\"\nType=\"menu\"";
$trailing = "Menu=\"Tools\"\nTitle=\"X\"\nType=\"menu\"\n";

foreach (['with body' => $body, 'no body' => $noBody, 'trailing newline' => $trailing] as $label => $raw) {
    $edited = mm_apply_edits($raw, ['Menu' => 'DiskUtilities:10', 'Title' => 'New', 'Code' => 'e909']);
    check("$label: pristine() undoes every edit byte for byte", mm_pristine($edited) === $raw);
    check("$label: editing twice equals editing once", mm_apply_edits($edited, ['Menu' => 'DiskUtilities:10', 'Title' => 'New', 'Code' => 'e909']) === $edited);
    check("$label: no edits gives the original back", mm_apply_edits($edited, []) === $raw);
    $parsed = mm_parse_header(mm_split($edited)[0]);
    check("$label: header still parses", $parsed !== []);
    check("$label: Menu is replaced", ($parsed['Menu'] ?? '') === 'DiskUtilities:10');
    check("$label: a key that was missing is added", ($parsed['Code'] ?? '') === 'e909');
    check("$label: the markers are not read as keys", !preg_grep('/mm/i', array_keys($parsed)));
    check("$label: an untouched key survives", ($parsed['Type'] ?? $parsed['Icon'] ?? 'menu') !== '');
}

$edited = mm_apply_edits($body, ['Menu' => 'A']);
check('the body is never touched', mm_split($edited)[1] === mm_split($body)[1]);
check('a page with no markers is returned as is', mm_pristine($body) === $body);
check('an empty Menu is written and parses as empty', (mm_parse_header(mm_split(mm_apply_edits($body, ['Menu' => '']))[0])['Menu'] ?? 'x') === '');
check('quotes and newlines cannot break the line', ($p = mm_parse_header(mm_split(mm_apply_edits($body, ['Title' => "A\"B\nC"]))[0])) && $p['Title'] === 'ABC');

// switching from one set of edits to another goes through the original
$a = mm_apply_edits($body, ['Menu' => 'One']);
$b = mm_apply_edits($a, ['Title' => 'Two']);
check('changing edits does not stack markers', mm_pristine($b) === $body && (mm_parse_header(mm_split($b)[0])['Menu'] ?? '') === 'Utilities');

// a Menu line written without quotes still round-trips
$bare = "Menu=Tools\nTitle=Bare\n---\nx\n";
check('unquoted original round-trips', mm_pristine(mm_apply_edits($bare, ['Menu' => 'X'])) === $bare);

finish('header_test');
