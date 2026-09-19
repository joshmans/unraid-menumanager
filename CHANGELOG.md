# Menu Manager

## Unreleased

First scaffold.

- Reorder tiles and categories on the Tools and Settings pages, and drag tiles between categories.
- Rename categories and tiles, and create your own categories.
- Move a tile or a whole category between Tools and Settings.
- Hide tiles or categories you never use.
- Optional unified page in the main menu that lists every category from Tools and Settings, with a filter box, and an option to hide the built-in Tools and Settings menus.
- Pages that pick their category from a setting (`Menu="/file KEY=default"` or `$var`) can be moved and hidden too; pages whose setting points nowhere appear under "Not in a category".
- Every change is reversible: original page headers are kept in the files and "Reset to defaults" (or `apply.php revert`) restores them exactly.
