(function () {
  'use strict';
  var app = document.getElementById('mm-app');
  var api = app.dataset.api, csrf = app.dataset.csrf;
  var state = null, dirty = false, drag = null, newCount = 0;
  var ROOTS = ['Tools', 'Settings'];
  var HUB_NAMES = ['Control Center', 'Launchpad', 'Toolbox', 'Everything', 'Console', 'Hub'];

  function h(tag, attrs) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'class') el.className = attrs[k];
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== false && attrs[k] != null) el.setAttribute(k, attrs[k]);
    });
    (function add(list) {
      list.forEach(function (c) {
        if (c == null) return;
        if (Array.isArray(c)) add(c);
        else el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
      });
    })(Array.prototype.slice.call(arguments, 2));
    return el;
  }

  function call(action, extra) {
    var body = new URLSearchParams(Object.assign({ action: action, csrf_token: csrf }, extra || {}));
    return fetch(api, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || r.statusText); return j; });
    });
  }

  function setDirty(v) {
    dirty = v;
    var s = app.querySelector('.mm-status');
    if (s) { s.textContent = v ? 'Unsaved changes' : s.textContent; s.classList.toggle('dirty', v); }
  }
  function status(msg) { var s = app.querySelector('.mm-status'); s.textContent = msg; s.classList.remove('dirty'); }

  function findGroup(id) {
    for (var r = 0; r < ROOTS.length; r++) {
      var list = state.roots[ROOTS[r]];
      for (var i = 0; i < list.length; i++) if (list[i].id === id) return { root: ROOTS[r], list: list, index: i, group: list[i] };
    }
    return null;
  }
  function findTile(id) {
    var u = state.unplaced || [];
    for (var k = 0; k < u.length; k++) if (u[k].id === id) return { group: null, tiles: u, index: k, tile: u[k] };
    for (var r = 0; r < ROOTS.length; r++) {
      var list = state.roots[ROOTS[r]];
      for (var i = 0; i < list.length; i++) {
        var t = list[i].tiles;
        for (var j = 0; j < t.length; j++) if (t[j].id === id) return { group: list[i], tiles: t, index: j, tile: t[j] };
      }
    }
    return null;
  }

  function change(fn) { fn(); setDirty(true); render(); }

  function moveGroup(id, dir) {
    change(function () {
      var f = findGroup(id), to = f.index + dir;
      if (to < 0 || to >= f.list.length) return;
      f.list.splice(to, 0, f.list.splice(f.index, 1)[0]);
    });
  }
  function sendGroup(id) {
    change(function () {
      var f = findGroup(id), other = f.root === 'Tools' ? 'Settings' : 'Tools';
      state.roots[other].push(f.list.splice(f.index, 1)[0]);
    });
  }
  function rename(item) {
    var t = window.prompt('New name', item.title);
    if (t && t.trim()) change(function () { item.title = t.trim(); });
  }
  function moveTile(tileId, groupId, beforeId) {
    change(function () {
      var f = findTile(tileId), dest = findGroup(groupId).group;
      var tile = f.tiles.splice(f.index, 1)[0];
      var at = dest.tiles.length;
      if (beforeId) for (var i = 0; i < dest.tiles.length; i++) if (dest.tiles[i].id === beforeId) at = i;
      dest.tiles.splice(at, 0, tile);
    });
  }
  function addGroup(root) {
    var t = window.prompt('Name for the new category');
    if (!t || !t.trim()) return;
    change(function () {
      state.roots[root].push({ id: 'new' + (++newCount), title: t.trim(), hidden: false, custom: true, tag: 'th-large', plugin: 'menumanager', tiles: [] });
    });
  }
  function delGroup(id) {
    var f = findGroup(id);
    if (!window.confirm('Delete "' + f.group.title + '"? Its tiles go back to where their plugins put them.')) return;
    change(function () { f.list.splice(f.index, 1); });
  }

  function iconBtn(label, title, fn) { return h('button', { type: 'button', class: 'mm-i', title: title, onclick: fn }, label); }

  function tileRow(t, unplaced) {
    var moveSel = h('select', { class: 'mm-move', title: 'Move to another category', onchange: function () {
      if (this.value) moveTile(t.id, this.value);
    } }, h('option', { value: '' }, '→'));
    ROOTS.forEach(function (r) {
      state.roots[r].forEach(function (g) { moveSel.appendChild(h('option', { value: g.id }, r + ' › ' + g.title)); });
    });
    var row = h('div', { class: 'mm-tile' + (t.hidden ? ' hidden' : ''), draggable: 'true', 'data-tile': t.id },
      h('span', { class: 'mm-ttitle', title: t.id }, t.title),
      t.indirect ? h('span', { class: 'mm-badge', title: 'This plugin decides its own category from a setting. Placing it here overrides that until you reset.' }, 'setting') : null,
      h('span', { class: 'mm-plugin' }, t.plugin),
      iconBtn('✎', 'Rename', function () { rename(t); }),
      iconBtn(t.hidden ? 'show' : 'hide', t.hidden ? 'Show in menus' : 'Hide this tile', function () { change(function () { t.hidden = !t.hidden; }); }),
      moveSel);
    row.addEventListener('dragstart', function (e) { drag = t.id; row.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', t.id); });
    row.addEventListener('dragend', function () { drag = null; render(); });
    if (unplaced) return row;   // nothing can be dropped into "not in a category"
    row.addEventListener('dragover', function (e) { if (drag && drag !== t.id) { e.preventDefault(); e.stopPropagation(); row.classList.add('dropbefore'); } });
    row.addEventListener('dragleave', function () { row.classList.remove('dropbefore'); });
    row.addEventListener('drop', function (e) {
      if (!drag) return;
      e.preventDefault(); e.stopPropagation();
      var g = findTile(t.id).group;
      moveTile(drag, g.id, t.id);
    });
    return row;
  }

  function groupCard(g, root, index, count) {
    var tiles = h('div', { class: 'mm-tiles' }, g.tiles.length ? g.tiles.map(function (t) { return tileRow(t, false); }) : h('span', { class: 'mm-note' }, 'Empty. Drag tiles here.'));
    var card = h('div', { class: 'mm-group' + (g.hidden ? ' hidden' : ''), 'data-group': g.id },
      h('div', { class: 'mm-ghead' },
        h('b', { title: g.id }, g.title),
        g.custom ? h('span', { class: 'mm-badge' }, 'yours') : null,
        g.hidden ? h('span', { class: 'mm-badge' }, 'hidden') : null,
        iconBtn('▲', 'Move up', function () { moveGroup(g.id, -1); }),
        iconBtn('▼', 'Move down', function () { moveGroup(g.id, 1); }),
        iconBtn('⇄', 'Move to ' + (root === 'Tools' ? 'Settings' : 'Tools'), function () { sendGroup(g.id); }),
        iconBtn('✎', 'Rename', function () { rename(g); }),
        iconBtn(g.hidden ? 'show' : 'hide', 'Hide or show the whole category', function () { change(function () { g.hidden = !g.hidden; }); }),
        g.custom ? iconBtn('✕', 'Delete this category', function () { delGroup(g.id); }) : null),
      tiles);
    card.addEventListener('dragover', function (e) { if (drag) { e.preventDefault(); card.classList.add('dragover'); } });
    card.addEventListener('dragleave', function (e) { if (e.target === card) card.classList.remove('dragover'); });
    card.addEventListener('drop', function (e) { if (!drag) return; e.preventDefault(); moveTile(drag, g.id); });
    return card;
  }

  function unplacedCard() {
    return h('div', { class: 'mm-group mm-unplaced' },
      h('div', { class: 'mm-ghead' }, h('b', {}, 'Not in a category'),
        h('span', { class: 'mm-note' }, 'These pages pick their category from a setting that points nowhere. Drag or move them into one.')),
      h('div', { class: 'mm-tiles' }, state.unplaced.map(function (t) { return tileRow(t, true); })));
  }

  function hubBox() {
    var hub = state.hub;
    var enabled = h('input', { type: 'checkbox', onchange: function () { hub.enabled = this.checked; if (!this.checked) hub.hideBuiltin = false; setDirty(true); render(); } });
    enabled.checked = !!hub.enabled;
    var title = h('input', { type: 'text', list: 'mm-hub-names', value: hub.title, oninput: function () { hub.title = this.value; setDirty(true); } });
    var hide = h('input', { type: 'checkbox', onchange: function () { hub.hideBuiltin = this.checked; setDirty(true); } });
    hide.checked = !!hub.hideBuiltin; hide.disabled = !hub.enabled;
    var rank = h('input', { type: 'number', min: '0', value: hub.rank, oninput: function () { hub.rank = this.value; setDirty(true); } });
    return h('div', { class: 'mm-hub' },
      h('h3', {}, 'Unified page'),
      h('p', { class: 'mm-note' }, 'One page in the main menu that lists every category from Tools and Settings, with a filter box.'),
      h('label', {}, enabled, ' Show the unified page in the main menu'),
      h('label', {}, 'Name ', title,
        h('datalist', { id: 'mm-hub-names' }, HUB_NAMES.map(function (n) { return h('option', { value: n }); }))),
      h('label', {}, 'Position (lower is further left / higher up) ', rank),
      h('label', {}, hide, ' Hide the built-in Tools and Settings menus'),
      h('p', { class: 'mm-note mm-danger' }, 'Hiding the built-ins removes them from the main menu. Their pages still work at /Tools and /Settings, and the Reset button (or "php /usr/local/emhttp/plugins/menumanager/scripts/apply.php revert" over SSH) brings everything back.'));
  }

  function save() {
    status('Saving…');
    call('save', { state: JSON.stringify(state) }).then(function (j) {
      state = j.state; dirty = false; render(); showWarnings(j.warnings);
      status('Saved. Reload the page to see the new menu.');
    }).catch(function (e) { status('Error: ' + e.message); });
  }
  function reset() {
    if (!window.confirm('Put every tile and category back where Unraid and its plugins put them?')) return;
    status('Resetting…');
    call('reset').then(function (j) { state = j.state; dirty = false; render(); status('Everything is back to the defaults.'); })
      .catch(function (e) { status('Error: ' + e.message); });
  }
  function showWarnings(w) {
    var box = app.querySelector('.mm-warnings');
    box.innerHTML = '';
    (w || []).forEach(function (m) { box.appendChild(h('div', { class: 'mm-warn' }, m)); });
  }

  function render() {
    var keepWarn = app.querySelector('.mm-warnings');
    var warnHTML = keepWarn ? keepWarn.innerHTML : '';
    var keepStatus = app.querySelector('.mm-status');
    var statusText = keepStatus ? keepStatus.textContent : '';
    var cols = ROOTS.map(function (root) {
      var list = state.roots[root];
      var col = h('div', { class: 'mm-col' },
        h('h3', {}, root),
        list.map(function (g, i) { return groupCard(g, root, i, list.length); }),
        h('button', { type: 'button', onclick: function () { addGroup(root); } }, 'Add category to ' + root));
      col.addEventListener('dragover', function (e) { if (drag && !e.defaultPrevented) e.preventDefault(); });
      return col;
    });
    app.innerHTML = '';
    app.appendChild(h('div', { class: 'mm-bar' },
      h('button', { type: 'button', onclick: save }, 'Save & apply'),
      h('button', { type: 'button', onclick: reset }, 'Reset to defaults'),
      h('span', { class: 'mm-status' }, statusText)));
    app.appendChild(h('div', { class: 'mm-warnings' }));
    app.querySelector('.mm-warnings').innerHTML = warnHTML;
    app.appendChild(h('div', { class: 'mm-cols' }, cols));
    if (state.unplaced && state.unplaced.length) app.appendChild(unplacedCard());
    app.appendChild(hubBox());
    if (dirty) setDirty(true);
  }

  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  fetch(api + '?action=state', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
    state = j.state; render(); showWarnings(j.warnings);
  }).catch(function (e) { app.textContent = 'Could not load: ' + e.message; });
})();
