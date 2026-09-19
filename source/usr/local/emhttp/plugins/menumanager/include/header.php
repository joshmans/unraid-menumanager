<?php
/* .page header editing.
 *
 * Unraid rebuilds its menus on every request by reading the header of each
 * plugin .page file (see webgui's PageBuilder.php), so rewriting a header
 * moves a tile immediately. Everything here works on the raw file text and is
 * reversible: before a line is changed its original is stashed in a ";mm-orig:"
 * comment (ini comments are ignored by parse_ini_string), so pristine() gives
 * back the exact original bytes even after a reboot-less plugin update. */

const MM_SEP  = "\n---\n";
const MM_MARK = ';mm-orig:';

/** Split a page file into [header, rest]. Rest keeps the "---" separator. A
 *  file with no separator is all header (Unraid treats it the same way). */
function mm_split(string $raw): array {
    $i = strpos($raw, MM_SEP);
    return $i === false ? [$raw, ''] : [substr($raw, 0, $i), substr($raw, $i)];
}

/** Parse a header the way Unraid does (default ini scanner). Bad -> []. */
function mm_parse_header(string $header): array {
    $p = @parse_ini_string($header);
    return is_array($p) ? $p : [];
}

/** Header values may not carry characters that would break the ini line. */
function mm_clean_value(string $v): string {
    return trim(str_replace(['"', "\r", "\n"], '', $v));
}

function mm_lines(string $header): array {
    $lines = explode("\n", $header);
    $trail = end($lines) === '';
    if ($trail) array_pop($lines);
    return [$lines, $trail];
}

function mm_join(array $lines, bool $trail): string {
    return implode("\n", $lines) . ($trail ? "\n" : '');
}

/** The file as Unraid's plugin author shipped it: every mm-orig marker is
 *  undone, byte for byte. */
function mm_pristine(string $raw): string {
    [$h, $rest] = mm_split($raw);
    [$lines, $trail] = mm_lines($h);
    $orig = [];
    $kept = [];
    foreach ($lines as $l) {
        if (strncmp($l, MM_MARK, strlen(MM_MARK)) === 0) {
            $kv = explode('=', substr($l, strlen(MM_MARK)), 2);
            if (count($kv) === 2) $orig[$kv[0]] = json_decode($kv[1]);
            continue;
        }
        $kept[] = $l;
    }
    if (!$orig) return $raw;
    $out = [];
    foreach ($kept as $l) {
        if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s*=/', $l, $m) && array_key_exists($m[1], $orig)) {
            $o = $orig[$m[1]];
            unset($orig[$m[1]]);
            if ($o !== null) $out[] = $o;   // null: key did not exist, drop the line we added
            continue;
        }
        $out[] = $l;
    }
    return mm_join($out, $trail) . $rest;
}

/** Apply key => value edits to the pristine version of a file. Empty edits
 *  give the pristine file back. */
function mm_apply_edits(string $raw, array $edits): string {
    $raw = mm_pristine($raw);
    if (!$edits) return $raw;
    [$h, $rest] = mm_split($raw);
    [$lines, $trail] = mm_lines($h);
    $marks = [];
    foreach ($edits as $key => $value) {
        $new = $key . '="' . mm_clean_value((string)$value) . '"';
        $found = false;
        foreach ($lines as $i => $l) {
            if (preg_match('/^' . preg_quote($key, '/') . '\s*=/', $l)) {
                $marks[] = MM_MARK . $key . '=' . json_encode($l);
                $lines[$i] = $new;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $marks[] = MM_MARK . $key . '=null';
            $lines[] = $new;
        }
    }
    return mm_join(array_merge($lines, $marks), $trail) . $rest;
}
