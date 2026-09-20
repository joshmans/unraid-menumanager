# Menu Manager

## 2026.09.20

- The plugin now names the same update URL that its Community Apps listing uses (raw.githubusercontent.com), so Community Apps recognises an installed copy and shows it as installed. No other change.

## 2026.09.19f

- Fixed the once-a-minute re-apply not being scheduled after a first install. Unraid registers a plugin only after its install script has run, so the schedule is now built once that has happened, and again when the array starts.

## 2026.09.19e

- Fixed uninstalling then reinstalling the same version before a reboot doing nothing. The package is now removed from Unraid's installed list on uninstall.
- The package is checked with SHA-256 instead of MD5.

## 2026.09.19d

First release.

- Reorder tiles and categories on the Tools and Settings pages, and drag tiles between categories.
- Rename categories and tiles, and create your own categories.
- Move a tile or a whole category between Tools and Settings.
- Hide tiles or categories you never use.
- Optional unified page in the main menu that lists every category from Tools and Settings, with a filter box, and an option to hide the built-in Tools and Settings menus.
- Pages that pick their category from a setting (`Menu="/file KEY=default"` or `$var`) can be moved and hidden too; pages whose setting points nowhere appear under "Not in a category".
- The unified page has quick actions at the top: **Page Settings** (a link to Menu Manager) and **Disable this page**, which also brings back the built-in Tools and Settings menus if they were hidden.
- The unified page is labelled by its name in the main menu (it used to show its internal file name).
- Save & apply and Reset to defaults are repeated at the bottom of the settings page.
- Every change is reversible: original page headers are kept in the files and "Reset to defaults" (or `apply.php revert`) restores them exactly.
