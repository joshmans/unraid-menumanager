# How it works

## What Unraid does

Every request, `template.php` builds `$site` by reading the header of `webGui/*.page` and then `plugins/*/*.page` (a later page with the same file name replaces an earlier one). A page's `Menu=` header decides where it is listed, and `find_pages($item)` returns the pages whose `Menu=` names `$item`, sorted with a natural `ksort` on `rank + name`.

- `Tools.page` and `Settings.page` are `Type="xmenu"` pages in the main menu (`Menu="Tasks:90"`, `Menu="Tasks:4"`).
- A **category** is a page with `Type="menu"` and `Menu="Tools"` or `Menu="Settings"`. Its **file name** (not its title) is what tiles put in their own `Menu=` to join it.
- A **tile** is any page whose `Menu=` names a category, optionally with a rank (`Menu="DiskUtilities:20"`).
- A page can list several menus separated by spaces. Only the first token may be indirect (`$var`, or `/file KEY=default`).
- Nothing is cached, so editing a header takes effect on the next page load. The links use the root you are browsing (`/Tools/Name`, `/Settings/Name`); a page resolves by its last URL segment.

## What we do

The config (`/boot/config/plugins/menumanager/layout.json`) is declarative: for each category the ordered list of tiles, the order of categories in each root, renames, hidden pages, your own categories, and the unified-page options.

`mm_plan()` computes header edits from the **pristine** headers, so it is idempotent and deleting an entry restores the original. Edits touch only `Menu=` (one token of it for multi-menu pages), `Title=`, and, for the unified page, `Code=`. Before a line changes, the original is saved in an `;mm-orig:` ini comment in the same file, which `mm_pristine()` undoes byte for byte.

- **Move / reorder:** rewrite `Menu=` to `Category:rank` (ranks are 10, 20, 30…).
- **Swap Tools/Settings:** move the *category* page's `Menu=` to the other root. Its tiles follow.
- **Your own category:** a generated `MM_<Name>.page` (`Type="menu"`) in our plugin folder.
- **Hide:** an empty `Menu=` (`find_pages` skips those); the page still resolves by URL.
- **Unified page:** `MenuManagerHub.page` ships with an empty `Menu=`. Enabling it sets `Menu="Tasks:<rank>"` and lists the live `find_pages()` tree. Hiding the built-ins sets `Menu=""` on `Tools`/`Settings`, and is refused unless the unified page is on.

`/usr/local/emhttp` is RAM, so the plan is re-applied at install, on the `started` event and from cron every minute. `apply` only writes files whose content would change.

## Files

| Path | Role |
|---|---|
| `include/header.php` | split, parse and reversibly edit a page header |
| `include/model.php` | scan page files, find categories and tiles |
| `include/plan.php` | config <-> plan <-> UI state |
| `include/apply.php`, `scripts/apply.php` | write the plan, revert, CLI |
| `include/api.php` | JSON endpoint for the settings page |
| `include/hub.php`, `MenuManagerHub.page` | the unified page |
| `MenuManager.page`, `include/editor.php`, `js/`, `css/` | the settings page |
| `tests/` | `lib.php` copies the relevant part of `PageBuilder.php` so the tests assert Unraid's own reading of the files |
