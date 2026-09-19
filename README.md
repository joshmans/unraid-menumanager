# Menu Manager for Unraid

Choose where every icon on the **Tools** and **Settings** pages lives.

1. **Reorder and organise** the tiles inside each category, drag them between categories, rename categories, and create your own.
2. **Swap between Tools and Settings.** Move a single tile, or a whole category, to the other page. Some plugins land in Settings when they belong in Tools.
3. **Hide what you never use** and, if you like, show everything on **one unified page** in the main menu (with a filter box) and hide the built-in Tools and Settings menus.

Everything is reversible. The plugin never deletes anything: it rewrites the one `Menu=` line of a page header and remembers the original, so **Reset to defaults** puts every file back byte for byte.

> **Status: scaffold.** The logic is covered by tests that use a copy of Unraid's own menu-building code, and the settings page has been driven in a browser against a fixture. It has **not** been run on a real Unraid box yet. Try it on a box you can afford to break, and read *If something goes wrong* first.

## Naming the unified page

The default is **Control Center**. It is configurable, and the settings page suggests: *Launchpad*, *Toolbox*, *Everything*, *Console*, *Hub*. Pick whichever reads best next to Dashboard / Main / Shares.

## Install (development)

```sh
./build.sh 2026.09.19            # builds packages/menumanager-2026.09.19.txz
scp packages/*.txz root@YOUR_UNRAID:/boot/config/plugins/menumanager/
ssh root@YOUR_UNRAID 'upgradepkg --install-new /boot/config/plugins/menumanager/menumanager-2026.09.19.txz && php -q /usr/local/emhttp/plugins/menumanager/scripts/apply.php apply'
```

Then open **Settings → Menu Manager**. Once published, install from the `.plg` URL as usual.

## If something goes wrong

Over SSH, this restores every page exactly as its plugin shipped it:

```sh
php /usr/local/emhttp/plugins/menumanager/scripts/apply.php revert
```

`/usr/local/emhttp` lives in RAM, so a reboot also gives you Unraid's original menus, but the plugin re-applies your saved layout at boot. To stop that, uninstall the plugin (it reverts first) or delete `/boot/config/plugins/menumanager/layout.json`.

The built-in Tools and Settings pages still answer at `/Tools` and `/Settings` when hidden from the menu, and the plugin refuses to hide them unless the unified page is enabled.

## Limits

- A change made by another plugin's update is undone until the next apply. The plugin re-applies every minute and on array start, so a moved tile can briefly reappear in its old place.
- Some plugins choose their own category from a setting (`Menu="/boot/config/plugins/x/x.cfg MENU=Utilities"`, or a `$variable`). They are shown in the category they currently resolve to, tagged **setting**, and you can move or hide them like any other tile. Moving one replaces that line with a fixed category, so the plugin's own setting is ignored until you reset. Saving only rewrites categories whose order you actually changed, so setting-driven tiles you didn't touch keep following their setting (reordering a category they sit in does pin them). `$variable` menus can't be evaluated outside Unraid's page loader, so their default is used to place them. A tile whose setting points at no category shows up under **Not in a category** until you place it.
- Tabs of a multi-page plugin, dashboard widgets and toolbar buttons are not tiles and are never touched.

## Development

```sh
tests/run.sh                    # needs only php-cli
tests/harness/serve.sh          # settings page on http://127.0.0.1:8765 against a fixture
```

See [ARCHITECTURE.md](ARCHITECTURE.md) for how it works.
