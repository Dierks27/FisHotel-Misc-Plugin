/**
 * Scanner tests — js/scanner.js, "Scan a tank" inside the app.
 *
 *   1. What a tank label is: this site's own origin, its home address,
 *      fh_front_desk=1 and a tank number the numbering rule allows —
 *      and everything that is not (another site, a look-alike host, http
 *      for https, another port, another path, javascript: and data:,
 *      anything added, a broken number).
 *   2. The decisions, one event at a time: open, the camera and the
 *      reader ready, a code, a label, Cancel, the page hidden and back,
 *      the camera refused or missing → the number pad, a typed number.
 *   3. Which reader: the browser's own when it reads QR codes, jsQR when
 *      not — loaded once, and only then.
 *   4. On a stand-in page with a stand-in camera: the whole thing, with
 *      the browser's reader and with jsQR reading QR codes made by the
 *      Clean Room's own label encoder (scan-codes.json) — the camera is
 *      stopped on Cancel, on a label, when the page is hidden or left,
 *      and a camera that answers after Cancel is stopped at once.
 *
 * Run from the plugin root:
 *   node includes/sections/shop-app/tests/scanner.test.js
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

'use strict';

const fs = require('fs');
const path = require('path');

const FILE = path.join(__dirname, '..', 'js', 'scanner.js');
const JSQR = path.join(__dirname, '..', 'js', 'vendor', 'jsQR.js');
const CODES = JSON.parse(fs.readFileSync(path.join(__dirname, 'scan-codes.json'), 'utf8'));
const SITE = 'https://shop.example.test';
const LABEL = SITE + '/?fh_front_desk=1';
const QR_LIB = SITE + '/wp-content/plugins/fishotel-misc-plugin/includes/sections/shop-app/js/vendor/jsQR.js?ver=0.46.1';

let failures = 0;
let checks = 0;
function check(label, condition, detail) {
	checks++;
	if (condition) {
		console.log('  ok   ' + label);
		return;
	}
	failures++;
	console.log('  FAIL ' + label + (detail !== undefined ? '\n       ' + (typeof detail === 'string' ? detail : JSON.stringify(detail)) : ''));
}

const S = require(FILE);
const site = S.siteOf(LABEL);
const R = (text, where, flag) => S.readTankCode(text, where || site, flag || 'fh_front_desk');

console.log('1. What a tank label is');
check('this site, from its own label address: https, the host, the home path', site.scheme === 'https' && site.host === 'shop.example.test' && site.path === '/');
check('a label: its tank number', R(SITE + '/?fh_front_desk=1&tank=121').tank === 121 && R(SITE + '/?fh_front_desk=1&tank=121').why === '');
check('a temporary slot\'s label (901 … 909)', R(SITE + '/?fh_front_desk=1&tank=901').tank === 901 && R(SITE + '/?fh_front_desk=1&tank=909').tank === 909);
check('the two parameters the other way round', R(SITE + '/?tank=521&fh_front_desk=1').tank === 521);
check('/index.php, no path at all, capitals in the scheme and host, the default port written out: the same origin and address', R(SITE + '/index.php?fh_front_desk=1&tank=121').tank === 121 && R(SITE + '?fh_front_desk=1&tank=121').tank === 121 && R('HTTPS://Shop.Example.TEST/?fh_front_desk=1&tank=121').tank === 121 && R('https://shop.example.test:443/?fh_front_desk=1&tank=121').tank === 121);
check('a line break after it (some printers add one)', R(SITE + '/?fh_front_desk=1&tank=121\n').tank === 121);
check('every tank the layout gives a label (the seeded buildings and nine slots) is read', CODES.tanks.length > 20 && CODES.tanks.every((n) => R(SITE + '/?fh_front_desk=1&tank=' + n).tank === n), CODES.tanks.filter((n) => R(SITE + '/?fh_front_desk=1&tank=' + n).tank !== n));
const folder = S.siteOf('https://shop.example.test/fish/?fh_front_desk=1');
check('a site in a folder: its labels are read there, and the root of the host is not the site', R('https://shop.example.test/fish/?fh_front_desk=1&tank=121', folder).tank === 121 && R('https://shop.example.test/fish/index.php?fh_front_desk=1&tank=121', folder).tank === 121 && R(SITE + '/?fh_front_desk=1&tank=121', folder).why === 'not_label');
check('a test site on a port: that port is part of the origin', R('http://127.0.0.1:8491/?fh_front_desk=1&tank=121', S.siteOf('http://127.0.0.1:8491/?fh_front_desk=1')).tank === 121 && R('http://127.0.0.1:8492/?fh_front_desk=1&tank=121', S.siteOf('http://127.0.0.1:8491/?fh_front_desk=1')).why === 'other_site');

for (const [what, text] of [
	['another site', 'https://elsewhere.example/?fh_front_desk=1&tank=121'],
	['this site as a subdomain of another', 'https://shop.example.test.elsewhere.example/?fh_front_desk=1&tank=121'],
	['a look-alike: a letter changed', 'https://shop.examp1e.test/?fh_front_desk=1&tank=121'],
	['a look-alike: a hyphen added', 'https://shop-example.test/?fh_front_desk=1&tank=121'],
	['a look-alike: www. in front', 'https://www.shop.example.test/?fh_front_desk=1&tank=121'],
	['a look-alike: a trailing dot', 'https://shop.example.test./?fh_front_desk=1&tank=121'],
	['this site in front of an @ (the host is the other one)', 'https://shop.example.test@elsewhere.example/?fh_front_desk=1&tank=121'],
	['a user name in front of this site', 'https://someone@shop.example.test/?fh_front_desk=1&tank=121'],
	['a backslash trick', 'https://elsewhere.example\\@shop.example.test/?fh_front_desk=1&tank=121'],
	['http instead of https', 'http://shop.example.test/?fh_front_desk=1&tank=121'],
	['another port', 'https://shop.example.test:8443/?fh_front_desk=1&tank=121'],
	['a javascript: address', 'javascript:alert(1)//?fh_front_desk=1&tank=121'],
	['a javascript: address in capitals', 'JavaScript:location="https://elsewhere.example"'],
	['a data: address', 'data:text/html,<script>alert(1)</script>?fh_front_desk=1&tank=121'],
	['a file: address', 'file:///etc/passwd?fh_front_desk=1&tank=121'],
	['a scheme-relative address', '//shop.example.test/?fh_front_desk=1&tank=121']
]) {
	const got = R(text);
	check('not opened — ' + what + ' (' + got.why + ')', got.tank === 0 && (got.why === 'other_site' || got.why === 'junk'));
}
for (const [what, text, why] of [
	['a product page of this site', SITE + '/product/invented-goby/', 'not_label'],
	['the shop front', SITE + '/', 'not_label'],
	['another path with the switch', SITE + '/shop/?fh_front_desk=1&tank=121', 'not_label'],
	['the app\'s own address', SITE + '/?fh_shop_app=1&tank=121', 'not_label'],
	['the switch missing', SITE + '/?tank=121', 'not_label'],
	['the switch off', SITE + '/?fh_front_desk=0&tank=121', 'not_label'],
	['the switch with another value', SITE + '/?fh_front_desk=yes&tank=121', 'not_label'],
	['no tank (the picker)', SITE + '/?fh_front_desk=1', 'not_label'],
	['the day\'s list', SITE + '/?fh_front_desk=1&list=1', 'not_label'],
	['something added: a tracking parameter', SITE + '/?fh_front_desk=1&tank=121&utm_source=x', 'not_label'],
	['something added: the tank twice', SITE + '/?fh_front_desk=1&tank=121&tank=511', 'not_label'],
	['something added: the switch twice', SITE + '/?fh_front_desk=1&fh_front_desk=1&tank=121', 'not_label'],
	['something added: an empty pair', SITE + '/?fh_front_desk=1&&tank=121', 'not_label'],
	['something added: a fragment', SITE + '/?fh_front_desk=1&tank=121#x', 'not_label'],
	['something added: words after it', SITE + '/?fh_front_desk=1&tank=121 please', 'junk'],
	['something added: a tab inside', SITE + '/?fh_front_desk=1&\ttank=121', 'junk'],
	['an encoded tank', SITE + '/?fh_front_desk=1&tank=%31%32%31', 'bad_tank'],
	['a tank that is not a number', SITE + '/?fh_front_desk=1&tank=12a', 'bad_tank'],
	['a tank with a sign', SITE + '/?fh_front_desk=1&tank=-121', 'bad_tank'],
	['a tank with a leading zero', SITE + '/?fh_front_desk=1&tank=0121', 'bad_tank'],
	['a two-digit tank', SITE + '/?fh_front_desk=1&tank=99', 'bad_tank'],
	['a four-digit tank', SITE + '/?fh_front_desk=1&tank=1000', 'bad_tank'],
	['tank 100 (no position 0)', SITE + '/?fh_front_desk=1&tank=100', 'bad_tank'],
	['tank 120', SITE + '/?fh_front_desk=1&tank=120', 'bad_tank'],
	['tank 0', SITE + '/?fh_front_desk=1&tank=0', 'bad_tank'],
	['a ten-digit tank', SITE + '/?fh_front_desk=1&tank=1234567890', 'bad_tank'],
	['plain text', 'Tank 121', 'junk'],
	['a bare number', '121', 'junk'],
	['letters and accents', 'Tänk 121', 'junk'],
	['a very long code', SITE + '/?fh_front_desk=1&tank=121&x=' + 'a'.repeat(3000), 'junk'],
	['nothing', '', 'empty'],
	['spaces', '   ', 'empty']
]) {
	const got = R(text);
	check('not a label — ' + what + ' (' + why + ')', got.tank === 0 && got.why === why, got);
}
check('null, undefined, a number, an object: nothing read', [null, undefined, 121, {}].every((v) => S.readTankCode(v, site, 'fh_front_desk').tank === 0));
check('without knowing this site nothing is a label', S.readTankCode(SITE + '/?fh_front_desk=1&tank=121', null, 'fh_front_desk').tank === 0 && S.siteOf('') === null && S.siteOf('javascript:x') === null && S.siteOf('ftp://shop.example.test/') === null);
check('a Front Desk with another switch name: that name, and only it', R(SITE + '/?fh_desk=1&tank=121', site, 'fh_desk').tank === 121 && R(SITE + '/?fh_front_desk=1&tank=121', site, 'fh_desk').why === 'not_label');
check('the number rule on its own: 101 … 999, three digits, never ending in 0', S.tankNumber('101') === 101 && S.tankNumber('999') === 999 && S.tankNumber(521) === 521 && S.tankNumber('100') === 0 && S.tankNumber('1000') === 0 && S.tankNumber('011') === 0 && S.tankNumber('') === 0 && S.tankNumber(null) === 0 && S.tankNumber('12.5') === 0 && S.TANK_MIN === 101 && S.TANK_MAX === 999);

console.log('\n2. The decisions, one event at a time');
const run = (events, from) => {
	let m = from || null;
	const effects = [];
	events.forEach((e) => {
		const n = S.step(m, e);
		effects.push(n.effects.join(','));
		m = n;
	});
	return { m: m, effects: effects };
};
const LABEL_121 = { type: 'code', result: R(SITE + '/?fh_front_desk=1&tank=121') };
const FOREIGN = { type: 'code', result: R('https://elsewhere.example/?fh_front_desk=1&tank=121') };
const PRODUCT = { type: 'code', result: R(SITE + '/product/invented-goby/') };
let w = run([{ type: 'open', camera: true }]);
check('open with a camera: the scanner over the screen, the camera asked for, the reader readied', w.m.phase === 'starting' && w.effects[0] === 'show,camera,reader');
w = run([{ type: 'open', camera: true }, { type: 'ready' }]);
check('camera and reader ready: scanning, frame after frame', w.m.phase === 'scanning' && w.effects[1] === 'loop');
w = run([{ type: 'open', camera: true }, { type: 'ready' }, FOREIGN]);
check('another site\'s code: a short message, still scanning, nothing opened', w.m.phase === 'scanning' && w.m.why === 'other_site' && w.m.tank === 0 && w.effects[2] === 'say');
w = run([{ type: 'open', camera: true }, { type: 'ready' }, PRODUCT, { type: 'code', result: R('garbage') }]);
check('a product link, then garbage: a message each, still scanning', w.m.phase === 'scanning' && w.effects[2] === 'say' && w.effects[3] === 'say' && w.m.why === 'junk');
w = run([{ type: 'open', camera: true }, { type: 'ready' }, FOREIGN, LABEL_121]);
check('then a label: the camera stopped, a buzz, the scanner away, that tank opened — in that order', w.m.phase === 'done' && w.m.tank === 121 && w.effects[3] === 'stop,vibrate,hide,go');
w = run([{ type: 'open', camera: true }, { type: 'ready' }, LABEL_121, { type: 'code', result: R(SITE + '/?fh_front_desk=1&tank=511') }]);
check('a second code read in the same moment does nothing more: one tank opened', w.m.tank === 121 && w.effects[3] === '');
w = run([{ type: 'open', camera: true }, LABEL_121]);
check('a code before the camera is ready is not acted on', w.m.phase === 'starting' && w.effects[1] === '');
w = run([{ type: 'open', camera: true }, { type: 'ready' }, { type: 'cancel' }]);
check('Cancel: the camera stopped, the scanner away', w.m.phase === 'closed' && w.effects[2] === 'stop,hide');
w = run([{ type: 'open', camera: true }, { type: 'cancel' }, { type: 'ready' }, LABEL_121]);
check('Cancel while the camera is still being asked for: closed, and what comes late is not acted on', w.m.phase === 'closed' && w.effects[2] === '' && w.effects[3] === '');
w = run([{ type: 'open', camera: true }, { type: 'ready' }, { type: 'hidden' }]);
check('the page hidden (screen off, another app): the camera stopped', w.m.phase === 'paused' && w.effects[2] === 'stop');
w = run([{ type: 'open', camera: true }, { type: 'ready' }, { type: 'hidden' }, LABEL_121, { type: 'visible' }, { type: 'ready' }]);
check('… nothing read while hidden; back: the camera asked for again, then scanning', w.effects[3] === '' && w.effects[4] === 'camera,reader' && w.m.phase === 'scanning');
w = run([{ type: 'open', camera: true }, { type: 'hidden' }, { type: 'visible' }]);
check('hidden while still starting: the same', w.effects[1] === 'stop' && w.effects[2] === 'camera,reader' && w.m.phase === 'starting');
w = run([{ type: 'open', camera: true }, { type: 'ready' }, { type: 'leave' }]);
check('the page left: the camera stopped, the scanner away', w.m.phase === 'closed' && w.effects[2] === 'stop,hide');
w = run([{ type: 'open', camera: true }, { type: 'denied' }]);
check('the camera refused: the camera let go, the number pad, the plain words', w.m.phase === 'typing' && w.m.why === 'denied' && w.effects[1] === 'stop,pad,say');
w = run([{ type: 'open', camera: true }, { type: 'failed' }]);
check('no camera found (or it failed): the number pad', w.m.phase === 'typing' && w.m.why === 'no_camera' && w.effects[1] === 'stop,pad,say');
w = run([{ type: 'open', camera: true }, { type: 'failed', why: 'no_reader' }]);
check('the reader could not be loaded: the number pad, saying so', w.m.phase === 'typing' && w.m.why === 'no_reader');
w = run([{ type: 'open', camera: false }]);
check('no camera in this browser at all: straight to the number pad', w.m.phase === 'typing' && w.m.why === 'no_camera' && w.effects[0] === 'show,pad,say');
w = run([{ type: 'open', camera: true }, { type: 'ready' }, { type: 'type' }]);
check('"Type the tank number" while scanning: the camera stopped, the pad', w.m.phase === 'typing' && w.effects[2] === 'stop,pad,say');
w = run([{ type: 'open', camera: false }, { type: 'typed', value: '120' }, { type: 'typed', value: '9a1' }, { type: 'typed', value: '901' }]);
check('a typed number the rule does not allow: said, still on the pad; a good one opens that tank', w.effects[1] === 'say' && w.m.tank === 901 && w.m.phase === 'done' && w.effects[3] === 'hide,go');
w = run([{ type: 'open', camera: false }, { type: 'hidden' }, { type: 'visible' }, { type: 'cancel' }]);
check('on the pad the page going away and back changes nothing; Cancel closes', w.effects[1] === '' && w.effects[2] === '' && w.m.phase === 'closed' && w.effects[3] === 'stop,hide');
w = run([{ type: 'open', camera: true }, { type: 'open', camera: true }]);
check('a second tap on Scan while it is open: nothing new', w.effects[1] === '' && w.m.phase === 'starting');
w = run([{ type: 'open', camera: true }, { type: 'ready' }, LABEL_121, { type: 'open', camera: true }]);
check('after a tank opened (and the page came back from the history): it opens again', w.m.phase === 'starting' && w.effects[3] === 'show,camera,reader');
check('anything else: no change, no effect', S.step(null, { type: 'nonsense' }).phase === 'closed' && S.step(null, { type: 'nonsense' }).effects.length === 0 && S.step(null, null).effects.length === 0);

(async () => {
	console.log('\n3. Which reader');
	const native = { BarcodeDetector: class { constructor(o) { this.o = o; } static getSupportedFormats() { return Promise.resolve(['ean_13', 'qr_code']); } } };
	check('the browser reads QR codes itself: its own reader', await S.chooseReader(native) === 'native');
	check('no BarcodeDetector (an iPhone, Firefox): jsQR', await S.chooseReader({}) === 'jsqr' && await S.chooseReader(null) === 'jsqr');
	check('a BarcodeDetector without QR codes: jsQR', await S.chooseReader({ BarcodeDetector: class { static getSupportedFormats() { return Promise.resolve(['ean_13']); } } }) === 'jsqr');
	check('one whose list fails, or throws: jsQR', await S.chooseReader({ BarcodeDetector: class { static getSupportedFormats() { return Promise.reject(new Error('x')); } } }) === 'jsqr' && await S.chooseReader({ BarcodeDetector: class { static getSupportedFormats() { throw new Error('x'); } } }) === 'jsqr');
	check('one with no list that cannot be made for QR codes: jsQR; one that can: its own', await S.chooseReader({ BarcodeDetector: class { constructor() { throw new TypeError('no'); } } }) === 'jsqr' && await S.chooseReader({ BarcodeDetector: class {} }) === 'native');

	console.log('\n4. On a stand-in page with a stand-in camera');

	/* A stand-in page: elements, listeners, timers run by hand. */
	function makePage(opts) {
		opts = opts || {};
		const page = { timers: [], opened: [], buzz: [], asked: [], tracks: [], scripts: [], frames: 0, frame: null, docListeners: {}, winListeners: {} };
		function node(tag) {
			const n = {
				tag: tag, children: [], parentNode: null, attrs: {}, listeners: {}, className: '', textContent: '', hidden: false,
				classes: new Set(),
				setAttribute(k, v) { this.attrs[k] = String(v); },
				addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
				appendChild(child) { if (child.parentNode) { child.parentNode.removeChild(child); } child.parentNode = this; this.children.push(child); if (child.tag === 'script') { page.scripts.push(child); } return child; },
				removeChild(child) { this.children = this.children.filter((c) => c !== child); child.parentNode = null; return child; }
			};
			n.classList = { add(c) { n.classes.add(c); }, remove(c) { n.classes.delete(c); }, contains(c) { return n.classes.has(c); } };
			if (tag === 'video') {
				Object.assign(n, { videoWidth: 640, videoHeight: 480, readyState: 4, srcObject: null, play() { return Promise.resolve(); } });
			}
			if (tag === 'canvas') {
				n.width = 0;
				n.height = 0;
				n.getContext = () => ({
					drawImage() { page.frames++; },
					getImageData(x, y, cw, ch) { return { data: page.frame || new Uint8ClampedArray(cw * ch * 4).fill(255), width: cw, height: ch }; }
				});
			}
			return n;
		}
		const doc = {
			hidden: false,
			createElement: node,
			createTextNode(text) { return { tag: '#text', textContent: String(text), children: [], parentNode: null }; },
			addEventListener(type, fn) { (page.docListeners[type] = page.docListeners[type] || []).push(fn); },
			removeEventListener(type, fn) { page.docListeners[type] = (page.docListeners[type] || []).filter((f) => f !== fn); }
		};
		doc.body = node('body');
		doc.head = node('head');
		const win = {
			isSecureContext: !opts.insecure,
			location: { protocol: 'https:' },
			navigator: {
				vibrate(ms) { page.buzz.push(ms); return true; }
			},
			setTimeout(fn, ms) { page.timers.push({ fn: fn, ms: ms, live: true }); return page.timers.length; },
			clearTimeout(id) { if (page.timers[id - 1]) { page.timers[id - 1].live = false; } },
			addEventListener(type, fn) { (page.winListeners[type] = page.winListeners[type] || []).push(fn); },
			removeEventListener(type, fn) { page.winListeners[type] = (page.winListeners[type] || []).filter((f) => f !== fn); }
		};
		if (!opts.noCamera) {
			win.navigator.mediaDevices = {
				getUserMedia(constraints) {
					page.asked.push(constraints);
					if (opts.cameraError) {
						const err = new Error('no');
						err.name = opts.cameraError;
						return Promise.reject(err);
					}
					if (opts.holdCamera) {
						return new Promise((resolve) => { page.giveCamera = resolve; });
					}
					return Promise.resolve(makeStream());
				}
			};
		}
		function makeStream() {
			const track = { stopped: false, stop() { this.stopped = true; } };
			page.tracks.push(track);
			return { getTracks() { return [track]; } };
		}
		page.makeStream = makeStream;
		if (opts.codes) {
			win.BarcodeDetector = class {
				static getSupportedFormats() { return Promise.resolve(['qr_code']); }
				detect() { page.frames++; return Promise.resolve((page.codes.shift() || []).map((text) => ({ rawValue: text }))); }
			};
			page.codes = opts.codes.slice();
		}
		function el(tag, attrs, children) {
			const n = doc.createElement(tag);
			Object.keys(attrs || {}).forEach((k) => {
				const v = attrs[k];
				if (v === null || v === undefined || v === false) { return; }
				if (k === 'class') { n.className = v; } else if (k === 'text') { n.textContent = String(v); } else if (k === 'on') { Object.keys(v).forEach((e) => n.addEventListener(e, v[e])); } else if (k === 'hidden') { n.hidden = !!v; } else { n.setAttribute(k, v); }
			});
			(children || []).forEach((c) => { if (c) { n.appendChild(typeof c === 'string' ? doc.createTextNode(c) : c); } });
			return n;
		}
		page.doc = doc;
		page.win = win;
		page.scanner = S.create({
			win: win, doc: doc, el: el, label: LABEL, labelVar: 'fh_front_desk', reader: QR_LIB,
			t: (key, fallback) => fallback,
			open: (tank) => { page.opened.push(tank); }
		});
		page.tick = () => {
			const due = page.timers.filter((t) => t.live && t.ms === 200);
			due.forEach((t) => { t.live = false; t.fn(); });
			return due.length;
		};
		page.layer = () => doc.body.children.filter((c) => c.className === 'fh-sa-scan')[0] || null;
		page.find = (cls) => {
			const out = [];
			(function walk(n) { (n.children || []).forEach((c) => { if ((c.className || '').split(' ').indexOf(cls) !== -1) { out.push(c); } walk(c); }); })(doc.body);
			return out[0] || null;
		};
		page.hint = () => (page.find('fh-sa-scan__hint') || {}).textContent || '';
		page.click = (n) => (n.listeners.click || []).forEach((fn) => fn({}));
		page.key = (label) => {
			const out = [];
			(function walk(n) { (n.children || []).forEach((c) => { if (c.tag === 'button' && c.textContent === label && (c.className || '').indexOf('fh-sa-key') !== -1) { out.push(c); } walk(c); }); })(doc.body);
			return out[0];
		};
		page.fire = (where, type, ev) => ((where === 'doc' ? page.docListeners : page.winListeners)[type] || []).slice().forEach((fn) => fn(ev || {}));
		return page;
	}

	async function settle() {
		for (let i = 0; i < 12; i++) {
			await new Promise((resolve) => setImmediate(resolve));
		}
	}

	/* A QR code from scan-codes.json drawn into a 640×480 camera frame (RGBA). */
	function frameOf(code, moduleSize) {
		const W = 640;
		const H = 480;
		const px = moduleSize || 6;
		const data = new Uint8ClampedArray(W * H * 4).fill(255);
		const side = code.size * px;
		const x0 = Math.floor((W - side) / 2);
		const y0 = Math.floor((H - side) / 2);
		for (let y = 0; y < code.size; y++) {
			for (let x = 0; x < code.size; x++) {
				if (code.rows[y][x] !== '1') { continue; }
				for (let dy = 0; dy < px; dy++) {
					for (let dx = 0; dx < px; dx++) {
						const i = ((y0 + y * px + dy) * W + (x0 + x * px + dx)) * 4;
						data[i] = 20; data[i + 1] = 20; data[i + 2] = 20;
					}
				}
			}
		}
		return data;
	}

	/* -- The browser's own reader -- */
	let page = makePage({ codes: [[], ['https://elsewhere.example/?fh_front_desk=1&tank=121'], [SITE + '/product/invented-goby/'], [SITE + '/?fh_front_desk=1&tank=121']] });
	page.scanner.open();
	await settle();
	check('Scan: full screen over the page — the picture, the square, Cancel, "Type the tank number"', page.layer() !== null && page.find('fh-sa-scan__video') !== null && page.find('fh-sa-scan__frame') !== null && page.find('fh-sa-scan__cancel').textContent === 'Cancel' && page.find('fh-sa-scan__type').textContent === 'Type the tank number');
	check('the rear camera is asked for, no sound', page.asked.length === 1 && page.asked[0].video.facingMode.ideal === 'environment' && page.asked[0].audio === false);
	check('the picture is the camera\'s stream, shown inline (not full-screen video on an iPhone)', page.find('fh-sa-scan__video').srcObject !== null && page.find('fh-sa-scan__video').attrs.playsinline === '' && page.find('fh-sa-scan__video').muted === true);
	check('no script loaded: this browser reads QR codes itself', page.scripts.length === 0 && page.scanner.phase() === 'scanning');
	check('it says what to do', page.hint() === 'Hold the tank\'s label inside the square.');
	page.tick();
	await settle();
	check('a frame with nothing in it: still scanning, the next frame asked for', page.scanner.phase() === 'scanning' && page.timers.filter((t) => t.live && t.ms === 200).length === 1);
	page.tick();
	await settle();
	check('another site\'s code: "for another website. It was not opened." — nothing opened, still scanning', /another website\. It was not opened\./.test(page.hint()) && page.opened.length === 0 && page.scanner.phase() === 'scanning' && page.tracks[0].stopped === false);
	page.tick();
	await settle();
	check('a product link: "not a FisHotel tank label", still scanning', /not a FisHotel tank label/.test(page.hint()) && page.opened.length === 0 && page.scanner.phase() === 'scanning');
	page.tick();
	await settle();
	check('a label: that tank opened at once — the number only, through the app', page.opened.join() === '121');
	check('… the camera stopped, a short buzz, the scanner gone, no more frames asked for', page.tracks[0].stopped === true && page.buzz.length === 1 && page.layer() === null && page.timers.filter((t) => t.live).length === 0);
	check('… and nothing of it listens any more (hidden page, keys, leaving)', (page.docListeners.visibilitychange || []).length === 0 && (page.docListeners.keydown || []).length === 0 && (page.winListeners.pagehide || []).length === 0);

	page = makePage({ codes: [] });
	page.scanner.open();
	await settle();
	page.click(page.find('fh-sa-scan__cancel'));
	check('Cancel: the camera stopped, the scanner gone, nothing opened', page.tracks[0].stopped && page.layer() === null && page.opened.length === 0 && page.scanner.phase() === 'closed');
	page.scanner.open();
	await settle();
	check('Scan again: a new camera stream, scanning', page.tracks.length === 2 && page.tracks[1].stopped === false && page.scanner.phase() === 'scanning');
	page.fire('doc', 'keydown', { key: 'Escape' });
	check('Escape on a keyboard is Cancel', page.tracks[1].stopped && page.layer() === null);

	page = makePage({ codes: [[SITE + '/?fh_front_desk=1&tank=121']] });
	page.scanner.open();
	await settle();
	page.doc.hidden = true;
	page.fire('doc', 'visibilitychange');
	check('the page hidden: the camera stopped at once, the scanner still there', page.tracks[0].stopped && page.layer() !== null && page.scanner.phase() === 'paused');
	check('… and no frame is read while it is hidden', page.tick() === 0 && page.opened.length === 0);
	page.doc.hidden = false;
	page.fire('doc', 'visibilitychange');
	await settle();
	check('back: the camera again, scanning', page.tracks.length === 2 && !page.tracks[1].stopped && page.scanner.phase() === 'scanning');
	page.tick();
	await settle();
	check('… and a label opens its tank', page.opened.join() === '121' && page.tracks[1].stopped);

	page = makePage({ codes: [] });
	page.scanner.open();
	await settle();
	page.fire('win', 'pagehide');
	check('the page left (pagehide): the camera stopped, the scanner gone', page.tracks[0].stopped && page.layer() === null && page.scanner.phase() === 'closed');

	page = makePage({ codes: [], holdCamera: true });
	page.scanner.open();
	await settle();
	page.click(page.find('fh-sa-scan__cancel'));
	page.giveCamera(page.makeStream());
	await settle();
	check('a camera that answers after Cancel is stopped at once and never shown', page.tracks.length === 1 && page.tracks[0].stopped && page.layer() === null && page.scanner.phase() === 'closed');

	page = makePage({ codes: [], holdCamera: true });
	page.scanner.open();
	await settle();
	page.doc.hidden = true;
	page.fire('doc', 'visibilitychange');
	page.giveCamera(page.makeStream());
	await settle();
	check('a camera that answers after the page was hidden is stopped too', page.tracks[0].stopped && page.scanner.phase() === 'paused');

	/* -- No camera → the number pad -- */
	page = makePage({ codes: [], cameraError: 'NotAllowedError' });
	page.scanner.open();
	await settle();
	check('the camera refused: plain words, the number pad', /not allowed for this app\. Type the tank number instead/.test(page.hint()) && page.find('fh-sa-scan__pad').hidden === false && page.layer().classes.has('is-typing') && page.scanner.phase() === 'typing');
	page.click(page.key('1'));
	page.click(page.key('2'));
	check('two digits: shown, nothing opened yet', page.find('fh-sa-scan__typed').textContent === '12' && page.opened.length === 0);
	page.click(page.key('0'));
	check('a third that makes no tank number (120): said, nothing opened', /three digits, like 121 or 901/.test(page.hint()) && page.opened.length === 0 && page.scanner.phase() === 'typing');
	page.click(page.key('⌫'));
	page.click(page.key('1'));
	page.click(page.key('2'));
	page.click(page.key('1'));
	check('back, then 1 2 1: tank 121 opened, the pad gone', page.opened.join() === '121' && page.layer() === null);

	page = makePage({ codes: [], cameraError: 'NotFoundError' });
	page.scanner.open();
	await settle();
	check('no camera found: "could not be opened", the pad', /camera could not be opened\. Type the tank number instead/.test(page.hint()) && page.scanner.phase() === 'typing');
	page.fire('doc', 'keydown', { key: '9', preventDefault() {} });
	page.fire('doc', 'keydown', { key: '0', preventDefault() {} });
	page.fire('doc', 'keydown', { key: '1', preventDefault() {} });
	check('a keyboard types on the pad too: 901', page.opened.join() === '901');

	page = makePage({ noCamera: true, codes: [] });
	page.scanner.open();
	check('a browser with no camera at all: straight to the pad, nothing asked for', page.scanner.phase() === 'typing' && page.asked.length === 0 && page.find('fh-sa-scan__pad').hidden === false);
	page = makePage({ insecure: true, codes: [] });
	page.scanner.open();
	check('not on HTTPS (the camera needs it): the pad, nothing asked for', page.scanner.phase() === 'typing' && page.asked.length === 0);

	page = makePage({ codes: [[]] });
	page.scanner.open();
	await settle();
	page.click(page.find('fh-sa-scan__type'));
	check('"Type the tank number" while scanning: the camera stopped, the pad', page.tracks[0].stopped && page.scanner.phase() === 'typing' && page.find('fh-sa-scan__pad').hidden === false && page.tick() === 0);

	/* -- jsQR: loaded only when needed, reading the shop's own label codes -- */
	S._reset();
	page = makePage({});
	page.scanner.open();
	await settle();
	check('no reader in the browser: jsQR is asked for — one script tag, from this section, as the scanner opens', page.scripts.length === 1 && page.scripts[0].attrs.src === QR_LIB && page.scanner.phase() === 'starting');
	check('… and until it is there nothing is read', page.tick() === 0);
	page.win.jsQR = require(JSQR);
	(page.scripts[0].listeners.load || []).forEach((fn) => fn());
	await settle();
	check('jsQR there: scanning', page.scanner.phase() === 'scanning');
	page.frame = frameOf(CODES.codes.other);
	page.tick();
	await settle();
	check('jsQR reads another site\'s code printed like a label: not opened, still scanning', page.frames === 1 && /another website/.test(page.hint()) && page.opened.length === 0 && page.scanner.phase() === 'scanning');
	page.frame = frameOf(CODES.codes.product);
	page.tick();
	await settle();
	check('… a product link: not a label', /not a FisHotel tank label/.test(page.hint()) && page.opened.length === 0);
	page.frame = frameOf(CODES.codes.label901, 4);
	page.tick();
	await settle();
	check('… the label the Clean Room prints for slot 901: tank 901 opened, the camera stopped, a buzz', page.opened.join() === '901' && page.tracks[0].stopped && page.buzz.length === 1);

	page.scanner.open();
	await settle();
	check('Scan again on the same page: jsQR is already there — no second script tag, scanning at once', page.scripts.length === 1 && page.scanner.phase() === 'scanning');
	page.scanner.cancel();

	S._reset();
	page = makePage({ codes: [] });
	page.scanner.open();
	await settle();
	check('a browser that reads QR codes itself never loads jsQR', page.scripts.length === 0 && typeof page.win.jsQR === 'undefined');

	S._reset();
	page = makePage({});
	page.scanner.open();
	await settle();
	(page.scripts[0].listeners.error || []).forEach((fn) => fn());
	await settle();
	check('jsQR could not be loaded (no connection): the camera let go, the pad, saying so', page.scanner.phase() === 'typing' && page.tracks[0].stopped && /code reader could not be loaded/.test(page.hint()));
	page.click(page.find('fh-sa-scan__cancel'));
	page.scanner.open();
	await settle();
	check('… the next Scan tries to load it again', page.scripts.length === 2);
	page.win.jsQR = require(JSQR);
	(page.scripts[1].listeners.load || []).forEach((fn) => fn());
	await settle();
	page.frame = frameOf(CODES.codes.label121);
	page.tick();
	await settle();
	check('… and then reads the label of tank 121', page.opened.join() === '121');

	console.log('\n5. The script itself');
	const code = fs.readFileSync(FILE, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
	check('never innerHTML, document.write or eval', !/innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\(|new Function\(/.test(code));
	check('it keeps nothing on the device', !/localStorage|sessionStorage|indexedDB|document\.cookie/.test(code));
	check('it navigates nowhere itself: the tank NUMBER goes to the page (opts.open), never what the camera read', !/location/.test(code.replace(/win\.location && win\.location\.protocol/, '')) && /opts\.open\(machine\.tank\)/.test(code));
	check('what the camera read goes only into readTankCode', (code.match(/rawValue/g) || []).length === 1 && /readTankCode\(list\[i\], site, opts\.labelVar\)/.test(code));

	console.log('\n' + (failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed'));
	process.exit(failures ? 1 : 0);
})().catch((e) => {
	console.error(e);
	process.exit(1);
});
