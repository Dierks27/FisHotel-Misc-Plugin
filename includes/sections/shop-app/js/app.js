/**
 * FisHotel Shop — the app's script. One file, two places:
 *
 *   mode "app"        inlined into the static shell at /?fh_shop_app=1. It
 *                     asks /?fh_shop_app=state what this device is and
 *                     draws: "needs HTTPS", "not set up", the keypad, or —
 *                     with a live shop session — the home screen (who is
 *                     unlocked + Lock, Scan a tank label, buildings then
 *                     tanks). Opening a tank goes to the tank's label
 *                     address, which is the Clean Room's own Front Desk.
 *
 *   mode "frontdesk"  pasted into a Front Desk page that was opened with a
 *                     shop session. It adds a bar (back to the tanks, who
 *                     is unlocked, Lock), shows the keypad again when the
 *                     session ran out, and stands between the page and the
 *                     network: a save that gets no answer opens a sheet —
 *                     MAY NOT BE SAVED, what was entered is still there,
 *                     Retry — instead of vanishing. Every save carries a
 *                     save id, the same on every retry AND when the same
 *                     entry is saved again after "Back to the entry", so
 *                     the server writes it once however often it is sent.
 *                     An entry that can no longer be saved (somebody with
 *                     another account unlocked) is said to be lost before
 *                     the tank is loaded afresh.
 *
 * The keypad is made for wet fingers: twelve very large buttons, no
 * on-screen keyboard, every press lights the key, the PIN is sent the
 * moment its last digit is in, a wrong PIN shakes and clears.
 *
 * Every screen is built with the DOM API from data (never innerHTML).
 * Nothing here is stored on the device: no localStorage, no tank data,
 * no names.
 *
 * THE SEAM FOR AN OFFLINE QUEUE (a later step — nothing is queued today):
 * when a save fails, document gets a "fhshopapp:savefailed" event whose
 * detail is { id, body, reason }, and if window.fhShopApp.onSaveFailed is
 * a function it is asked first: when it returns a Promise of a Response,
 * that Response is handed to the page instead of the sheet being shown.
 * The server half is already there — a repeated save id is answered, not
 * written again.
 *
 * The pure helpers (clock, fmt, tankFromLabel, newSaveId, withSaveId,
 * reuseSaveId, bodyAction, classifyReply, padPress, deadlineFrom,
 * findBuilding) are exported for
 * tests/app.test.js and the file skips the DOM wiring when there is no
 * document / config.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

/* global module */
(function (root) {
	'use strict';

	/* ---------------------------------------------------------------- */
	/*  Pure helpers                                                     */
	/* ---------------------------------------------------------------- */

	/**
	 * printf-lite: the first %d / %s is replaced.
	 *
	 * @param {string} template Template.
	 * @param {*}      value    Value.
	 * @return {string}
	 */
	function fmt(template, value) {
		return String(template || '').replace(/%[ds]/, function () { return String(value); });
	}

	/**
	 * Seconds as a countdown: 4:59, 1:02:03.
	 *
	 * @param {number} seconds Seconds.
	 * @return {string}
	 */
	function clock(seconds) {
		var s = Math.max(0, Math.ceil(Number(seconds) || 0));
		var h = Math.floor(s / 3600);
		var m = Math.floor((s % 3600) / 60);
		var r = s % 60;
		var two = function (n) { return (n < 10 ? '0' : '') + n; };
		return h > 0 ? h + ':' + two(m) + ':' + two(r) : m + ':' + two(r);
	}

	function hostKey(host) {
		return String(host || '').toLowerCase().replace(/:\d+$/, '').replace(/^www\./, '');
	}

	/**
	 * The tank number a scanned label names, or 0 when the text is not a
	 * tank label of this site. Only the number is ever taken from a scan —
	 * the address itself is never opened.
	 *
	 * @param {string} text     What the camera read.
	 * @param {string} siteHost This site's host.
	 * @param {string} labelVar The Front Desk's query parameter.
	 * @return {number}
	 */
	function tankFromLabel(text, siteHost, labelVar) {
		var match = /^https?:\/\/([^\/?#]+)(?:\/[^?#]*)?\?([^#]*)/i.exec(String(text || '').trim());
		if (!match) {
			return 0;
		}
		var want = hostKey(siteHost);
		if (!want || hostKey(match[1]) !== want) {
			return 0;
		}
		var params = {};
		match[2].split('&').forEach(function (pair) {
			var cut = pair.indexOf('=');
			var key = cut < 0 ? pair : pair.slice(0, cut);
			var value = cut < 0 ? '' : pair.slice(cut + 1);
			try {
				key = decodeURIComponent(key);
				value = decodeURIComponent(value);
			} catch (e) {
				return;
			}
			if (!Object.prototype.hasOwnProperty.call(params, key)) {
				params[key] = value;
			}
		});
		var flag = params[String(labelVar || 'fh_front_desk')];
		if (!flag || flag === '0') {
			return 0;
		}
		if (!/^\d{1,9}$/.test(params.tank || '')) {
			return 0;
		}
		return parseInt(params.tank, 10);
	}

	/**
	 * A fresh save id: 32 hex characters.
	 *
	 * @param {Function=} random A source of bytes (tests); default: the browser's.
	 * @return {string}
	 */
	function newSaveId(random) {
		var bytes = [];
		var i;
		if (typeof random === 'function') {
			bytes = random(16);
		} else if (root.crypto && root.crypto.getRandomValues) {
			var arr = new Uint8Array(16);
			root.crypto.getRandomValues(arr);
			for (i = 0; i < arr.length; i++) {
				bytes.push(arr[i]);
			}
		} else {
			for (i = 0; i < 16; i++) {
				bytes.push(Math.floor(Math.random() * 256));
			}
		}
		var out = '';
		for (i = 0; i < 16; i++) {
			var b = (bytes[i] || 0) & 255;
			out += (b < 16 ? '0' : '') + b.toString(16);
		}
		return out;
	}

	/**
	 * A form-encoded body with the save id added (once).
	 *
	 * @param {string} body  The body.
	 * @param {string} field The field's name.
	 * @param {string} id    The save id.
	 * @return {string}
	 */
	function withSaveId(body, field, id) {
		var text = String(body || '');
		var has = text.split('&').some(function (pair) { return pair.split('=')[0] === field; });
		if (has || !id) {
			return text;
		}
		return text + (text ? '&' : '') + field + '=' + id;
	}

	/**
	 * How long a save that got no answer keeps its id for the same entry
	 * saved again (ms).
	 */
	var REUSE_MS = 3600000;

	/**
	 * The save id to send with a body: the id of the last save that got
	 * no answer when this is the very same entry again (the server may
	 * have written it, and answers a known id from memory instead of
	 * writing twice), otherwise ''.
	 *
	 * @param {{body: string, id: string, at: number}|null} last The last save that got no answer.
	 * @param {string} body The body about to be sent (without a save id).
	 * @param {number} now  Date.now().
	 * @return {string}
	 */
	function reuseSaveId(last, body, now) {
		if (!last || !last.id || typeof last.body !== 'string' || last.body !== String(body)) {
			return '';
		}
		var age = Number(now) - Number(last.at);
		return age >= 0 && age < REUSE_MS ? last.id : '';
	}

	/**
	 * The building of that name in a (fresh) picker, or null: the picker
	 * is redrawn from every new state, never from the one a tile was
	 * tapped in.
	 *
	 * @param {Array}  home  state.home.
	 * @param {{area: string, label: string}|null} open The building that is open.
	 * @return {Object|null}
	 */
	function findBuilding(home, open) {
		var found = null;
		if (!open) {
			return null;
		}
		(home || []).forEach(function (area) {
			if (found || !area || String(area.label) !== String(open.area)) {
				return;
			}
			(area.buildings || []).forEach(function (b) {
				if (!found && b && String(b.label) === String(open.label)) {
					found = b;
				}
			});
		});
		return found;
	}

	/**
	 * The "action" of a form-encoded body.
	 *
	 * @param {string} body The body.
	 * @return {string}
	 */
	function bodyAction(body) {
		var found = '';
		String(body || '').split('&').some(function (pair) {
			var cut = pair.indexOf('=');
			if ((cut < 0 ? pair : pair.slice(0, cut)) === 'action') {
				try {
					found = decodeURIComponent((cut < 0 ? '' : pair.slice(cut + 1)).replace(/\+/g, ' '));
				} catch (e) {
					found = '';
				}
				return true;
			}
			return false;
		});
		return found;
	}

	/**
	 * What an answer to a Front Desk request means for the app:
	 *   ok         hand it to the page (also its own "no": a 400 with a
	 *              message, a 403 for a stale page);
	 *   pending    the same save is still running on the server (409);
	 *   signedout  WordPress did not know the device (admin-ajax's bare 0);
	 *   server     the site failed (5xx);
	 *   garbled    a 200 that is not JSON (a Wi-Fi login page, a proxy).
	 *
	 * @param {number} status HTTP status.
	 * @param {string} text   The body.
	 * @return {string}
	 */
	function classifyReply(status, text) {
		var body = String(text === null || typeof text === 'undefined' ? '' : text).trim();
		if (status === 409) {
			return 'pending';
		}
		if (status >= 500) {
			return 'server';
		}
		if (status === 400 && (body === '0' || body === '')) {
			return 'signedout';
		}
		if (status === 200) {
			try {
				var json = JSON.parse(body);
				return json !== null && typeof json === 'object' ? 'ok' : 'garbled';
			} catch (e) {
				return 'garbled';
			}
		}
		return 'ok';
	}

	/**
	 * One press on the keypad.
	 *
	 * @param {string} digits What is entered so far.
	 * @param {string} key    0-9, "back" or "clear".
	 * @param {number} length The PIN length.
	 * @return {{digits: string, submit: boolean}}
	 */
	function padPress(digits, key, length) {
		var now = String(digits || '');
		if (key === 'back') {
			now = now.slice(0, -1);
		} else if (key === 'clear') {
			now = '';
		} else if (/^\d$/.test(String(key)) && now.length < length) {
			now += String(key);
		}
		return { digits: now, submit: now.length === length };
	}

	/**
	 * When (in this device's clock, ms) a session ends, from what the
	 * server said: the two clocks need not agree, only the time left.
	 *
	 * @param {{expires: number, now: number}} session The session as sent.
	 * @param {number} receivedAt Date.now() when it arrived.
	 * @return {number}
	 */
	function deadlineFrom(session, receivedAt) {
		if (!session) {
			return 0;
		}
		return receivedAt + Math.max(0, (Number(session.expires) || 0) - (Number(session.now) || 0)) * 1000;
	}

	var api = {
		fmt: fmt,
		clock: clock,
		tankFromLabel: tankFromLabel,
		newSaveId: newSaveId,
		withSaveId: withSaveId,
		reuseSaveId: reuseSaveId,
		findBuilding: findBuilding,
		bodyAction: bodyAction,
		classifyReply: classifyReply,
		padPress: padPress,
		deadlineFrom: deadlineFrom
	};

	root.fhShopAppHelpers = api;

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}

	if (typeof document === 'undefined' || !root.fhShopApp) {
		return;
	}

	/* ---------------------------------------------------------------- */
	/*  Shared                                                           */
	/* ---------------------------------------------------------------- */

	var cfg = root.fhShopApp;
	var i18n = cfg.i18n || {};
	var urls = cfg.urls || {};
	var nativeFetch = root.fetch ? root.fetch.bind(root) : null;
	var TIMEOUT_MS = 25000;

	function t(key, fallback) {
		return i18n && i18n[key] ? i18n[key] : fallback;
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
			} else if (key === 'disabled' || key === 'hidden') {
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

	function empty(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function secure() {
		if (typeof root.isSecureContext === 'boolean') {
			return root.isSecureContext;
		}
		return root.location.protocol === 'https:';
	}

	/* The app's own endpoints. Every call carries the app header; nothing is cached. */
	function call(url, method, fields) {
		var init = { method: method, credentials: 'same-origin', cache: 'no-store', headers: {} };
		init.headers[cfg.header || 'X-FH-Shop-App'] = '1';
		if (method === 'POST') {
			var body = new URLSearchParams();
			Object.keys(fields || {}).forEach(function (key) { body.append(key, fields[key]); });
			init.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
			init.body = body.toString();
		}
		if (!nativeFetch) {
			return Promise.reject(new Error('no fetch'));
		}
		return nativeFetch(url, init).then(function (res) { return res.json(); });
	}

	var server = {
		state: function () { return call(urls.state, 'GET'); },
		unlock: function (pin) { return call(urls.unlock, 'POST', { pin: pin }); },
		lock: function () { return call(urls.lock, 'POST', {}); }
	};

	function tankUrl(number) {
		var base = String(urls.label || '');
		return base + (base.indexOf('?') === -1 ? '?' : '&') + 'tank=' + encodeURIComponent(String(number));
	}

	/* ---------------------------------------------------------------- */
	/*  The keypad                                                       */
	/* ---------------------------------------------------------------- */

	/*
	 * keypad({ length, title, note, lockSeconds, onPin }) → { node, lock(seconds), stop() }
	 * onPin(pin) returns a Promise of the server's reply ({ ok, code, seconds, left }).
	 */
	function keypad(opts) {
		var length = Math.min(6, Math.max(4, parseInt(opts.length, 10) || 4));
		var digits = '';
		var busy = false;
		var lockedUntil = 0;
		var timer = null;

		var dots = el('div', { class: 'fh-sa-dots', role: 'status', 'aria-live': 'polite' });
		var message = el('p', { class: 'fh-sa-pad__msg', role: 'alert' });
		var grid = el('div', { class: 'fh-sa-keys' });
		var node = el('div', { class: 'fh-sa-pad' }, [
			el('h1', { class: 'fh-sa-pad__title', text: opts.title || t('enterPin', 'Enter your PIN') }),
			opts.note ? el('p', { class: 'fh-sa-pad__note', text: opts.note }) : null,
			dots,
			message,
			grid
		]);

		function drawDots() {
			empty(dots);
			for (var i = 0; i < length; i++) {
				dots.appendChild(el('span', { class: 'fh-sa-dot' + (i < digits.length ? ' is-on' : '') }));
			}
			dots.setAttribute('aria-label', digits.length + ' / ' + length);
		}

		function say(text, bad) {
			message.textContent = text || '';
			message.className = 'fh-sa-pad__msg' + (bad ? ' is-bad' : '');
		}

		function isLocked() {
			return lockedUntil > Date.now();
		}

		function tick() {
			if (!isLocked()) {
				root.clearInterval(timer);
				timer = null;
				lockedUntil = 0;
				node.classList.remove('is-locked');
				say('');
				return;
			}
			say(fmt(t('lockedFor', 'This device is locked. Try again in %s.'), clock((lockedUntil - Date.now()) / 1000)), true);
		}

		function lock(seconds) {
			lockedUntil = Date.now() + Math.max(1, Number(seconds) || 0) * 1000;
			digits = '';
			drawDots();
			node.classList.add('is-locked');
			root.clearInterval(timer);
			timer = root.setInterval(tick, 500);
			tick();
		}

		function shake() {
			node.classList.remove('is-wrong');
			// Reading a layout property restarts the animation.
			void node.offsetWidth;
			node.classList.add('is-wrong');
			if (root.navigator && root.navigator.vibrate) {
				try { root.navigator.vibrate([60, 40, 60]); } catch (e) { /* no vibration */ }
			}
		}

		function send() {
			var pin = digits;
			busy = true;
			node.classList.add('is-busy');
			say(t('checking', 'Checking…'));
			opts.onPin(pin).then(function (reply) {
				busy = false;
				node.classList.remove('is-busy');
				digits = '';
				drawDots();
				if (reply && reply.ok) {
					say('');
					return;
				}
				if (reply && reply.code === 'locked') {
					shake();
					lock(reply.seconds || 5);
					return;
				}
				if (reply && reply.code === 'wrong_pin') {
					shake();
					var left = typeof reply.left === 'number' ? reply.left : -1;
					var more = left === 1 ? t('oneTryLeft', '1 try left') : (left > 1 ? fmt(t('triesLeft', '%d tries left'), left) : '');
					say(t('wrongPin', 'Wrong PIN') + (more ? ' · ' + more : ''), true);
					return;
				}
				say(t('error', 'Something went wrong. Try again.'), true);
			}).catch(function () {
				busy = false;
				node.classList.remove('is-busy');
				digits = '';
				drawDots();
				say(t('offline', 'No connection.'), true);
			});
		}

		function press(key) {
			if (busy || isLocked()) {
				return;
			}
			var next = padPress(digits, key, length);
			digits = next.digits;
			drawDots();
			if (message.className.indexOf('is-bad') !== -1 && digits.length) {
				say('');
			}
			if (next.submit) {
				send();
			}
		}

		function key(label, value, extra) {
			var button = el('button', {
				type: 'button',
				class: 'fh-sa-key' + (extra ? ' ' + extra : ''),
				'aria-label': value === 'back' ? t('delete', 'Delete') : (value === 'clear' ? t('clear', 'Clear') : label),
				text: label
			});
			// Every press shows at once, and long enough to be seen through a wet screen.
			button.addEventListener('pointerdown', function () {
				button.classList.add('is-pressed');
				root.setTimeout(function () { button.classList.remove('is-pressed'); }, 180);
				if (root.navigator && root.navigator.vibrate && !isLocked() && !busy) {
					try { root.navigator.vibrate(8); } catch (e) { /* no vibration */ }
				}
			});
			button.addEventListener('click', function () { press(value); });
			return button;
		}

		['1', '2', '3', '4', '5', '6', '7', '8', '9'].forEach(function (d) { grid.appendChild(key(d, d)); });
		grid.appendChild(key(t('clear', 'Clear'), 'clear', 'fh-sa-key--word'));
		grid.appendChild(key('0', '0'));
		grid.appendChild(key('⌫', 'back', 'fh-sa-key--word'));

		// A real keyboard works too (a desk, a test) — the on-screen one never opens: there is no input.
		function onKey(e) {
			if (e.ctrlKey || e.metaKey || e.altKey || !node.isConnected) {
				return;
			}
			if (/^\d$/.test(e.key)) {
				press(e.key);
				e.preventDefault();
			} else if (e.key === 'Backspace') {
				press('back');
				e.preventDefault();
			} else if (e.key === 'Escape') {
				press('clear');
			}
		}
		document.addEventListener('keydown', onKey);

		drawDots();
		if (opts.lockSeconds > 0) {
			lock(opts.lockSeconds);
		}

		return {
			node: node,
			lock: lock,
			stop: function () {
				root.clearInterval(timer);
				document.removeEventListener('keydown', onKey);
			}
		};
	}

	/* ---------------------------------------------------------------- */
	/*  Mode "app": the shell                                            */
	/* ---------------------------------------------------------------- */

	function startApp() {
		var app = document.getElementById('fh-sa-app');
		if (!app) {
			return;
		}

		var state = null;
		var pad = null;
		var deadline = 0;
		var building = null; // The open building as the CURRENT state has it.
		var open = null;     // Which one that is: { area, label }.
		var installEvent = null;
		var canScan = false;
		var scanner = null;
		var wantTank = (function () {
			var m = /[?&]tank=(\d{1,9})(?:&|$)/.exec(root.location.search || '');
			return m ? parseInt(m[1], 10) : 0;
		})();

		function clear() {
			if (pad) {
				pad.stop();
				pad = null;
			}
			empty(app);
		}

		function notice(lines, actions) {
			clear();
			app.appendChild(el('div', { class: 'fh-sa-notice' }, [
				el('div', { class: 'fh-sa-brand', text: t('title', 'FisHotel Shop') })
			].concat(lines.map(function (line, i) {
				return el('p', { class: i === 0 ? 'fh-sa-notice__lead' : 'fh-sa-notice__more', text: line });
			})).concat(actions || [])));
		}

		function bigLink(label, href, cls) {
			return el('a', { class: 'fh-sa-btn ' + (cls || ''), href: href, text: label });
		}

		function bigButton(label, cls, onClick) {
			return el('button', { type: 'button', class: 'fh-sa-btn ' + (cls || ''), text: label, on: { click: onClick } });
		}

		function installButton() {
			if (!installEvent) {
				return null;
			}
			return bigButton(t('install', 'Install app'), 'fh-sa-btn--quiet', function () {
				var evt = installEvent;
				installEvent = null;
				try {
					evt.prompt();
				} catch (e) { /* the browser changed its mind */ }
				render(state);
			});
		}

		function wpLoginNote() {
			if (!state || !state.wpLogin) {
				return null;
			}
			return el('div', { class: 'fh-sa-warn' }, [
				el('span', { text: fmt(t('wpLogin', 'This device is also logged in to WordPress as %s, so the tank labels open without a PIN.'), state.wpLogin.name) }),
				' ',
				el('a', { href: state.wpLogin.logoutUrl, text: t('wpLogout', 'Log out of WordPress here') })
			]);
		}

		function showOffline() {
			notice([t('offline', 'No connection.')], [bigButton(t('retry', 'Retry'), 'fh-sa-btn--primary', refresh)]);
		}

		function showNotEnrolled() {
			var actions = [];
			if (state.adminUrl) {
				actions.push(bigLink(t('setUpHere', 'Set up with a manager login'), state.adminUrl, 'fh-sa-btn--primary'));
			}
			if (state.frontDesk && state.frontDesk.available && urls.label) {
				actions.push(bigLink(t('openFrontDesk', 'Open the Front Desk with a WordPress login'), urls.label, 'fh-sa-btn--quiet'));
			}
			var install = installButton();
			if (install) {
				actions.push(install);
			}
			notice([t('notEnrolled', 'This device is not set up for the Shop App.'), t('notEnrolledHow', '')], actions);
		}

		function showPad() {
			clear();
			if (!state.hasPeople) {
				notice([t('noPeople', 'Nobody has a PIN yet.')], [bigButton(t('retry', 'Retry'), 'fh-sa-btn--quiet', refresh)]);
				return;
			}
			pad = keypad({
				length: state.pinLength,
				title: t('enterPin', 'Enter your PIN'),
				note: state.device ? state.device.name : '',
				lockSeconds: state.lockout && state.lockout.locked ? state.lockout.seconds : 0,
				onPin: function (pin) {
					return server.unlock(pin).then(function (reply) {
						if (reply && reply.ok && reply.state) {
							root.setTimeout(function () { accept(reply.state); }, 0);
						} else if (reply && reply.code === 'not_enrolled' && reply.state) {
							root.setTimeout(function () { accept(reply.state); }, 0);
						}
						return reply;
					});
				}
			});
			app.appendChild(el('div', { class: 'fh-sa-brand fh-sa-brand--pad', text: t('title', 'FisHotel Shop') }));
			app.appendChild(pad.node);
			var foot = el('div', { class: 'fh-sa-foot' }, [wpLoginNote(), installButton()]);
			app.appendChild(foot);
		}

		function knownTank(number) {
			var found = false;
			((state && state.home) || []).forEach(function (area) {
				(area.buildings || []).forEach(function (b) {
					(b.tanks || []).forEach(function (tk) {
						if (tk.number === number) {
							found = true;
						}
					});
				});
			});
			return found;
		}

		function openTank(number) {
			root.location.href = tankUrl(number);
		}

		function lockNow() {
			server.lock().then(function (reply) {
				open = null;
				accept(reply && reply.state ? reply.state : null);
			}).catch(function () {
				// The lock could not reach the server: say so rather than pretend.
				showOffline();
			});
		}

		function showHome() {
			clear();
			var head = el('div', { class: 'fh-sa-top' }, [
				el('div', { class: 'fh-sa-who' }, [
					el('span', { class: 'fh-sa-who__name', text: state.session.person }),
					el('span', { class: 'fh-sa-who__device', text: state.device ? state.device.name : '' })
				]),
				bigButton(t('lock', 'Lock'), 'fh-sa-btn--lock', lockNow)
			]);
			app.appendChild(head);

			if (!state.frontDesk || !state.frontDesk.available) {
				app.appendChild(el('div', { class: 'fh-sa-notice' }, [
					el('p', { class: 'fh-sa-notice__lead', text: t('noFrontDesk', 'There are no tank screens to open.') }),
					el('p', { class: 'fh-sa-notice__more', text: (state.frontDesk && state.frontDesk.reason) || '' })
				]));
			} else if (building) {
				showTanks();
			} else {
				showBuildings();
			}

			var links = (state.links || []).map(function (link) {
				return bigLink(link.label, link.url, 'fh-sa-btn--quiet');
			});
			app.appendChild(el('div', { class: 'fh-sa-foot' }, links.concat([installButton()])));
		}

		function showBuildings() {
			if (canScan) {
				app.appendChild(bigButton(t('scan', 'Scan a tank label'), 'fh-sa-btn--primary fh-sa-btn--scan', openScanner));
			}
			app.appendChild(el('h2', { class: 'fh-sa-h', text: t('pickBuilding', 'Pick a building') }));
			var any = false;
			(state.home || []).forEach(function (area) {
				if (!(area.buildings || []).length) {
					return;
				}
				app.appendChild(el('h3', { class: 'fh-sa-area', text: area.label }));
				var grid = el('div', { class: 'fh-sa-grid' });
				(area.buildings || []).forEach(function (b) {
					any = true;
					var coming = b.status === 'coming_soon';
					grid.appendChild(el('button', {
						type: 'button',
						class: 'fh-sa-tile' + (b.inQt > 0 ? ' is-qt' : ''),
						disabled: coming || !(b.tanks || []).length,
						on: { click: function () { open = { area: area.label, label: b.label }; building = b; showHome(); root.scrollTo(0, 0); } }
					}, [
						el('span', { class: 'fh-sa-tile__name', text: b.label }),
						el('span', { class: 'fh-sa-tile__sub', text: coming ? t('comingSoon', 'coming soon') : fmt(t('tankCount', '%d tanks'), (b.tanks || []).length) + (b.inQt > 0 ? ' · ' + fmt(t('inQt', 'QT %d'), b.inQt) : '') })
					]));
				});
				app.appendChild(grid);
			});
			if (!any) {
				app.appendChild(el('p', { class: 'fh-sa-notice__more', text: t('noTanks', 'No buildings or tanks yet.') }));
			}
		}

		function showTanks() {
			app.appendChild(el('div', { class: 'fh-sa-crumb' }, [
				bigButton('‹ ' + t('back', 'Back'), 'fh-sa-btn--quiet fh-sa-btn--back', function () { open = null; building = null; showHome(); root.scrollTo(0, 0); }),
				el('h2', { class: 'fh-sa-h', text: building.label })
			]));
			app.appendChild(el('p', { class: 'fh-sa-sub', text: t('pickTank', 'Pick a tank') }));
			var grid = el('div', { class: 'fh-sa-grid fh-sa-grid--tanks' });
			(building.tanks || []).forEach(function (tk) {
				grid.appendChild(el('button', {
					type: 'button',
					class: 'fh-sa-tile fh-sa-tile--tank' + (tk.empty ? ' is-empty' : ''),
					on: { click: function () { openTank(tk.number); } }
				}, [
					el('span', { class: 'fh-sa-tile__name', text: String(tk.number) }),
					el('span', { class: 'fh-sa-tile__sub', text: tk.empty ? t('empty', 'empty') : tk.count + (tk.size ? ' · ' + tk.size : '') })
				]));
			});
			app.appendChild(grid);
		}

		/* The camera, inside the app. Only the tank number is taken from what it reads. */
		function openScanner() {
			if (scanner) {
				return;
			}
			var video = el('video', { class: 'fh-sa-scan__video', playsinline: '', muted: '', autoplay: '' });
			var hint = el('p', { class: 'fh-sa-scan__hint', text: t('scanHint', 'Point the camera at the label on the tank.') });
			var layer = el('div', { class: 'fh-sa-scan' }, [
				video,
				el('div', { class: 'fh-sa-scan__frame' }),
				hint,
				bigButton(t('cancel', 'Cancel'), 'fh-sa-btn--quiet fh-sa-scan__cancel', function () { closeScanner(); })
			]);
			scanner = { layer: layer, stream: null, timer: null, busy: false };
			document.body.appendChild(layer);

			var detector;
			try {
				detector = new root.BarcodeDetector({ formats: ['qr_code'] });
			} catch (e) {
				hint.textContent = t('scanNoCamera', 'The camera could not be opened. Pick the tank instead.');
				return;
			}

			root.navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }).then(function (stream) {
				if (!scanner) {
					stream.getTracks().forEach(function (track) { track.stop(); });
					return;
				}
				scanner.stream = stream;
				video.srcObject = stream;
				var playing = video.play();
				if (playing && playing.catch) {
					playing.catch(function () { /* autoplay refused: the stream still shows */ });
				}
				scanner.timer = root.setInterval(function () {
					if (!scanner || scanner.busy || video.readyState < 2) {
						return;
					}
					scanner.busy = true;
					detector.detect(video).then(function (codes) {
						if (!scanner) {
							return;
						}
						scanner.busy = false;
						for (var i = 0; i < (codes || []).length; i++) {
							var number = tankFromLabel(codes[i].rawValue, root.location.host, cfg.labelVar);
							if (!number) {
								hint.textContent = t('scanNotLabel', 'That is not a FisHotel tank label.');
								continue;
							}
							if (!knownTank(number)) {
								hint.textContent = fmt(t('scanUnknown', 'Tank %d is not in the layout.'), number);
								continue;
							}
							closeScanner();
							openTank(number);
							return;
						}
					}).catch(function () {
						if (scanner) {
							scanner.busy = false;
						}
					});
				}, 250);
			}).catch(function () {
				hint.textContent = t('scanNoCamera', 'The camera could not be opened. Pick the tank instead.');
			});
		}

		function closeScanner() {
			if (!scanner) {
				return;
			}
			root.clearInterval(scanner.timer);
			if (scanner.stream) {
				scanner.stream.getTracks().forEach(function (track) { track.stop(); });
			}
			if (scanner.layer.parentNode) {
				scanner.layer.parentNode.removeChild(scanner.layer);
			}
			scanner = null;
		}

		function render(next) {
			state = next;
			// The open building is looked up again in what just arrived: a
			// tile must never show what an older state said about a tank.
			building = state && state.session ? findBuilding(state.home, open) : null;
			if (!building) {
				open = null;
			}
			closeScanner();
			if (!state) {
				showOffline();
				return;
			}
			if (!state.https) {
				notice([t('needsHttps', 'The Shop App needs HTTPS.')]);
				return;
			}
			if (!state.enrolled) {
				showNotEnrolled();
				return;
			}
			if (!state.session) {
				showPad();
				return;
			}
			if (wantTank) {
				// Came from a tank label: straight on to that tank.
				var number = wantTank;
				wantTank = 0;
				root.location.replace(tankUrl(number));
				return;
			}
			showHome();
		}

		/* What decides which screen is shown. */
		function signature(st) {
			if (!st) {
				return 'none';
			}
			return [
				st.https ? 1 : 0,
				st.enrolled ? 1 : 0,
				st.hasPeople ? 1 : 0,
				st.pinLength || 0,
				st.session ? st.session.person + '|' + st.session.account : '',
				st.lockout && st.lockout.locked ? 1 : 0,
				st.frontDesk && st.frontDesk.available ? 1 : 0,
				st.wpLogin ? st.wpLogin.name : ''
			].join('~');
		}

		function accept(next) {
			deadline = next && next.session ? deadlineFrom(next.session, Date.now()) : 0;
			if (pad && state && !wantTank && signature(next) === signature(state)) {
				// The keypad is up and nothing changed: what is typed stays typed.
				state = next;
				return;
			}
			render(next);
		}

		function refresh() {
			server.state().then(accept).catch(function () {
				if (!state) {
					showOffline();
				}
			});
		}

		if (!secure()) {
			notice([t('needsHttps', 'The Shop App needs HTTPS.')]);
			return;
		}

		// When the session runs out the screen must stop saying somebody is unlocked.
		root.setInterval(function () {
			if (state && state.session && deadline && Date.now() > deadline) {
				refresh();
			}
		}, 15000);
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) {
				refresh();
			}
		});
		root.addEventListener('pageshow', function (e) {
			if (e.persisted) {
				refresh();
			}
		});

		root.addEventListener('beforeinstallprompt', function (e) {
			e.preventDefault();
			installEvent = e;
			if (state) {
				render(state);
			}
		});
		root.addEventListener('appinstalled', function () {
			installEvent = null;
			if (state) {
				render(state);
			}
		});

		if ('serviceWorker' in root.navigator && urls.sw) {
			root.navigator.serviceWorker.register(urls.sw, { scope: urls.swScope }).catch(function () { /* the app works without it */ });
		}

		if ('BarcodeDetector' in root && root.navigator.mediaDevices && root.navigator.mediaDevices.getUserMedia) {
			var formats = root.BarcodeDetector.getSupportedFormats ? root.BarcodeDetector.getSupportedFormats() : Promise.resolve(['qr_code']);
			formats.then(function (list) {
				canScan = (list || []).indexOf('qr_code') !== -1;
				if (canScan && state && state.session) {
					render(state);
				}
			}).catch(function () { canScan = false; });
		}

		refresh();
	}

	/* ---------------------------------------------------------------- */
	/*  Mode "frontdesk": a tank screen inside the app                   */
	/* ---------------------------------------------------------------- */

	function startFrontDesk() {
		var session = cfg.session || null;
		var deadline = deadlineFrom(session, Date.now());
		var nameNode = el('span', { class: 'fh-sa-bar__name', text: session ? session.person : '' });
		var layer = null;
		var layerPad = null;
		var sheet = null;
		var retryNow = null;
		var waiting = [];     // What waits for a PIN: { then, write }.
		var unsure = null;    // The last save that got no answer: { body, id, at }.

		/* The bar. */
		var bar = el('div', { class: 'fh-sa-bar' }, [
			el('a', { class: 'fh-sa-bar__home', href: urls.app, text: '‹ ' + t('tanks', 'Tanks') }),
			nameNode,
			el('button', {
				type: 'button',
				class: 'fh-sa-bar__lock',
				text: t('lock', 'Lock'),
				on: {
					click: function () {
						server.lock().then(function () {
							root.location.href = urls.app;
						}).catch(function () {
							root.location.href = urls.app;
						});
					}
				}
			})
		]);
		document.body.insertBefore(bar, document.body.firstChild);
		document.body.classList.add('fh-sa-inside');

		// wp-admin is not part of a shop session: its links would only lead to a login page.
		if (urls.admin) {
			var hide = document.createElement('style');
			hide.textContent = 'a[href^="' + String(urls.admin).replace(/["\\]/g, '') + '"]{display:none !important}';
			document.head.appendChild(hide);
		}

		function contact() {
			if (session && session.mode === 'last_use') {
				deadline = Date.now() + (Number(session.length) || 0) * 1000;
			}
		}

		function adopt(next) {
			session = next;
			deadline = deadlineFrom(next, Date.now());
			nameNode.textContent = next ? next.person : '';
		}

		/* The keypad again, over the page, when the session is gone. */
		function showPin(note, st) {
			if (layer) {
				return;
			}
			if (!st || !st.enrolled || !st.hasPeople) {
				// Not a device any more (revoked), or nobody can unlock: the app says which.
				root.location.href = urls.app;
				return;
			}
			layerPad = keypad({
				length: st.pinLength || cfg.pinLength,
				title: t('enterPin', 'Enter your PIN'),
				note: note,
				lockSeconds: st.lockout && st.lockout.locked ? st.lockout.seconds : 0,
				onPin: function (pin) {
					return server.unlock(pin).then(function (reply) {
						if (reply && reply.ok && reply.state && reply.state.session) {
							root.setTimeout(function () { unlocked(reply.state.session); }, 0);
						} else if (reply && reply.code === 'not_enrolled') {
							root.location.href = urls.app;
						}
						return reply;
					});
				}
			});
			layer = el('div', { class: 'fh-sa-layer' }, [
				el('div', { class: 'fh-sa-brand fh-sa-brand--pad', text: t('title', 'FisHotel Shop') }),
				layerPad.node
			]);
			document.body.appendChild(layer);
		}

		/* Ask for the PIN; then() once somebody unlocked on the same account. */
		function askPin(note, then, st, isWrite) {
			if (then) {
				waiting.push({ then: then, write: !!isWrite });
			}
			showPin(note, st);
		}

		function closePin() {
			if (layerPad) {
				layerPad.stop();
				layerPad = null;
			}
			if (layer && layer.parentNode) {
				layer.parentNode.removeChild(layer);
			}
			layer = null;
		}

		/*
		 * An entry was waiting for a PIN and somebody with another account
		 * unlocked: it cannot be saved as theirs, and it is not saved. Say
		 * so — the tank is loaded afresh only when that was read.
		 */
		function lostEntry(next) {
			closePin();
			closeSheet();
			var ok = el('button', { type: 'button', class: 'fh-sa-btn fh-sa-btn--primary', text: t('lostOk', 'OK — enter it again') });
			ok.addEventListener('click', function () { root.location.reload(); });
			sheet = el('div', { class: 'fh-sa-sheet fh-sa-sheet--lost', role: 'alertdialog', 'aria-modal': 'true' }, [
				el('div', { class: 'fh-sa-sheet__box' }, [
					el('h2', { class: 'fh-sa-sheet__title', text: t('lostTitle', 'NOT SAVED') }),
					el('p', { class: 'fh-sa-sheet__why', text: fmt(t('lostWhy', 'The entry that was waiting was made before %s unlocked. It was not saved — enter it again.'), next && next.person ? next.person : '') }),
					ok
				])
			]);
			document.body.appendChild(sheet);
		}

		function unlocked(next) {
			var same = session && next && next.account === session.account;
			if (!same) {
				// Another account: this page's nonce is not theirs, so nothing
				// that waits here can be sent as them. Start the tank afresh —
				// but never drop an entry without a word.
				var lost = waiting.some(function (w) { return w.write; });
				waiting = [];
				if (lost) {
					lostEntry(next);
					return;
				}
				root.location.reload();
				return;
			}
			adopt(next);
			closePin();
			var list = waiting;
			waiting = [];
			list.forEach(function (w) { w.then(true); });
		}

		function checkSession() {
			if (layer) {
				return;
			}
			server.state().then(function (st) {
				if (st && st.session) {
					if (session && st.session.account !== session.account) {
						root.location.reload();
						return;
					}
					adopt(st.session);
				} else if (st) {
					askPin(t('sessionEnded', 'Locked. Enter your PIN to go on.'), null, st);
				}
			}).catch(function () { /* offline: the next action will tell */ });
		}

		root.setInterval(function () {
			if (!layer && deadline && Date.now() > deadline) {
				checkSession();
			}
		}, 15000);
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden && deadline && Date.now() > deadline) {
				checkSession();
			}
		});
		root.addEventListener('pageshow', function (e) {
			if (e.persisted) {
				checkSession();
			}
		});

		/* The sheet: MAY NOT BE SAVED · Retry. */
		function closeSheet() {
			if (sheet && sheet.parentNode) {
				sheet.parentNode.removeChild(sheet);
			}
			sheet = null;
			retryNow = null;
		}

		function openSheet(reason, onRetry, onClose) {
			closeSheet();
			var status = el('p', { class: 'fh-sa-sheet__status', role: 'status' });
			var retry = el('button', { type: 'button', class: 'fh-sa-btn fh-sa-btn--primary fh-sa-sheet__retry', text: t('retry', 'Retry') });
			var back = el('button', { type: 'button', class: 'fh-sa-btn fh-sa-btn--quiet', text: t('keepEditing', 'Back to the entry') });
			retryNow = function () {
				if (retry.disabled) {
					return;
				}
				retry.disabled = true;
				back.disabled = true;
				status.textContent = t('saving', 'Saving…');
				onRetry();
			};
			retry.addEventListener('click', retryNow);
			back.addEventListener('click', function () {
				closeSheet();
				onClose();
			});
			sheet = el('div', { class: 'fh-sa-sheet', role: 'alertdialog', 'aria-modal': 'true' }, [
				el('div', { class: 'fh-sa-sheet__box' }, [
					el('h2', { class: 'fh-sa-sheet__title', text: t('notSaved', 'MAY NOT BE SAVED') }),
					el('p', { class: 'fh-sa-sheet__why', text: reason === 'server' ? t('notSavedServer', 'The site had a problem and may not have saved it.') : t('notSavedWhy', 'No answer came back, so it may not have been saved.') }),
					status,
					retry,
					back
				])
			]);
			document.body.appendChild(sheet);
		}

		// The connection is back while the sheet is open: try at once.
		root.addEventListener('online', function () {
			if (retryNow) {
				retryNow();
			}
		});

		function announce(detail) {
			try {
				document.dispatchEvent(new root.CustomEvent('fhshopapp:savefailed', { detail: detail }));
			} catch (e) { /* an old browser without CustomEvent */ }
		}

		/* Between the page and the network. */
		function guarded(input, init, isWrite) {
			// The very entry that got no answer a moment ago keeps its id: the
			// server may have written it, and then answers instead of writing
			// it a second time. Anything else is a new save with a new id.
			var id = isWrite ? (reuseSaveId(unsure, init.body, Date.now()) || newSaveId()) : '';
			var body = isWrite ? withSaveId(init.body, cfg.saveField || 'fh_save_id', id) : init.body;
			var waits = 0;

			return new Promise(function (resolve, reject) {
				function failed(reason) {
					var detail = { id: id, body: body, reason: reason };
					unsure = { body: init.body, id: id, at: Date.now() };
					announce(detail);
					if (typeof cfg.onSaveFailed === 'function') {
						var taken = null;
						try {
							taken = cfg.onSaveFailed(detail);
						} catch (e) {
							taken = null;
						}
						if (taken && typeof taken.then === 'function') {
							taken.then(resolve, function () { show(reason); });
							return;
						}
					}
					show(reason);
				}

				function show(reason) {
					openSheet(reason, attempt, function () {
						reject(new Error(t('notSavedClosed', 'Not confirmed as saved. Check the connection, then save again — the same entry is written once.')));
					});
				}

				function attempt() {
					var ctl = typeof root.AbortController !== 'undefined' ? new root.AbortController() : null;
					var timer = root.setTimeout(function () {
						if (ctl) {
							ctl.abort();
						}
					}, TIMEOUT_MS);
					var opts = {};
					Object.keys(init).forEach(function (key) { opts[key] = init[key]; });
					opts.body = body;
					if (ctl) {
						opts.signal = ctl.signal;
					}

					nativeFetch(input, opts).then(function (res) {
						root.clearTimeout(timer);
						return res.clone().text().then(function (text) {
							return { res: res, kind: classifyReply(res.status, text) };
						});
					}).then(function (out) {
						if (out.kind === 'ok') {
							if (isWrite && unsure && unsure.id === id) {
								// Answered: saved (or refused by the page's own
								// rules) — either way no longer in doubt.
								unsure = null;
							}
							closeSheet();
							contact();
							resolve(out.res);
							return;
						}
						if (out.kind === 'pending' && waits < 15) {
							waits++;
							root.setTimeout(attempt, 2000);
							return;
						}
						if (out.kind === 'signedout') {
							// WordPress did not know this device. Ask the app's own
							// endpoint whether the session is really gone.
							closeSheet();
							server.state().then(function (st) {
								if (st && st.session) {
									// It is live: this answer is the page's own business.
									adopt(st.session);
									resolve(out.res);
									return;
								}
								askPin(isWrite ? fmt(t('sessionEndedSave', 'Locked before it was saved. %s: enter your PIN and it is saved.'), session ? session.person : '') : t('sessionEnded', 'Locked. Enter your PIN to go on.'), function () {
									attempt();
								}, st, isWrite);
							}).catch(function () {
								if (isWrite) {
									failed('offline');
								} else {
									resolve(out.res);
								}
							});
							return;
						}
						if (!isWrite) {
							// A read: the page shows its own message.
							resolve(out.res);
							return;
						}
						failed(out.kind === 'garbled' ? 'offline' : 'server');
					}).catch(function (err) {
						root.clearTimeout(timer);
						if (!isWrite) {
							reject(err);
							return;
						}
						failed('offline');
					});
				}

				attempt();
			});
		}

		if (nativeFetch) {
			root.fetch = function (input, init) {
				var url = typeof input === 'string' ? input : '';
				if (url !== urls.ajax || !init || String(init.method || 'GET').toUpperCase() !== 'POST' || typeof init.body !== 'string') {
					return nativeFetch(input, init);
				}
				var action = bodyAction(init.body);
				if ((cfg.actions || []).indexOf(action) === -1) {
					return nativeFetch(input, init);
				}
				return guarded(input, init, action === cfg.write);
			};
		}
	}

	if (cfg.mode === 'frontdesk') {
		startFrontDesk();
	} else {
		startApp();
	}
})(typeof window !== 'undefined' ? window : globalThis);
