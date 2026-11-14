/**
 * FisHotel Shop — the scanner: "Scan a tank" inside the app, so a tank
 * label opens its tank without leaving for a camera app and coming back.
 *
 * Inlined in front of js/app.js (the shell and a tank screen opened in the
 * app), never anywhere else. It sets window.fhShopScanner; app.js puts the
 * button on its screens and opens it.
 *
 * What it does, step by step:
 *
 *   open      the rear camera, full screen, a square to hold the label in,
 *             Cancel and "Type the tank number". No camera (none, refused,
 *             not allowed here) → the plain words and the number pad.
 *   decode    continuously. The browser's own reader (BarcodeDetector)
 *             when it has one that reads QR codes; otherwise jsQR, kept in
 *             js/vendor/ (Apache-2.0, its licence beside it) and loaded
 *             only the first time the scanner opens on such a browser.
 *   a label   of THIS site (its own origin, the home address, nothing but
 *             fh_front_desk=1 and a tank number the numbering rule allows):
 *             the camera stops, a short buzz, and the tank opens — at the
 *             address built from this site's own label address, the one
 *             the laminated label holds, so the PIN, the session and
 *             "Today for this water" all apply as for a label. What the
 *             camera read is never opened.
 *   anything  else — another site, a product link, a broken code: a short
 *             message, and it keeps scanning.
 *   stop      the camera is stopped on Cancel, on a label, on "Type the
 *             tank number", when the page is hidden (it starts again when
 *             the page is back) and when the page is left.
 *
 * The decisions are a pure step function, step(machine, event) → the next
 * machine and the effects to carry out, so the tests walk every path
 * without a camera. readTankCode() is the one place that says what a
 * label is.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

/* global module */
(function (root) {
	'use strict';

	/* ---------------------------------------------------------------- */
	/*  What a tank label is                                             */
	/* ---------------------------------------------------------------- */

	/*
	 * Tank numbers by the Clean Room's numbering rule: hundreds = building
	 * (100 … 900), tens = level (0 for a temporary slot), ones = position
	 * from 1. So three digits, 101 … 999, never ending in 0.
	 */
	var TANK_MIN = 101;
	var TANK_MAX = 999;

	/**
	 * A tank number from its digits, or 0.
	 *
	 * @param {*} value The digits.
	 * @return {number}
	 */
	function tankNumber(value) {
		var text = typeof value === 'number' ? String(value) : value;
		if (typeof text !== 'string' || !/^[1-9]\d\d$/.test(text)) {
			return 0;
		}
		var number = parseInt(text, 10);
		return number >= TANK_MIN && number <= TANK_MAX && number % 10 !== 0 ? number : 0;
	}

	/**
	 * An absolute address in its parts, or null. Strict on purpose: only
	 * printable ASCII, no spaces.
	 *
	 * @param {string} text The address.
	 * @return {null|{scheme: string, host: string, path: string, query: (string|null), fragment: (string|null)}}
	 */
	function splitUrl(text) {
		var match = /^([a-zA-Z][a-zA-Z0-9+.\-]*):\/\/([^\/?#]*)([^?#]*)(?:\?([^#]*))?(?:#(.*))?$/.exec(String(text));
		if (!match) {
			return null;
		}
		var scheme = match[1].toLowerCase();
		var host = match[2].toLowerCase();
		// A port that is the scheme's own is the same origin without it.
		if ((scheme === 'https' && /:443$/.test(host)) || (scheme === 'http' && /:80$/.test(host))) {
			host = host.replace(/:\d+$/, '');
		}
		return {
			scheme: scheme,
			host: host,
			path: match[3],
			query: typeof match[4] === 'string' ? match[4] : null,
			fragment: typeof match[5] === 'string' ? match[5] : null
		};
	}

	/**
	 * This site, from its own label address (home_url( '/?fh_front_desk=1' )).
	 *
	 * @param {string} labelUrl The label address without a tank.
	 * @return {null|{scheme: string, host: string, path: string}}
	 */
	function siteOf(labelUrl) {
		var parts = splitUrl(labelUrl);
		if (!parts || !/^https?$/.test(parts.scheme) || !parts.host) {
			return null;
		}
		return { scheme: parts.scheme, host: parts.host, path: parts.path || '/' };
	}

	/**
	 * What the camera read: the tank of a label of THIS site, or why not.
	 *
	 *   why ''           a label: { tank: N }
	 *       'empty'      nothing read
	 *       'junk'       not an address (plain text, spaces, other characters)
	 *       'other_site' an address of another origin — another site, a
	 *                    look-alike host, another scheme or port, a
	 *                    javascript: or data: address
	 *       'not_label'  this site, but not a tank label (a product page,
	 *                    another path, the switch missing or off, anything
	 *                    added to the address)
	 *       'bad_tank'   a label whose tank number the numbering rule does
	 *                    not allow
	 *
	 * @param {*}      text     What the camera read.
	 * @param {Object} site     siteOf( the label address ).
	 * @param {string} labelVar The Front Desk's query parameter.
	 * @return {{tank: number, why: string}}
	 */
	function readTankCode(text, site, labelVar) {
		var no = function (why) { return { tank: 0, why: why }; };
		if (typeof text !== 'string') {
			return no('empty');
		}
		var raw = text.replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, '');
		if (raw === '') {
			return no('empty');
		}
		if (raw.length > 2000 || /[^\x21-\x7e]/.test(raw)) {
			return no('junk');
		}
		if (/^(javascript|data|vbscript|blob|file):/i.test(raw)) {
			return no('other_site');
		}
		var parts = splitUrl(raw);
		if (!parts) {
			return no('junk');
		}
		if (!site || !site.host || parts.scheme !== site.scheme || parts.host !== site.host) {
			return no('other_site');
		}
		var path = parts.path === '' ? '/' : parts.path;
		if (path !== site.path && path !== site.path + 'index.php') {
			return no('not_label');
		}
		if (parts.fragment !== null || parts.query === null) {
			return no('not_label');
		}
		var flag = String(labelVar || 'fh_front_desk');
		var seen = {};
		var pairs = parts.query.split('&');
		for (var i = 0; i < pairs.length; i++) {
			var cut = pairs[i].indexOf('=');
			var key = cut < 0 ? pairs[i] : pairs[i].slice(0, cut);
			if ((key !== flag && key !== 'tank') || cut < 0 || Object.prototype.hasOwnProperty.call(seen, key)) {
				return no('not_label');
			}
			seen[key] = pairs[i].slice(cut + 1);
		}
		if (seen[flag] !== '1' || typeof seen.tank !== 'string') {
			return no('not_label');
		}
		var tank = tankNumber(seen.tank);
		return tank ? { tank: tank, why: '' } : no('bad_tank');
	}

	/* ---------------------------------------------------------------- */
	/*  The decisions                                                    */
	/* ---------------------------------------------------------------- */

	/*
	 * Phases: closed → starting (asking for the camera, readying the
	 * reader) → scanning → done; paused while the page is hidden; typing
	 * when there is no camera or the number is typed.
	 *
	 * Effects, carried out in this order by the page:
	 *   show    put the scanner over the screen
	 *   camera  ask for the rear camera
	 *   reader  ready the code reader (loads jsQR only when it is needed)
	 *   loop    start reading frames
	 *   stop    stop the camera and the reading
	 *   pad     show the number pad
	 *   say     show machine.why
	 *   vibrate a short buzz
	 *   hide    take the scanner away
	 *   go      open machine.tank
	 */
	var CAMERA_PHASES = ['starting', 'scanning', 'paused'];

	/**
	 * One step.
	 *
	 * @param {null|{phase: string, why: string, tank: number}} machine Where it is.
	 * @param {{type: string}} event What happened.
	 * @return {{phase: string, why: string, tank: number, effects: string[]}}
	 */
	function step(machine, event) {
		var m = machine || { phase: 'closed', why: '', tank: 0 };
		var at = m.phase;
		var next = function (phase, why, tank, effects) {
			return { phase: phase, why: why, tank: tank || 0, effects: effects };
		};
		var same = next(at, m.why || '', m.tank, []);
		var type = event && event.type;

		switch (type) {
			case 'open':
				if (at !== 'closed' && at !== 'done') {
					return same;
				}
				return event.camera
					? next('starting', '', 0, ['show', 'camera', 'reader'])
					: next('typing', 'no_camera', 0, ['show', 'pad', 'say']);
			case 'ready':
				return at === 'starting' ? next('scanning', '', 0, ['loop']) : same;
			case 'denied':
			case 'failed':
				if (at !== 'starting' && at !== 'scanning') {
					return same;
				}
				return next('typing', type === 'denied' ? 'denied' : (event.why || 'no_camera'), 0, ['stop', 'pad', 'say']);
			case 'code':
				if (at !== 'scanning' || !event.result) {
					return same;
				}
				if (event.result.tank) {
					return next('done', '', event.result.tank, ['stop', 'vibrate', 'hide', 'go']);
				}
				return next('scanning', event.result.why || 'junk', 0, ['say']);
			case 'type':
				return CAMERA_PHASES.indexOf(at) !== -1 ? next('typing', '', 0, ['stop', 'pad', 'say']) : same;
			case 'typed':
				if (at !== 'typing') {
					return same;
				}
				var typed = tankNumber(event.value);
				return typed ? next('done', '', typed, ['hide', 'go']) : next('typing', 'bad_typed', 0, ['say']);
			case 'hidden':
				// say: a message about the last code read must not outlive it.
				return at === 'starting' || at === 'scanning' ? next('paused', '', 0, ['stop', 'say']) : same;
			case 'visible':
				return at === 'paused' ? next('starting', '', 0, ['say', 'camera', 'reader']) : same;
			case 'cancel':
			case 'leave':
				return at === 'closed' || at === 'done' ? same : next('closed', '', 0, ['stop', 'hide']);
			default:
				return same;
		}
	}

	/* ---------------------------------------------------------------- */
	/*  The reader                                                       */
	/* ---------------------------------------------------------------- */

	/**
	 * Which reader this browser gets: 'native' when BarcodeDetector exists
	 * and reads QR codes, otherwise 'jsqr'.
	 *
	 * @param {Object} win The window.
	 * @return {Promise<string>}
	 */
	function chooseReader(win) {
		var Detector = win && win.BarcodeDetector;
		if (typeof Detector !== 'function') {
			return Promise.resolve('jsqr');
		}
		var test = function () {
			try {
				new Detector({ formats: ['qr_code'] }); // eslint-disable-line no-new
				return 'native';
			} catch (e) {
				return 'jsqr';
			}
		};
		if (typeof Detector.getSupportedFormats !== 'function') {
			return Promise.resolve(test());
		}
		return Promise.resolve().then(function () {
			return Detector.getSupportedFormats();
		}).then(function (formats) {
			return (formats || []).indexOf('qr_code') !== -1 ? test() : 'jsqr';
		}, function () {
			return 'jsqr';
		});
	}

	/* One script tag for jsQR per page, however often the scanner opens. */
	var jsqrLoading = null;

	/**
	 * jsQR, loaded the first time it is needed.
	 *
	 * @param {Object} win The window.
	 * @param {Object} doc The document.
	 * @param {string} src Its address (this section's js/vendor/jsQR.js).
	 * @return {Promise<Function>}
	 */
	function loadJsQr(win, doc, src) {
		if (win && typeof win.jsQR === 'function') {
			return Promise.resolve(win.jsQR);
		}
		if (jsqrLoading) {
			return jsqrLoading;
		}
		jsqrLoading = new Promise(function (resolve, reject) {
			if (!src || !doc || !doc.createElement) {
				reject(new Error('no reader'));
				return;
			}
			var tag = doc.createElement('script');
			tag.addEventListener('load', function () {
				if (typeof win.jsQR === 'function') {
					resolve(win.jsQR);
				} else {
					reject(new Error('no reader'));
				}
			});
			tag.addEventListener('error', function () { reject(new Error('no reader')); });
			tag.setAttribute('src', String(src));
			tag.setAttribute('async', '');
			(doc.head || doc.body).appendChild(tag);
		}).catch(function (err) {
			jsqrLoading = null; // A later try may find the network back.
			throw err;
		});
		return jsqrLoading;
	}

	/* The browser's own reader: the texts in a frame. */
	function nativeReader(win) {
		var detector = new win.BarcodeDetector({ formats: ['qr_code'] });
		return {
			kind: 'native',
			read: function (video) {
				return detector.detect(video).then(function (codes) {
					return (codes || []).map(function (code) { return code && code.rawValue; });
				});
			}
		};
	}

	/* jsQR on a frame drawn small (a label fills the square; 640 px is plenty). */
	function jsqrReader(decode, doc) {
		var canvas = doc.createElement('canvas');
		var ctx = null;
		return {
			kind: 'jsqr',
			read: function (video) {
				var w = video.videoWidth || 0;
				var h = video.videoHeight || 0;
				if (!w || !h) {
					return Promise.resolve([]);
				}
				var scale = Math.min(1, 640 / Math.max(w, h));
				w = Math.max(1, Math.round(w * scale));
				h = Math.max(1, Math.round(h * scale));
				if (canvas.width !== w || canvas.height !== h) {
					canvas.width = w;
					canvas.height = h;
					ctx = null;
				}
				ctx = ctx || canvas.getContext('2d', { willReadFrequently: true });
				if (!ctx) {
					return Promise.reject(new Error('no canvas'));
				}
				ctx.drawImage(video, 0, 0, w, h);
				var image = ctx.getImageData(0, 0, w, h);
				var code = decode(image.data, w, h, { inversionAttempts: 'dontInvert' });
				return Promise.resolve(code && typeof code.data === 'string' ? [code.data] : []);
			}
		};
	}

	/* ---------------------------------------------------------------- */
	/*  The scanner on the page                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * A scanner for one page.
	 *
	 * @param {Object}   opts
	 * @param {Object}   opts.win      The window.
	 * @param {Object}   opts.doc      The document.
	 * @param {Function} opts.t        t( key, fallback ): the page's strings.
	 * @param {Function} opts.el       el( tag, attrs, children ): the page's DOM builder.
	 * @param {string}   opts.label    This site's label address (no tank).
	 * @param {string}   opts.labelVar The Front Desk's query parameter.
	 * @param {string}   opts.reader   jsQR's address.
	 * @param {Function} opts.open     open( tank ): opens that tank in the app.
	 * @param {number=}  opts.every    ms between two frames read (default 200).
	 * @return {{open: Function, cancel: Function, phase: Function}}
	 */
	function create(opts) {
		var win = opts.win;
		var doc = opts.doc;
		var t = opts.t;
		var el = opts.el;
		var site = siteOf(opts.label);
		var every = opts.every || 200;

		var machine = { phase: 'closed', why: '', tank: 0 };
		var layer = null;
		var video = null;
		var hint = null;
		var padBox = null;
		var typedNode = null;
		var typed = '';
		var stream = null;
		var reader = null;
		var readerAsk = null;
		var round = 0;      // Bumped by every start and stop: a late camera answer of an old round is put away.
		var loopTimer = null;
		var busy = false;

		var WORDS = {
			no_camera: function () { return t('scanNoCamera', 'The camera could not be opened. Type the tank number instead.'); },
			denied: function () { return t('scanDenied', 'The camera is not allowed for this app. Type the tank number instead — or allow the camera in the browser\'s settings.'); },
			no_reader: function () { return t('scanNoReader', 'The code reader could not be loaded. Type the tank number instead.'); },
			other_site: function () { return t('scanOtherSite', 'That code is for another website. It was not opened.'); },
			bad_tank: function () { return t('scanBadTank', 'That label\'s tank number is not one the shop uses.'); },
			bad_typed: function () { return t('typeBad', 'Tank numbers have three digits, like 121 or 901.'); },
			not_label: function () { return t('scanNotLabel', 'That is not a FisHotel tank label.'); },
			junk: function () { return t('scanNotLabel', 'That is not a FisHotel tank label.'); },
			empty: function () { return t('scanNotLabel', 'That is not a FisHotel tank label.'); }
		};

		function cameraPossible() {
			var nav = win.navigator;
			var secure = typeof win.isSecureContext === 'boolean' ? win.isSecureContext : (win.location && win.location.protocol === 'https:');
			return !!(secure && nav && nav.mediaDevices && typeof nav.mediaDevices.getUserMedia === 'function');
		}

		function stopTracks(s) {
			try {
				(s && s.getTracks ? s.getTracks() : []).forEach(function (track) { track.stop(); });
			} catch (e) { /* already stopped */ }
		}

		function onVisibility() {
			dispatch({ type: doc.hidden ? 'hidden' : 'visible' });
		}

		function onLeave() {
			dispatch({ type: 'leave' });
		}

		function onKey(e) {
			if (machine.phase === 'closed' || e.ctrlKey || e.metaKey || e.altKey) {
				return;
			}
			if (e.key === 'Escape') {
				dispatch({ type: 'cancel' });
				return;
			}
			if (machine.phase !== 'typing') {
				return;
			}
			if (/^\d$/.test(e.key)) {
				press(e.key);
				e.preventDefault();
			} else if (e.key === 'Backspace') {
				press('back');
				e.preventDefault();
			}
		}

		/* The number pad: three digits open the tank. */
		function press(key) {
			if (key === 'back') {
				typed = typed.slice(0, -1);
			} else if (key === 'clear') {
				typed = '';
			} else if (/^\d$/.test(key) && typed.length < 3) {
				typed += key;
			}
			typedNode.textContent = typed || '—';
			if (machine.why === 'bad_typed' && typed.length < 3) {
				say('');
			}
			if (typed.length === 3) {
				var value = typed;
				typed = '';
				dispatch({ type: 'typed', value: value });
				if (machine.phase === 'typing' && typedNode) {
					typedNode.textContent = value;
				}
			}
		}

		function padKey(label, value, extra) {
			var button = el('button', {
				type: 'button',
				class: 'fh-sa-key' + (extra ? ' ' + extra : ''),
				'aria-label': value === 'back' ? t('delete', 'Delete') : (value === 'clear' ? t('clear', 'Clear') : label),
				text: label
			});
			button.addEventListener('pointerdown', function () {
				button.classList.add('is-pressed');
				win.setTimeout(function () { button.classList.remove('is-pressed'); }, 180);
			});
			button.addEventListener('click', function () { press(value); });
			return button;
		}

		function show() {
			video = el('video', { class: 'fh-sa-scan__video', playsinline: '', muted: '', autoplay: '' });
			video.muted = true;
			video.playsInline = true;
			hint = el('p', { class: 'fh-sa-scan__hint', role: 'status', 'aria-live': 'polite', text: t('scanStarting', 'Starting the camera…') });
			typedNode = el('div', { class: 'fh-sa-scan__typed', 'aria-live': 'polite', text: '—' });
			var keys = el('div', { class: 'fh-sa-keys' });
			['1', '2', '3', '4', '5', '6', '7', '8', '9'].forEach(function (d) { keys.appendChild(padKey(d, d)); });
			keys.appendChild(padKey(t('clear', 'Clear'), 'clear', 'fh-sa-key--word'));
			keys.appendChild(padKey('0', '0'));
			keys.appendChild(padKey('⌫', 'back', 'fh-sa-key--word'));
			padBox = el('div', { class: 'fh-sa-scan__pad', hidden: true }, [
				el('h2', { class: 'fh-sa-scan__title', text: t('typeTitle', 'Type the tank number') }),
				typedNode,
				keys
			]);
			layer = el('div', { class: 'fh-sa-scan', role: 'dialog', 'aria-modal': 'true', 'aria-label': t('scan', 'Scan a tank') }, [
				video,
				el('div', { class: 'fh-sa-scan__frame', 'aria-hidden': 'true' }),
				padBox,
				el('div', { class: 'fh-sa-scan__foot' }, [
					hint,
					el('button', { type: 'button', class: 'fh-sa-btn fh-sa-btn--quiet fh-sa-scan__type', text: t('typeTitle', 'Type the tank number'), on: { click: function () { dispatch({ type: 'type' }); } } }),
					el('button', { type: 'button', class: 'fh-sa-btn fh-sa-btn--quiet fh-sa-scan__cancel', text: t('cancel', 'Cancel'), on: { click: function () { dispatch({ type: 'cancel' }); } } })
				])
			]);
			doc.body.appendChild(layer);
			doc.addEventListener('visibilitychange', onVisibility);
			doc.addEventListener('keydown', onKey);
			win.addEventListener('pagehide', onLeave);
		}

		function hide() {
			if (layer && layer.parentNode) {
				layer.parentNode.removeChild(layer);
			}
			layer = null;
			video = null;
			hint = null;
			padBox = null;
			typedNode = null;
			typed = '';
			doc.removeEventListener('visibilitychange', onVisibility);
			doc.removeEventListener('keydown', onKey);
			win.removeEventListener('pagehide', onLeave);
		}

		function say(why) {
			if (!hint) {
				return;
			}
			var plain;
			if (machine.phase === 'typing') {
				plain = t('typeHint', 'It opens at the third digit.');
			} else if (machine.phase === 'starting' || machine.phase === 'paused') {
				plain = t('scanStarting', 'Starting the camera…');
			} else {
				plain = t('scanHint', 'Hold the tank\'s label inside the square.');
			}
			hint.textContent = why && WORDS[why] ? WORDS[why]() : plain;
			hint.className = 'fh-sa-scan__hint' + (why ? ' is-bad' : '');
		}

		function startCamera() {
			var mine = ++round;
			var nav = win.navigator;
			var asked;
			try {
				asked = nav.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
			} catch (e) {
				asked = Promise.reject(e);
			}
			Promise.resolve(asked).then(function (got) {
				if (mine !== round || machine.phase !== 'starting' || !video) {
					stopTracks(got); // Cancelled, hidden or left while the camera was being asked for.
					return;
				}
				stream = got;
				video.srcObject = got;
				try {
					var playing = video.play();
					if (playing && playing.catch) {
						playing.catch(function () { /* the stream shows all the same */ });
					}
				} catch (e) { /* the stream shows all the same */ }
				maybeReady();
			}, function (err) {
				if (mine !== round) {
					return;
				}
				var name = err && err.name ? String(err.name) : '';
				dispatch({ type: name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError' ? 'denied' : 'failed' });
			});
		}

		function readyReader() {
			if (reader) {
				maybeReady();
				return;
			}
			if (!readerAsk) {
				readerAsk = chooseReader(win).then(function (kind) {
					if (kind === 'native') {
						return nativeReader(win);
					}
					return loadJsQr(win, doc, opts.reader).then(function (decode) {
						return jsqrReader(decode, doc);
					});
				});
			}
			readerAsk.then(function (r) {
				reader = r;
				maybeReady();
			}, function () {
				readerAsk = null;
				dispatch({ type: 'failed', why: 'no_reader' });
			});
		}

		function maybeReady() {
			if (machine.phase === 'starting' && stream && reader) {
				dispatch({ type: 'ready' });
			}
		}

		function stopCamera() {
			round++;
			win.clearTimeout(loopTimer);
			loopTimer = null;
			busy = false;
			stopTracks(stream);
			stream = null;
			if (video) {
				try {
					video.srcObject = null;
				} catch (e) { /* nothing to let go of */ }
			}
		}

		function schedule() {
			win.clearTimeout(loopTimer);
			loopTimer = win.setTimeout(tick, every);
		}

		function tick() {
			loopTimer = null;
			if (machine.phase !== 'scanning' || !reader || !video) {
				return;
			}
			if (busy || (typeof video.readyState === 'number' && video.readyState < 2)) {
				schedule();
				return;
			}
			busy = true;
			var mine = round;
			var frame;
			try {
				frame = reader.read(video);
			} catch (e) {
				frame = Promise.reject(e);
			}
			Promise.resolve(frame).then(function (texts) {
				busy = false;
				if (mine !== round) {
					return;
				}
				var list = texts || [];
				for (var i = 0; i < list.length && machine.phase === 'scanning'; i++) {
					dispatch({ type: 'code', result: readTankCode(list[i], site, opts.labelVar) });
				}
				if (machine.phase === 'scanning') {
					schedule();
				}
			}, function () {
				busy = false;
				if (mine === round && machine.phase === 'scanning') {
					schedule();
				}
			});
		}

		function run(effect) {
			switch (effect) {
				case 'show':
					show();
					break;
				case 'camera':
					startCamera();
					break;
				case 'reader':
					readyReader();
					break;
				case 'loop':
					say('');
					if (layer) {
						layer.classList.add('is-live');
					}
					schedule();
					break;
				case 'stop':
					stopCamera();
					if (layer) {
						layer.classList.remove('is-live');
					}
					break;
				case 'pad':
					if (layer && padBox) {
						layer.classList.add('is-typing');
						padBox.hidden = false;
					}
					break;
				case 'say':
					say(machine.why);
					break;
				case 'vibrate':
					if (win.navigator && typeof win.navigator.vibrate === 'function') {
						try { win.navigator.vibrate(80); } catch (e) { /* no vibration */ }
					}
					break;
				case 'hide':
					hide();
					break;
				case 'go':
					opts.open(machine.tank);
					break;
			}
		}

		function dispatch(event) {
			var next = step(machine, event);
			machine = { phase: next.phase, why: next.why, tank: next.tank };
			next.effects.forEach(run);
		}

		return {
			open: function () { dispatch({ type: 'open', camera: cameraPossible() }); },
			cancel: function () { dispatch({ type: 'cancel' }); },
			phase: function () { return machine.phase; }
		};
	}

	var api = {
		TANK_MIN: TANK_MIN,
		TANK_MAX: TANK_MAX,
		tankNumber: tankNumber,
		siteOf: siteOf,
		readTankCode: readTankCode,
		step: step,
		chooseReader: chooseReader,
		loadJsQr: loadJsQr,
		create: create,
		_reset: function () { jsqrLoading = null; }
	};

	root.fhShopScanner = api;

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
})(typeof window !== 'undefined' ? window : globalThis);
