# Menu Manager for Unraid

Choose where every icon on the **Tools** and **Settings** pages lives.

1. **Reorder and organise.** Drag tiles between categories, reorder them, rename categories, and create your own.
2. **Swap between Tools and Settings.** Move a single tile, or a whole category, to the other page. Some plugins land in Settings when they belong in Tools.
3. **Hide and unify.** Hide what you never use and, if you like, show everything on **one unified page** in the main menu (called **Switchboard** by default) with a filter box, and hide the built-in Tools and Settings menus.

Everything is reversible. The plugin never deletes anything: it rewrites the one `Menu=` line of a page header and remembers the original, so **Reset to defaults** puts every file back byte for byte.

> **Status:** tested on an Unraid 7.2+ server through install, uninstall, reset, reinstall and a reboot, and covered by automated tests that use a copy of Unraid's own menu-building code. Every change is reversible (see *If something goes wrong*). Hiding the built-in Tools and Settings menus has had the least real-world use, so read the recovery steps before trying it. Please open an issue if anything behaves oddly.

## Install

In Unraid, go to **Plugins → Install Plugin** and paste:

```
https://raw.githubusercontent.com/joshmans/unraid-menumanager/main/menumanager.plg
```

Then open **Settings → Menu Manager**. Requires Unraid 7.2 or newer.

## Using it

Open **Settings → Menu Manager**. Tools categories are on the left and Settings categories on the right.

- Drag a tile onto another category, or use the arrow menu on the tile. Use the arrows on a category to reorder it, **⇄** to send it to the other page, **✎** to rename, and **hide** to remove it from the menus.
- **Add category** creates one of your own. Delete it and its tiles go back where their plugins put them.
- Tiles tagged **setting** are from plugins that pick their own category from a setting. You can move them like any other tile; that replaces the plugin's setting with a fixed placement until you reset. Tiles under **Not in a category** point at nowhere until you place them.
- Nothing changes until you press **Save & apply**, then reload the page to see the new menus.

### The unified page

Tick **Show the unified page in the main menu** to add one page that lists every category from Tools and Settings with a filter box. Rename it to whatever you like; it defaults to **Switchboard**, and the dropdown suggests *Control Center*, *Launchpad*, *Toolbox*, *Cockpit*, *Bridge* and *Workshop*.

The top of that page has two quick actions: **Page Settings** (a link back to Menu Manager) and **Disable this page**, which turns the unified page off and brings the built-in Tools and Settings menus back if they were hidden. **Hide the built-in Tools and Settings menus** is optional and is refused unless the unified page is on.

## If something goes wrong

Over SSH, this restores every page exactly as its plugin shipped it:

```sh
php /usr/local/emhttp/plugins/menumanager/scripts/apply.php revert
```

`/usr/local/emhttp` lives in RAM, so a reboot also gives you Unraid's original menus, but the plugin re-applies your saved layout at boot. To stop that, uninstall the plugin (it reverts first) or delete `/boot/config/plugins/menumanager/layout.json`.

The built-in Tools and Settings pages still answer at `/Tools` and `/Settings` when hidden from the menu.

## Limits

- Another plugin's update undoes a move until the next apply. The plugin re-applies every minute and on array start, so a moved tile can briefly reappear in its old place.
- Only tiles inside a category are managed. Tabs of a multi-page plugin, dashboard widgets and toolbar buttons are never touched.
- `$variable` menus can't be evaluated outside Unraid's page loader, so their default value decides which category they show in.

## Development

```sh
tests/run.sh                    # needs only php-cli
tests/harness/serve.sh          # settings page on http://127.0.0.1:8765 against a fixture
./build.sh 2026.09.19           # builds packages/menumanager-<version>.txz and stamps the .plg
```

To try a build on a server without publishing it, copy the `.txz` to `/tmp` and run `upgradepkg --install-new /tmp/menumanager-<version>.txz`. It lives in RAM, so a reboot removes it. Each build needs a new version, or Unraid skips it as already installed.

See [ARCHITECTURE.md](ARCHITECTURE.md) for how it works.

## License

MIT
