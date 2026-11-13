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
 * A group's sale state (saleSummary) reads its chosen sale date — its
 * own, else its batch's — or "Sale date not set" with the suggestion, and
 * warns "still in the Spa" when the date has come before it was cleared.
 *
 * A group's size (one of the standard sizes the server sends in
 * screen.sizes) shows beside its name (sizeText); the Arrival sheet
 * has a Size dropdown of them — optional.
 *
 * A fish nobody knows yet is checked in with "Unknown fish (??)" beside
 * "use as an unlisted fish": it reads "??" and what was typed
 * (unknownName) and its row says "needs identifying". A group's sheet has
 * Identify / Correct the fish: the same species search and the Size
 * dropdown, one post — the server corrects the stay and its supplier
 * order line together.
 *
 * A temporary tank (a slot of the own-water building: "TEMP 1 · 901") has
 * its own screens: a free slot says "Nothing is set up here" and offers
 * Set up — what it is for leads to the treatment, shown before anything
 * is saved —; one that is set up shows its kind, purpose, schedule and
 * its own day, with Schedule (its own steps, for this tank alone) and
 * Take down. The tank picker has a "Today" entry: everything due today
 * as one list by water system, ticked once per system (the server's
 * Todo). A move of a group still in quarantine into a CLEAN temporary
 * tank asks first: it clears the group.
 *
 * The pure helpers (normalizeNumberWords, previewSentence, tankValue,
 * englishValue, fmt, escapeHtml, saleSummary, sizeText, unknownName, and
 * the temporary tanks' and the list's: tankHeading, offerTemplates,
 * previewLines, lineDetail, feedText, flagText, todoHeadline, moveClears,
 * gridTanks) are exported for tests/front-desk.test.js and
 * tests/temp-tanks.test.js, and the file skips the DOM wiring when there
 * is no document / config.
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

	/**
	 * A group's sale state in words: "on sale 2026-10-30 · from the batch",
	 * "on sale … · own date", or "Sale date not set · suggested …"; and the
	 * "still in the Spa" warning when it is for sale but not cleared.
	 *
	 * @param {Object} o       Occupant (saleReadyDate, saleSource, saleSuggested, readyInSpa).
	 * @param {Object} strings readyOn, saleOwn, saleBatch, noSaleDate, suggestedOn, stillInSpa (English fallbacks).
	 * @return {{text: string, short: string, warning: string}}
	 */
	function saleSummary(o, strings) {
		var s = strings || {};
		var get = function (key, fallback) { return s[key] ? s[key] : fallback; };
		o = o || {};
		var out = { text: '', short: '', warning: '' };
		if (o.saleReadyDate) {
			out.short = fmt(get('readyOn', 'on sale %s'), o.saleReadyDate);
			out.text = out.short + ' · ' + (o.saleSource === 'own' ? get('saleOwn', 'own date') : get('saleBatch', 'from the batch'));
		} else {
			out.short = get('noSaleDate', 'Sale date not set');
			out.text = out.short + (o.saleSuggested ? ' · ' + fmt(get('suggestedOn', 'suggested %s'), o.saleSuggested) : '');
		}
		if (o.readyInSpa) {
			out.warning = get('stillInSpa', 'still in the Spa');
		}
		return out;
	}

	/**
	 * A group's size beside its name: '' without one, its label, and
	 * " (not a standard size)" when it is no longer in the list.
	 *
	 * @param {Object} o       Occupant (size, sizeStandard).
	 * @param {Object} strings notStandard (English fallback).
	 * @return {string}
	 */
	function sizeText(o, strings) {
		if (!o || !o.size) {
			return '';
		}
		if (o.sizeStandard === false) {
			return String(o.size) + ' (' + ((strings && strings.notStandard) || 'not a standard size') + ')';
		}
		return String(o.size);
	}

	/**
	 * The name of an unknown fish: "??", or "?? " + what it looks like; a
	 * mark that is already there is not doubled (the server's
	 * Identify::unknown_name()).
	 *
	 * @param {*} description What was typed.
	 * @return {string}
	 */
	function unknownName(description) {
		var text = description === null || typeof description === 'undefined' || typeof description === 'boolean' || typeof description === 'object' ? '' : String(description);
		text = text.replace(/\s+/g, ' ').replace(/^ +| +$/g, '').replace(/^[? ]+/, '').replace(/^ +| +$/g, '');
		return (text === '' ? '??' : '?? ' + text).substring(0, 120);
	}

	/* ---------------------------------------------------------------- */
	/*  Pure helpers: temporary tanks and the day's list                 */
	/* ---------------------------------------------------------------- */

	/**
	 * What a tank is called at the top of its screen: "TEMP 1 · 901" for a
	 * temporary slot, "Tank 521" for any other.
	 *
	 * @param {Object} tk The screen's tank (number, temp).
	 * @return {string}
	 */
	function tankHeading(tk) {
		if (tk && tk.temp && tk.temp.label) {
			return tk.temp.label + ' · ' + tk.number;
		}
		return 'Tank ' + (tk ? tk.number : '');
	}

	/**
	 * The schedules a "what is it for" offers: the ones carrying that tag
	 * (whatever its case) first, the rest after. A tag no template carries
	 * offers none first.
	 *
	 * @param {Object[]} templates name, tags[].
	 * @param {string}   tag       What it is for.
	 * @return {{matching: string[], others: string[]}}
	 */
	function offerTemplates(templates, tag) {
		var wanted = String(tag === null || typeof tag === 'undefined' ? '' : tag).replace(/\s+/g, ' ').replace(/^ +| +$/g, '').toLowerCase();
		var out = { matching: [], others: [] };
		(templates || []).forEach(function (template) {
			var carries = wanted !== '' && (template.tags || []).some(function (candidate) {
				return String(candidate).toLowerCase() === wanted;
			});
			(carries ? out.matching : out.others).push(String(template.name));
		});
		return out;
	}

	/**
	 * What a schedule does, one line per step that is not the feeding:
	 * "Furan-2 — days 2, 4, 6" and, when the step names a medication its
	 * label does not already spell out, " · Metronidazole".
	 *
	 * @param {Object|null} template name, preview[] (label, when, medication).
	 * @return {string[]}
	 */
	function previewLines(template) {
		if (!template || !template.preview) {
			return [];
		}
		return template.preview.map(function (step) {
			var line = String(step.label) + ' — ' + String(step.when);
			var medication = String(step.medication || '');
			if (medication && String(step.label).toLowerCase().indexOf(medication.toLowerCase()) === -1) {
				line += ' · ' + medication;
			}
			return line;
		});
	}

	/**
	 * What a line of the list says beside its step: the medication and the
	 * dose it names ("Praziquantel · suggested 1.9 g for 205 gal"), '' when
	 * it names neither.
	 *
	 * @param {Object} item A to-do item (medication, dose).
	 * @return {string}
	 */
	function lineDetail(item) {
		var bits = [];
		if (item && item.medication) {
			bits.push(String(item.medication));
		}
		if (item && item.dose) {
			bits.push(String(item.dose));
		}
		return bits.join(' · ');
	}

	/**
	 * The feeding of a building as one line: "Feed — 7 tanks with fish",
	 * and "· 3 left" while some are fed and some are not.
	 *
	 * @param {Object} item    A feed line (label, count, left).
	 * @param {Object} strings feedOne, feedMany, leftN (English fallbacks).
	 * @return {string}
	 */
	function feedText(item, strings) {
		var s = strings || {};
		var count = parseInt(item && item.count, 10) || 0;
		var left = parseInt(item && item.left, 10) || 0;
		var text = fmt(count === 1 ? (s.feedOne || '%1$s — %2$d tank with fish') : (s.feedMany || '%1$s — %2$d tanks with fish'), item ? item.label : '', count);
		if (left > 0 && left < count) {
			text += ' · ' + fmt(s.leftN || '%d left', left);
		}
		return text;
	}

	function plainNumber(value) {
		return String(parseFloat(Number(value).toFixed(2)));
	}

	/**
	 * A copper flag at the top of the list, in words — the server's
	 * Admin_Temp::flag_text(): "Building 500 · Cu 2.1 — below the floor",
	 * "… · copper reading due — last one 2.4, 26 h ago", "… · copper
	 * reading overdue — no reading yet".
	 *
	 * @param {Object} flag    where, low, due, overdue, value, ago.
	 * @param {Object} strings flagLow, flagDue, flagOverdue, flagNone, flagLast.
	 * @return {string}
	 */
	function flagText(flag, strings) {
		var s = strings || {};
		var value = (!flag || flag.value === null || typeof flag.value === 'undefined') ? '' : plainNumber(flag.value);
		var bits = [String(flag ? flag.where : '')];
		if (flag && flag.low) {
			bits.push(fmt(s.flagLow || 'Cu %s — below the floor', value));
		}
		if (flag && flag.due) {
			var what = flag.overdue ? (s.flagOverdue || 'copper reading overdue') : (s.flagDue || 'copper reading due');
			what += ' — ' + (value === '' ? (s.flagNone || 'no reading yet') : fmt(s.flagLast || 'last one %1$s, %2$s ago', value, flag.ago || ''));
			bits.push(what);
		}
		return bits.join(' · ');
	}

	/**
	 * The count at the top of the list: "7 things left to do", "All done",
	 * or "Nothing is due today".
	 *
	 * @param {Object|null} list    The day's list (left, total).
	 * @param {Object}      strings todoLeft, todoLeftOne, todoAllDone, todoNothing.
	 * @return {string}
	 */
	function todoHeadline(list, strings) {
		var s = strings || {};
		var left = list ? parseInt(list.left, 10) || 0 : 0;
		var total = list ? parseInt(list.total, 10) || 0 : 0;
		if (!total) {
			return s.todoNothing || 'Nothing is due today';
		}
		if (!left) {
			return s.todoAllDone || 'All done';
		}
		return fmt(left === 1 ? (s.todoLeftOne || '%d thing left to do') : (s.todoLeft || '%d things left to do'), left);
	}

	/**
	 * Whether moving these groups into a destination clears any of them:
	 * the destination is a clean tank and a group is still in quarantine.
	 *
	 * @param {Object}   dest      A grid tank (clean).
	 * @param {Object[]} occupants The groups that move (status).
	 * @return {boolean}
	 */
	function moveClears(dest, occupants) {
		if (!dest || !dest.clean) {
			return false;
		}
		return (occupants || []).some(function (o) { return !!o && o.status === 'in_qt'; });
	}

	/**
	 * The tanks of a grid building a screen offers: every rack tank; of the
	 * temporary slots only the ones that are set up — a slot with nothing
	 * in it is no tank to pick.
	 *
	 * @param {Object} building A grid building (tanks[]).
	 * @return {Object[]}
	 */
	function gridTanks(building) {
		return ((building && building.tanks) || []).filter(function (tk) { return !tk.free; });
	}

	var api = {
		normalizeNumberWords: normalizeNumberWords,
		previewSentence: previewSentence,
		tankValue: tankValue,
		englishValue: englishValue,
		fmt: fmt,
		escapeHtml: escapeHtml,
		saleSummary: saleSummary,
		sizeText: sizeText,
		unknownName: unknownName
	};

	// Temporary tanks and the day's list.
	api.tankHeading = tankHeading;
	api.offerTemplates = offerTemplates;
	api.previewLines = previewLines;
	api.lineDetail = lineDetail;
	api.feedText = feedText;
	api.flagText = flagText;
	api.todoHeadline = todoHeadline;
	api.moveClears = moveClears;
	api.gridTanks = gridTanks;

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
		toastSticky: false,
		openFeeds: {}
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
			// No tank on the page: the picker, the day's list, or a setup started from the picker.
			if (state.view === 'today') {
				renderTodayList();
			} else if (state.view === 'setup') {
				renderSetup();
			} else {
				renderPicker();
			}
			return;
		}
		if (tank().temp && tank().temp.free && state.view !== 'setup') {
			renderFreeSlot(); // A slot's label was scanned and nothing is set up in it.
			return;
		}
		switch (state.view) {
			case 'arrival': renderArrival(); break;
			case 'pick': renderPick(); break;
			case 'occupant': renderOccupant(); break;
			case 'identify': renderIdentify(); break;
			case 'qty': renderQty(); break;
			case 'move': renderMove(); break;
			case 'dates': renderDates(); break;
			case 'note': renderNote(); break;
			case 'copper': renderCopper(); break;
			case 'dose': renderDose(); ownWaterDose(); break;
			case 'today': renderTodayList(); break;
			case 'setup': renderSetup(); break;
			case 'schedule': renderSchedule(); break;
			case 'confirm': renderConfirm(); break;
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
		if (tk.temp && tk.temp.setup) {
			return tempSub(tk);
		}
		var bits = [el('strong', { text: tk.areaLabel + ' · ' + tk.building })];
		if (tk.sizeLabel) {
			bits.push(' · ' + tk.sizeLabel);
		}
		bits.push(' · ' + (tk.sharesWith && tk.sharesWith.length ? t('sharesWater', 'shares water with') + ' ' + tk.sharesWith.join(', ') : t('ownWater', 'its own water')));
		if (tk.system && tk.system.label) {
			bits.push(' · ' + tk.system.label);
		}
		if (tk.system && tk.system.schedule) {
			bits.push(' · ' + t('schedule', 'schedule') + ' ' + tk.system.schedule);
		}
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
		var sale = saleSummary(o, i18n);
		var meta = [
			el('span', { class: 'fh-fd-badge fh-fd-badge--' + (o.saleReady ? 'ready' : o.status), text: o.stateLabel }),
			sale.warning ? el('span', { class: 'fh-fd-warn', text: sale.warning }) : null,
			o.needsId ? el('span', { class: 'fh-fd-warn', text: t('needsId', 'needs identifying') }) : null,
			dayText(o.day) ? el('span', { text: dayText(o.day) }) : null,
			o.status === 'in_qt' && o.checkinDate ? el('span', { text: fmt(t('inSince', 'in since %s'), o.checkinDate) }) : null,
			!o.saleReady ? el('span', { text: sale.short }) : null,
			o.qtyArrived !== o.qtyLive ? el('span', { text: o.qtyLive + '/' + o.qtyArrived }) : null
		];
		var node = el(tag, attrs, [
			el('span', { class: 'fh-fd-occ__qty' }, [String(o.qtyLive), el('small', { text: t('live', 'live') })]),
			el('span', { class: 'fh-fd-occ__body' }, [
				el('span', { class: 'fh-fd-occ__name' }, [o.species, sizeText(o, i18n) ? el('small', { class: 'fh-fd-occ__size' + (o.sizeStandard === false ? ' is-stale' : ''), text: sizeText(o, i18n) }) : null]),
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
		app.appendChild(header(tankHeading(tk), tankSub()));

		// The fish rows first: "−1 lost" must be the first thing under the title.
		if (!occ.length) {
			app.appendChild(el('div', { class: 'fh-fd-empty', text: t('empty', 'No fish in this tank') }));
		} else {
			app.appendChild(el('div', { class: 'fh-fd-list' }, occ.map(function (o) {
				return occupantNode(o, function () { go('occupant', { stay: o }); }, true);
			})));
		}

		app.appendChild(todayStrip(tk));

		if (tk.temp && tk.temp.setup) {
			app.appendChild(tempTools(tk));
		}

		app.appendChild(el('div', { class: 'fh-fd-links' }, [
			el('a', { href: cfg.baseUrl, text: t('allTanks', 'All tanks') }),
			el('button', { type: 'button', text: t('todayList', 'Today\'s list'), on: { click: function () { go('today'); } } }),
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
			el('span', { class: 'fh-fd-today__tools' }, [
				tk.isSpa || tk.copper ? copperLine(tk) : null,
				el('button', { type: 'button', class: 'fh-fd-copper fh-fd-dose', text: t('dose', 'Dose') + ' +', on: { click: function () { go('dose'); } } })
			])
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
		app.appendChild(todayEntry());
		renderGrid(app, function (tk) { root.location.href = tk.url; }, 0, { setUp: function () { go('setup', {}); } });
		app.appendChild(el('div', { class: 'fh-fd-links' }, [
			el('a', { href: cfg.boardUrl, text: t('board', 'Room board') })
		]));
	}

	/*
	 * The tank grid. A temporary slot shows what it is called ("TEMP 1")
	 * and what is set up in it; one with nothing set up is no tank to pick
	 * — the picker (opts.setUp) offers "Set up" in their place.
	 */
	function renderGrid(host, onPick, currentNumber, opts) {
		(state.screen.grid || []).forEach(function (area) {
			var areaNode = el('div', { class: 'fh-fd-area' }, [el('h3', { text: area.label })]);
			var shown = 0;
			(area.buildings || []).forEach(function (b) {
				var coming = b.status === 'coming_soon';
				var list = b.temp ? gridTanks(b) : (b.tanks || []);
				var canSetUp = !!(b.temp && opts && opts.setUp && b.status === 'active' && state.screen.temp && state.screen.temp.free && state.screen.temp.free.length);
				if (b.temp && !list.length && !canSetUp) {
					return;
				}
				shown++;
				var cls = 'fh-fd-building' + (b.inQt > 0 ? ' is-qt' : '') + (coming ? ' is-coming' : '') + (b.temp ? ' is-temp' : '');
				var head = el('div', { class: 'fh-fd-building__head' }, [
					el('span', {}, [b.label, coming ? el('small', { text: ' · ' + t('comingSoon', 'coming soon') }) : null]),
					b.inQt > 0 ? el('span', { class: 'fh-fd-badge fh-fd-badge--qt', text: t('qtHere', 'QT') + ' ' + b.inQt }) : null
				]);
				var tanks = el('div', { class: 'fh-fd-tanks' }, list.map(function (tk) {
					var isCurrent = tk.number === currentNumber;
					var small = isCurrent ? t('here', 'here') : (tk.empty ? t('emptyTank', 'empty') : tk.count + ' · ' + (tk.sizeLabel || ''));
					if (tk.temp && !isCurrent) {
						small = (tk.sub || '') + (tk.empty ? '' : ' · ' + tk.count);
					}
					return el('button', {
						type: 'button',
						class: 'fh-fd-tank' + (tk.empty ? ' is-empty' : '') + (isCurrent ? ' is-current' : '') + (tk.temp ? ' is-temp' + (tk.clean ? ' is-clean' : ' is-quarantine') : ''),
						disabled: isCurrent || coming,
						on: { click: function () { onPick(tk); } }
					}, [
						tk.title || String(tk.number),
						el('small', { text: small })
					]);
				}));
				if (canSetUp) {
					tanks.appendChild(el('button', { type: 'button', class: 'fh-fd-tank is-empty is-setup', on: { click: opts.setUp } }, [
						'+',
						el('small', { text: t('setUp', 'Set up') })
					]));
				}
				areaNode.appendChild(el('div', { class: cls }, [head, tanks]));
			});
			if (shown) {
				host.appendChild(areaNode);
			}
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
				text: (tk.title ? tk.title + ' · ' : '') + tk.number + (tk.empty ? ' · ' + t('emptyTank', 'empty') : ' · ' + tk.count),
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
		screen.appendChild(speciesField(f, function () { render(); }, { unknown: true }));
		screen.appendChild(stepperField(t('howMany', 'How many?'), f, 'qty', 1, 999));
		var sizes = sizeChooser(f);
		if (sizes) {
			screen.appendChild(sizes);
		}
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
				act({ act: 'arrival', tank: tk.number, to_tank: f.toTank, species: f.species, product_id: f.productId || 0, unknown: f.unknown ? 1 : 0, qty: f.qty, size: f.size || '', date: f.date, via: 'qr' });
			})
		]);
	}

	/*
	 * The Size dropdown: the standard sizes, optional ("—" = none). Nothing
	 * when the list is empty. `own` is the size a group already has ({slug,
	 * label}): when it is no longer in the list it is one more option, so
	 * correcting the fish does not drop it.
	 */
	function sizeChooser(f, own) {
		var list = (state.screen.sizes || []).slice();
		var stale = own && own.slug && !list.some(function (size) { return size.slug === own.slug; });
		if (stale) {
			list.push({ slug: own.slug, label: (own.label || own.slug) + ' (' + t('notStandard', 'not a standard size') + ')' });
		}
		if (!list.length) {
			return null;
		}
		var options = [el('option', { value: '', text: '—' })].concat(list.map(function (size) {
			var option = el('option', { value: size.slug, text: size.label });
			option.selected = f.size === size.slug;
			return option;
		}));
		return el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-size', text: t('size', 'Size') + ' · ' + t('sizeHint', 'optional') }),
			el('select', { id: 'fh-fd-size', class: 'fh-fd-input', on: { change: function (e) { f.size = e.target.value; } } }, options)
		]);
	}

	/*
	 * The species search field (chosen chip, search box, suggestions, recent
	 * chips). With opts.unknown it also offers "Unknown fish (??)" beside
	 * "use as an unlisted fish": the group is "??" and what was typed.
	 */
	function speciesField(f, onChange, opts) {
		var wrap = el('div', { class: 'fh-fd-field' }, [el('label', { class: 'fh-fd-label', text: t('species', 'Species') })]);
		if (f.species) {
			wrap.appendChild(el('div', { class: 'fh-fd-chosen' }, [
				el('span', { text: f.species }),
				el('button', { type: 'button', text: t('cancel', 'Cancel'), on: { click: function () { f.species = ''; f.productId = 0; f.unknown = false; onChange(); } } })
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
			f.unknown = !!item.unknown;
			onChange();
		}
		wrap.appendChild(input);
		wrap.appendChild(list);
		if (f.typed && f.typed.trim()) {
			wrap.appendChild(el('button', { type: 'button', class: 'fh-fd-chip fh-fd-chip--ghost', text: t('unlisted', 'Use as an unlisted fish') + ': ' + f.typed.trim(), on: { click: function () { choose({ name: f.typed.trim(), product_id: 0 }); } } }));
		}
		if (opts && opts.unknown) {
			// What is typed is what the fish looks like: the chip follows the box.
			var unknownLabel = function () {
				return t('unknownFish', 'Unknown fish (??)') + (input.value.trim() ? ': ' + input.value.trim() : '');
			};
			var unknownChip = el('button', { type: 'button', class: 'fh-fd-chip fh-fd-chip--ghost fh-fd-chip--unknown', text: unknownLabel(), on: { click: function () { choose({ name: unknownName(input.value), product_id: 0, unknown: true }); } } });
			input.addEventListener('input', function () { unknownChip.textContent = unknownLabel(); });
			wrap.appendChild(unknownChip);
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
		app.appendChild(header(o.species, [(sizeText(o, i18n) ? sizeText(o, i18n) + ' · ' : '') + 'Tank ' + tk.number + ' · ' + o.qtyLive + ' ' + t('live', 'live') + ' / ' + o.qtyArrived]));
		var screen = el('div', { class: 'fh-fd-screen' });
		var sale = saleSummary(o, i18n);
		screen.appendChild(el('div', { class: 'fh-fd-facts' }, [
			el('span', { class: 'fh-fd-badge fh-fd-badge--' + (o.saleReady ? 'ready' : o.status), text: o.stateLabel }),
			sale.warning ? el('span', { class: 'fh-fd-warn', text: sale.warning }) : null,
			el('span', { text: dayText(o.day) }),
			el('span', { text: fmt(t('inSince', 'in since %s'), o.checkinDate) }),
			o.clearedDate ? el('span', { text: fmt(t('clearedOn', 'cleared %s'), o.clearedDate) }) : null,
			el('span', { text: sale.text })
		]));
		screen.appendChild(el('div', { class: 'fh-fd-grid2' }, [
			button(t('lostOne', '−1 lost'), 'fh-fd-btn--danger fh-fd-btn--big', function () { act({ act: 'loss', tank: tk.number, stay_id: o.stayId, qty: 1, via: 'qr' }); }, o.qtyLive < 1),
			button(t('lost', 'Lost') + ' …', 'fh-fd-btn--danger', function () { go('qty', { act: 'loss', stay: o }); }, o.qtyLive < 1),
			button(t('shipped', 'Shipped'), 'fh-fd-btn--ok', function () { go('qty', { act: 'sold', stay: o }); }, o.qtyLive < 1),
			button(t('move', 'Move'), '', function () { go('move', { act: 'move', stay: o, single: true }); }),
			button(t('headCount', 'Full recount'), '', function () { go('qty', { act: 'count', stay: o }); }),
			button(t('note', 'Note'), '', function () { go('note', { stay: o }); }),
			button(t('dates', 'Dates'), '', function () { go('dates', { stay: o }); }),
			button(o.needsId ? t('identify', 'Identify') : t('correctFish', 'Correct the fish'), o.needsId ? 'fh-fd-btn--primary' : '', function () { go('identify', { stay: o }); })
		]));
		app.appendChild(screen);
		bar([button(t('back', 'Back'), 'fh-fd-btn--quiet', function () { go('tank'); })]);
	}

	/*
	 * Identify / Correct the fish: the real fish from the same search the
	 * Arrival sheet uses (or still unknown, with what it looks like) and
	 * its size. The server corrects the stay and its supplier order line
	 * together and words the toast; there is no Undo — correct it again.
	 */
	function renderIdentify() {
		var tk = tank();
		var o = (state.pending || {}).stay;
		var f = state.form;
		if (!o) {
			go('tank');
			return;
		}
		if (typeof f.size === 'undefined') {
			f.size = o.sizeSlug || '';
		}
		app.appendChild(header(o.needsId ? t('identify', 'Identify') : t('correctFish', 'Correct the fish'), [o.species + (sizeText(o, i18n) ? ' · ' + sizeText(o, i18n) : '') + ' · Tank ' + tk.number]));
		var screen = el('div', { class: 'fh-fd-screen' });
		screen.appendChild(speciesField(f, function () { render(); }, { unknown: true }));
		var sizes = sizeChooser(f, { slug: o.sizeSlug, label: o.size });
		if (sizes) {
			screen.appendChild(sizes);
		}
		screen.appendChild(el('p', { class: 'fh-fd-muted', text: t('identifyHint', 'Pick the real fish and its size: the tank row, its supplier order line and the remembered box share follow.') }));
		app.appendChild(screen);
		bar([
			button(t('cancel', 'Cancel'), 'fh-fd-btn--quiet', function () { go('occupant', { stay: o }); }),
			button(t('save', 'Save'), 'fh-fd-btn--primary', function () {
				if (!f.species) {
					toast(t('whichFish', 'Which fish?'), null, true);
					return;
				}
				act({ act: 'identify', tank: tk.number, stay_id: o.stayId, species: f.species, product_id: f.productId || 0, unknown: f.unknown ? 1 : 0, size: f.size || '', via: 'qr' });
			})
		]);
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
	var MOVE_REASONS = ['clear_building', 'bullying', 'pairing', 'hospital', 'other'];
	var MOVE_REASON_KEYS = { clear_building: 'clearBuilding', bullying: 'bullying', pairing: 'pairing', hospital: 'hospital', other: 'other' };

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
		// Said before the tap: what a move into a clean tank does to a group still in quarantine.
		var moving = p.all ? (tk.occupants || []) : (o ? [o] : []);
		if (moveClears({ clean: true }, moving)) {
			screen.appendChild(el('p', { class: 'fh-fd-muted fh-fd-move-hint', text: t('moveClearsHint', 'Still in quarantine: a move into the Clean Room — or into a clean temporary tank — clears the group today.') }));
		}
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
			if (dest.temp && moveClears(dest, moving)) {
				// A clean temporary tank looks like any other slot: ask before clearing.
				go('confirm', { kind: 'moveClear', fields: fields, dest: dest, moving: moving, back: p });
				return;
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

	/* Dates: the check-in date, the cleared date (both by hand) and the group's own sale date */
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
		// The suggestion (check-in + the setting) only fills the picker on a tap; Save writes it.
		var readyInput = el('input', { id: 'fh-fd-rdate', class: 'fh-fd-input', type: 'date', value: f.ready, min: state.screen.saleDateMin || null, max: state.screen.saleDateMax || null, on: { change: function (e) { f.ready = e.target.value; } } });
		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-rdate', text: t('readyDate', 'Sale date (this group)') }),
			el('div', { class: 'fh-fd-row' }, [
				readyInput,
				button(t('save', 'Save'), 'fh-fd-btn--primary', function () { act({ act: 'ready', tank: tk.number, stay_id: o.stayId, date: f.ready || '', via: 'qr' }); })
			]),
			o.saleSuggested && o.saleSuggested !== f.ready ? el('button', { type: 'button', class: 'fh-fd-chip fh-fd-chip--ghost', text: fmt(t('suggestedOn', 'suggested %s'), o.saleSuggested), on: { click: function () { f.ready = o.saleSuggested; readyInput.value = o.saleSuggested; } } }) : null,
			el('p', { class: 'fh-fd-muted', text: o.batchSaleDate ? fmt(t('readyBatch', 'Empty = the batch date (%s).'), o.batchSaleDate) : t('readyNone', 'Empty = no sale date: the batch has none yet (set it on the supplier order).') })
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

	/*
	 * Dose: medication chips, the amount with its unit, "whole system"
	 * (default when the building has a volume) or this tank only, and the
	 * suggested amount as a chip that fills the field. The server words
	 * the toast; a dose has no undo.
	 */
	function renderDose() {
		var tk = tank();
		var f = state.form;
		var meds = tk.medications || [];
		var systemGallons = tk.system ? parseInt(tk.system.gallons, 10) || 0 : 0;
		if (typeof f.whole === 'undefined') {
			f.whole = systemGallons > 0;
		}
		if (!f.unit) {
			f.unit = 'g';
		}
		function med() {
			for (var i = 0; i < meds.length; i++) {
				if (meds[i].key === f.medication) {
					return meds[i];
				}
			}
			return null;
		}
		app.appendChild(header(t('dose', 'Dose'), ['Tank ' + tk.number + ' · ' + tk.building + (tk.system && tk.system.label ? ' · ' + tk.system.label : '')]));
		var screen = el('div', { class: 'fh-fd-screen' });

		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', text: t('medication', 'Medication') }),
			el('div', { class: 'fh-fd-chips' }, meds.map(function (m) {
				return el('button', {
					type: 'button',
					class: 'fh-fd-chip' + (f.medication === m.key ? ' is-on' : ''),
					text: m.short || m.label,
					on: { click: function () { f.medication = m.key; f.unit = m.unit || f.unit; render(); } }
				});
			}))
		]));

		var chosen = med();
		var suggestion = chosen ? (f.whole ? chosen.system : chosen.tank) : null;
		var amountInput = el('input', { class: 'fh-fd-input fh-fd-input--big', type: 'number', inputmode: 'decimal', step: '0.01', min: '0', max: '9999', placeholder: suggestion !== null && suggestion !== undefined ? String(suggestion) : '', value: f.amount || '', on: { input: function (e) { f.amount = e.target.value; } } });
		var unitSelect = el('select', { class: 'fh-fd-input fh-fd-unit', on: { change: function (e) { f.unit = e.target.value; } } }, ['g', 'ml', 'tsp'].map(function (u) {
			var opt = el('option', { value: u, text: u });
			if (u === f.unit) {
				opt.selected = true;
			}
			return opt;
		}));
		var amountField = el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', text: t('amount', 'Amount') }),
			el('div', { class: 'fh-fd-row fh-fd-row--amount' }, [amountInput, unitSelect])
		]);
		if (chosen) {
			if (suggestion !== null && suggestion !== undefined) {
				amountField.appendChild(el('div', { class: 'fh-fd-chips' }, [
					el('button', { type: 'button', class: 'fh-fd-chip fh-fd-chip--ghost', text: fmt(t('suggested', 'Suggested: %s'), suggestion + ' ' + (chosen.unit || f.unit)), on: { click: function () { f.amount = String(suggestion); f.unit = chosen.unit || f.unit; amountInput.value = f.amount; unitSelect.value = f.unit; } } })
				]));
			} else {
				amountField.appendChild(el('p', { class: 'fh-fd-muted', text: t('noSuggest', 'Dosed to the test reading') }));
			}
		}
		screen.appendChild(amountField);

		var wholeLabel = fmt(t('wholeSystem', 'Whole system — %s'), tk.building + (systemGallons > 0 ? ' · ' + tk.system.label : ''));
		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('div', { class: 'fh-fd-chips' }, [
				el('button', { type: 'button', class: 'fh-fd-chip' + (f.whole ? ' is-on' : ''), text: wholeLabel, on: { click: function () { f.whole = true; render(); } } }),
				el('button', { type: 'button', class: 'fh-fd-chip' + (!f.whole ? ' is-on' : ''), text: t('thisTank', 'This tank only') + ' · ' + tk.number, on: { click: function () { f.whole = false; render(); } } })
			])
		]));
		screen.appendChild(el('p', { class: 'fh-fd-muted', text: t('noUndoDose', 'A dose cannot be undone — note a correction instead.') }));
		app.appendChild(screen);

		bar([
			button(t('cancel', 'Cancel'), 'fh-fd-btn--quiet', function () { go('tank'); }),
			button(t('save', 'Save'), 'fh-fd-btn--primary', function () {
				if (!f.medication) {
					toast(t('medication', 'Medication') + '?', null, true);
					return;
				}
				if (!f.amount || !(parseFloat(f.amount) > 0)) {
					toast(t('amount', 'Amount') + '?', null, true);
					return;
				}
				act({ act: 'dose', tank: tk.number, medication: f.medication, amount: f.amount, unit: f.unit, whole: f.whole ? 1 : 0, via: 'qr' });
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
			if (p.intent === 'arrival') {
				var spoken = p.species && p.species.text ? p.species.text : '';
				chips.appendChild(el('button', { type: 'button', class: 'fh-fd-chip fh-fd-chip--ghost fh-fd-chip--unknown', text: t('unknownFish', 'Unknown fish (??)') + (spoken ? ': ' + spoken : ''), on: { click: function () { chooseForPreview({ name: unknownName(spoken), product_id: 0, unknown: true }, false); } } }));
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
		p.species.unknown = !!item.unknown;
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
			unknown: !!(p && p.intent === 'arrival'), // Only an arrival can be a fish nobody knows yet.
			onChoose: function (item) {
				chooseForPreview(item, !item.unknown);
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
				p.onChoose({ name: f.species, product_id: f.productId || 0, unknown: !!f.unknown });
			} else {
				render();
			}
		}, { unknown: !!p.unknown }));
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
				fields.unknown = sp.unknown ? 1 : 0;
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
	/*  Temporary tanks and the day's list                               */
	/* ---------------------------------------------------------------- */

	/*
	 * A write that chooses its own screen afterwards (act() always goes
	 * back to the tank): the list stays the list after a tick, a setup
	 * started from the picker goes to its slot. The form is kept when the
	 * server refuses.
	 */
	function sideAct(fields, after) {
		busy(true);
		return post('fishotel_cleanroom_act', fields).then(function (data) {
			if (data.screen) {
				state.screen = data.screen;
			}
			state.pending = null;
			state.form = {};
			after(data);
			toast(data.message || t('saved', 'Saved'), null);
			return data;
		}).catch(failToast).then(function () {
			busy(false);
		});
	}

	function renderKeepingScroll() {
		var y = root.pageYOffset || 0;
		render();
		root.scrollTo(0, y);
	}

	/* A temporary tank's line under its heading: its name, kind, purpose, its own water, schedule and day. */
	function tempSub(tk) {
		var su = tk.temp.setup;
		var bits = [];
		if (su.name) {
			bits.push(el('strong', { text: su.name }));
			bits.push(' · ');
		}
		bits.push(tk.building + ' · ');
		bits.push(el('span', { class: 'fh-fd-badge fh-fd-badge--' + (su.kind === 'clean' ? 'cleared' : 'in_qt'), text: su.kindLabel }));
		if (su.purpose) {
			bits.push(' · ' + su.purpose);
		}
		bits.push(' · ' + t('ownWater', 'its own water'));
		if (su.gallons > 0) {
			bits.push(' · ' + su.gallons + ' gal');
		}
		bits.push(' · ' + t('schedule', 'schedule') + ' ' + su.schedule);
		if (su.dayText) {
			bits.push(' · ');
			bits.push(el('strong', { text: su.dayText }));
		}
		return bits;
	}

	/* Under a temporary tank's to-do: its own schedule, and taking it down. */
	function tempTools(tk) {
		var su = tk.temp.setup;
		return el('div', { class: 'fh-fd-temp-tools' }, [
			button(t('scheduleEdit', 'Schedule'), 'fh-fd-btn--quiet', function () { go('schedule'); }),
			button(t('takeDown', 'Take down'), 'fh-fd-btn--quiet', function () {
				if (!su.empty) {
					toast(t('takeDownFish', 'Move the fish out first — then it can be taken down.'), null, true);
					return;
				}
				go('confirm', { kind: 'takeDown' });
			})
		]);
	}

	/* A slot's label was scanned and nothing is set up in it. */
	function renderFreeSlot() {
		var tk = tank();
		app.appendChild(header(tankHeading(tk), [el('strong', { text: tk.building }), ' · ' + t('ownWater', 'its own water')]));
		app.appendChild(el('div', { class: 'fh-fd-empty fh-fd-free' }, [
			el('p', { text: t('tempNothing', 'Nothing is set up here.') }),
			button(t('setUp', 'Set up'), 'fh-fd-btn--primary fh-fd-btn--big', function () { go('setup', { slot: tk.number }); })
		]));
		app.appendChild(el('div', { class: 'fh-fd-links' }, [
			el('a', { href: cfg.baseUrl, text: t('allTanks', 'All tanks') }),
			el('a', { href: cfg.boardUrl, text: t('board', 'Room board') }),
			el('button', { type: 'button', text: t('refresh', 'Refresh'), on: { click: refresh } })
		]));
	}

	function chipRow(items, isOn, onPick, cls) {
		return el('div', { class: 'fh-fd-chips' }, items.map(function (item) {
			return el('button', {
				type: 'button',
				class: 'fh-fd-chip' + (cls ? ' ' + cls : '') + (isOn(item) ? ' is-on' : ''),
				text: item.label,
				on: { click: function () { onPick(item); } }
			});
		}));
	}

	function templateByName(name) {
		var list = (state.screen.temp && state.screen.temp.templates) || [];
		for (var i = 0; i < list.length; i++) {
			if (list[i].name === name) {
				return list[i];
			}
		}
		return null;
	}

	/*
	 * Set up a temporary tank: the slot, a name, what it is for — which
	 * offers the treatments marked for it —, the kind, the gallons, the
	 * treatment (shown, step by step, before anything is saved), its start
	 * date and a note.
	 */
	function renderSetup() {
		var sheet = state.screen.temp || { free: [], tags: [], templates: [] };
		var free = sheet.free || [];
		var f = state.form;
		var p = state.pending || {};
		var tk = tank();
		var fixed = tk && tk.temp && tk.temp.free ? tk.number : 0;
		if (typeof f.slot === 'undefined') {
			f.slot = fixed || p.slot || (free.length ? free[0].number : 0);
		}
		if (!f.start) {
			f.start = state.screen.today;
		}
		if (typeof f.template === 'undefined') {
			f.template = '';
		}
		var slotLabel = '';
		free.forEach(function (slot) {
			if (slot.number === f.slot) {
				slotLabel = slot.label;
			}
		});

		app.appendChild(header(t('setUpTitle', 'Set up a temporary tank'), [slotLabel]));
		var screen = el('div', { class: 'fh-fd-screen' });
		function leave() {
			go(tk ? 'tank' : 'picker');
		}

		if (!free.length) {
			screen.appendChild(el('p', { class: 'fh-fd-muted', text: t('slotsFull', 'Every temporary slot is in use. Take one down, or add a slot in Settings → Layout.') }));
			app.appendChild(screen);
			bar([button(t('back', 'Back'), 'fh-fd-btn--quiet', leave)]);
			return;
		}

		if (!fixed && free.length > 1) {
			screen.appendChild(el('div', { class: 'fh-fd-field' }, [
				el('label', { class: 'fh-fd-label', text: t('tempSlot', 'Slot') }),
				chipRow(free.map(function (slot) { return { label: slot.label, number: slot.number }; }), function (item) { return item.number === f.slot; }, function (item) { f.slot = item.number; render(); }, 'fh-fd-chip--tank')
			]));
		}

		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-tname', text: t('tempName', 'Name') }),
			el('input', { id: 'fh-fd-tname', class: 'fh-fd-input', type: 'text', maxlength: 60, autocomplete: 'off', placeholder: t('tempNameHint', 'Hospital 1'), value: f.name || '', on: { input: function (e) { f.name = e.target.value; } } })
		]));

		// What is it for → the treatment.
		var tags = (sheet.tags || []).map(function (tag) { return { label: tag, tag: tag }; });
		tags.push({ label: t('somethingElse', 'Something else'), other: true });
		var forField = el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', text: t('whatFor', 'What is it for?') }),
			chipRow(tags, function (item) { return item.other ? !!f.other : (!f.other && f.purpose === item.tag); }, function (item) {
				f.other = !!item.other;
				f.purpose = item.other ? '' : item.tag;
				var offered = offerTemplates(sheet.templates, f.purpose);
				if (offered.matching.length) {
					f.template = offered.matching[0];
				}
				render();
			})
		]);
		if (f.other) {
			forField.appendChild(el('input', {
				class: 'fh-fd-input',
				type: 'text',
				maxlength: 120,
				autocomplete: 'off',
				placeholder: t('whatForHint', 'in a few words'),
				value: f.purpose || '',
				on: {
					input: function (e) { f.purpose = e.target.value; },
					// After the typing is done, offer what matches — but never from inside a render
					// (removing the focused field fires this event).
					change: function () { root.setTimeout(function () { if (state.view === 'setup') { render(); } }, 0); }
				}
			}));
		}
		screen.appendChild(forField);

		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', text: t('tempKind', 'What kind of tank?') }),
			el('div', { class: 'fh-fd-kinds' }, [
				el('button', { type: 'button', class: 'fh-fd-kind' + (f.kind === 'quarantine' ? ' is-on' : ''), on: { click: function () { f.kind = 'quarantine'; render(); } } }, [
					el('strong', { text: t('kindQuarantine', 'Quarantine') }),
					el('small', { text: t('kindQuarantineHint', 'for fish still in quarantine or under treatment') })
				]),
				el('button', { type: 'button', class: 'fh-fd-kind' + (f.kind === 'clean' ? ' is-on' : ''), on: { click: function () { f.kind = 'clean'; render(); } } }, [
					el('strong', { text: t('kindClean', 'Clean') }),
					el('small', { text: t('kindCleanHint', 'for fish that are already cleared') })
				])
			])
		]));

		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-tgal', text: t('gallons', 'Gallons') }),
			el('input', { id: 'fh-fd-tgal', class: 'fh-fd-input', type: 'number', inputmode: 'numeric', min: 0, max: 9999, step: 1, placeholder: '10', value: f.gallons || '', on: { input: function (e) { f.gallons = e.target.value; } } })
		]));

		// The treatment: the schedules marked for this purpose first.
		var offered = offerTemplates(sheet.templates, f.purpose);
		var treatField = el('div', { class: 'fh-fd-field' }, [el('label', { class: 'fh-fd-label', text: t('treatment', 'Treatment') })]);
		function pickTemplate(item) {
			f.template = item.name;
			render();
		}
		function isTemplate(item) {
			return f.template === item.name;
		}
		if (offered.matching.length) {
			treatField.appendChild(el('p', { class: 'fh-fd-muted', text: fmt(t('usedForThis', 'Used for %s:'), f.purpose) }));
			treatField.appendChild(chipRow(offered.matching.map(function (name) { return { label: name, name: name }; }), isTemplate, pickTemplate, 'fh-fd-chip--match'));
		} else if (f.purpose) {
			treatField.appendChild(el('p', { class: 'fh-fd-muted', text: fmt(t('noneForTag', 'No schedule is marked "%s" yet — pick any one, or no treatment.'), f.purpose) }));
		}
		treatField.appendChild(chipRow(
			offered.others.map(function (name) { return { label: name, name: name }; }).concat([{ label: t('noTreatment', 'No treatment, just watching'), name: '' }]),
			isTemplate,
			pickTemplate
		));
		var chosen = templateByName(f.template);
		var lines = previewLines(chosen);
		treatField.appendChild(el('div', { class: 'fh-fd-preview' }, [
			el('p', { class: 'fh-fd-muted', text: chosen ? t('whatItDoes', 'What it does — nothing is saved yet:') : t('justFeeding', 'Only the daily feeding, while fish are in it.') }),
			chosen ? el('ul', {}, lines.map(function (line) { return el('li', { text: line }); }).concat([el('li', { class: 'fh-fd-muted', text: t('feedingToo', 'plus the daily feeding') })])) : null
		]));
		screen.appendChild(treatField);

		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-tstart', text: t('startDate', 'Treatment starts (day 1)') }),
			el('input', { id: 'fh-fd-tstart', class: 'fh-fd-input', type: 'date', value: f.start, min: sheet.startMin || null, max: sheet.startMax || null, on: { change: function (e) { f.start = e.target.value; } } })
		]));
		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-tnote', text: t('note', 'Note') }),
			el('textarea', { id: 'fh-fd-tnote', class: 'fh-fd-textarea', text: f.note || '', on: { input: function (e) { f.note = e.target.value; } } })
		]));
		app.appendChild(screen);

		bar([
			button(t('cancel', 'Cancel'), 'fh-fd-btn--quiet', leave),
			button(t('setItUp', 'Set it up'), 'fh-fd-btn--primary', function () {
				if (!f.kind) {
					toast(t('tempKind', 'What kind of tank?'), null, true);
					return;
				}
				sideAct({
					act: 'setup',
					tank: tk ? tk.number : 0,
					slot: f.slot || 0,
					name: f.name || '',
					purpose: f.purpose || '',
					kind: f.kind,
					gallons: f.gallons || '',
					template: f.template || '',
					start_date: f.start || '',
					note: f.note || '',
					via: 'qr'
				}, function (data) {
					if (!tk && data.screen && data.screen.tank) {
						root.location.href = data.screen.tank.url; // Started from the picker: go to the slot's own page.
						return;
					}
					state.view = 'tank';
					render();
				});
			})
		]);
	}

	/*
	 * A temporary tank's own schedule: its copy of the steps. Move one a
	 * day, remove one, add one — for this tank alone; the template stays
	 * as it is.
	 */
	function renderSchedule() {
		var tk = tank();
		var su = tk.temp && tk.temp.setup;
		var f = state.form;
		if (!su) {
			go('tank');
			return;
		}
		function change(fields) {
			fields.act = 'setup_steps';
			fields.tank = tk.number;
			fields.setup_id = su.id; // This setup, as this page shows it — not whatever stands in the slot by now.
			fields.seen = su.seen || '';
			fields.via = 'qr';
			sideAct(fields, function () {
				state.view = 'schedule';
				renderKeepingScroll();
			});
		}
		app.appendChild(header(t('scheduleEdit', 'Schedule'), [tankHeading(tk) + ' · ' + su.schedule + (su.dayText ? ' · ' + su.dayText : '')]));
		var screen = el('div', { class: 'fh-fd-screen' });
		screen.appendChild(el('p', { class: 'fh-fd-muted', text: t('thisTankOnly', 'This tank\'s own schedule: changes here are for this tank only — the template stays as it is. Day 1 is the treatment start.') }));
		screen.appendChild(el('div', { class: 'fh-fd-steps' }, (su.steps || []).map(function (step) {
			var feed = step.kind === 'feed';
			return el('div', { class: 'fh-fd-step' }, [
				el('div', { class: 'fh-fd-step__what' }, [el('strong', { text: step.label }), el('small', { text: step.when })]),
				el('div', { class: 'fh-fd-step__tools' }, [
					feed ? null : el('button', { type: 'button', class: 'fh-fd-chip', text: t('earlier', '−1 day'), disabled: step.day <= 1, on: { click: function () { change({ op: 'move', index: step.index, days: -1 }); } } }),
					feed ? null : el('button', { type: 'button', class: 'fh-fd-chip', text: t('later', '+1 day'), on: { click: function () { change({ op: 'move', index: step.index, days: 1 }); } } }),
					el('button', { type: 'button', class: 'fh-fd-chip fh-fd-chip--ghost', text: t('removeStep', 'Remove'), on: { click: function () { change({ op: 'remove', index: step.index }); } } })
				])
			]);
		})));

		if (!f.kind) {
			f.kind = 'dose';
		}
		if (typeof f.day === 'undefined') {
			f.day = Math.max(1, parseInt(su.day, 10) || 1);
		}
		var kinds = [
			{ label: t('kindDose', 'dose'), kind: 'dose' },
			{ label: t('kindTest', 'test'), kind: 'test' },
			{ label: t('kindWater', 'water change'), kind: 'water_change' },
			{ label: t('kindOther', 'other'), kind: 'other' }
		];
		screen.appendChild(el('h3', { text: t('addStep', 'Add a step') }));
		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			el('label', { class: 'fh-fd-label', for: 'fh-fd-sname', text: t('stepName', 'Step') }),
			el('input', { id: 'fh-fd-sname', class: 'fh-fd-input', type: 'text', maxlength: 80, autocomplete: 'off', value: f.label || '', on: { input: function (e) { f.label = e.target.value; } } })
		]));
		screen.appendChild(el('div', { class: 'fh-fd-field' }, [
			chipRow(kinds, function (item) { return item.kind === f.kind; }, function (item) { f.kind = item.kind; render(); })
		]));
		screen.appendChild(stepperField(t('stepDay', 'Day'), f, 'day', 1, 120));
		screen.appendChild(button(t('addStep', 'Add a step'), 'fh-fd-btn--primary', function () {
			if (!f.label || !f.label.replace(/\s+/g, '')) {
				toast(t('stepName', 'Step') + '?', null, true);
				return;
			}
			change({ op: 'add', label: f.label, kind: f.kind, day: f.day });
		}));
		app.appendChild(screen);
		bar([button(t('back', 'Back'), 'fh-fd-btn--quiet', function () { go('tank'); })]);
	}

	/* Two things are asked before they happen: taking a setup down, and a move that clears. */
	function renderConfirm() {
		var tk = tank();
		var p = state.pending || {};
		var screen = el('div', { class: 'fh-fd-screen' });
		if (p.kind === 'takeDown' && tk.temp && tk.temp.setup) {
			var what = tk.temp.setup.name || tankHeading(tk);
			app.appendChild(header(t('takeDown', 'Take down'), [tankHeading(tk)]));
			screen.appendChild(el('p', { class: 'fh-fd-ask', text: fmt(t('takeDownAsk', 'Take %s down? The slot is free again; its history is kept.'), what) }));
			app.appendChild(screen);
			bar([
				button(t('cancel', 'Cancel'), 'fh-fd-btn--quiet', function () { go('tank'); }),
				button(t('takeDown', 'Take down'), 'fh-fd-btn--danger', function () {
					sideAct({ act: 'takedown', tank: tk.number, setup_id: tk.temp.setup.id, via: 'qr' }, function () {
						state.view = 'tank';
						render();
					});
				})
			]);
			return;
		}
		if (p.kind === 'moveClear' && p.fields && p.dest) {
			var names = (p.moving || []).filter(function (o) { return o.status === 'in_qt'; }).map(function (o) { return o.species; });
			app.appendChild(header(t('move', 'Move'), [(p.dest.title || p.dest.number) + ' · ' + (p.dest.sub || '')]));
			screen.appendChild(el('p', { class: 'fh-fd-ask', text: fmt(t('moveClearAsk', '%1$s is a CLEAN temporary tank. Moving %2$s into it clears it today — out of quarantine.'), (p.dest.title || '') + ' · ' + p.dest.number, names.join(', ')) }));
			app.appendChild(screen);
			bar([
				button(t('back', 'Back'), 'fh-fd-btn--quiet', function () { go('move', p.back || null); }),
				button(t('moveAndClear', 'Move and clear'), 'fh-fd-btn--primary', function () { act(p.fields); })
			]);
			return;
		}
		go('tank');
	}

	/* The Dose sheet on a tank that is its own water: there is no "whole system" to dose. */
	function ownWaterDose() {
		var tk = tank();
		if (!tk || !tk.ownWater) {
			return;
		}
		var prefix = String(t('wholeSystem', 'Whole system — %s')).split('%')[0];
		Array.prototype.forEach.call(app.querySelectorAll('.fh-fd-chip'), function (chip) {
			if (prefix && String(chip.textContent || '').indexOf(prefix) === 0) {
				chip.hidden = true;
				chip.style.display = 'none';
			}
		});
	}

	/* The picker's first entry: the day's list. */
	function todayEntry() {
		var list = state.screen.todoList;
		var left = list ? parseInt(list.left, 10) || 0 : 0;
		var flags = list && list.flags ? list.flags.length : 0;
		return el('button', {
			type: 'button',
			class: 'fh-fd-today-entry' + (list && list.all_done ? ' is-done' : (left > 0 ? ' is-open' : '')),
			on: { click: function () { go('today'); } }
		}, [
			el('span', { class: 'fh-fd-today-entry__title', text: t('today', 'Today') }),
			el('span', { class: 'fh-fd-today-entry__count', text: todoHeadline(list, i18n) + (flags ? ' · ' + fmt(t('flagsN', '%d copper flags'), flags) : '') }),
			el('span', { class: 'fh-fd-today__caret', text: '▸' })
		]);
	}

	/*
	 * Everything due today as one list, by water system: a block per
	 * building (a due step once for the whole system, the feeding as one
	 * line that opens to the tanks) and a block per temporary tank.
	 */
	function renderTodayList() {
		var list = state.screen.todoList;
		var tk = tank();
		app.appendChild(header(t('today', 'Today'), [todoHeadline(list, i18n) + (list && list.date ? ' · ' + list.date : '')]));
		function tick(fields) {
			fields.act = 'todo_tick';
			fields.tank = tk ? tk.number : 0;
			fields.via = 'qr';
			sideAct(fields, function () {
				state.view = 'today';
				renderKeepingScroll();
			});
		}

		if (!list) {
			app.appendChild(el('div', { class: 'fh-fd-empty', text: t('todoNothing', 'Nothing is due today') }));
		} else {
			if (list.flags && list.flags.length) {
				app.appendChild(el('div', { class: 'fh-fd-flags' }, list.flags.map(function (flag) {
					return el('span', { class: 'fh-fd-flag' + (flag.low || flag.overdue ? ' is-bad' : ''), text: flagText(flag, i18n) });
				})));
			}
			(list.blocks || []).forEach(function (block) {
				app.appendChild(todoBlock(block, tick));
			});
			if (!(list.blocks || []).length) {
				app.appendChild(el('div', { class: 'fh-fd-empty', text: t('todoNothing', 'Nothing is due today') }));
			}
		}

		app.appendChild(el('div', { class: 'fh-fd-links' }, [
			tk ? el('button', { type: 'button', text: tankHeading(tk), on: { click: function () { go('tank'); } } }) : null,
			tk ? el('a', { href: cfg.baseUrl, text: t('allTanks', 'All tanks') }) : el('button', { type: 'button', text: t('allTanks', 'All tanks'), on: { click: function () { go('picker'); } } }),
			el('a', { href: cfg.boardUrl, text: t('board', 'Room board') }),
			el('button', { type: 'button', text: t('refresh', 'Refresh'), on: { click: function () {
				busy(true);
				post('fishotel_cleanroom_screen', { tank: tk ? tk.number : 0 }).then(function (data) {
					if (data.screen) {
						state.screen = data.screen;
					}
					renderKeepingScroll();
				}).catch(failToast).then(function () { busy(false); });
			} } })
		]));
	}

	function todoBlock(block, tick) {
		var temp = block.type === 'temp';
		var meta = [];
		if (temp) {
			meta.push(block.kind_label);
			if (block.purpose) {
				meta.push(block.purpose);
			}
			meta.push(t('ownWater', 'its own water') + (block.gallons > 0 ? ' · ' + block.gallons + ' gal' : ''));
		} else if (block.gallons > 0) {
			meta.push(block.gallons + ' gal system');
		}
		if (block.schedule) {
			meta.push(block.schedule);
		}
		if (block.day_text) {
			meta.push(block.day_text);
		}
		if (temp) {
			meta.push(block.fish > 0 ? fmt(t('fishN', '%d fish'), block.fish) : t('noFish', 'no fish in it'));
		} else if (block.tanks > 0) {
			meta.push(fmt(block.tanks === 1 ? t('tanksOne', '%d tank with fish') : t('tanksMany', '%d tanks with fish'), block.tanks));
		}
		var copper = block.copper || {};
		var cu = '';
		if (copper.value !== null && typeof copper.value !== 'undefined') {
			cu = 'Cu ' + plainNumber(copper.value) + (copper.tank ? ' · ' + fmt(t('tankN', 'tank %d'), copper.tank) : '') + (copper.ago ? ' · ' + fmt(t('ago', '%s ago'), copper.ago) : '');
		}
		var items = block.items || [];
		var node = el('div', { class: 'fh-fd-todo-block' + (temp ? ' is-temp' : '') + (items.length && !block.left ? ' is-done' : '') + (!items.length ? ' is-idle' : '') }, [
			el('div', { class: 'fh-fd-todo-block__head' }, [
				temp ? el('span', { class: 'fh-fd-badge fh-fd-badge--temp', text: t('temporary', 'Temporary') }) : null,
				temp && block.url ? el('a', { class: 'fh-fd-todo-block__title', href: block.url, text: block.title }) : el('span', { class: 'fh-fd-todo-block__title', text: block.title }),
				el('small', { text: meta.join(' · ') }),
				cu ? el('span', { class: 'fh-fd-copper' + (copper.state ? ' is-' + copper.state : ''), text: cu }) : null
			])
		]);
		if (!items.length) {
			node.appendChild(el('p', { class: 'fh-fd-muted', text: temp ? t('nothingDueHere', 'Nothing is due on this tank today.') : t('noFishSystem', 'No fish in this system — nothing is due.') }));
			return node;
		}
		items.forEach(function (item) {
			node.appendChild(todoLine(block, item, tick));
		});
		return node;
	}

	function todoLine(block, item, tick) {
		var feed = item.scope === 'feed';
		var detail = lineDetail(item);
		var body = [
			el('span', { class: 'fh-fd-todo-line__step', text: (item.done ? '✓ ' : '') + (feed ? feedText(item, i18n) : item.label) }),
			detail ? el('span', { class: 'fh-fd-todo-line__dose', text: detail }) : null,
			item.for ? el('span', { class: 'fh-fd-todo-line__for', text: fmt(t('forThe', 'for the %s'), item.for) }) : null,
			item.note ? el('span', { class: 'fh-fd-todo-line__note', text: item.note }) : null
		];
		var line = el('div', { class: 'fh-fd-todo-line' + (item.done ? ' is-done' : '') }, [
			el('button', {
				type: 'button',
				class: 'fh-fd-todo-line__tick',
				disabled: !!item.done,
				on: {
					click: function () {
						if (item.scope === 'tank') {
							tick({ scope: 'tank', tick_tank: item.tank, key: item.key, kind: item.kind, label: item.label });
						} else {
							tick({ scope: 'system', building: item.building_id, key: item.key, kind: item.kind, label: item.label });
						}
					}
				}
			}, body)
		]);
		if (feed && (item.tanks || []).length > 1) {
			// The line opens to the tanks: one fed at a time, or all at once with the line itself.
			var id = block.building_id + ':' + item.key;
			var open = !!state.openFeeds[id] || (item.left > 0 && item.left < item.count);
			line.appendChild(el('button', { type: 'button', class: 'fh-fd-todo-line__more', 'aria-expanded': open ? 'true' : 'false', text: t('theTanks', 'the tanks') + ' ' + (open ? '▾' : '▸'), on: { click: function () { state.openFeeds[id] = !open; renderKeepingScroll(); } } }));
			if (open) {
				line.appendChild(el('div', { class: 'fh-fd-chips' }, item.tanks.map(function (fed) {
					return el('button', {
						type: 'button',
						class: 'fh-fd-chip fh-fd-chip--todo' + (fed.done ? ' is-done' : ''),
						disabled: !!fed.done,
						text: (fed.done ? '✓ ' : '') + fed.number,
						on: { click: function () { tick({ scope: 'tank', tick_tank: fed.number, key: item.key, kind: 'feed', label: item.label }); } }
					});
				})));
			}
		}
		return line;
	}

	/* ---------------------------------------------------------------- */
	/*  Boot                                                             */
	/* ---------------------------------------------------------------- */

	// "?list=1" opens the day's list straight away (the Board's "On the phone" link).
	if (/[?&]list=1(&|$)/.test(String(root.location && root.location.search || ''))) {
		state.view = 'today';
	}

	render();
})(typeof window !== 'undefined' ? window : globalThis);
