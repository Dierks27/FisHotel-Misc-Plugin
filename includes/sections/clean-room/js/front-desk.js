/**
 * Clean Room front desk — the phone screen. Inlined into the page by
 * Front_Desk::maybe_render() together with window.fhFrontDesk (config).
 *
 * Every screen is built with the DOM API from the data the server sends
 * (never innerHTML from data); every write goes through the one AJAX
 * action fishotel_cleanroom_act and re-renders from the reply; every stay
 * change shows a toast whose Undo posts a compensating event — it stays
 * until the next action, and at least settings.undoSeconds.
 *
 * The fastest path is a loss (Jeff counts bodies): one tap on "−1 lost"
 * on the fish's row, or Lost → the row — so the fish rows come first and
 * the Today strip (the to-do) sits under them, folded to one line until
 * tapped. Arrivals only ever go into a Spa tank. A move with a smaller
 * quantity splits the group; a tank with one group skips "Which fish?".
 *
 * The pure helpers (normalizeNumberWords, previewSentence, tankValue,
 * englishValue, fmt, escapeHtml) are exported for tests/front-desk.test.js
 * and the file skips the DOM wiring when there is no document / config.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

/* global module */
(function (root) {
	'use strict';

	/* ---------------------------------------------------------------- */
	/*  Pure helpers                                                     */
	/* ---------------------------------------------------------------- */

	var WORDS = {
		zero: 0, one: 1, two: 2, three: 3, four: 4, five: 5, six: 6, seven: 7, eight: 8, nine: 9,
		ten: 10, eleven: 11, twelve: 12, thirteen: 13, fourteen: 14, fifteen: 15, sixteen: 16,
		seventeen: 17, eighteen: 18, nineteen: 19, twenty: 20, thirty: 30, forty: 40, fifty: 50,
		sixty: 60, seventy: 70, eighty: 80, ninety: 90, hundred: 100
	};

	/**
	 * printf-lite: %s / %d, positional (%1$s) or sequential.
	 *
	 * @param {string} template Template.
	 * @return {string}
	 */
	function fmt(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(template || '').replace(/%(\d+\$)?[ds]/g, function (match, pos) {
			var index = pos ? parseInt(pos, 10) - 1 : i++;
			return typeof args[index] === 'undefined' ? '' : args[index];
		});
	}

	/**
	 * Escape text for insertion into HTML.
	 *
	 * @param {*} text Text.
	 * @return {string}
	 */
	function escapeHtml(text) {
		return String(text === null || typeof text === 'undefined' ? '' : text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function tokenValue(token) {
		if (/^\d+$/.test(token)) {
			return parseInt(token, 10);
		}
		return Object.prototype.hasOwnProperty.call(WORDS, token) ? WORDS[token] : null;
	}

	/**
	 * Plain English value of a run of values: [20, 2] → 22, [1, 100, 20, 1]
	 * → 121, [4] → 4; null when the run is not a number ([4, 6]).
	 *
	 * @param {number[]} values Values.
	 * @return {number|null}
	 */
	function englishValue(values) {
		if (!values || !values.length) {
			return null;
		}
		var current = 0;
		var prev = null;
		for (var i = 0; i < values.length; i++) {
			var v = values[i];
			if (v === 100) {
				current = Math.max(1, current) * 100;
			} else if (v >= 20 && v % 10 === 0) {
				if (prev !== null && prev < 100) {
					return null;
				}
				current += v;
			} else {
				if (prev !== null && prev < 20) {
					return null;
				}
				current += v;
			}
			prev = v;
		}
		return current;
	}

	/**
	 * A tank number from a run of number words: "one twenty one" → 121,
	 * "five twenty one" → 521, "five two one" → 521, "one hundred and
	 * twenty one" → 121, "1 21" → 121; null when the run is not one.
	 *
	 * @param {string[]} words Tokens of the run.
	 * @return {number|null}
	 */
	function tankValue(words) {
		if (!words || !words.length) {
			return null;
		}
		var allDigits = words.every(function (w) { return /^\d+$/.test(w); });
		if (allDigits) {
			var joined = words.join('');
			var value = parseInt(joined, 10);
			return (joined.length === 3 && value >= 100) ? value : null;
		}
		var values = [];
		for (var i = 0; i < words.length; i++) {
			var v = tokenValue(words[i]);
			if (v === null) {
				return null;
			}
			values.push(v);
		}
		if (values.indexOf(100) !== -1) {
			var total = englishValue(values);
			return (total !== null && total >= 100 && total <= 999) ? total : null;
		}
		if (values.length === 1) {
			return (values[0] >= 100 && values[0] <= 999) ? values[0] : null;
		}
		var single = values.every(function (n) { return n <= 9; });
		if (single && values.length === 3) {
			return values[0] * 100 + values[1] * 10 + values[2];
		}
		if (values[0] >= 1 && values[0] <= 9) {
			var rest = englishValue(values.slice(1));
			if (rest !== null && rest >= 0 && rest <= 99) {
				return values[0] * 100 + rest;
			}
		}
		return null;
	}

	function obeysRule(number) {
		if (number < 111 || number > 999) {
			return false;
		}
		var level = Math.floor((number % 100) / 10);
		return (level === 1 || level === 2) && number % 10 >= 1;
	}

	/**
	 * Number words → digits, the way the server's parser reads them:
	 * "four pajama cardinals in one twenty one" → "4 pajama cardinals in
	 * 121", "twenty one clowns" → "21 clowns", "four six line wrasse" →
	 * "4 6 line wrasse" (not 46 — "six" is part of the fish). A run that
	 * forms a known tank number becomes that number; "a couple" / "a
	 * dozen" / "two point four" are left for the server.
	 *
	 * @param {string}   text       Text.
	 * @param {number[]} knownTanks Tank numbers (empty = any number obeying the rule).
	 * @return {string}
	 */
	function normalizeNumberWords(text, knownTanks) {
		var known = Array.isArray(knownTanks) ? knownTanks.map(function (n) { return parseInt(n, 10); }) : [];
		var tokens = String(text || '').toLowerCase().replace(/[-–—]/g, ' ').split(/\s+/).filter(Boolean);
		var out = [];
		var i = 0;

		function isKnown(n) {
			return known.length ? known.indexOf(n) !== -1 : obeysRule(n);
		}

		while (i < tokens.length) {
			if (tokenValue(tokens[i]) === null) {
				out.push(tokens[i]);
				i++;
				continue;
			}
			var run = [];
			var j = i;
			while (j < tokens.length) {
				if (tokenValue(tokens[j]) !== null) {
					run.push(tokens[j]);
					j++;
					continue;
				}
				if (tokens[j] === 'and' && run.length && tokens[j - 1] === 'hundred' && j + 1 < tokens.length && tokenValue(tokens[j + 1]) !== null) {
					j++;
					continue;
				}
				break;
			}
			var allDigits = run.every(function (w) { return /^\d+$/.test(w); });
			var tank = tankValue(run);
			if (tank !== null && isKnown(tank) && (run.length >= 2 || String(run[0]).length >= 3)) {
				out.push(String(tank));
				i = j;
				continue;
			}
			if (!allDigits) {
				var english = englishValue(run.map(tokenValue));
				if (english !== null) {
					out.push(String(english));
					i = j;
					continue;
				}
			}
			for (var k = 0; k < run.length; k++) {
				out.push(/^\d+$/.test(run[k]) ? run[k] : String(tokenValue(run[k])));
			}
			i = j;
		}

		return out.join(' ');
	}

	/**
	 * The preview in plain words — the same sentence the server's
	 * Parser::summary() builds: "1 × Pajama Cardinalfish lost in Tank 521",
	 * "Which fish? 2 lost in Tank 513", "Move 2 × Ocellaris Clownfish from
	 * Tank 521 to Tank 221", "Copper 2.4 in Tank 521".
	 *
	 * @param {Object} p A parse result.
	 * @return {string}
	 */
	function previewSentence(p) {
		p = p || {};
		var needs = Array.isArray(p.needs) ? p.needs : [];
		var species = p.species || {};
		var name = species.name ? String(species.name) : 'fish';
		var qty = (p.qty === null || typeof p.qty === 'undefined' || p.qty === '') ? null : parseInt(p.qty, 10);
		var tank = p.tank > 0 ? 'Tank ' + parseInt(p.tank, 10) : 'which tank?';
		var to = p.to_tank > 0 ? 'Tank ' + parseInt(p.to_tank, 10) : 'which tank?';
		var ask = needs.indexOf('species') !== -1;
		var subject = ask ? '' : name;
		var what = ((qty !== null ? qty + ' × ' : '') + subject).replace(/^[\s×]+|[\s×]+$/g, '');
		if (what === '') {
			what = p.all ? 'all fish' : 'fish';
		}
		var prefix = ask ? 'Which fish? ' : '';

		switch (String(p.intent || '')) {
			case 'move':
				return prefix + 'Move ' + ((p.all && qty === null) ? 'all fish' : what) + ' from ' + tank + ' to ' + to;
			case 'loss':
				var reason = p.reason ? ' (' + (p.reason === 'doa' ? 'DOA' : String(p.reason).toUpperCase()) + ')' : '';
				return prefix + ((qty === null && !ask) ? '1 × ' : '') + what + ' lost in ' + tank + reason;
			case 'sold':
				return prefix + ((qty === null && !ask) ? '1 × ' : '') + what + ' shipped from ' + tank;
			case 'copper':
				var value = (p.value === null || typeof p.value === 'undefined') ? 'how much?' : String(parseFloat(Number(p.value).toFixed(2)));
				return 'Copper ' + value + ' in ' + tank;
			case 'clear':
				return prefix + (ask ? 'cleared' : name + ' in ' + tank + ' cleared today');
			case 'count':
				return prefix + 'Count: ' + (qty === null ? 'how many? ' : qty + ' × ') + (ask ? '' : name) + ' in ' + tank;
			case 'note':
				return 'Note on ' + tank + ': ' + String(p.note || '');
			case 'arrival':
				var where = needs.indexOf('tank') !== -1 ? 'which Spa tank?' : tank;
				return prefix + ((qty === null && !ask) ? '1 × ' : '') + what + ' arriving in ' + where + ' today';
			default:
				return 'Say what happened, for example "lost one cardinal in 521".';
		}
	}

	var api = {
		normalizeNumberWords: normalizeNumberWords,
		previewSentence: previewSentence,
		tankValue: tankValue,
		englishValue: englishValue,
		fmt: fmt,
		escapeHtml: escapeHtml
	};

	root.fhFrontDeskHelpers = api;

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}

	if (typeof document === 'undefined' || !root.fhFrontDesk) {
		return;
	}

	/* ---------------------------------------------------------------- */
	/*  App                                                              */
	/* ---------------------------------------------------------------- */

	var cfg = root.fhFrontDesk;
	var i18n = cfg.i18n || {};
	var app = document.getElementById('fh-fd-app');
	var toastEl = document.getElementById('fh-fd-toast');

	var state = {
		screen: cfg.screen || {},
		view: 'tank',
		pending: null,
		voice: null,
		form: {},
		timers: {},
		toastShownAt: 0,
		toastSticky: false
	};

	var TODAY_KEY = 'fhFrontDeskTodayOpen';

	/* The Today strip's open / closed state, kept for the session (a reload or the next tank keeps it). */
	function todayOpen() {
		try {
			return root.sessionStorage.getItem(TODAY_KEY) === '1';
		} catch (e) {
			return false;
		}
	}

	function setTodayOpen(open) {
		try {
			root.sessionStorage.setItem(TODAY_KEY, open ? '1' : '0');
		} catch (e) { /* private mode: the strip just stays as rendered */ }
	}

	function t(key, fallback) {
		return (i18n && i18n[key]) ? i18n[key] : fallback;
	}

	function tank() {
		return state.screen && state.screen.tank ? state.screen.tank : null;
	}

	function settings() {
		return (state.screen && state.screen.settings) || {};
	}

	function knownTankNumbers() {
		var out = [];
		(state.screen.grid || []).forEach(function (area) {
			(area.buildings || []).forEach(function (b) {
				(b.tanks || []).forEach(function (tk) { out.push(tk.number); });
			});
		});
		return out;
	}

	function occupantById(id) {
		var list = tank() ? (tank().occupants || []) : [];
		for (var i = 0; i < list.length; i++) {
			if (list[i].stayId === id) {
				return list[i];
			}
		}
		return null;
	}

	/* DOM builder: el(tag, { attrs }, children) — text children are text nodes. */
	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (key) {
			var value = attrs[key];
			if (value === null || typeof value === 'undefined' || value === false) {
				return;
			}
			if (key === 'class') {
				node.className = value;
			} else if (key === 'text') {
				node.textContent = String(value);
			} else if (key === 'on') {
				Object.keys(value).forEach(function (evt) { node.addEventListener(evt, value[evt]); });
			} else if (key === 'disabled' || key === 'hidden' || key === 'checked') {
				node[key] = !!value;
			} else {
				node.setAttribute(key, String(value));
			}
		});
		(children || []).forEach(function (child) {
			if (child === null || typeof child === 'undefined' || child === false) {
				return;
			}
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function clearTimers() {
		Object.keys(state.timers).forEach(function (k) {
			if (k === 'toast' || k === 'toastBar') {
				return;
			}
			root.clearInterval(state.timers[k]);
			root.clearTimeout(state.timers[k]);
			delete state.timers[k];
		});
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX                                                             */
	/* ---------------------------------------------------------------- */

	function post(action, fields) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(fields || {}).forEach(function (key) {
			var value = fields[key];
			if (Array.isArray(value)) {
				value.forEach(function (v) { body.append(key + '[]', v); });
			} else if (value !== null && typeof value !== 'undefined') {
				body.append(key, value);
			}
		});
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			if (res.status === 403 || res.status === 401) {
				throw new Error('expired');
			}
			return res.json().catch(function () { throw new Error('bad'); });
		}).then(function (json) {
			if (!json || !json.success) {
				var msg = json && json.data && json.data.message ? json.data.message : t('error', 'Something went wrong.');
				throw new Error(msg);
			}
			return json.data;
		});
	}

	function act(fields) {
		busy(true);
		var before = tank() ? tank().number : 0;
		return post('fishotel_cleanroom_act', fields).then(function (data) {
			if (data.screen) {
				state.screen = data.screen;
			}
			if (data.screen && data.screen.tank && before && data.screen.tank.number !== before) {
				// The fish went to another tank (an arrival into a Spa tank, an
				// undone move): follow them so the URL matches the screen.
				root.location.href = data.screen.tank.url;
				return data;
			}
			state.view = 'tank';
			state.pending = null;
			state.voice = null;
			state.form = {};
			render();
			if (fields.act === 'undo') {
				toast(data.message || t('undone', 'Undone'), null);
			} else {
				toast(data.message || t('saved', 'Saved'), data.undo && data.undo.eventIds && data.undo.eventIds.length ? data.undo.eventIds : null);
			}
			return data;
		}).catch(function (err) {
			failToast(err);
		}).then(function () {
			busy(false);
		});
	}

	function failToast(err) {
		var msg = err && err.message ? err.message : t('error', 'Something went wrong.');
		if (msg === 'expired') {
			toast(t('expired', 'Session expired — tap to reload.'), null, true, function () { root.location.reload(); });
			return;
		}
		if (msg === 'bad') {
			msg = t('error', 'Something went wrong.');
		}
		toast(msg, null, true);
	}

	function busy(on) {
		app.style.opacity = on ? '0.6' : '';
		app.style.pointerEvents = on ? 'none' : '';
	}

	/* ---------------------------------------------------------------- */
	/*  Toast with Undo                                                  */
	/* ---------------------------------------------------------------- */

	/*
	 * A toast with Undo is sticky: it stays until the next action
	 * (dismissToast(), called when a new screen opens or a new toast
	 * replaces it) and at least settings.undoSeconds either way, so a wet
	 * thumb can still undo after the server round trip. The bar shows that
	 * minimum running out; the server honours the undo for its own window.
	 */
	function toast(message, undoIds, isError, onTap) {
		var seconds = isError ? 4 : Math.max(10, parseInt(settings().undoSeconds, 10) || 10);
		var sticky = !isError && !!(undoIds && undoIds.length);
		root.clearTimeout(state.timers.toast);
		root.clearInterval(state.timers.toastBar);

		while (toastEl.firstChild) {
			toastEl.removeChild(toastEl.firstChild);
		}
		toastEl.className = 'fh-fd-toast' + (isError ? ' is-error' : '') + (sticky ? ' is-sticky' : '');
		toastEl.appendChild(el('span', { text: message }));
		if (undoIds && undoIds.length) {
			toastEl.appendChild(el('button', {
				type: 'button',
				text: t('undo', 'Undo'),
				on: {
					click: function (e) {
						e.stopPropagation();
						hideToast();
						act({ act: 'undo', event_ids: undoIds, tank: tank() ? tank().number : 0 });
					}
				}
			}));
		}
		if (onTap) {
			toastEl.addEventListener('click', onTap, { once: true });
		} else if (sticky) {
			// A tap on the message itself closes it — once the minimum has passed.
			toastEl.addEventListener('click', function () { dismissToast(); });
		}
		var bar = el('span', { class: 'fh-fd-toast__bar' });
		bar.style.width = '100%';
		toastEl.appendChild(bar);
		toastEl.hidden = false;

		var started = Date.now();
		state.toastShownAt = started;
		state.toastSticky = sticky;
		state.timers.toastBar = root.setInterval(function () {
			var left = Math.max(0, 1 - (Date.now() - started) / (seconds * 1000));
			bar.style.width = (left * 100) + '%';
			if (left <= 0) {
				root.clearInterval(state.timers.toastBar);
			}
		}, 200);
		if (!sticky) {
			state.timers.toast = root.setTimeout(hideToast, seconds * 1000);
		}
	}

	/* The next action happened: close a sticky toast, but never before its minimum. */
	function dismissToast() {
		if (toastEl.hidden || !state.toastSticky) {
			return;
		}
		var minimum = Math.max(10, parseInt(settings().undoSeconds, 10) || 10) * 1000;
		var left = minimum - (Date.now() - state.toastShownAt);
		root.clearTimeout(state.timers.toast);
		if (left <= 0) {
			hideToast();
			return;
		}
		state.timers.toast = root.setTimeout(hideToast, left);
	}

	function hideToast() {
		root.clearTimeout(state.timers.toast);
		root.clearInterval(state.timers.toastBar);
		state.toastSticky = false;
		toastEl.hidden = true;
	}

	/* ---------------------------------------------------------------- */
	/*  Render                                                           */
	/* ---------------------------------------------------------------- */

	function render() {
		clearTimers();
		while (app.firstChild) {
			app.removeChild(app.firstChild);
		}
		var old = document.querySelector('.fh-fd-bar');
		if (old) {
			old.parentNode.removeChild(old);
		}

		if (!state.screen.tablesOk) {
			app.appendChild(el('div', { class: 'fh-fd-empty', text: t('noTables', 'The Clean Room tables are missing.') }));
			return;
		}
		if (!tank()) {
			renderPicker();
			return;
		}
		switch (state.view) {
			case 'arrival': renderArrival(); break;
			case 'pick': renderPick(); break;
			case 'occupant': renderOccupant(); break;
			case 'qty': renderQty(); break;
			case 'move': renderMove(); break;
			case 'dates': renderDates(); break;
			case 'note': renderNote(); break;
			case 'copper': renderCopper(); break;
			case 'voice': renderVoice(); break;
			case 'species': renderSpecies(); break;
			default: renderTank();
		}
		root.scrollTo(0, 0);
	}

	function header(title, sub) {
		return el('div', { class: 'fh-fd-head' }, [
			el('h1', { text: title }),
			sub ? el('p', { class: 'fh-fd-sub' }, sub) : null
		]);
	}

	function tankSub() {
		var tk = tank();
		var bits = [el('strong', { text: tk.areaLabel + ' · ' + tk.building })];
		if (tk.sizeLabel) {
			bits.push(' · ' + tk.sizeLabel);
		}
		bits.push(' · ' + (tk.sharesWith && tk.sharesWith.length ? t('sharesWater', 'shares water with') + ' ' + tk.sharesWith.join(', ') : t('ownWater', 'its own water')));
		return bits;
	}

	function dayText(day) {
		return (day === null || typeof day === 'undefined') ? '' : fmt(t('day', 'day %d'), day);
	}

	/* An occupant row; onClick opens it, quick adds a "−1 lost" button. */
	function occupantNode(o, onClick, quick) {
		var tag = onClick ? 'button' : 'div';
		var attrs = { class: 'fh-fd-occ' };
		if (onClick) {
			attrs.type = 'button';
			attrs.on = { click: onClick };
		}
		var meta = [
			el('span', { class: 'fh-fd-badge fh-fd-badge--' + (o.saleReady ? 'ready' : o.status), text: o.stateLabel }),
			dayText(o.day) ? el('span', { text: dayText(o.day) }) : null,
			o.status === 'in_qt' && o.checkinDate ? el('span', { text: fmt(t('inSince', 'in since %s'), o.checkinDate) }) : null,
			o.status === 'cleared' && !o.saleReady && o.saleReadyDate ? el('span', { text: fmt(t('readyOn', 'ready %s'), o.saleReadyDate) }) : null,
			o.qtyArrived !== o.qtyLive ? el('span', { text: o.qtyLive + '/' + o.qtyArrived }) : null
		];
		var node = el(tag, attrs, [
			el('span', { class: 'fh-fd-occ__qty' }, [String(o.qtyLive), el('small', { text: t('live', 'live') })]),
			el('span', { class: 'fh-fd-occ__body' }, [
				el('span', { class: 'fh-fd-occ__name', text: o.species }),
				el('span', { class: 'fh-fd-occ__meta' }, meta)
			])
		]);
		if (!quick) {
			return node;
		}
		var row = el('div', { class: 'fh-fd-occ-row' }, [node]);
		row.appendChild(el('button', {
			type: 'button',
			class: 'fh-fd-quick',
			text: t('lostOne', '−1 lost'),
			disabled: o.qtyLive < 1,
			on: { click: function (e) { e.stopPropagation(); act({ act: 'loss', tank: tank().number, stay_id: o.stayId, qty: 1, via: 'qr' }); } }
		}));
		return row;
	}

	function bar(buttons) {
		var inner = el('div', { class: 'fh-fd-bar__inner' }, buttons);
		var node = el('div', { class: 'fh-fd-bar' }, [inner]);
		document.body.appendChild(node);
	}

	function button(label, cls, onClick, disabled) {
		return el('button', { type: 'button', class: 'fh-fd-btn ' + (cls || ''), text: label, disabled: !!disabled, on: { click: onClick } });
	}

	function go(view, pending) {
		dismissToast();
		state.view = view;
		state.pending = pending || null;
		state.form = {};
		render();
	}

	/* Move: with one group in the tank there is nothing to pick — straight to "Where to?". */
	function startMove() {
		var occ = tank() ? (tank().occupants || []) : [];
		if (occ.length === 1) {
			go('move', { act: 'move', stay: occ[0], single: true });
			return;
		}
		go('pick', { act: 'move' });
	}

	/* ---------------------------------------------------------------- */
	/*  Tank screen                                                      */
	/* ---------------------------------------------------------------- */

	function renderTank() {
		var tk = tank();
		var occ = tk.occupants || [];
		app.appendChild(header('Tank ' + tk.number, tankSub()));

		// The fish rows first: "−1 lost" must be the first thing under the title.
		if (!occ.length) {
			app.appendChild(el('div', { class: 'fh-fd-empty', text: t('empty', 'No fish in this tank') }));
		} else {
			app.appendChild(el('div', { class: 'fh-fd-list' }, occ.map(function (o) {
				return occupantNode(o, function () { go('occupant', { stay: o }); }, true);
			})));
		}

		app.appendChild(todayStrip(tk));

		app.appendChild(el('div', { class: 'fh-fd-links' }, [
			el('a', { href: cfg.baseUrl, text: t('allTanks', 'All tanks') }),
			el('a', { href: cfg.boardUrl, text: t('board', 'Room board') }),
			el('button', { type: 'button', text: t('refresh', 'Refresh'), on: { click: refresh } })
		]));

		var hasOcc = occ.length > 0;
		var voiceOn = settings().voiceEnabled !== false;
		bar([
			button(t('lost', 'Lost'), 'fh-fd-btn--danger', function () { go('pick', { act: 'loss' }); }, !hasOcc),
			button(t('shipped', 'Shipped'), 'fh-fd-btn--ok', function () { go('pick', { act: 'sold' }); }, !hasOcc),
			button(t('move', 'Move'), '', startMove, !hasOcc),
			button(t('arrival', 'Arrival'), 'fh-fd-btn--primary', function () { go('arrival'); }),
			button(t('note', 'Note'), '', function () { go('note', { tank: true }); }),
			voiceOn ? button(t('mic', 'Mic'), 'fh-fd-btn--mic', startVoice) : button(t('copper', 'Copper'), '', function () { go('copper'); })
		]);
	}

	/*
	 * The "Today" strip under the fish rows: one line ("Today · N to do ▸",
	 * plus "Cu low" when the last reading is under the floor) that opens
	 * on tap into the copper line and the to-do chips (tap = done). The
	 * open state is kept in sessionStorage.
	 */
	function todayStrip(tk) {
		var todo = tk.todo || [];
		var open = todo.filter(function (i) { return !i.done; }).length;
		var isOpen = todayOpen();
		var wrap = el('div', { class: 'fh-fd-today' + (isOpen ? ' is-open' : '') });
		var hasFish = !!(tk.occupants && tk.occupants.length);
		var line = open > 0 ? fmt(t('todayToDo', 'Today · %d to do'), open) : (hasFish ? t('todayNone', 'Today · all done') : t('today', 'Today'));
		if (tk.copper && tk.copper.state === 'low') {
			line += ' · ' + t('cuLow', 'Cu low');
		}
		wrap.appendChild(el('button', {
			type: 'button',
			class: 'fh-fd-today__toggle',
			'aria-expanded': isOpen ? 'true' : 'false',
			on: { click: function () { setTodayOpen(!isOpen); render(); } }
		}, [
			el('span', { text: line }),
			el('span', { class: 'fh-fd-today__caret', text: isOpen ? '▾' : '▸' })
		]));
		if (!isOpen) {
			return wrap;
		}
		var head = el('div', { class: 'fh-fd-today__head' }, [
			el('span', { text: t('today', 'Today') }),
			tk.isSpa || tk.copper ? copperLine(tk) : null
		]);
		wrap.appendChild(head);
		if (!todo.length) {
			if (tk.occupants && tk.occupants.length) {
				wrap.appendChild(el('p', { class: 'fh-fd-muted', text: t('todayDone', 'Nothing due today') }));
			}
			return wrap;
		}
		var chips = el('div', { class: 'fh-fd-chips' }, todo.map(function (item) {
			var label = item.label + (item.species ? ' · ' + item.species : '') + (item.day ? ' · ' + fmt(t('day', 'day %d'), item.day) : '');
			return el('button', {
				type: 'button',
				class: 'fh-fd-chip fh-fd-chip--todo' + (item.done ? ' is-done' : ''),
				title: item.note || '',
				disabled: !!item.done,
				text: (item.done ? '✓ ' : '') + label,
				on: { click: function () { act({ act: 'tick', tank: tk.number, key: item.key, kind: item.kind, label: item.label, via: 'qr' }); } }
			});
		}));
		wrap.appendChild(chips);
		if (!open) {
			wrap.appendChild(el('p', { class: 'fh-fd-muted', text: t('todayDone', 'All done for today') }));
		}
		return wrap;
	}

	function copperLine(tk) {
		var c = tk.copper;
		var text = c ? fmt(t('copperLast', 'Cu %s'), String(parseFloat(Number(c.value).toFixed(2)))) + ' · ' + fmt(t('ago', '%s ago'), c.ago) : t('copperNone', 'no copper reading yet');
		if (c && c.state === 'low') {
			text += ' · ' + t('copperLow', 'below the floor');
		}
		return el('button', {
			type: 'button',
			class: 'fh-fd-copper' + (c ? ' is-' + c.state : ''),
			text: text + ' +',
			on: { click: function () { go('copper'); } }
		});
	}

	function refresh() {
		busy(true);
		post('fishotel_cleanroom_screen', { tank: tank() ? tank().number : 0 }).then(function (data) {
			if (data.screen) {
				state.screen = data.screen;
			}
			render();
		}).catch(failToast).then(function () { busy(false); });
	}

	/* Tank picker (no tank in the URL) */
	function renderPicker() {
		app.appendChild(header(t('pickTank', 'Pick a tank'), null));
		renderGrid(app, function (tk) { root.location.href = tk.url; }, 0);
		app.appendChild(el('div', { class: 'fh-fd-links' }, [
			el('a', { href: cfg.boardUrl, text: t('board', 'Room board') })
		]));
	}

	function renderGrid(host, onPick, currentNumber) {
		(state.screen.grid || []).forEach(function (area) {
			var areaNode = el('div', { class: 'fh-fd-area' }, [el('h3', { text: area.label })]);
			(area.buildings || []).forEach(function (b) {
				var coming = b.status === 'coming_soon';
				var cls = 'fh-fd-building' + (b.inQt > 0 ? ' is-qt' : '') + (coming ? ' is-coming' : '');
				var head = el('div', { class: 'fh-fd-building__head' }, [
					el('span', {}, [b.label, coming ? el('small', { text: ' · ' + t('comingSoon', 'coming soon') }) : null]),
					b.inQt > 0 ? el('span', { class: 'fh-fd-badge fh-fd-badge--qt', text: t('qtHere', 'QT') + ' ' + b.inQt }) : null
				]);
				var tanks = el('div', { class: 'fh-fd-tanks' }, (b.tanks || []).map(function (tk) {
					var isCurrent = tk.number === currentNumber;
					return el('button', {
						type: 'button',
						class: 'fh-fd-tank' + (tk.empty ? ' is-empty' : '') + (isCurrent ? ' is-current' : ''),
						disabled: isCurrent || coming,
						on: { click: function () { onPick(tk); } }
					}, [
						String(tk.number),
						el('small', { text: isCurrent ? t('here', 'here') : (tk.empty ? t('emptyTank', 'empty') : tk.count + ' · ' + (tk.sizeLabel || '')) })
					]);
				}));
				areaNode.appendChild(el('div', { class: cls }, [head, tanks]));
			});
			host.appendChild(areaNode);
		});
	}

	/* The Spa tanks for a check-in, as big chips. */
	function spaChooser(f, onChange) {
		var list = state.screen.spaTanks || [];
		var wrap = el('div', { class: 'fh-fd-field' }, [el('label', { class: 'fh-fd-label', text: t('spaTank', 'Check in to which Spa tank?') })]);
		var chips = el('div', { class: 'fh-fd-chips' }, list.map(function (tk) {
			return el('button', {
				type: 'button',
				class: 'fh-fd-chip fh-fd-chip--tank' + (f.toTank === tk.number ? ' is-on' : ''),
				text: tk.number + (tk.empty ? ' · ' + t('emptyTank', 'empty') : ' · ' + tk.count),
				on: { click: function () { f.toTank = tk.number; onChange(); } }
			});
		}));
		wrap.appendChild(chips);
		if (!list.length) {
			wrap.appendChild(el('p', { class: 'fh-fd-muted', text: t('notSpa', 'Arrivals go into a Spa tank') }));
		}
		return wrap;
	}

	function defaultSpaTank() {
		var tk = tank();
		if (tk && tk.isSpa) {
			return tk.number;
		}
		var list = state.screen.spaTanks || [];
		return list.length ? list[0].number : 0;
	}

	/* ---------------------------------------------------------------- */
	/*  Arrival                                                          */
	/* ---------------------------------------------------------------- */

	function renderArrival() {
		var tk = tank();
		var f = state.form;
		if (typeof f.qty === 'undefined') {
			f.qty = 1;
		}
		if (!f.date) {
			f.date = state.screen.today;
		}
		if (typeof f.toTank === 'undefined') {
			f.toTank = defaultSpaTank();
		}
		app.appendChild(header(t('arrival', 'Arrival'), [t('notSpa', 'Arrivals go into a Spa tank')]));

		var screen = el('div', { class: 'fh-fd-screen' });
		screen.appendChild(speciesField(f, function () { render(); }));
		screen.appendChild(stepperField(t('howMany', 'How many?'), f, 'qty', 1, 999));
		screen.appendChild(spaChooser(f, render));
		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-date', text: t('date', 'Date') }),
			el('input', { id: 'fh-fd-date', class: 'fh-fd-input', type: 'date', value: f.date, max: state.screen.today, on: { change: function (e) { f.date = e.target.value; } } })
		]));
		app.appendChild(screen);

		bar([
			button(t('cancel', 'Cancel'), 'fh-fd-btn--quiet', function () { go('tank'); }),
			button(t('save', 'Save'), 'fh-fd-btn--primary', function () {
				if (!f.species) {
					toast(t('whichFish', 'Which fish?'), null, true);
					return;
				}
				if (!f.toTank) {
					toast(t('notSpa', 'Arrivals go into a Spa tank'), null, true);
					return;
				}
				act({ act: 'arrival', tank: tk.number, to_tank: f.toTank, species: f.species, product_id: f.productId || 0, qty: f.qty, date: f.date, via: 'qr' });
			})
		]);
	}

	/* The species search field (chosen chip, search box, suggestions, recent chips). */
	function speciesField(f, onChange) {
		var wrap = el('div', { class: 'fh-fd-field' }, [el('label', { class: 'fh-fd-label', text: t('species', 'Species') })]);
		if (f.species) {
			wrap.appendChild(el('div', { class: 'fh-fd-chosen' }, [
				el('span', { text: f.species }),
				el('button', { type: 'button', text: t('cancel', 'Cancel'), on: { click: function () { f.species = ''; f.productId = 0; onChange(); } } })
			]));
			return wrap;
		}
		var list = el('ul', { class: 'fh-fd-suggest' });
		var input = el('input', { class: 'fh-fd-input', type: 'search', placeholder: t('searchHint', 'Type a fish name…'), autocomplete: 'off', autocapitalize: 'words', value: f.typed || '' });
		var timer = null;
		input.addEventListener('input', function () {
			f.typed = input.value;
			root.clearTimeout(timer);
			timer = root.setTimeout(function () { suggest(f.typed, list, choose); }, 250);
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && input.value.trim()) {
				e.preventDefault();
				choose({ name: input.value.trim(), product_id: 0 });
			}
		});
		function choose(item) {
			f.species = item.name;
			f.productId = item.product_id || 0;
			onChange();
		}
		wrap.appendChild(input);
		wrap.appendChild(list);
		if (f.typed && f.typed.trim()) {
			wrap.appendChild(el('button', { type: 'button', class: 'fh-fd-chip fh-fd-chip--ghost', text: t('unlisted', 'Use as an unlisted fish') + ': ' + f.typed.trim(), on: { click: function () { choose({ name: f.typed.trim(), product_id: 0 }); } } }));
		}
		var recent = state.screen.recent || [];
		if (recent.length) {
			wrap.appendChild(el('h3', { text: t('recent', 'Recent') }));
			wrap.appendChild(el('div', { class: 'fh-fd-chips' }, recent.map(function (r) {
				return el('button', { type: 'button', class: 'fh-fd-chip', text: r.species, on: { click: function () { choose({ name: r.species, product_id: r.product_id || 0 }); } } });
			})));
		}
		root.setTimeout(function () { input.focus(); }, 50);
		if (f.typed) {
			suggest(f.typed, list, choose);
		}
		return wrap;
	}

	function suggest(term, list, choose) {
		while (list.firstChild) {
			list.removeChild(list.firstChild);
		}
		if (!term || !term.trim()) {
			return;
		}
		post('fishotel_cleanroom_suggest', { term: term.trim() }).then(function (data) {
			while (list.firstChild) {
				list.removeChild(list.firstChild);
			}
			(data.items || []).forEach(function (item) {
				var sub = item.match === 'alias' ? ('"' + item.alias + '"') : (item.common || item.sci || '');
				list.appendChild(el('li', {}, [
					el('button', { type: 'button', on: { click: function () { choose(item); } } }, [item.name, sub ? el('small', { text: sub }) : null])
				]));
			});
		}).catch(function () { /* suggestions are optional */ });
	}

	function stepperField(label, f, key, min, max) {
		var input = el('input', { class: 'fh-fd-input', type: 'number', inputmode: 'numeric', min: min, max: max, value: f[key] });
		input.addEventListener('change', function () { f[key] = clamp(parseInt(input.value, 10) || min, min, max); input.value = f[key]; });
		function step(d) {
			f[key] = clamp((parseInt(f[key], 10) || 0) + d, min, max);
			input.value = f[key];
		}
		return el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', text: label }),
			el('div', { class: 'fh-fd-stepper' }, [
				button('−', '', function () { step(-1); }),
				input,
				button('+', '', function () { step(1); })
			])
		]);
	}

	function clamp(n, min, max) {
		return Math.max(min, Math.min(max, n));
	}

	/* ---------------------------------------------------------------- */
	/*  Pick an occupant (loss / sold / move)                            */
	/* ---------------------------------------------------------------- */

	function renderPick() {
		var tk = tank();
		var p = state.pending || {};
		var titles = { loss: t('lost', 'Lost'), sold: t('shipped', 'Shipped'), move: t('move', 'Move') };
		app.appendChild(header(titles[p.act] || '', [t('whichFish', 'Which fish?')]));
		var list = el('div', { class: 'fh-fd-list' });
		if (p.act === 'move' && (tk.occupants || []).length > 1) {
			list.appendChild(button(t('allFish', 'All fish in this tank'), 'fh-fd-btn--big', function () { go('move', { act: 'move', all: true }); }));
		}
		(tk.occupants || []).forEach(function (o) {
			if (p.act === 'move') {
				list.appendChild(occupantNode(o, function () { go('move', { act: 'move', stay: o }); }, false));
			} else {
				list.appendChild(occupantNode(o, function () { go('qty', { act: p.act, stay: o }); }, p.act === 'loss'));
			}
		});
		app.appendChild(list);
		bar([button(t('back', 'Back'), 'fh-fd-btn--quiet', function () { go('tank'); })]);
	}

	/* ---------------------------------------------------------------- */
	/*  One occupant: everything about one group                         */
	/* ---------------------------------------------------------------- */

	function renderOccupant() {
		var tk = tank();
		var o = occupantById((state.pending || {}).stay ? state.pending.stay.stayId : 0) || (state.pending || {}).stay;
		if (!o) {
			go('tank');
			return;
		}
		app.appendChild(header(o.species, ['Tank ' + tk.number + ' · ' + o.qtyLive + ' ' + t('live', 'live') + ' / ' + o.qtyArrived]));
		var screen = el('div', { class: 'fh-fd-screen' });
		screen.appendChild(el('div', { class: 'fh-fd-facts' }, [
			el('span', { class: 'fh-fd-badge fh-fd-badge--' + (o.saleReady ? 'ready' : o.status), text: o.stateLabel }),
			el('span', { text: dayText(o.day) }),
			el('span', { text: fmt(t('inSince', 'in since %s'), o.checkinDate) }),
			o.clearedDate ? el('span', { text: fmt(t('clearedOn', 'cleared %s'), o.clearedDate) }) : null,
			o.saleReadyDate ? el('span', { text: fmt(t('readyOn', 'ready %s'), o.saleReadyDate) + (o.saleReadyManual ? ' *' : '') }) : null
		]));
		screen.appendChild(el('div', { class: 'fh-fd-grid2' }, [
			button(t('lostOne', '−1 lost'), 'fh-fd-btn--danger fh-fd-btn--big', function () { act({ act: 'loss', tank: tk.number, stay_id: o.stayId, qty: 1, via: 'qr' }); }, o.qtyLive < 1),
			button(t('lost', 'Lost') + ' …', 'fh-fd-btn--danger', function () { go('qty', { act: 'loss', stay: o }); }, o.qtyLive < 1),
			button(t('shipped', 'Shipped'), 'fh-fd-btn--ok', function () { go('qty', { act: 'sold', stay: o }); }, o.qtyLive < 1),
			button(t('move', 'Move'), '', function () { go('move', { act: 'move', stay: o, single: true }); }),
			button(t('headCount', 'Full recount'), '', function () { go('qty', { act: 'count', stay: o }); }),
			button(t('note', 'Note'), '', function () { go('note', { stay: o }); }),
			button(t('dates', 'Dates'), '', function () { go('dates', { stay: o }); })
		]));
		app.appendChild(screen);
		bar([button(t('back', 'Back'), 'fh-fd-btn--quiet', function () { go('tank'); })]);
	}

	/* Quantity (+ reason) for loss / sold / count */
	function renderQty() {
		var tk = tank();
		var p = state.pending || {};
		var o = p.stay;
		var f = state.form;
		var isCount = p.act === 'count';
		if (typeof f.qty === 'undefined') {
			f.qty = isCount ? o.qtyLive : 1;
		}
		var titles = { loss: t('lost', 'Lost'), sold: t('shipped', 'Shipped'), count: t('headCount', 'Full recount') };
		app.appendChild(header(titles[p.act] || '', [o ? o.species + ' · ' + o.qtyLive + ' ' + t('live', 'live') : '']));
		var screen = el('div', { class: 'fh-fd-screen' });
		screen.appendChild(stepperField(isCount ? t('count', 'Count') : t('howMany', 'How many?') + ' ' + fmt(t('ofThem', 'of %d'), o.qtyLive), f, 'qty', isCount ? 0 : 1, isCount ? 999 : Math.max(1, o.qtyLive)));
		if (p.act === 'loss') {
			var reasons = ['doa', 'disease', 'jumped', 'unknown'];
			var chips = el('div', { class: 'fh-fd-chips' });
			reasons.forEach(function (r) {
				var chip = el('button', { type: 'button', class: 'fh-fd-chip' + (f.reason === r ? ' is-on' : ''), text: t(r, r) });
				chip.addEventListener('click', function () {
					f.reason = f.reason === r ? '' : r;
					Array.prototype.forEach.call(chips.children, function (c, idx) { c.className = 'fh-fd-chip' + (f.reason === reasons[idx] ? ' is-on' : ''); });
				});
				chips.appendChild(chip);
			});
			screen.appendChild(el('div', { class: 'fh-fd-field' }, [el('label', { class: 'fh-fd-label', text: t('reason', 'Reason (optional)') }), chips]));
		}
		app.appendChild(screen);
		var cls = p.act === 'loss' ? 'fh-fd-btn--danger' : (p.act === 'sold' ? 'fh-fd-btn--ok' : 'fh-fd-btn--primary');
		bar([
			button(t('cancel', 'Cancel'), 'fh-fd-btn--quiet', function () { go('tank'); }),
			button(t('save', 'Save'), cls, function () {
				if (isCount) {
					act({ act: 'count', tank: tk.number, stay_id: o.stayId, count: f.qty, via: 'qr' });
				} else {
					act({ act: p.act, tank: tk.number, stay_id: o.stayId, qty: f.qty, reason: f.reason || '', via: 'qr' });
				}
			})
		]);
	}

	/* Move: how many (a smaller number splits), why (optional chips), then the destination grid */
	var MOVE_REASONS = ['clear_building', 'bullying', 'pairing', 'other'];
	var MOVE_REASON_KEYS = { clear_building: 'clearBuilding', bullying: 'bullying', pairing: 'pairing', other: 'other' };

	function renderMove() {
		var tk = tank();
		var p = state.pending || {};
		var f = state.form;
		var o = p.stay;
		if (o && typeof f.qty === 'undefined') {
			f.qty = o.qtyLive;
		}
		app.appendChild(header(t('move', 'Move'), [p.all ? t('allFish', 'All fish in this tank') : (o ? o.species : ''), ' · ', t('whereTo', 'Where to?')]));
		var screen = el('div', { class: 'fh-fd-screen' });
		if (o && o.qtyLive > 1) {
			screen.appendChild(stepperField(t('howMany', 'How many?') + ' ' + fmt(t('ofThem', 'of %d'), o.qtyLive), f, 'qty', 1, o.qtyLive));
		}
		var chips = el('div', { class: 'fh-fd-chips' });
		MOVE_REASONS.forEach(function (r) {
			var chip = el('button', { type: 'button', class: 'fh-fd-chip' + (f.reason === r ? ' is-on' : ''), text: t(MOVE_REASON_KEYS[r], r.replace('_', ' ')) });
			chip.addEventListener('click', function () {
				f.reason = f.reason === r ? '' : r;
				Array.prototype.forEach.call(chips.children, function (c, idx) { c.className = 'fh-fd-chip' + (f.reason === MOVE_REASONS[idx] ? ' is-on' : ''); });
			});
			chips.appendChild(chip);
		});
		screen.appendChild(el('div', { class: 'fh-fd-field' }, [el('label', { class: 'fh-fd-label', text: t('whyMove', 'Why? (optional)') }), chips]));
		app.appendChild(screen);
		renderGrid(app, function (dest) {
			var fields = { act: 'move', tank: tk.number, to_tank: dest.number, reason: f.reason || '', via: 'qr' };
			if (p.all) {
				fields.all = 1;
			} else if (o) {
				fields.stay_id = o.stayId;
				if (o.qtyLive > 1 && f.qty < o.qtyLive) {
					fields.qty = f.qty;
				}
			}
			act(fields);
		}, tk.number);
		bar([button(t('back', 'Back'), 'fh-fd-btn--quiet', function () {
			if (p.single) {
				go('tank');
			} else {
				go('pick', { act: 'move' });
			}
		})]);
	}

	/* Dates: the check-in date, the cleared date (both by hand) and the sale-ready override */
	function renderDates() {
		var tk = tank();
		var o = (state.pending || {}).stay;
		var f = state.form;
		if (typeof f.checkin === 'undefined') {
			f.checkin = o.checkinDate || '';
		}
		if (typeof f.cleared === 'undefined') {
			f.cleared = o.clearedDate || '';
		}
		if (typeof f.ready === 'undefined') {
			f.ready = o.saleReadyManual || '';
		}
		app.appendChild(header(t('dates', 'Dates'), [o.species]));
		var screen = el('div', { class: 'fh-fd-screen' });
		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-idate', text: t('checkinDate', 'Check-in date') }),
			el('div', { class: 'fh-fd-row' }, [
				el('input', { id: 'fh-fd-idate', class: 'fh-fd-input', type: 'date', value: f.checkin, max: state.screen.today, on: { change: function (e) { f.checkin = e.target.value; } } }),
				button(t('save', 'Save'), 'fh-fd-btn--ok', function () {
					if (!f.checkin) {
						toast(t('date', 'Date') + '?', null, true);
						return;
					}
					act({ act: 'checkin', tank: tk.number, stay_id: o.stayId, date: f.checkin, via: 'qr' });
				})
			])
		]));
		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-cdate', text: t('clearedDate', 'Cleared date') }),
			el('div', { class: 'fh-fd-row' }, [
				el('input', { id: 'fh-fd-cdate', class: 'fh-fd-input', type: 'date', value: f.cleared, max: state.screen.today, on: { change: function (e) { f.cleared = e.target.value; } } }),
				button(t('save', 'Save'), 'fh-fd-btn--ok', function () {
					if (!f.cleared) {
						act({ act: 'clear', tank: tk.number, stay_id: o.stayId, unset: 1, via: 'qr' });
					} else {
						act({ act: 'clear', tank: tk.number, stay_id: o.stayId, date: f.cleared, via: 'qr' });
					}
				})
			]),
			o.clearedDate ? el('button', { type: 'button', class: 'fh-fd-chip fh-fd-chip--ghost', text: t('unsetDate', 'Not cleared'), on: { click: function () { act({ act: 'clear', tank: tk.number, stay_id: o.stayId, unset: 1, via: 'qr' }); } } }) : null
		]));
		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-rdate', text: t('readyDate', 'Sale-ready date') }),
			el('div', { class: 'fh-fd-row' }, [
				el('input', { id: 'fh-fd-rdate', class: 'fh-fd-input', type: 'date', value: f.ready, on: { change: function (e) { f.ready = e.target.value; } } }),
				button(t('save', 'Save'), 'fh-fd-btn--primary', function () { act({ act: 'ready', tank: tk.number, stay_id: o.stayId, date: f.ready || '', via: 'qr' }); })
			]),
			el('p', { class: 'fh-fd-muted', text: fmt(t('readyAuto', 'Leave empty for %d days after check-in'), settings().saleReadyDays || 28) + (o.saleReadyDate ? ' (' + o.saleReadyDate + ')' : '') })
		]));
		app.appendChild(screen);
		bar([button(t('back', 'Back'), 'fh-fd-btn--quiet', function () { go('occupant', { stay: o }); })]);
	}

	/* Note: on the tank, or on one group */
	function renderNote() {
		var tk = tank();
		var p = state.pending || {};
		var o = p.stay || null;
		var f = state.form;
		app.appendChild(header(t('note', 'Note'), [o ? o.species : 'Tank ' + tk.number]));
		var screen = el('div', { class: 'fh-fd-screen' });
		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-note', text: t('noteHint', 'What happened?') }),
			el('textarea', { id: 'fh-fd-note', class: 'fh-fd-textarea', on: { input: function (e) { f.note = e.target.value; } } })
		]));
		if (!o && (tk.occupants || []).length) {
			screen.appendChild(el('h3', { text: t('forFish', 'For one fish group') }));
			screen.appendChild(el('div', { class: 'fh-fd-chips' }, tk.occupants.map(function (occ) {
				return el('button', { type: 'button', class: 'fh-fd-chip', text: occ.species, on: { click: function () { var note = f.note; go('note', { stay: occ }); state.form.note = note; var ta = document.getElementById('fh-fd-note'); if (ta) { ta.value = note || ''; } } } });
			})));
		}
		app.appendChild(screen);
		root.setTimeout(function () { var ta = document.getElementById('fh-fd-note'); if (ta) { ta.focus(); } }, 50);
		bar([
			button(t('cancel', 'Cancel'), 'fh-fd-btn--quiet', function () { go('tank'); }),
			button(t('save', 'Save'), 'fh-fd-btn--primary', function () {
				var fields = { act: 'note', tank: tk.number, note: f.note || '', via: 'qr' };
				if (o) {
					fields.stay_id = o.stayId;
				}
				act(fields);
			})
		]);
	}

	/* Copper reading */
	function renderCopper() {
		var tk = tank();
		var f = state.form;
		app.appendChild(header(t('copper', 'Copper'), ['Tank ' + tk.number + (tk.copper ? ' · ' + fmt(t('copperLast', 'Cu %s'), String(tk.copper.value)) + ' · ' + fmt(t('ago', '%s ago'), tk.copper.ago) : '')]));
		var input = el('input', { class: 'fh-fd-input fh-fd-input--big', type: 'number', inputmode: 'decimal', step: '0.01', min: '0', max: '9.99', placeholder: '2.4', on: { input: function (e) { f.value = e.target.value; } } });
		app.appendChild(el('div', { class: 'fh-fd-screen' }, [
			el('div', { class: 'fh-fd-field' }, [
				el('label', { class: 'fh-fd-label', text: fmt(t('copperHint', 'Reading (target %s, hold at %s or higher)'), settings().copperTarget, settings().copperFloor) }),
				input
			])
		]));
		root.setTimeout(function () { input.focus(); }, 50);
		bar([
			button(t('cancel', 'Cancel'), 'fh-fd-btn--quiet', function () { go('tank'); }),
			button(t('save', 'Save'), 'fh-fd-btn--primary', function () {
				if (!f.value) {
					toast(t('copperHint', 'Reading?'), null, true);
					return;
				}
				act({ act: 'copper', tank: tk.number, value: f.value, via: 'qr' });
			})
		]);
	}

	/* ---------------------------------------------------------------- */
	/*  Voice                                                            */
	/* ---------------------------------------------------------------- */

	function startVoice() {
		var SR = root.SpeechRecognition || root.webkitSpeechRecognition;
		state.view = 'voice';
		state.pending = null;
		state.form = {};
		if (!SR) {
			state.voice = { unsupported: true };
			render();
			return;
		}
		var rec;
		try {
			rec = new SR();
		} catch (e) {
			state.voice = { unsupported: true };
			render();
			return;
		}
		rec.lang = 'en-US';
		rec.interimResults = false;
		rec.maxAlternatives = 1;
		rec.continuous = false;
		state.voice = { listening: true, rec: rec };
		render();
		rec.onresult = function (e) {
			var text = e.results && e.results[0] && e.results[0][0] ? e.results[0][0].transcript : '';
			state.voice = { heard: text };
			parsePhrase(text);
		};
		rec.onerror = function (e) {
			state.voice = { unsupported: false, error: e && e.error ? e.error : 'error' };
			render();
		};
		rec.onend = function () {
			if (state.voice && state.voice.listening) {
				state.voice = { error: 'no-speech' };
				render();
			}
		};
		try {
			rec.start();
		} catch (e) {
			state.voice = { unsupported: true };
			render();
		}
	}

	function parsePhrase(text) {
		state.voice = { heard: text, parsing: true };
		render();
		post('fishotel_cleanroom_parse', { text: text, tank: tank().number }).then(function (data) {
			state.voice = { heard: text, preview: data.preview };
			render();
		}).catch(function (err) {
			state.voice = { heard: text, error: 'parse' };
			render();
			failToast(err);
		});
	}

	function renderVoice() {
		var tk = tank();
		var v = state.voice || {};
		app.appendChild(header(t('mic', 'Mic'), ['Tank ' + tk.number]));
		var box = el('div', { class: 'fh-fd-voice' });

		if (v.listening) {
			box.appendChild(el('div', { class: 'fh-fd-pulse' }));
			box.appendChild(el('p', { class: 'fh-fd-voice__state', text: t('listening', 'Listening…') }));
		} else if (v.parsing) {
			box.appendChild(el('p', { class: 'fh-fd-voice__state', text: '…' }));
			box.appendChild(el('p', { class: 'fh-fd-voice__heard', text: t('heard', 'Heard') + ': ' + normalizeNumberWords(v.heard, knownTankNumbers()) }));
		} else if (v.preview) {
			renderPreview(box, v);
		} else {
			if (v.unsupported) {
				box.appendChild(el('p', { class: 'fh-fd-muted', text: t('noVoice', 'No speech recognition here — type the phrase; the keyboard mic key dictates.') }));
			} else if (v.error) {
				box.appendChild(el('p', { class: 'fh-fd-muted', text: t('error', 'Something went wrong.') + ' (' + v.error + ')' }));
			}
		}

		if (!v.listening && !v.parsing) {
			var input = el('input', { class: 'fh-fd-input', type: 'text', placeholder: t('typeInstead', 'Or type it'), autocomplete: 'off', value: v.heard && !v.preview ? v.heard : '' });
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && input.value.trim()) {
					e.preventDefault();
					parsePhrase(input.value.trim());
				}
			});
			box.appendChild(el('div', { class: 'fh-fd-spacer' }));
			box.appendChild(el('div', { class: 'fh-fd-row' }, [
				input,
				button(t('go', 'Go'), 'fh-fd-btn--primary', function () { if (input.value.trim()) { parsePhrase(input.value.trim()); } })
			]));
			if (v.unsupported) {
				root.setTimeout(function () { input.focus(); }, 50);
			}
		}

		app.appendChild(box);
		bar([
			button(t('cancel', 'Cancel'), 'fh-fd-btn--quiet', function () { stopVoice(); go('tank'); }),
			(!v.listening && !v.unsupported) ? button(t('mic', 'Mic'), 'fh-fd-btn--mic', startVoice) : null
		]);
	}

	function stopVoice() {
		if (state.voice && state.voice.rec) {
			try {
				state.voice.rec.abort();
			} catch (e) { /* already stopped */ }
		}
		state.voice = null;
	}

	function renderPreview(box, v) {
		var p = v.preview;
		var needs = p.needs || [];
		box.appendChild(el('p', { class: 'fh-fd-voice__heard', text: t('heard', 'Heard') + ': ' + normalizeNumberWords(v.heard, knownTankNumbers()) }));
		box.appendChild(el('p', { class: 'fh-fd-voice__sentence', text: previewSentence(p) }));

		if (needs.indexOf('species') !== -1) {
			var chips = el('div', { class: 'fh-fd-chips' });
			(p.candidates || []).forEach(function (c) {
				chips.appendChild(el('button', { type: 'button', class: 'fh-fd-chip', text: c.name, on: { click: function () { chooseForPreview(c, true); } } }));
			});
			if (p.intent === 'arrival' && p.species && p.species.text) {
				chips.appendChild(el('button', { type: 'button', class: 'fh-fd-chip fh-fd-chip--ghost', text: t('unlisted', 'Use as an unlisted fish') + ': ' + p.species.text, on: { click: function () { chooseForPreview({ name: titleCase(p.species.text), product_id: 0 }, false); } } }));
			}
			chips.appendChild(el('button', { type: 'button', class: 'fh-fd-chip fh-fd-chip--ghost', text: t('notThatFish', 'Not that fish'), on: { click: openSpeciesPicker } }));
			box.appendChild(chips);
		} else if (['note', 'copper'].indexOf(p.intent) === -1 && !p.all) {
			box.appendChild(el('div', { class: 'fh-fd-chips' }, [
				el('button', { type: 'button', class: 'fh-fd-chip fh-fd-chip--ghost', text: t('notThatFish', 'Not that fish'), on: { click: openSpeciesPicker } })
			]));
		}

		if (p.intent === 'arrival' && needs.indexOf('tank') !== -1) {
			var f = state.form;
			box.appendChild(spaChooser(f, function () {
				p.tank = f.toTank;
				p.needs = p.needs.filter(function (n) { return n !== 'tank'; });
				render();
			}));
		} else if (needs.indexOf('to_tank') !== -1 || needs.indexOf('tank') !== -1) {
			box.appendChild(el('h3', { text: t('whereTo', 'Where to?') }));
			var gridHost = el('div');
			box.appendChild(gridHost);
			renderGrid(gridHost, function (dest) {
				if (needs.indexOf('to_tank') !== -1) {
					p.to_tank = dest.number;
					p.needs = p.needs.filter(function (n) { return n !== 'to_tank'; });
				} else {
					p.tank = dest.number;
					p.needs = p.needs.filter(function (n) { return n !== 'tank'; });
				}
				render();
			}, p.intent === 'move' ? (p.tank || tank().number) : 0);
		}

		if (needs.indexOf('value') !== -1 || needs.indexOf('qty') !== -1) {
			var vf = state.form;
			var vin = el('input', { class: 'fh-fd-input', type: 'number', inputmode: 'decimal', step: needs.indexOf('value') !== -1 ? '0.01' : '1', placeholder: needs.indexOf('value') !== -1 ? '2.4' : '?', on: { input: function (e) { vf.value = e.target.value; } } });
			box.appendChild(el('div', { class: 'fh-fd-row' }, [
				vin,
				button(t('go', 'Go'), 'fh-fd-btn--primary', function () {
					if (!vf.value) {
						return;
					}
					if (needs.indexOf('value') !== -1) {
						p.value = parseFloat(vf.value);
						p.needs = p.needs.filter(function (n) { return n !== 'value'; });
					} else {
						p.qty = parseInt(vf.value, 10);
						p.needs = p.needs.filter(function (n) { return n !== 'qty'; });
					}
					render();
				})
			]));
		}

		var ready = !needs.length;
		var seconds = parseInt(settings().autoConfirmSeconds, 10);
		if (ready && seconds > 0) {
			var barEl = el('div', { class: 'fh-fd-countdown' }, [el('span')]);
			barEl.firstChild.style.width = '100%';
			var label = el('p', { class: 'fh-fd-muted', text: fmt(t('confirmIn', 'Confirming in %d…'), seconds) });
			box.appendChild(label);
			box.appendChild(barEl);
			var started = Date.now();
			state.timers.confirm = root.setInterval(function () {
				var elapsed = (Date.now() - started) / 1000;
				var left = Math.max(0, seconds - elapsed);
				barEl.firstChild.style.width = (left / seconds * 100) + '%';
				label.textContent = fmt(t('confirmIn', 'Confirming in %d…'), Math.ceil(left));
				if (left <= 0) {
					root.clearInterval(state.timers.confirm);
					confirmPreview();
				}
			}, 250);
		}

		box.appendChild(el('div', { class: 'fh-fd-spacer' }));
		box.appendChild(el('div', { class: 'fh-fd-row' }, [
			button(t('cancel', 'Cancel'), 'fh-fd-btn--quiet', function () { clearTimers(); state.voice = { heard: v.heard }; render(); }),
			button(t('confirmNow', 'Confirm now'), 'fh-fd-btn--primary', function () { clearTimers(); confirmPreview(); }, !ready)
		]));
	}

	function titleCase(text) {
		return String(text || '').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}

	/* A chip or picker choice replaces the preview's species; a product / species choice teaches an alias. */
	function chooseForPreview(item, learn) {
		var v = state.voice;
		if (!v || !v.preview) {
			return;
		}
		var p = v.preview;
		p.species = p.species || {};
		var spoken = p.species.text || '';
		p.species.name = item.name;
		p.species.product_id = item.product_id || item.productId || null;
		p.species.stay_id = item.stay_id || item.stayId || null;
		p.species.confidence = 1;
		p.needs = (p.needs || []).filter(function (n) { return n !== 'species'; });
		if (learn && spoken && !p.species.stay_id) {
			post('fishotel_cleanroom_act', { act: 'alias', alias: spoken, product_id: p.species.product_id || 0, species: item.name, via: 'voice' }).catch(function () { /* learning is best-effort */ });
		}
		state.view = 'voice';
		render();
	}

	function openSpeciesPicker() {
		clearTimers();
		var v = state.voice;
		var p = v && v.preview ? v.preview : null;
		state.form = {};
		state.pending = {
			act: 'species',
			occupants: p && p.intent !== 'arrival' ? (tank().occupants || []) : [],
			onChoose: function (item) {
				chooseForPreview(item, true);
			}
		};
		state.view = 'species';
		render();
	}

	/* Species picker (from "Not that fish") */
	function renderSpecies() {
		var p = state.pending || {};
		var f = state.form;
		app.appendChild(header(t('whichFish', 'Which fish?'), null));
		var screen = el('div', { class: 'fh-fd-screen' });
		if (p.occupants && p.occupants.length) {
			screen.appendChild(el('div', { class: 'fh-fd-list' }, p.occupants.map(function (o) {
				return occupantNode(o, function () { p.onChoose({ name: o.species, product_id: o.productId, stay_id: o.stayId }); }, false);
			})));
			screen.appendChild(el('div', { class: 'fh-fd-spacer' }));
		}
		screen.appendChild(speciesField(f, function () {
			if (f.species) {
				p.onChoose({ name: f.species, product_id: f.productId || 0 });
			} else {
				render();
			}
		}));
		app.appendChild(screen);
		bar([button(t('back', 'Back'), 'fh-fd-btn--quiet', function () { state.view = 'voice'; render(); })]);
	}

	function confirmPreview() {
		var v = state.voice;
		if (!v || !v.preview) {
			return;
		}
		var p = v.preview;
		var tk = tank();
		var fields = { tank: p.tank || tk.number, via: 'voice' };
		var sp = p.species || {};
		switch (p.intent) {
			case 'arrival':
				fields.act = 'arrival';
				fields.tank = tk.number;
				fields.to_tank = p.tank || defaultSpaTank();
				fields.species = sp.name || '';
				fields.product_id = sp.product_id || 0;
				fields.qty = p.qty || 1;
				fields.date = state.screen.today;
				break;
			case 'move':
				fields.act = 'move';
				fields.to_tank = p.to_tank;
				if (p.all || !sp.stay_id) {
					fields.all = 1;
				} else {
					fields.stay_id = sp.stay_id;
					if (p.qty) {
						fields.qty = p.qty;
					}
				}
				break;
			case 'loss':
			case 'sold':
				fields.act = p.intent;
				fields.stay_id = sp.stay_id;
				fields.qty = p.qty || 1;
				fields.reason = p.reason || '';
				break;
			case 'clear':
				fields.act = 'clear';
				fields.date = state.screen.today;
				if (p.all || !sp.stay_id) {
					fields.all = 1;
				} else {
					fields.stay_id = sp.stay_id;
				}
				break;
			case 'count':
				fields.act = 'count';
				fields.stay_id = sp.stay_id;
				fields.count = p.qty;
				break;
			case 'note':
				fields.act = 'note';
				fields.note = p.note || '';
				break;
			case 'copper':
				fields.act = 'copper';
				fields.value = p.value;
				break;
			default:
				toast(t('error', 'Something went wrong.'), null, true);
				return;
		}
		if ((fields.act === 'loss' || fields.act === 'sold' || fields.act === 'count') && !fields.stay_id) {
			toast(t('whichFish', 'Which fish?'), null, true);
			return;
		}
		act(fields);
	}

	/* ---------------------------------------------------------------- */
	/*  Boot                                                             */
	/* ---------------------------------------------------------------- */

	render();
})(typeof window !== 'undefined' ? window : globalThis);
