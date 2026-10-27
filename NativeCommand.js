/*!
 * NativeCommand — keyboard command palette for ProcessWire
 * (c) Gregor Rojko (Pyxios) — MIT
 */
(function () {
	'use strict';

	// admin: ProcessWire.config; front-end: a JSON data block (works with a strict Content-Security-Policy)
	function readConfig() {
		if (window.ProcessWire && ProcessWire.config && ProcessWire.config.NativeCommand) return ProcessWire.config.NativeCommand;
		if (window.NativeCommandConfig) return window.NativeCommandConfig;
		var node = document.getElementById('nc-config');
		if (!node) return null;
		try { return JSON.parse(node.textContent); } catch (e) { return null; }
	}
	var cfg = readConfig();
	if (!cfg || window.__nativeCommand) return;
	window.__nativeCommand = true;

	var L = cfg.labels || {};
	var G = L.groups || {};
	var MAX = cfg.max || 8;
	var LS_RECENT = 'nc-recent-' + (cfg.uid || 0);
	var LS_PINS = 'nc-pins-' + (cfg.uid || 0);
	var ORDER = ['pinned', 'recent', 'page', 'shortcuts', 'commands', 'edited', 'pages', 'actions', 'nav', 'templates', 'fields', 'modules', 'users', 'custom'];
	var PREFIX = { '>': 'commands', '/': 'pages', '#': 'templates', '$': 'fields', ':': 'modules', '@': 'users' };
	var SERVER_SCOPES = { pages: 1, templates: 1, fields: 1, modules: 1, users: 1 };
	var ARG_SCOPE = { page: 'pages', template: 'templates', field: 'fields' };

	var EMPTY_ORDER = ['pinned', 'page', 'shortcuts', 'commands', 'recent', 'edited'];
	var EMPTY_CAP = { pinned: 12, page: 20, shortcuts: 20, commands: 30, recent: 4, edited: 4 };
	var META_GROUPS = { recent: 1, pinned: 1 };

	var root, bar, chip, input, list, status, scopesBar;
	var staticItems = null, staticPromise = null;
	var items = [], active = 0, timer = null, reqId = 0, lastFocus = null;
	var mode = 'search'; // search | args | confirm | busy | result
	var cmd = null;      // { it, i, vals, labels }

	/* ================================================================ utils */

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text != null) e.textContent = text;
		return e;
	}

	function store(key, val) {
		try {
			if (val === undefined) return JSON.parse(localStorage.getItem(key) || '[]') || [];
			localStorage.setItem(key, JSON.stringify(val));
		} catch (e) { /* storage unavailable */ }
		return [];
	}

	function slim(it) {
		return { t: it.t, s: it.s || '', u: it.u || '', v: it.v || '', id: it.id || '', tpl: it.tpl || '', c: it.c || '', a: it.a || [], cf: it.cf || '', m: !!it.m, pc: !!it.pc, og: (it.og && !META_GROUPS[it.og]) ? it.og : (META_GROUPS[it.g] ? '' : (it.g || '')) };
	}

	function keyOf(it) { return it.c ? 'c:' + it.c : 'u:' + it.u; }

	function pushRecent(it) {
		var k = keyOf(it);
		var list = store(LS_RECENT).filter(function (x) { return keyOf(x) !== k; });
		list.unshift(slim(it));
		store(LS_RECENT, list.slice(0, 6));
	}

	function isPinned(it) {
		var k = keyOf(it);
		return store(LS_PINS).some(function (x) { return keyOf(x) === k; });
	}

	function togglePin(it) {
		if (!it || (!it.u && !it.c)) return;
		var k = keyOf(it);
		var pins = store(LS_PINS);
		var had = pins.some(function (x) { return keyOf(x) === k; });
		pins = pins.filter(function (x) { return keyOf(x) !== k; });
		if (!had) pins.push(slim(it));
		store(LS_PINS, pins.slice(0, 12));
	}

	// a stored command is only shown when it still exists for this context
	function stillValid(it) {
		if (!it.c) return true;
		return (staticItems || []).some(function (x) { return x.c === it.c; });
	}

	function fresh(it) {
		if (!it.c) return it;
		var live = (staticItems || []).filter(function (x) { return x.c === it.c; })[0];
		return live || it;
	}

	function score(q, it) {
		var title = (it.t || '').toLowerCase();
		var hay = title + ' ' + (it.k || '').toLowerCase() + ' ' + (it.s || '').toLowerCase();
		var i = title.indexOf(q);
		var bonus = it.c ? 40 : 0;
		if (i === 0) return 1000 + bonus - title.length;
		if (i > 0) return 800 + bonus - i;
		i = hay.indexOf(q);
		if (i >= 0) return 500 + bonus - i;
		var ti = 0, s = 0, last = -2;
		for (var qi = 0; qi < q.length; qi++) {
			var c = q.charAt(qi);
			if (c === ' ') continue;
			ti = title.indexOf(c, ti);
			if (ti < 0) return -1;
			s += (ti === last + 1) ? 6 : 1;
			last = ti;
			ti++;
		}
		return s * 10 + bonus - title.length;
	}

	function parse(raw) {
		var q = raw.replace(/^\s+/, '');
		var scope = '';
		if (PREFIX[q.charAt(0)]) { scope = PREFIX[q.charAt(0)]; q = q.slice(1).replace(/^\s+/, ''); }
		return { q: q.trim().toLowerCase(), raw: q.trim(), scope: scope };
	}

	function inScope(it, scope) {
		if (!scope) return true;
		var g = it.og || it.g;
		if (scope === 'commands') return !!it.c;
		if (scope === 'pages') return g === 'pages' || g === 'edited' || g === 'page';
		return g === scope;
	}

	/* ================================================================= data */

	function request(params) {
		return fetch(cfg.endpoint + params, {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
		}).then(function (r) {
			return r.json().catch(function () { throw new Error('HTTP ' + r.status); });
		}).then(function (d) {
			if (!d || !d.ok) throw new Error((d && d.message) || 'denied');
			return d.items || [];
		});
	}

	function loadStatic(force) {
		if (staticPromise && !force) return staticPromise;
		staticPromise = request('init&page=' + (cfg.pageId || 0)).then(function (list) {
			staticItems = list;
			return list;
		}).catch(function () {
			staticPromise = null;
			staticItems = staticItems || [];
			return staticItems;
		});
		return staticPromise;
	}

	/* =============================================================== render */

	function group(list, empty) {
		var by = {};
		var order = empty ? EMPTY_ORDER : ORDER;
		list.forEach(function (it) {
			var g = ORDER.indexOf(it.g) >= 0 ? it.g : 'custom';
			if (empty && order.indexOf(g) < 0) return;
			(by[g] = by[g] || []).push(it);
		});
		var keys = order.filter(function (g) { return by[g]; });
		var cap = keys.length === 1 ? 50 : MAX;
		return keys.map(function (g) {
			return { g: g, items: by[g].slice(0, empty ? EMPTY_CAP[g] : (g === 'pinned' ? 12 : cap)) };
		});
	}

	function render(groups, note, opts) {
		opts = opts || {};
		list.textContent = '';
		items = [];
		(opts.before || []).forEach(function (node) { list.appendChild(node); });
		groups.forEach(function (grp) {
			if (!grp.items.length) return;
			if (grp.label !== '') {
				var h = el('li', 'nc-group', grp.label || G[grp.g] || grp.g);
				h.setAttribute('role', 'presentation');
				list.appendChild(h);
			}
			grp.items.forEach(function (it) {
				var idx = items.length;
				items.push(it);
				var li = el('li', 'nc-item' + (it.c ? ' is-cmd' : '') + (it._cls ? ' ' + it._cls : ''));
				li.id = 'nc-opt-' + idx;
				li.setAttribute('role', 'option');
				li.dataset.i = idx;
				var main = el('span', 'nc-main');
				main.appendChild(el('span', 'nc-t', it.t || ''));
				if (it.s) main.appendChild(el('span', 'nc-s', it.s));
				li.appendChild(main);
				var idGroups = { pages: 1, edited: 1, page: 1, templates: 1, fields: 1, users: 1 };
				var numId = /^\d+$/.test(String(it.id || '')) && (it.tpl || idGroups[it.og || it.g]) ? String(it.id) : '';
				if (numId) {
					var meta = el('span', 'nc-meta');
					if (it.tpl) meta.appendChild(el('span', 'nc-tpl', it.tpl));
					var idb = el('span', 'nc-id', '#' + numId);
					idb.title = L.copyId || 'Copy ID';
					idb.dataset.copy = numId;
					meta.appendChild(idb);
					li.appendChild(meta);
				} else {
					var bg = (it.og && !META_GROUPS[it.og]) ? it.og : it.g;
					var badge = (bg && bg !== grp.g && !META_GROUPS[bg]) ? (G[bg] || '') : '';
					if (badge && grp.g !== '_') li.appendChild(el('span', 'nc-badge', badge));
				}
				if (mode === 'search' && (it.u || it.c)) {
					var pin = el('span', 'nc-pin' + (isPinned(it) ? ' is-on' : ''), isPinned(it) ? '★' : '☆');
					pin.title = isPinned(it) ? (L.unpin || 'Unpin') : (L.pin || 'Pin');
					pin.dataset.pin = idx;
					li.appendChild(pin);
				}
				list.appendChild(li);
			});
		});
		status.className = 'nc-status' + (opts.error ? ' is-error' : '');
		status.textContent = note || (items.length || (opts.before && opts.before.length) ? '' : (L.empty || 'No results'));
		status.hidden = !status.textContent;
		setActive(0, true);
	}

	function setActive(i, scroll) {
		if (!items.length) { input.removeAttribute('aria-activedescendant'); return; }
		active = (i + items.length) % items.length;
		var prev = list.querySelector('.nc-item.is-active');
		if (prev) { prev.classList.remove('is-active'); prev.setAttribute('aria-selected', 'false'); }
		var cur = document.getElementById('nc-opt-' + active);
		if (cur) {
			cur.classList.add('is-active');
			cur.setAttribute('aria-selected', 'true');
			input.setAttribute('aria-activedescendant', cur.id);
			if (scroll && cur.scrollIntoView) cur.scrollIntoView({ block: 'nearest' });
		}
	}

	function copyText(text) {
		var done = function () {
			status.className = 'nc-status is-ok';
			status.textContent = (L.copied || 'Copied: %s').replace('%s', text);
			status.hidden = false;
		};
		try {
			if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, done); return; }
		} catch (e) { /* fall back */ }
		var ta = el('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (e) { /* noop */ }
		ta.remove();
		done();
	}

	function setChip(text) {
		chip.textContent = text || '';
		chip.hidden = !text;
		if (scopesBar) scopesBar.hidden = !!text;
	}

	function syncScopes(scope) {
		if (!scopesBar) return;
		Array.prototype.forEach.call(scopesBar.children, function (b) {
			var on = PREFIX[b.dataset.p] === scope;
			b.classList.toggle('is-on', on);
			b.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
	}

	function buildScopes() {
		scopesBar = el('div', 'nc-scopes');
		(cfg.scopes || []).forEach(function (sc) {
			var b = el('button', 'nc-scope');
			b.type = 'button';
			b.dataset.p = sc.p;
			b.appendChild(el('kbd', '', sc.p));
			b.appendChild(document.createTextNode(' ' + sc.l));
			b.addEventListener('mousedown', function (e) { e.preventDefault(); });
			b.addEventListener('click', function () {
				var parsed = parse(input.value);
				var same = PREFIX[sc.p] === parsed.scope;
				input.value = same ? parsed.raw : sc.p + ' ' + parsed.raw;
				update();
				input.focus();
			});
			scopesBar.appendChild(b);
		});
		return scopesBar;
	}

	/* ========================================================= search mode */

	function localResults(parsed) {
		var pool = (staticItems || []).filter(function (it) { return it.g !== 'catalog'; });
		if (!parsed.q) {
			var seen = {};
			var decorate = function (g) {
				return function (it) {
					var f = fresh(it);
					var o = {}; for (var k in f) o[k] = f[k];
					o.og = (f.og && !META_GROUPS[f.og]) ? f.og : f.g; o.g = g;
					return o;
				};
			};
			var visible = function (it) { return stillValid(it) && (!it.pc || cfg.pageId); };
			var pins = store(LS_PINS).filter(visible).map(decorate('pinned'));
			// commands already listed in their own group are not repeated under "recent"
			var listed = {};
			pool.forEach(function (it) { if (it.c) listed[keyOf(it)] = 1; });
			var recent = store(LS_RECENT).filter(function (it) { return visible(it) && !listed[keyOf(it)]; }).map(decorate('recent'));
			return pins.concat(recent, pool).filter(function (it) {
				var k = keyOf(it);
				if (seen[k]) return false;
				seen[k] = 1;
				return inScope(it, parsed.scope);
			});
		}
		return pool
			.filter(function (it) { return inScope(it, parsed.scope); })
			.map(function (it) { return { it: it, s: score(parsed.q, it) }; })
			.filter(function (x) { return x.s >= 0; })
			.sort(function (a, b) { return b.s - a.s; })
			.map(function (x) { return x.it; });
	}

	function serverSearch(q, scope, done, fail) {
		clearTimeout(timer);
		var my = ++reqId;
		timer = setTimeout(function () {
			request('q&q=' + encodeURIComponent(q) + '&scope=' + encodeURIComponent(scope))
				.then(function (r) { if (my === reqId) done(r); })
				.catch(function () { if (my === reqId) fail(); });
		}, 160);
	}

	function update() {
		if (mode === 'args') return argUpdate();
		if (mode === 'result' || mode === 'confirm') { resetToSearch(input.value); return; }
		if (mode !== 'search') return;

		var parsed = parse(input.value);
		var local = localResults(parsed);
		var needServer = parsed.q.length >= 2 && (!parsed.scope || SERVER_SCOPES[parsed.scope]);
		syncScopes(parsed.scope);
		if (input.value.trim() === '?') return renderHelp();
		if (!parsed.q && !parsed.scope) {
			var tip = cfg.pageId ? '' : (L.tipNoPage || '');
			var before = [];
			if (tip) { var t = el('li', 'nc-tip', tip); t.setAttribute('role', 'presentation'); before.push(t); }
			render(group(local, true), staticItems ? '' : (L.loading || '…'), { before: before });
			clearTimeout(timer); reqId++;
			return;
		}
		render(group(local), needServer ? (L.loading || '…') : '');
		if (!needServer) { clearTimeout(timer); reqId++; return; }

		serverSearch(parsed.raw, parsed.scope, function (remote) {
			var seen = {};
			var merged = local.concat(remote).filter(function (it) {
				var k = keyOf(it);
				if (seen[k]) return false;
				seen[k] = 1;
				return true;
			});
			var keep = active;
			render(group(merged));
			if (keep && keep < items.length) setActive(keep, false);
		}, function () {
			render(group(local), L.error || 'Error', { error: true });
		});
	}

	function renderHelp() {
		clearTimeout(timer); reqId++;
		var nodes = [];
		var head = el('li', 'nc-group', L.helpTitle || 'How it works');
		head.setAttribute('role', 'presentation');
		nodes.push(head);
		var wrap = el('li', 'nc-rows nc-help');
		wrap.setAttribute('role', 'presentation');
		(L.help || []).forEach(function (r) {
			var row = el('div', 'nc-row');
			row.appendChild(el('span', 'nc-row-k', r[0]));
			row.appendChild(el('span', 'nc-row-v', r[1]));
			wrap.appendChild(row);
		});
		nodes.push(wrap);

		var pool = staticItems || [];
		var runnable = pool.filter(function (it) { return it.c; });
		var catalog = pool.filter(function (it) { return it.g === 'catalog'; });
		var groups = [];
		var pageCmds = runnable.filter(function (it) { return it.g === 'page'; });
		var globalCmds = runnable.filter(function (it) { return it.g !== 'page'; });
		if (pageCmds.length) groups.push({ g: 'page', items: pageCmds });
		if (globalCmds.length) groups.push({ g: 'commands', label: L.allCommands || G.commands, items: globalCmds });
		render(groups, staticItems ? '' : (L.loading || '…'), { before: nodes });

		if (catalog.length) {
			var h = el('li', 'nc-group', (G.page || 'This page') + ' — ' + (L.pageOnly || ''));
			h.setAttribute('role', 'presentation');
			list.appendChild(h);
			catalog.forEach(function (it) {
				var li = el('li', 'nc-item is-cmd is-info');
				li.setAttribute('role', 'presentation');
				var main = el('span', 'nc-main');
				main.appendChild(el('span', 'nc-t', it.t));
				if (it.s) main.appendChild(el('span', 'nc-s', it.s));
				li.appendChild(main);
				list.appendChild(li);
			});
		}
	}

	function resetToSearch(text) {
		mode = 'search';
		cmd = null;
		setChip('');
		input.readOnly = false;
		input.placeholder = L.placeholder || '';
		input.value = text || '';
		update();
		input.focus();
	}

	/* ======================================================== command mode */

	function startCommand(it) {
		it = fresh(it);
		pushRecent(it);
		cmd = { it: it, i: 0, vals: {}, labels: {} };
		advance();
	}

	function currentArg() { return cmd && cmd.it.a ? cmd.it.a[cmd.i] : null; }

	function advance() {
		var spec = currentArg();
		if (spec) {
			mode = 'args';
			setChip(cmd.it.t + ' › ' + spec.l);
			input.readOnly = false;
			input.placeholder = spec.l;
			input.value = cmd.vals[spec.n] != null && spec.t === 'text' ? cmd.vals[spec.n] : (spec.t === 'text' ? (spec.d || '') : '');
			cmd.dyn = null;
			input.focus();
			if (spec.t === 'pages') {
				cmd.pick = { q: null, total: 0, items: [], sel: {} };
				input.placeholder = L.pickHint || spec.l;
				pickUpdate();
				return;
			}
			if (spec.t === 'choice') {
				render([], L.loading || '…');
				fetchChoices('', function (res) {
					cmd.dyn = { mode: res.mode };
					if (res.mode === 'text') {
						input.value = res.d || '';
						argUpdate();
						input.select();
					} else {
						renderChoices(res.items || []);
					}
				});
				return;
			}
			argUpdate();
			if (spec.t === 'text') input.select();
			return;
		}
		var warn = [];
		if (cmd.it.cf) warn.push(cmd.it.cf);
		if (cfg.ctx === 'admin' && cmd.it.pc && cmd.it.m && document.querySelector('.InputfieldStateChanged')) warn.push(L.unsaved || '');
		if (warn.length) return confirmStep(warn.join(' '));
		execute();
	}

	function argSummary() {
		var parts = [];
		(cmd.it.a || []).forEach(function (s, i) {
			if (i < cmd.i && cmd.labels[s.n]) parts.push(s.l + ': ' + cmd.labels[s.n]);
		});
		return parts.join(' · ');
	}

	function choiceUrl(q) {
		var spec = currentArg();
		var url = 'opts&cmd=' + encodeURIComponent(cmd.it.c) + '&arg=' + encodeURIComponent(spec.n) +
			'&page=' + (cfg.pageId || 0) + '&q=' + encodeURIComponent(q);
		Object.keys(cmd.vals).forEach(function (k) { url += '&a[' + encodeURIComponent(k) + ']=' + encodeURIComponent(cmd.vals[k]); });
		return url;
	}

	function fetchChoices(q, done) {
		clearTimeout(timer);
		var my = ++reqId;
		var go = function () {
			fetch(cfg.endpoint + choiceUrl(q), {
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
			}).then(function (r) { return r.json(); }).then(function (d) {
				if (my !== reqId || mode !== 'args') return;
				if (!d || !d.ok) { render([], (d && d.message) || L.error || 'Error', { error: true }); return; }
				done(d);
			}).catch(function () {
				if (my === reqId) render([], L.error || 'Error', { error: true });
			});
		};
		if (q === '') go(); else timer = setTimeout(go, 140);
	}

	function renderChoices(list) {
		var spec = currentArg();
		var rows = list.map(function (o) {
			return { t: (o.on ? '✓ ' : '') + o.t, s: o.s, id: o.id, tpl: o.tpl || '', open: o.open || '', _choice: 1, _cls: (o.on ? 'is-current' : '') + (o.open ? ' is-editor' : '') };
		});
		render([{ g: '_', label: spec.l, items: rows }], rows.length ? argSummary() : (L.typeToSearch || ''));
	}

	/* ---- multi-page picker ('pages' argument) ---- */

	function pickSelected() {
		return cmd.pick.items.filter(function (p) { return cmd.pick.sel[p.id]; });
	}

	function pickRender() {
		var pk = cmd.pick;
		var n = pickSelected().length;
		var rows = [];
		if (pk.items.length) {
			rows.push({ t: (L.pickContinue || 'Continue with %d selected pages').replace('%d', n), s: '', _pickGo: 1, _cls: 'is-go' });
		}
		pk.items.slice(0, 150).forEach(function (p) {
			var on = !!pk.sel[p.id];
			rows.push({ t: (on ? '☑ ' : '☐ ') + p.t, s: p.s, id: p.id, tpl: p.tpl, _pick: 1, _cls: on ? 'is-current' : 'is-off' });
		});
		var note = '';
		if (pk.q) {
			note = (L.pickFound || '%1$d found').replace('%1$d', pk.total);
			if (pk.total > pk.items.length && pk.items.length >= 500) note += ' · ' + (L.pickMore || '').replace('%d', 500);
		} else {
			note = L.pickHint || '';
		}
		var keep = active;
		render(rows.length ? [{ g: '_', label: currentArg().l, items: rows }] : [], note);
		if (keep && keep < items.length) setActive(keep, false);
	}

	function pickUpdate() {
		var q = input.value.trim();
		if (!q) { cmd.pick.q = ''; cmd.pick.items = []; cmd.pick.sel = {}; cmd.pick.total = 0; pickRender(); return; }
		clearTimeout(timer);
		var my = ++reqId;
		render([], L.loading || '…');
		timer = setTimeout(function () {
			fetch(cfg.endpoint + 'find&q=' + encodeURIComponent(q), {
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
			}).then(function (r) { return r.json(); }).then(function (d) {
				if (my !== reqId || mode !== 'args' || !cmd || !cmd.pick) return;
				if (!d || !d.ok) { render([], (d && d.message) || L.error || 'Error', { error: true }); return; }
				cmd.pick.q = q;
				cmd.pick.total = d.total || 0;
				cmd.pick.items = d.items || [];
				cmd.pick.sel = {};
				cmd.pick.items.forEach(function (p) { cmd.pick.sel[p.id] = 1; });
				active = 0;
				pickRender();
			}).catch(function () {
				if (my === reqId) render([], L.error || 'Error', { error: true });
			});
		}, 250);
	}

	function pickToggle(it) {
		if (cmd.pick.sel[it.id]) delete cmd.pick.sel[it.id]; else cmd.pick.sel[it.id] = 1;
		pickRender();
	}

	function pickToggleAll() {
		var all = pickSelected().length === cmd.pick.items.length;
		cmd.pick.sel = {};
		if (!all) cmd.pick.items.forEach(function (p) { cmd.pick.sel[p.id] = 1; });
		pickRender();
	}

	function pickGo() {
		var sel = pickSelected();
		if (!sel.length) {
			status.className = 'nc-status is-error';
			status.textContent = L.pickNone || 'Select at least one page.';
			status.hidden = false;
			return;
		}
		cmd.pick = null;
		setArg(sel.map(function (p) { return p.id; }).join(','), (L.pickCount || '%d pages').replace('%d', sel.length));
	}

	function argUpdate() {
		var spec = currentArg();
		if (!spec) return;
		if (spec.t === 'pages') return pickUpdate();
		var summary = argSummary();
		if (spec.t === 'choice') {
			if (!cmd.dyn) return;
			if (cmd.dyn.mode === 'list') {
				fetchChoices(input.value.trim(), function (res) { renderChoices(res.items || []); });
				return;
			}
		}
		if (spec.t === 'text' || spec.t === 'int' || spec.t === 'choice') {
			var v = input.value.trim();
			var hint = spec.r ? (L.enter || '') : (L.keepEmpty || L.enter || '');
			render([{ g: '_', label: spec.l, items: [{ t: v || '…', s: summary ? summary + ' · ' + hint : hint, _submit: 1 }] }]);
			return;
		}
		var q = input.value.trim();
		if (!q) { render([], L.typeToSearch || '…'); return; }
		render([], L.loading || '…');
		serverSearch(q, ARG_SCOPE[spec.t] || 'pages', function (remote) {
			render([{ g: '_', label: spec.l, items: remote.filter(function (r) { return r.id; }) }], summary);
		}, function () {
			render([], L.error || 'Error', { error: true });
		});
	}

	function setArg(value, label) {
		var spec = currentArg();
		if (!spec) return;
		if ((value === '' || value == null) && spec.r) {
			status.textContent = L.required || 'Required';
			status.className = 'nc-status is-error';
			status.hidden = false;
			return;
		}
		cmd.vals[spec.n] = value;
		cmd.labels[spec.n] = label;
		cmd.i++;
		advance();
	}

	function argBack() {
		if (!cmd || cmd.i === 0) return resetToSearch('');
		cmd.i--;
		advance();
	}

	function confirmStep(text) {
		mode = 'confirm';
		setChip(cmd.it.t);
		input.value = '';
		input.placeholder = '';
		var note = el('li', 'nc-msg is-warn', text);
		note.setAttribute('role', 'presentation');
		render([{ g: '_', label: '', items: [
			{ t: L.confirm || 'Run', s: argSummary(), _confirm: 1, _cls: 'is-danger' },
			{ t: L.cancel || 'Cancel', _cancel: 1 }
		] }], '', { before: [note] });
	}

	function execute() {
		mode = 'busy';
		setChip(cmd.it.t);
		input.readOnly = true;
		render([], L.running || '…');

		var fd = new FormData();
		fd.append('cmd', cmd.it.c);
		fd.append('page', cfg.pageId || 0);
		fd.append('ctx', cfg.ctx || 'admin');
		if (cfg.csrf) fd.append(cfg.csrf.n, cfg.csrf.v);
		Object.keys(cmd.vals).forEach(function (k) { fd.append('args[' + k + ']', cmd.vals[k]); });

		fetch(cfg.endpoint + 'run', {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
		}).then(function (r) {
			return r.json().catch(function () { return { ok: false, message: 'HTTP ' + r.status }; });
		}).then(function (res) {
			if (res.ok && (res.redirect || res.reload)) {
				try { sessionStorage.setItem('nc-flash', JSON.stringify({ m: res.message || '', u: !!res.undo })); } catch (e) { /* noop */ }
				if (res.redirect) window.location.href = res.redirect; else window.location.reload();
				return;
			}
			loadStatic(true);
			showResult(res);
		}).catch(function () {
			showResult({ ok: false, message: L.error || 'Error' });
		});
	}

	function showResult(res) {
		mode = 'result';
		input.readOnly = false;
		input.value = '';
		input.placeholder = L.typeNew || '';
		var nodes = [];
		var bad = !res.ok || res.type === 'error';
		var msg = el('li', 'nc-msg ' + (bad ? 'is-error' : 'is-ok'), res.message || '');
		msg.setAttribute('role', 'presentation');
		nodes.push(msg);
		if (res.rows && res.rows.length) {
			var wrap = el('li', 'nc-rows');
			wrap.setAttribute('role', 'presentation');
			res.rows.forEach(function (r) {
				var row = el('div', 'nc-row');
				row.appendChild(el('span', 'nc-row-k', r[0]));
				row.appendChild(el('span', 'nc-row-v', r[1]));
				wrap.appendChild(row);
			});
			nodes.push(wrap);
		}
		var acts = [];
		if (res.ok && res.undo) acts.push({ t: L.undo || 'Undo', _undo: 1 });
		if (res.open) acts.push({ t: L.open || 'Open', s: res.open, u: res.open });
		if (!res.ok && cmd) acts.push({ t: cmd.it.t, _retry: 1 });
		acts.push({ t: L.back || 'Back', _back: 1 });
		render([{ g: '_', label: '', items: acts }], '', { before: nodes });
	}

	/* ===================================================== native field editor */

	function hasUnsavedChanges() {
		return cfg.ctx === 'admin' && !!document.querySelector('.InputfieldStateChanged');
	}

	function editorStep(url, label) {
		if (!hasUnsavedChanges()) return openEditor(url, label);
		mode = 'confirm';
		setChip(label);
		input.value = '';
		input.placeholder = '';
		var note = el('li', 'nc-msg is-warn', L.unsaved || '');
		note.setAttribute('role', 'presentation');
		render([{ g: '_', label: '', items: [
			{ t: L.confirm || 'Continue', _openEditor: url, _label: label, _cls: 'is-danger' },
			{ t: L.cancel || 'Cancel', _cancel: 1 }
		] }], '', { before: [note] });
	}

	function openEditor(url, label) {
		close();
		var wrap = el('div', 'nc-editor');
		var backdrop = el('div', 'nc-editor-backdrop');
		var panel = el('div', 'nc-editor-panel');
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-modal', 'true');
		var head = el('div', 'nc-editor-head');
		var title = (L.editorTitle || 'Edit: %s').replace('%s', label);
		head.appendChild(el('span', 'nc-editor-title', title));
		panel.setAttribute('aria-label', title);
		var x = el('button', 'nc-editor-x', '×');
		x.type = 'button';
		x.title = L.close || 'Close';
		x.setAttribute('aria-label', L.close || 'Close');
		head.appendChild(x);
		var frame = el('iframe', 'nc-editor-frame');
		frame.setAttribute('title', title);
		frame.src = url;
		panel.appendChild(head);
		panel.appendChild(frame);
		wrap.appendChild(backdrop);
		wrap.appendChild(panel);
		document.body.appendChild(wrap);
		document.documentElement.classList.add('nc-open');

		var done = false;
		function shut() {
			if (!wrap.parentNode) return;
			wrap.remove();
			document.documentElement.classList.remove('nc-open');
			document.removeEventListener('keydown', onEsc, true);
		}
		function onEsc(e) { if (e.key === 'Escape') { e.preventDefault(); shut(); } }
		document.addEventListener('keydown', onEsc, true);
		x.addEventListener('click', shut);
		backdrop.addEventListener('mousedown', shut);

		frame.addEventListener('load', function () {
			var w, d;
			try { w = frame.contentWindow; d = frame.contentDocument; } catch (e) { return; }
			if (!w || !d) return;
			try { d.addEventListener('keydown', onEsc, true); } catch (e) { /* noop */ }
			var qs = '';
			try { qs = w.location.search || ''; } catch (e) { return; }
			// ProcessPageEdit redirects to ...&s=1&c=<number of changes> after saving
			var saved = /[?&]s=1(&|$)/.test(qs);
			if (!saved || done) return;
			var hasError = !!d.querySelector('.NoticeError, .InputfieldStateError, .uk-alert-danger');
			if (hasError) return; // keep the editor open so the message can be read
			done = true;
			var m = /[?&]c=(\d+)/.exec(qs);
			var changes = m ? parseInt(m[1], 10) : 1;
			if (!changes) { shut(); return; }
			try { sessionStorage.setItem('nc-flash', JSON.stringify({ m: (L.editorSaved || 'Saved: %s').replace('%s', label), u: false })); } catch (e) { /* noop */ }
			window.location.reload();
		});
		setTimeout(function () { try { frame.focus(); } catch (e) { /* noop */ } }, 50);
	}

	/* ============================================================= choose */

	function choose(it, how) {
		if (!it) return;
		if (mode === 'search') {
			if (it.c) return startCommand(it);
			return navigate(it, how);
		}
		if (mode === 'args') {
			var spec = currentArg();
			if (it.open) return editorStep(it.open, String(it.t).replace(/^✓ /, ''));
			if (it._pickGo) return pickGo();
			if (it._pick) return pickToggle(it);
			if (it._submit) {
				var v = input.value.trim();
				if (spec.t === 'int' && v !== '' && isNaN(+v)) v = '';
				return setArg(v, v);
			}
			return setArg(String(it.id), String(it.t).replace(/^✓ /, ''));
		}
		if (mode === 'confirm') {
			if (it._openEditor) return openEditor(it._openEditor, it._label);
			if (it._confirm) return execute();
			return resetToSearch('');
		}
		if (mode === 'result') {
			if (it._undo) return runById('undo.last');
			if (it._retry && cmd) { cmd.i = 0; cmd.vals = {}; cmd.labels = {}; return advance(); }
			if (it.u) return navigate(it, how);
			return resetToSearch('');
		}
	}

	function navigate(it, how) {
		var url = (how === 'view' && it.v) ? it.v : it.u;
		if (!url) return;
		if (it.g !== '_' && !it._back) pushRecent(it);
		if (how === 'tab') { window.open(url, '_blank', 'noopener'); return; }
		close();
		window.location.href = url;
	}

	function onKey(e) {
		e.stopPropagation(); // typing in the palette must not trigger page or theme shortcuts (Esc, Ctrl+S, …)
		if (mode === 'busy') { if (e.key !== 'Escape') e.preventDefault(); return; }
		var how = (e.ctrlKey || e.metaKey) ? 'tab' : (e.shiftKey ? 'view' : '');
		switch (e.key) {
			case 'ArrowDown': e.preventDefault(); setActive(active + 1, true); break;
			case 'ArrowUp': e.preventDefault(); setActive(active - 1, true); break;
			case 'PageDown': e.preventDefault(); setActive(Math.min(active + 5, items.length - 1), true); break;
			case 'PageUp': e.preventDefault(); setActive(Math.max(active - 5, 0), true); break;
			case 'Tab': e.preventDefault(); setActive(active + (e.shiftKey ? -1 : 1), true); break;
			case 'Enter':
				e.preventDefault();
				choose(items[active], how);
				break;
			case 'Escape':
				e.preventDefault();
				if (mode === 'search') close(); else resetToSearch('');
				break;
			case 'Backspace':
				if (mode === 'args' && input.value === '') { e.preventDefault(); argBack(); }
				break;
			case 'a':
			case 'A':
				if ((e.ctrlKey || e.metaKey) && mode === 'args' && cmd && cmd.pick && cmd.pick.items.length) {
					e.preventDefault();
					pickToggleAll();
				}
				break;
			default:
				if (e.altKey && (e.code === 'KeyP' || (e.key || '').toLowerCase() === 'p') && mode === 'search') {
					e.preventDefault();
					togglePin(items[active]);
					var keep = active;
					update();
					setActive(keep, false);
				}
		}
	}

	/* ================================================================ shell */

	function build() {
		root = el('div', 'nc-root');
		root.hidden = true;
		var backdrop = el('div', 'nc-backdrop');
		backdrop.addEventListener('mousedown', close);

		var panel = el('div', 'nc-panel');
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-modal', 'true');
		panel.setAttribute('aria-label', L.title || 'Command palette');

		bar = el('div', 'nc-bar');
		chip = el('span', 'nc-chip');
		chip.hidden = true;
		input = el('input', 'nc-input');
		input.type = 'text';
		input.setAttribute('autocomplete', 'off');
		input.setAttribute('spellcheck', 'false');
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-expanded', 'true');
		input.setAttribute('aria-controls', 'nc-list');
		input.placeholder = L.placeholder || '';
		bar.appendChild(chip);
		bar.appendChild(input);
		var helpBtn = el('button', 'nc-help-btn', '?');
		helpBtn.type = 'button';
		helpBtn.title = L.helpBtn || 'Help';
		helpBtn.setAttribute('aria-label', L.helpBtn || 'Help');
		helpBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
		helpBtn.addEventListener('click', function () {
			if (mode !== 'search') resetToSearch('');
			input.value = input.value.trim() === '?' ? '' : '?';
			update();
			input.focus();
		});
		bar.appendChild(helpBtn);
		bar.appendChild(el('kbd', 'nc-kbd', 'Esc'));

		list = el('ul', 'nc-list');
		list.id = 'nc-list';
		list.setAttribute('role', 'listbox');

		status = el('div', 'nc-status');
		status.setAttribute('aria-live', 'polite');

		panel.appendChild(bar);
		if ((cfg.scopes || []).length) panel.appendChild(buildScopes());
		panel.appendChild(list);
		panel.appendChild(status);
		panel.appendChild(el('div', 'nc-foot', L.hint || ''));
		root.appendChild(backdrop);
		root.appendChild(panel);
		document.body.appendChild(root);

		input.addEventListener('input', update);
		input.addEventListener('keydown', onKey);
		// keep keyboard focus in the input while clicking items
		list.addEventListener('mousedown', function (e) { if (e.button === 0) e.preventDefault(); });
		list.addEventListener('mousemove', function (e) {
			var li = e.target.closest('.nc-item');
			if (li && +li.dataset.i !== active) setActive(+li.dataset.i, false);
		});
		list.addEventListener('click', function (e) {
			var cp = e.target.closest('[data-copy]');
			if (cp) {
				e.preventDefault();
				e.stopPropagation();
				copyText(cp.dataset.copy);
				input.focus();
				return;
			}
			var pin = e.target.closest('.nc-pin');
			if (pin) {
				e.preventDefault();
				e.stopPropagation();
				togglePin(items[+pin.dataset.pin]);
				var keep = active;
				update();
				setActive(keep, false);
				input.focus();
				return;
			}
			var li = e.target.closest('.nc-item');
			if (!li || mode === 'busy') return;
			e.preventDefault();
			choose(items[+li.dataset.i], (e.ctrlKey || e.metaKey) ? 'tab' : (e.shiftKey ? 'view' : ''));
		});
		list.addEventListener('auxclick', function (e) {
			var li = e.target.closest('.nc-item');
			if (li && e.button === 1 && mode === 'search' && items[+li.dataset.i].u) {
				e.preventDefault();
				navigate(items[+li.dataset.i], 'tab');
			}
		});
	}

	function open(initial) {
		if (!root) build();
		if (!root.hidden) { input.select(); return; }
		lastFocus = document.activeElement;
		root.hidden = false;
		document.documentElement.classList.add('nc-open');
		mode = 'search';
		cmd = null;
		setChip('');
		input.readOnly = false;
		input.placeholder = L.placeholder || '';
		input.value = typeof initial === 'string' ? initial : '';
		update();
		input.focus();
		loadStatic().then(function () { if (!root.hidden && mode === 'search') update(); });
	}

	function close() {
		if (!root || root.hidden) return;
		if (mode === 'busy') return;
		root.hidden = true;
		reqId++;
		clearTimeout(timer);
		document.documentElement.classList.remove('nc-open');
		if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* noop */ } }
	}

	function isTyping(t) {
		if (!t) return false;
		var tag = (t.tagName || '').toLowerCase();
		return tag === 'input' || tag === 'textarea' || tag === 'select' || t.isContentEditable;
	}

	// match the physical key, so it works on any layout and with Option on macOS (which changes e.key)
	function keyMatches(e) {
		var k = String(cfg.key || 'k').toLowerCase();
		var code = /^[a-z]$/.test(k) ? 'Key' + k.toUpperCase() : (/^[0-9]$/.test(k) ? 'Digit' + k : '');
		if (e.code && code) return e.code === code;
		return (e.key || '').toLowerCase() === k;
	}

	function isShortcut(e) {
		if (!keyMatches(e)) return false;
		var mod = e.ctrlKey || e.metaKey;
		switch (cfg.mod) {
			case 'mod': return mod && !e.altKey && !e.shiftKey;
			case 'modShift': return mod && e.shiftKey && !e.altKey;
			case 'modAlt': return mod && e.altKey && !e.shiftKey;
			default: return e.altKey && !mod && !e.shiftKey; // 'alt'
		}
	}

	window.addEventListener('keydown', function (e) {
		if (isShortcut(e)) {
			// the palette owns its shortcut: stop other handlers (e.g. the theme's search) from reacting too
			e.preventDefault();
			e.stopImmediatePropagation();
			(root && !root.hidden) ? close() : open();
			return;
		}
		var key = (e.key || '').toLowerCase();
		if (cfg.slash && key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !isTyping(e.target) && (!root || root.hidden)) {
			e.preventDefault();
			open();
		}
	}, true);

	function runById(id) {
		if (!root || root.hidden) open();
		mode = 'busy';
		render([], L.running || '…');
		loadStatic(true).then(function () {
			var it = (staticItems || []).filter(function (x) { return x.c === id; })[0];
			mode = 'search';
			if (it) startCommand(it); else resetToSearch('');
		});
	}

	/* ================================================================ toast */

	function toast(msg, withUndo) {
		var t = el('div', 'nc-toast');
		t.setAttribute('role', 'status');
		t.appendChild(el('span', 'nc-toast-msg', msg));
		if (withUndo) {
			var u = el('button', 'nc-toast-btn', L.undo || 'Undo');
			u.type = 'button';
			u.addEventListener('click', function () { t.remove(); runById('undo.last'); });
			t.appendChild(u);
		}
		var x = el('button', 'nc-toast-x', '×');
		x.type = 'button';
		x.setAttribute('aria-label', 'Close');
		x.addEventListener('click', function () { t.remove(); });
		t.appendChild(x);
		document.body.appendChild(t);
		setTimeout(function () { if (t.parentNode) t.remove(); }, 10000);
	}

	function showFlash() {
		var f = null;
		try { f = JSON.parse(sessionStorage.getItem('nc-flash') || 'null'); sessionStorage.removeItem('nc-flash'); } catch (e) { f = null; }
		if (f && f.m) toast(f.m, f.u);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', showFlash); else showFlash();

	window.NativeCommand = { open: open, close: close, run: runById };
})();
