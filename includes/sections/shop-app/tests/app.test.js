/**
 * App script tests — js/app.js.
 *
 * The pure helpers first (the label reader, the countdown, the save id,
 * what an answer means, one keypad press). Then the script is loaded into
 * a small stand-in page — a document that can make elements, a fetch that
 * answers as the test says, timers the test runs by hand — and driven:
 *
 *   inside a tank screen ("frontdesk"): an entry is never lost silently
 *   and never written twice. A save with no answer opens the MAY NOT BE
 *   SAVED sheet, the page's request stays open, Retry sends the SAME save
 *   id again, "Back to the entry" hands the page a plain refusal so it
 *   keeps what was typed — and when that very entry is saved again it
 *   goes out under the id it had; a save the server is still writing is
 *   asked for again; a session that ran out brings the keypad up over the
 *   page and the save goes through after the PIN — or, when somebody with
 *   another account unlocks, the screen says the entry was NOT saved
 *   before the tank is loaded afresh; the page's own "no" is left to the
 *   page.
 *
 *   the app itself ("app"): what a device that is not set up sees (no
 *   keypad), the keypad (twelve keys, the PIN sent with its last digit,
 *   wrong PIN, lockout), the home screen (who is unlocked, Lock,
 *   buildings then tanks — drawn from the newest state, never from the
 *   one a tile was tapped in), a tank label scanned before the PIN.
 *
 * Run from the plugin root:
 *   node includes/sections/shop-app/tests/app.test.js
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

'use strict';

const fs = require('fs');
const path = require('path');
const nodeCrypto = require('crypto');

const FILE = path.join(__dirname, '..', 'js', 'app.js');
const SITE = 'https://shop.example.test';
const AJAX = SITE + '/wp-admin/admin-ajax.php';

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

const helpers = require(FILE);

console.log('1. Reading a tank label');
const L = (text) => helpers.tankFromLabel(text, 'shop.example.test', 'fh_front_desk');
check('the label address gives its tank number', L('https://shop.example.test/?fh_front_desk=1&tank=521') === 521);
check('the parameters in the other order', L('https://shop.example.test/?tank=121&fh_front_desk=1') === 121);
check('http instead of https, www in front, capitals in the host, spaces around: still this shop\'s label', L('http://shop.example.test/?fh_front_desk=1&tank=7') === 7 && L('https://www.shop.example.test/?fh_front_desk=1&tank=7') === 7 && L('  HTTPS://Shop.Example.Test/?fh_front_desk=1&tank=7 ') === 7);
check('a site in a folder, /index.php, a fragment after it', L('https://shop.example.test/fish/?fh_front_desk=1&tank=7') === 7 && L('https://shop.example.test/index.php?fh_front_desk=1&tank=7#top') === 7);
for (const [what, text] of [
	['another site', 'https://elsewhere.example/?fh_front_desk=1&tank=521'],
	['another site that puts this one in front of an @', 'https://shop.example.test@elsewhere.example/?fh_front_desk=1&tank=521'],
	['another site with this one as a subdomain label', 'https://shop.example.test.elsewhere.example/?fh_front_desk=1&tank=521'],
	['this site without the Front Desk switch', 'https://shop.example.test/?tank=521'],
	['the switch off', 'https://shop.example.test/?fh_front_desk=0&tank=521'],
	['no tank', 'https://shop.example.test/?fh_front_desk=1'],
	['a tank that is not a number', 'https://shop.example.test/?fh_front_desk=1&tank=12a'],
	['a tank with a sign', 'https://shop.example.test/?fh_front_desk=1&tank=-5'],
	['a ten-digit tank', 'https://shop.example.test/?fh_front_desk=1&tank=1234567890'],
	['the app\'s own address', 'https://shop.example.test/?fh_shop_app=1&tank=521'],
	['a javascript: address', 'javascript:alert(1)//?fh_front_desk=1&tank=521'],
	['plain text', 'Tank 521'],
	['a bare number', '521'],
	['nothing', ''],
	['a broken escape', 'https://shop.example.test/?fh_front_desk=1&tank=%E0%A4%A']
]) {
	check('not a tank label: ' + what, L(text) === 0);
}
check('null, undefined and a number are not labels', helpers.tankFromLabel(null, 'shop.example.test', 'fh_front_desk') === 0 && helpers.tankFromLabel(undefined, 'shop.example.test', 'fh_front_desk') === 0 && helpers.tankFromLabel(521, 'shop.example.test', 'fh_front_desk') === 0);
check('without knowing this site\'s host nothing is a label', helpers.tankFromLabel('https://shop.example.test/?fh_front_desk=1&tank=521', '', 'fh_front_desk') === 0);
check('the port is not part of the comparison', helpers.tankFromLabel('http://127.0.0.1:8491/?fh_front_desk=1&tank=9', '127.0.0.1:8491', 'fh_front_desk') === 9);

console.log('\n2. Small things');
check('a countdown: 4:59, 0:05, 1:02:03, never negative', helpers.clock(299) === '4:59' && helpers.clock(5) === '0:05' && helpers.clock(3723) === '1:02:03' && helpers.clock(-4) === '0:00' && helpers.clock(299.2) === '5:00' && helpers.clock('x') === '0:00');
check('fmt fills the first %d or %s', helpers.fmt('%d tries left', 3) === '3 tries left' && helpers.fmt('Tank %d is not in the layout.', 9) === 'Tank 9 is not in the layout.' && helpers.fmt('locked for %s', '4:59') === 'locked for 4:59' && helpers.fmt(undefined, 1) === '');
const idA = helpers.newSaveId();
const idB = helpers.newSaveId();
check('a save id is 32 hex characters, never twice the same', /^[a-f0-9]{32}$/.test(idA) && /^[a-f0-9]{32}$/.test(idB) && idA !== idB);
check('it is made from the bytes it is given', helpers.newSaveId(() => [0, 1, 15, 16, 255, 171, 0, 0, 0, 0, 0, 0, 0, 0, 0, 9]) === '00010f10ffab00000000000000000009');
check('the id is added to a form body once', helpers.withSaveId('action=x&act=loss', 'fh_save_id', idA) === 'action=x&act=loss&fh_save_id=' + idA && helpers.withSaveId('action=x&fh_save_id=' + idB, 'fh_save_id', idA) === 'action=x&fh_save_id=' + idB && helpers.withSaveId('', 'fh_save_id', idA) === 'fh_save_id=' + idA && helpers.withSaveId('action=x', 'fh_save_id', '') === 'action=x');
check('the action of a form body', helpers.bodyAction('action=fishotel_cleanroom_act&nonce=abc') === 'fishotel_cleanroom_act' && helpers.bodyAction('nonce=abc&action=fishotel_cleanroom_screen&tank=5') === 'fishotel_cleanroom_screen' && helpers.bodyAction('nonce=abc&reaction=x') === '' && helpers.bodyAction('') === '' && helpers.bodyAction('action=a%20b') === 'a b');
const LAST = { body: 'action=x&act=loss&qty=1', id: idA, at: 1000000 };
check('the very entry that got no answer keeps its id when it is saved again', helpers.reuseSaveId(LAST, 'action=x&act=loss&qty=1', 1000000 + 5000) === idA && helpers.reuseSaveId(LAST, 'action=x&act=loss&qty=1', 1000000 + 3599999) === idA);
check('… any other entry does not: one more fish, another tank, a note', helpers.reuseSaveId(LAST, 'action=x&act=loss&qty=2', 1000000 + 5000) === '' && helpers.reuseSaveId(LAST, 'action=x&act=loss&qty=1&note=a', 1000000 + 5000) === '' && helpers.reuseSaveId(LAST, '', 1000000 + 5000) === '');
check('… nor after an hour, nor when nothing is in doubt', helpers.reuseSaveId(LAST, LAST.body, 1000000 + 3600000) === '' && helpers.reuseSaveId(null, LAST.body, 1000000) === '' && helpers.reuseSaveId({ body: LAST.body, id: '', at: 1000000 }, LAST.body, 1000001) === '' && helpers.reuseSaveId(LAST, LAST.body, 999999) === '');
const PICK = [{ label: 'Clean Room', buildings: [{ label: 'Building 100', tanks: [{ number: 111 }] }, { label: 'Annex', tanks: [] }] }, { label: 'Spa', buildings: [{ label: 'Annex', tanks: [{ number: 911 }] }] }];
check('a building is found again by its area and name in a fresh picker', helpers.findBuilding(PICK, { area: 'Clean Room', label: 'Building 100' }) === PICK[0].buildings[0] && helpers.findBuilding(PICK, { area: 'Spa', label: 'Annex' }) === PICK[1].buildings[0] && helpers.findBuilding(PICK, { area: 'Clean Room', label: 'Annex' }) === PICK[0].buildings[1]);
check('… and is nothing when it is gone, or nothing is open', helpers.findBuilding(PICK, { area: 'Spa', label: 'Building 100' }) === null && helpers.findBuilding(PICK, null) === null && helpers.findBuilding(null, { area: 'Spa', label: 'Annex' }) === null && helpers.findBuilding([], { area: 'Spa', label: 'Annex' }) === null);
check('when a session ends, in this device\'s clock: the time left as the server sees it, added to now', helpers.deadlineFrom({ expires: 1800007200, now: 1800000000 }, 5000) === 5000 + 7200000 && helpers.deadlineFrom({ expires: 10, now: 20 }, 5000) === 5000 && helpers.deadlineFrom(null, 5000) === 0);

console.log('\n3. What an answer means');
const C = helpers.classifyReply;
check('a JSON success: hand it to the page', C(200, '{"success":true,"data":{}}') === 'ok');
check('the Front Desk\'s own "no" (400 with a message): hand it to the page — it shows it and keeps the form', C(400, '{"success":false,"data":{"message":"How many? 1–5."}}') === 'ok');
check('a stale page (403, -1): hand it to the page', C(403, '-1') === 'ok');
check('409: the same save is still running', C(409, '{"success":false,"data":{"pending":true}}') === 'pending');
check('admin-ajax\'s bare 0 with 400: WordPress did not know the device', C(400, '0') === 'signedout' && C(400, ' 0\n') === 'signedout' && C(400, '') === 'signedout');
check('500, 502, 503: the site failed', C(500, 'x') === 'server' && C(502, '') === 'server' && C(503, '<html>') === 'server');
check('a 200 that is not JSON (a Wi-Fi login page): garbled', C(200, '<html><body>Sign in to Wi-Fi</body></html>') === 'garbled' && C(200, '') === 'garbled' && C(200, '0') === 'garbled' && C(200, 'null') === 'garbled');

console.log('\n4. One press on the keypad');
const P = helpers.padPress;
check('a digit is added', P('', '4', 4).digits === '4' && P('48', '2', 4).digits === '482' && !P('48', '2', 4).submit);
check('the last digit says: send it', P('482', '1', 4).digits === '4821' && P('482', '1', 4).submit === true);
check('with six digits the fourth is not the last', !P('482', '1', 6).submit && P('48213', '5', 6).submit);
check('more digits than a PIN has are not taken', P('4821', '9', 4).digits === '4821');
check('back takes one off, clear takes all', P('482', 'back', 4).digits === '48' && P('', 'back', 4).digits === '' && P('482', 'clear', 4).digits === '');
check('anything that is not a digit is ignored', P('48', 'x', 4).digits === '48' && P('48', '12', 4).digits === '48' && P('48', '', 4).digits === '48');

/* ------------------------------------------------------------------ */
/*  A stand-in page                                                    */
/* ------------------------------------------------------------------ */

function makeDocument() {
	const doc = {
		hidden: false,
		listeners: {},
		events: [],
		byId: {},
		createElement(tag) {
			const node = {
				tag: tag,
				children: [],
				parentNode: null,
				attrs: {},
				listeners: {},
				style: {},
				className: '',
				textContent: '',
				hidden: false,
				disabled: false,
				value: '',
				classes: new Set(),
				setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'id') { doc.byId[v] = this; } },
				getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; },
				removeAttribute(k) { delete this.attrs[k]; },
				addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
				removeEventListener() {},
				appendChild(child) { if (child.parentNode) { child.parentNode.removeChild(child); } child.parentNode = this; this.children.push(child); return child; },
				insertBefore(child, ref) { child.parentNode = this; const i = this.children.indexOf(ref); this.children.splice(i < 0 ? 0 : i, 0, child); return child; },
				removeChild(child) { this.children = this.children.filter((c) => c !== child); child.parentNode = null; return child; },
				get firstChild() { return this.children[0] || null; },
				get isConnected() { let n = this; while (n) { if (n === doc.body) { return true; } n = n.parentNode; } return false; },
				get offsetWidth() { return 0; },
				play() { return Promise.resolve(); }
			};
			node.classList = {
				add(c) { node.classes.add(c); },
				remove(c) { node.classes.delete(c); },
				contains(c) { return node.classes.has(c) || node.className.split(' ').indexOf(c) !== -1; }
			};
			return node;
		},
		createTextNode(text) { return { tag: '#text', textContent: String(text), children: [], parentNode: null, classes: new Set(), className: '' }; },
		addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
		removeEventListener() {},
		getElementById(id) { return this.byId[id] || null; },
		dispatchEvent(event) { this.events.push(event); return true; }
	};
	doc.body = doc.createElement('body');
	doc.head = doc.createElement('head');
	return doc;
}

function has(node, cls) {
	return node.className.split(' ').indexOf(cls) !== -1 || node.classes.has(cls);
}

function findAll(node, cls, out) {
	out = out || [];
	(node.children || []).forEach((child) => {
		if (child.tag !== '#text' && has(child, cls)) {
			out.push(child);
		}
		findAll(child, cls, out);
	});
	return out;
}

function find(node, cls) {
	return findAll(node, cls)[0] || null;
}

function text(node) {
	return (node.textContent || '') + (node.children || []).map(text).join('');
}

function click(node) {
	(node.listeners.click || []).forEach((fn) => fn({ preventDefault() {}, stopPropagation() {} }));
}

function reply(status, body) {
	const res = {
		status: status,
		body: typeof body === 'string' ? body : JSON.stringify(body),
		clone() { return res; },
		text() { return Promise.resolve(res.body); },
		json() { return new Promise((resolve) => resolve(JSON.parse(res.body))); }
	};
	return res;
}

/* Let every promise that is ready run. */
async function settle() {
	for (let i = 0; i < 12; i++) {
		await new Promise((resolve) => setImmediate(resolve));
	}
}

const I18N = {
	title: 'FisHotel Shop', enterPin: 'Enter your PIN', wrongPin: 'Wrong PIN', triesLeft: '%d tries left', oneTryLeft: '1 try left',
	lockedFor: 'This device is locked. Try again in %s.', checking: 'Checking…', lock: 'Lock', tanks: 'Tanks', back: 'Back',
	pickBuilding: 'Pick a building', pickTank: 'Pick a tank', notEnrolled: 'This device is not set up for the Shop App.', notEnrolledHow: 'A shop manager sets it up.',
	setUpHere: 'Set up with a manager login', noPeople: 'Nobody has a PIN yet.', offline: 'No connection.', retry: 'Retry', error: 'Something went wrong. Try again.',
	notSaved: 'MAY NOT BE SAVED', notSavedWhy: 'No answer came back — the connection may be down — so it may not have been saved. What you entered is still here. Retry sends the same entry again; it is written once.', notSavedServer: 'The site had a problem and may not have saved it. What you entered is still here. Retry sends the same entry again; it is written once.',
	notSavedClosed: 'Not confirmed as saved. Check the connection, then save again — the same entry is written once.', keepEditing: 'Back to the entry', saving: 'Saving…',
	lostTitle: 'NOT SAVED', lostWhy: 'The entry that was waiting was made before %s unlocked. It was not saved — enter it again.', lostOk: 'OK — enter it again',
	sessionEnded: 'Locked. Enter your PIN to go on.', sessionEndedSave: 'Locked before it was saved. %s: enter your PIN and it is saved.', needsHttps: 'The Shop App needs HTTPS.',
	empty: 'empty', comingSoon: 'coming soon', tankCount: '%d tanks', inQt: 'QT %d', wpLogin: 'Also logged in to WordPress as %s.', wpLogout: 'Log out of WordPress here',
	noFrontDesk: 'There are no tank screens to open.', scan: 'Scan a tank label'
};

const URLS = {
	app: SITE + '/?fh_shop_app=1', state: SITE + '/?fh_shop_app=state', unlock: SITE + '/?fh_shop_app=unlock', lock: SITE + '/?fh_shop_app=lock',
	sw: SITE + '/?fh_shop_app=sw', swScope: SITE + '/?fh_shop_app=', label: SITE + '/?fh_front_desk=1', ajax: AJAX, admin: SITE + '/wp-admin/'
};

/* Load js/app.js into a stand-in page. */
function boot(config, opts) {
	opts = opts || {};
	const doc = makeDocument();
	if (config.mode === 'app') {
		const app = doc.createElement('div');
		app.setAttribute('id', 'fh-sa-app');
		doc.body.appendChild(app);
	}
	const page = {
		doc: doc,
		calls: [],        // every request that reached the network: { url, init }
		answer: opts.answer || (() => Promise.reject(new TypeError('Failed to fetch'))),
		timers: [],
		listeners: {},
		reloads: 0,
		went: []
	};
	const location = {
		protocol: 'https:',
		host: 'shop.example.test',
		search: opts.search || '',
		reload() { page.reloads++; },
		replace(url) { page.went.push(url); },
		get href() { return page.went[page.went.length - 1] || SITE + '/'; },
		set href(url) { page.went.push(url); }
	};
	const win = {
		fhShopApp: config,
		document: doc,
		location: location,
		isSecureContext: opts.insecure ? false : true,
		navigator: {},
		crypto: nodeCrypto.webcrypto,
		AbortController: AbortController,
		CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
		fetch(url, init) {
			page.calls.push({ url: url, init: init || {} });
			return page.answer(url, init || {});
		},
		setTimeout(fn, ms) { page.timers.push({ fn: fn, ms: ms, live: true }); return page.timers.length; },
		clearTimeout(id) { if (page.timers[id - 1]) { page.timers[id - 1].live = false; } },
		setInterval() { return 0; },
		clearInterval() {},
		addEventListener(type, fn) { (page.listeners[type] = page.listeners[type] || []).push(fn); },
		scrollTo() {}
	};
	// The script reads `document` whenever it draws, so the stand-ins stay
	// in place until the next page replaces them (one page at a time).
	global.window = win;
	global.document = doc;
	delete require.cache[require.resolve(FILE)];
	require(FILE);
	page.win = win;
	/* Run the timers of a given delay that are still wanted. */
	page.run = (ms) => {
		const due = page.timers.filter((t) => t.live && t.ms === ms);
		due.forEach((t) => { t.live = false; t.fn(); });
		return due.length;
	};
	page.sheet = () => find(doc.body, 'fh-sa-sheet');
	page.layer = () => find(doc.body, 'fh-sa-layer');
	return page;
}

function frontDeskConfig() {
	return {
		mode: 'frontdesk', urls: URLS, header: 'X-FH-Shop-App', labelVar: 'fh_front_desk', device: 'Shop tablet',
		session: { person: 'Sam', account: 'aaaa000011112222', started: 1800000000, expires: 1800007200, mode: 'last_use', length: 7200, now: 1800000000 },
		pinLength: 4, write: 'fishotel_cleanroom_act', actions: ['fishotel_cleanroom_act', 'fishotel_cleanroom_parse', 'fishotel_cleanroom_suggest', 'fishotel_cleanroom_screen'],
		saveField: 'fh_save_id', i18n: I18N
	};
}

const SAVE = { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: 'action=fishotel_cleanroom_act&nonce=abc&act=loss&tank=521&stay_id=7&qty=1&via=qr' };
const SAVED = { success: true, data: { message: '1 × Invented Goby lost', undo: { eventIds: [41] }, screen: { tank: { number: 521 } } } };
const idOf = (call) => (/(?:^|&)fh_save_id=([a-f0-9]{32})(?:&|$)/.exec(call.init.body) || [])[1] || '';
const STATE_LIVE = { ok: true, https: true, enrolled: true, hasPeople: true, pinLength: 4, lockout: { locked: false, seconds: 0 }, device: { name: 'Shop tablet' }, session: frontDeskConfig().session };
const STATE_LOCKED = Object.assign({}, STATE_LIVE, { session: null });

(async () => {
	console.log('\n5. Inside a tank screen: the bar');
	let page = boot(frontDeskConfig());
	const bar = page.doc.body.children[0];
	check('the bar is the first thing on the page', bar && has(bar, 'fh-sa-bar'));
	check('it shows who is unlocked', text(find(bar, 'fh-sa-bar__name')) === 'Sam');
	check('a way back to the tanks (the app\'s home)', find(bar, 'fh-sa-bar__home').attrs.href === URLS.app && /Tanks/.test(text(find(bar, 'fh-sa-bar__home'))));
	check('and Lock', text(find(bar, 'fh-sa-bar__lock')) === 'Lock');
	check('links into wp-admin are hidden — a shop session cannot go there', page.doc.head.children.length === 1 && page.doc.head.children[0].textContent === 'a[href^="' + URLS.admin + '"]{display:none !important}');
	page.answer = () => Promise.resolve(reply(200, { ok: true, state: STATE_LOCKED }));
	click(find(bar, 'fh-sa-bar__lock'));
	await settle();
	check('Lock posts to the app with its header, then leaves the tank for the keypad', page.calls.length === 1 && page.calls[0].url === URLS.lock && page.calls[0].init.method === 'POST' && page.calls[0].init.headers['X-FH-Shop-App'] === '1' && page.went.join() === URLS.app);

	console.log('\n6. What it leaves alone');
	page = boot(frontDeskConfig());
	page.answer = () => Promise.resolve(reply(200, { success: true }));
	const other = { method: 'GET' };
	await page.win.fetch(SITE + '/something-else', other);
	check('a request to another address goes out untouched', page.calls[0].init === other);
	const foreign = { method: 'POST', body: 'action=some_other_action&x=1' };
	await page.win.fetch(AJAX, foreign);
	check('an admin-ajax call that is not the Front Desk\'s goes out untouched', page.calls[1].init === foreign);
	const read = { method: 'POST', body: 'action=fishotel_cleanroom_screen&nonce=abc&tank=521' };
	let res = await page.win.fetch(AJAX, read);
	check('a read (screen) gets no save id, and its answer goes to the page', page.calls[2].init.body === read.body && res.status === 200);
	page.answer = () => Promise.reject(new TypeError('Failed to fetch'));
	let thrown = null;
	await page.win.fetch(AJAX, read).catch((e) => { thrown = e; });
	check('a read that fails is the page\'s own business: no sheet, the page gets the failure as before', thrown instanceof TypeError && page.sheet() === null);

	console.log('\n7. A save that goes through');
	page = boot(frontDeskConfig());
	page.answer = () => Promise.resolve(reply(200, SAVED));
	res = await page.win.fetch(AJAX, SAVE);
	check('the save carries a save id — everything else of the body is as the page wrote it', page.calls.length === 1 && page.calls[0].init.body === SAVE.body + '&fh_save_id=' + idOf(page.calls[0]) && idOf(page.calls[0]).length === 32);
	check('its answer goes to the page untouched', res.status === 200 && (await res.json()).data.message === '1 × Invented Goby lost');
	check('no sheet, nothing announced', page.sheet() === null && page.doc.events.length === 0);
	check('the page\'s own request object is not changed', SAVE.body.indexOf('fh_save_id') === -1);
	await page.win.fetch(AJAX, SAVE);
	check('the next save gets an id of its own', idOf(page.calls[1]) !== idOf(page.calls[0]));

	console.log('\n8. No connection: MAY NOT BE SAVED · Retry');
	page = boot(frontDeskConfig());
	let done = null;
	let failed = null;
	page.answer = () => Promise.reject(new TypeError('Failed to fetch'));
	page.win.fetch(AJAX, SAVE).then((r) => { done = r; }, (e) => { failed = e; });
	await settle();
	check('the sheet opens and says what is true: it MAY NOT BE SAVED (no answer came — the server may have written it all the same)', page.sheet() !== null && text(find(page.sheet(), 'fh-sa-sheet__title')) === 'MAY NOT BE SAVED' && /may not have been saved/.test(text(find(page.sheet(), 'fh-sa-sheet__why'))));
	check('it says that what was entered is still there', /still here/.test(text(find(page.sheet(), 'fh-sa-sheet__why'))));
	check('it offers Retry', text(find(page.sheet(), 'fh-sa-sheet__retry')) === 'Retry');
	check('the page is told nothing yet: its request is still open, so it keeps its form as it is', done === null && failed === null);
	check('the failure is announced for a later offline queue: the id, the body, the reason', page.doc.events.length === 1 && page.doc.events[0].type === 'fhshopapp:savefailed' && page.doc.events[0].detail.id === idOf(page.calls[0]) && page.doc.events[0].detail.body === page.calls[0].init.body && page.doc.events[0].detail.reason === 'offline');

	click(find(page.sheet(), 'fh-sa-sheet__retry'));
	await settle();
	check('Retry with the connection still down: asked again with the SAME save id, the sheet stays', page.calls.length === 2 && idOf(page.calls[1]) === idOf(page.calls[0]) && page.calls[1].init.body === page.calls[0].init.body && page.sheet() !== null && done === null && failed === null);

	page.answer = () => Promise.resolve(reply(200, SAVED));
	click(find(page.sheet(), 'fh-sa-sheet__retry'));
	await settle();
	check('Retry with the connection back: the same id a third time, the sheet closes, the page gets its answer', page.calls.length === 3 && idOf(page.calls[2]) === idOf(page.calls[0]) && page.sheet() === null && done && done.status === 200 && failed === null);

	console.log('\n9. Back to the entry');
	page = boot(frontDeskConfig());
	done = null;
	failed = null;
	page.answer = () => Promise.reject(new TypeError('Failed to fetch'));
	page.win.fetch(AJAX, SAVE).then((r) => { done = r; }, (e) => { failed = e; });
	await settle();
	const back = findAll(page.sheet(), 'fh-sa-btn').filter((b) => text(b) === 'Back to the entry')[0];
	click(back);
	await settle();
	check('the sheet closes and the page is told plainly: not confirmed as saved — save again', page.sheet() === null && done === null && failed instanceof Error && failed.message === 'Not confirmed as saved. Check the connection, then save again — the same entry is written once.');
	check('nothing more was sent', page.calls.length === 1);

	// The page does what it was told: the same entry, Save again. The first
	// request may have reached the server — so it must go out under the
	// SAME id, or a dead fish is counted twice.
	page.answer = () => Promise.resolve(reply(200, SAVED));
	res = await page.win.fetch(AJAX, SAVE);
	check('Save again with the same entry: it goes out under the id it had — the server writes it once', page.calls.length === 2 && idOf(page.calls[1]) !== '' && idOf(page.calls[1]) === idOf(page.calls[0]) && res.status === 200 && page.sheet() === null);
	await page.win.fetch(AJAX, SAVE);
	check('once it was answered nothing is in doubt any more: the same entry entered again on purpose is a new save', page.calls.length === 3 && idOf(page.calls[2]) !== idOf(page.calls[0]));

	page = boot(frontDeskConfig());
	page.answer = () => Promise.reject(new TypeError('Failed to fetch'));
	page.win.fetch(AJAX, SAVE).catch(() => {});
	await settle();
	click(findAll(page.sheet(), 'fh-sa-btn').filter((b) => text(b) === 'Back to the entry')[0]);
	await settle();
	page.answer = () => Promise.resolve(reply(200, SAVED));
	const CHANGED = Object.assign({}, SAVE, { body: SAVE.body.replace('qty=1', 'qty=2') });
	await page.win.fetch(AJAX, CHANGED);
	check('Back, the entry is CHANGED, Save: that is another entry and gets an id of its own', page.calls.length === 2 && idOf(page.calls[1]) !== idOf(page.calls[0]) && /qty=2/.test(page.calls[1].init.body));
	await page.win.fetch(AJAX, SAVE);
	check('… while the first one, saved after all, still goes out under its own id', page.calls.length === 3 && idOf(page.calls[2]) === idOf(page.calls[0]));

	page = boot(frontDeskConfig());
	page.answer = () => Promise.resolve(reply(502, '<html>Bad gateway</html>'));
	page.win.fetch(AJAX, SAVE).catch(() => {});
	await settle();
	click(findAll(page.sheet(), 'fh-sa-btn').filter((b) => text(b) === 'Back to the entry')[0]);
	await settle();
	page.answer = () => Promise.resolve(reply(200, SAVED));
	await page.win.fetch(AJAX, SAVE);
	check('the same after a site failure (502): Back, Save again — the same id', page.calls.length === 2 && idOf(page.calls[1]) === idOf(page.calls[0]));

	console.log('\n10. The connection comes back by itself');
	page = boot(frontDeskConfig());
	done = null;
	page.answer = () => Promise.reject(new TypeError('Failed to fetch'));
	page.win.fetch(AJAX, SAVE).then((r) => { done = r; }, () => {});
	await settle();
	page.answer = () => Promise.resolve(reply(200, SAVED));
	(page.listeners.online || []).forEach((fn) => fn());
	(page.listeners.online || []).forEach((fn) => fn());
	await settle();
	check('when the browser says "online" the waiting save is sent again — once, with its id', page.calls.length === 2 && idOf(page.calls[1]) === idOf(page.calls[0]) && page.sheet() === null && done && done.status === 200);

	console.log('\n11. The site fails, a Wi-Fi login page answers, the save is still running');
	page = boot(frontDeskConfig());
	done = null;
	page.answer = () => Promise.resolve(reply(502, '<html>Bad gateway</html>'));
	page.win.fetch(AJAX, SAVE).then((r) => { done = r; }, () => {});
	await settle();
	check('a 502: MAY NOT BE SAVED, and it says the site had a problem', page.sheet() !== null && /site had a problem/.test(text(find(page.sheet(), 'fh-sa-sheet__why'))) && done === null && page.doc.events[0].detail.reason === 'server');

	page = boot(frontDeskConfig());
	done = null;
	page.answer = () => Promise.resolve(reply(200, '<html><body>Sign in to this Wi-Fi</body></html>'));
	page.win.fetch(AJAX, SAVE).then((r) => { done = r; }, () => {});
	await settle();
	check('a 200 that is a Wi-Fi login page, not an answer: MAY NOT BE SAVED — it is never handed to the page as if it were one', page.sheet() !== null && done === null);

	page = boot(frontDeskConfig());
	done = null;
	page.answer = () => Promise.resolve(reply(409, { success: false, data: { pending: true } }));
	page.win.fetch(AJAX, SAVE).then((r) => { done = r; }, () => {});
	await settle();
	check('"still saving" (409): no sheet; it waits two seconds', page.sheet() === null && done === null && page.timers.filter((t) => t.live && t.ms === 2000).length === 1);
	page.answer = () => Promise.resolve(reply(200, Object.assign({}, SAVED, { data: Object.assign({ replayed: true }, SAVED.data) })));
	page.run(2000);
	await settle();
	check('… and asks again with the same id; the remembered answer goes to the page', page.calls.length === 2 && idOf(page.calls[1]) === idOf(page.calls[0]) && done && done.status === 200);

	console.log('\n12. The page\'s own "no" is the page\'s');
	page = boot(frontDeskConfig());
	page.answer = () => Promise.resolve(reply(400, { success: false, data: { message: 'How many? 1–5.' } }));
	res = await page.win.fetch(AJAX, SAVE);
	check('a refused entry (400 with a message) goes to the page, which shows it and keeps the form', res.status === 400 && (await res.json()).data.message === 'How many? 1–5.' && page.sheet() === null && page.layer() === null);
	page.answer = () => Promise.resolve(reply(403, '-1'));
	res = await page.win.fetch(AJAX, SAVE);
	check('a stale page (403) goes to the page, which says "tap to reload"', res.status === 403 && page.sheet() === null && page.layer() === null);

	console.log('\n13. The session ran out under an open page');
	page = boot(frontDeskConfig());
	done = null;
	failed = null;
	let unlocked = false;
	page.answer = (url, init) => {
		if (url === URLS.state) {
			return Promise.resolve(reply(200, unlocked ? STATE_LIVE : STATE_LOCKED));
		}
		if (url === URLS.unlock) {
			if (init.body === 'pin=4821') {
				unlocked = true;
				return Promise.resolve(reply(200, { ok: true, code: 'ok', state: STATE_LIVE }));
			}
			return Promise.resolve(reply(200, { ok: false, code: 'wrong_pin', left: 4, state: STATE_LOCKED }));
		}
		return Promise.resolve(unlocked ? reply(200, SAVED) : reply(400, '0'));
	};
	page.win.fetch(AJAX, SAVE).then((r) => { done = r; }, (e) => { failed = e; });
	await settle();
	check('WordPress did not know the device (400, "0"): the app asks its own address whether the session is gone', page.calls.length === 2 && page.calls[1].url === URLS.state && page.calls[1].init.headers['X-FH-Shop-App'] === '1');
	check('it is: the keypad comes up over the page, saying the entry waits — and whose PIN saves it', page.layer() !== null && findAll(page.layer(), 'fh-sa-key').length === 12 && /Locked before it was saved\. Sam: enter your PIN and it is saved\./.test(text(page.layer())));
	check('the page is told nothing: what was typed stays where it is', done === null && failed === null && page.sheet() === null);
	const key = (digit) => findAll(page.layer(), 'fh-sa-key').filter((k) => text(k) === digit)[0];
	['0', '0', '0', '0'].forEach((d) => click(key(d)));
	await settle();
	check('a wrong PIN: said so, the keypad stays, nothing is sent for the save', /Wrong PIN · 4 tries left/.test(text(page.layer())) && page.layer() !== null && page.calls.filter((c) => c.url === AJAX).length === 1);
	['4', '8', '2', '1'].forEach((d) => click(key(d)));
	await settle();
	page.run(0);
	await settle();
	const saves = page.calls.filter((c) => c.url === AJAX);
	check('the PIN is sent with its last digit, to the app, with the header', page.calls.some((c) => c.url === URLS.unlock && c.init.body === 'pin=4821' && c.init.method === 'POST' && c.init.headers['X-FH-Shop-App'] === '1'));
	check('after the PIN the keypad goes and the waiting save is sent again with its id', page.layer() === null && saves.length === 2 && idOf(saves[1]) === idOf(saves[0]));
	check('… and the page gets its answer: nothing was lost', done && done.status === 200 && failed === null);

	page = boot(frontDeskConfig());
	page.answer = (url) => Promise.resolve(url === URLS.state ? reply(200, STATE_LIVE) : reply(400, '0'));
	res = await page.win.fetch(AJAX, SAVE);
	check('a bare "0" while the session is in fact live is the page\'s own business: no keypad, the answer goes to the page', res.status === 400 && page.layer() === null && page.sheet() === null);

	page = boot(frontDeskConfig());
	done = null;
	page.answer = (url, init) => {
		if (url === URLS.state) {
			return Promise.resolve(reply(200, STATE_LOCKED));
		}
		if (url === URLS.unlock) {
			return Promise.resolve(reply(200, { ok: true, code: 'ok', state: Object.assign({}, STATE_LIVE, { session: Object.assign({}, STATE_LIVE.session, { person: 'Robin', account: 'bbbb000011112222' }) }) }));
		}
		return Promise.resolve(reply(400, '0'));
	};
	page.win.fetch(AJAX, SAVE).then((r) => { done = r; }, () => {});
	await settle();
	['1', '3', '7', '9'].forEach((d) => click(findAll(page.layer(), 'fh-sa-key').filter((k) => text(k) === d)[0]));
	await settle();
	page.run(0);
	await settle();
	check('somebody with ANOTHER account unlocks: Sam\'s entry is not saved as theirs — nothing more is sent', done === null && page.calls.filter((c) => c.url === AJAX).length === 1);
	check('… and it is NOT dropped without a word: the keypad goes and the screen says NOT SAVED, who unlocked, enter it again', page.layer() === null && page.sheet() !== null && text(find(page.sheet(), 'fh-sa-sheet__title')) === 'NOT SAVED' && /made before Robin unlocked\. It was not saved — enter it again\./.test(text(page.sheet())));
	check('… the tank is NOT loaded afresh until that was read', page.reloads === 0 && findAll(page.sheet(), 'fh-sa-btn').length === 1 && find(page.sheet(), 'fh-sa-sheet__retry') === null);
	click(findAll(page.sheet(), 'fh-sa-btn')[0]);
	check('… OK loads the tank afresh for the person who unlocked', page.reloads === 1);

	page = boot(frontDeskConfig());
	page.answer = (url) => {
		if (url === URLS.state) {
			return Promise.resolve(reply(200, STATE_LOCKED));
		}
		if (url === URLS.unlock) {
			return Promise.resolve(reply(200, { ok: true, code: 'ok', state: Object.assign({}, STATE_LIVE, { session: Object.assign({}, STATE_LIVE.session, { person: 'Robin', account: 'bbbb000011112222' }) }) }));
		}
		return Promise.resolve(reply(400, '0'));
	};
	page.win.fetch(AJAX, { method: 'POST', headers: {}, body: 'action=fishotel_cleanroom_screen&nonce=abc&tank=521' }).catch(() => {});
	await settle();
	['1', '3', '7', '9'].forEach((d) => click(findAll(page.layer(), 'fh-sa-key').filter((k) => text(k) === d)[0]));
	await settle();
	page.run(0);
	await settle();
	check('when only a READ was waiting nothing was lost: the tank is loaded afresh at once, no sheet', page.reloads === 1 && page.sheet() === null);

	page = boot(frontDeskConfig());
	page.answer = (url) => Promise.resolve(url === URLS.state ? reply(200, { ok: true, https: true, enrolled: false, session: null }) : reply(400, '0'));
	page.win.fetch(AJAX, SAVE).catch(() => {});
	await settle();
	check('the device was revoked meanwhile: no keypad — off to the app, which says it is not set up', page.layer() === null && page.went.join() === URLS.app);

	console.log('\n14. The seam for an offline queue');
	const cfg = frontDeskConfig();
	const taken = [];
	cfg.onSaveFailed = (detail) => { taken.push(detail); return Promise.resolve(reply(200, SAVED)); };
	page = boot(cfg);
	page.answer = () => Promise.reject(new TypeError('Failed to fetch'));
	res = await page.win.fetch(AJAX, SAVE);
	check('a window.fhShopApp.onSaveFailed that returns a promise of an answer is asked first — and no sheet opens', taken.length === 1 && taken[0].id === idOf(page.calls[0]) && taken[0].reason === 'offline' && res.status === 200 && page.sheet() === null);
	const cfg2 = frontDeskConfig();
	cfg2.onSaveFailed = () => { throw new Error('the queue broke'); };
	page = boot(cfg2);
	page.answer = () => Promise.reject(new TypeError('Failed to fetch'));
	page.win.fetch(AJAX, SAVE).catch(() => {});
	await settle();
	check('a hook that throws, or takes nothing, changes nothing: the sheet opens as ever', page.sheet() !== null);

	console.log('\n15. The app: a device that is not set up');
	const APP = { mode: 'app', urls: URLS, header: 'X-FH-Shop-App', labelVar: 'fh_front_desk', i18n: I18N };
	page = boot(APP, { answer: () => Promise.resolve(reply(200, { ok: true, https: true, enrolled: false, session: null, adminUrl: SITE + '/wp-admin/admin.php?page=fishotel-misc-shop-app', frontDesk: { available: true } })) });
	const app = page.doc.getElementById('fh-sa-app');
	await settle();
	check('it asks the state — with the header, with cookies, never from a cache', page.calls[0].url === URLS.state && page.calls[0].init.headers['X-FH-Shop-App'] === '1' && page.calls[0].init.credentials === 'same-origin' && page.calls[0].init.cache === 'no-store');
	check('it says the device is not set up', /This device is not set up for the Shop App\./.test(text(app)));
	check('it shows NO keypad', findAll(app, 'fh-sa-key').length === 0 && findAll(app, 'fh-sa-pad').length === 0);
	check('it offers a manager a way to set it up', findAll(app, 'fh-sa-btn').some((b) => b.attrs.href === SITE + '/wp-admin/admin.php?page=fishotel-misc-shop-app'));

	page = boot(APP, { insecure: true });
	await settle();
	check('without HTTPS it says so and asks the server nothing', /needs HTTPS/.test(text(page.doc.getElementById('fh-sa-app'))) && page.calls.length === 0);

	page = boot(APP);
	await settle();
	check('with no connection it says so and offers Retry', /No connection\./.test(text(page.doc.getElementById('fh-sa-app'))) && findAll(page.doc.getElementById('fh-sa-app'), 'fh-sa-btn').some((b) => text(b) === 'Retry'));

	console.log('\n16. The app: keypad, then home');
	const HOME = [{ label: 'Clean Room', buildings: [{ label: 'Building 100', status: 'active', inQt: 0, tanks: [{ number: 111, size: '40G', empty: true, count: 0 }, { number: 121, size: '20G', empty: false, count: 2 }] }, { label: 'Building 400', status: 'coming_soon', inQt: 0, tanks: [] }] }, { label: 'Spa', buildings: [{ label: 'Building 500', status: 'active', inQt: 3, tanks: [{ number: 511, size: '40G', empty: false, count: 1 }] }] }];
	const APP_LOCKED = { ok: true, https: true, enrolled: true, hasPeople: true, pinLength: 4, lockout: { locked: false, seconds: 0 }, device: { name: 'Shop tablet' }, session: null, frontDesk: { available: true }, wpLogin: null };
	const APP_LIVE = Object.assign({}, APP_LOCKED, { session: { person: 'Sam', account: 'aaaa', started: 1800000000, expires: 1800007200, now: 1800000000, mode: 'last_use', length: 7200 }, home: HOME, links: [{ label: 'Packing', url: SITE + '/?fh_packing=1' }] });
	let live = false;
	page = boot(APP, { answer: (url, init) => {
		if (url === URLS.unlock) {
			if (init.body === 'pin=4821') {
				live = true;
				return Promise.resolve(reply(200, { ok: true, code: 'ok', state: APP_LIVE }));
			}
			if (init.body === 'pin=9999') {
				return Promise.resolve(reply(200, { ok: false, code: 'locked', seconds: 300, state: APP_LOCKED }));
			}
			return Promise.resolve(reply(200, { ok: false, code: 'wrong_pin', left: 1, state: APP_LOCKED }));
		}
		if (url === URLS.lock) {
			live = false;
			return Promise.resolve(reply(200, { ok: true, state: APP_LOCKED }));
		}
		return Promise.resolve(reply(200, live ? APP_LIVE : APP_LOCKED));
	} });
	const root = page.doc.getElementById('fh-sa-app');
	await settle();
	const keys = () => findAll(root, 'fh-sa-key');
	const tap = (digit) => click(keys().filter((k) => text(k) === digit)[0]);
	check('an enrolled device opens on the keypad: twelve keys, 1–9, Clear, 0, back', keys().length === 12 && keys().map(text).join(' ') === '1 2 3 4 5 6 7 8 9 Clear 0 ⌫');
	check('the keys are buttons — there is no input field, so no on-screen keyboard', keys().every((k) => k.tag === 'button') && findAll(root, 'fh-sa-pad').length === 1 && !JSON.stringify(root, (k, v) => (k === 'parentNode' || k === 'classList' ? undefined : (v instanceof Set ? [...v] : v))).includes('"tag":"input"'));
	check('four empty dots, the device\'s name, no person\'s name', findAll(root, 'fh-sa-dot').length === 4 && findAll(root, 'is-on').length === 0 && /Shop tablet/.test(text(root)) && !/Sam/.test(text(root)));
	tap('4');
	tap('8');
	check('each press fills a dot; nothing is sent before the last digit', findAll(root, 'fh-sa-dot').filter((d) => has(d, 'is-on')).length === 2 && page.calls.filter((c) => c.url === URLS.unlock).length === 0);
	(keys()[0].listeners.pointerdown || []).forEach((fn) => fn({}));
	check('a press lights its key at once', keys()[0].classes.has('is-pressed'));
	const asked = page.calls.length;
	(page.doc.listeners.visibilitychange || []).forEach((fn) => fn());
	await settle();
	check('the screen coming back on asks the state again — and leaves the two typed digits where they are', page.calls.length === asked + 1 && findAll(root, 'fh-sa-dot').filter((d) => has(d, 'is-on')).length === 2);
	tap('⌫');
	tap('0');
	tap('0');
	tap('0');
	await settle();
	check('the fourth digit sends the PIN (4000) — to the app, as a POST, with the header', page.calls.filter((c) => c.url === URLS.unlock).length === 1 && page.calls.filter((c) => c.url === URLS.unlock)[0].init.body === 'pin=4000' && page.calls.filter((c) => c.url === URLS.unlock)[0].init.method === 'POST');
	check('a wrong PIN: "Wrong PIN · 1 try left", the pad shakes, the dots are cleared', /Wrong PIN · 1 try left/.test(text(root)) && find(root, 'fh-sa-pad').classes.has('is-wrong') && findAll(root, 'fh-sa-dot').filter((d) => has(d, 'is-on')).length === 0);
	['9', '9', '9', '9'].forEach(tap);
	await settle();
	check('locked out: the keypad says for how long and is dimmed', /This device is locked\. Try again in [45]:\d\d\./.test(text(root)) && find(root, 'fh-sa-pad').classes.has('is-locked'));
	['4', '8', '2', '1'].forEach(tap);
	await settle();
	check('while it is locked the keys do nothing — not even the right PIN is sent', page.calls.filter((c) => c.url === URLS.unlock).length === 2);

	live = false;
	page = boot(APP, { answer: (url) => {
		if (url === URLS.unlock) {
			live = true;
			return Promise.resolve(reply(200, { ok: true, code: 'ok', state: APP_LIVE }));
		}
		if (url === URLS.lock) {
			live = false;
			return Promise.resolve(reply(200, { ok: true, state: APP_LOCKED }));
		}
		return Promise.resolve(reply(200, live ? APP_LIVE : APP_LOCKED));
	} });
	const home = page.doc.getElementById('fh-sa-app');
	await settle();
	['4', '8', '2', '1'].forEach((d) => click(findAll(home, 'fh-sa-key').filter((k) => text(k) === d)[0]));
	await settle();
	page.run(0);
	await settle();
	check('the right PIN: the home screen says who is unlocked, on which device', text(find(home, 'fh-sa-who__name')) === 'Sam' && text(find(home, 'fh-sa-who__device')) === 'Shop tablet' && findAll(home, 'fh-sa-key').length === 0);
	check('with Lock', text(find(home, 'fh-sa-btn--lock')) === 'Lock');
	const tiles = () => findAll(home, 'fh-sa-tile');
	check('first the buildings, by area', /Pick a building/.test(text(home)) && findAll(home, 'fh-sa-area').map(text).join() === 'Clean Room,Spa' && tiles().map((t) => text(find(t, 'fh-sa-tile__name'))).join() === 'Building 100,Building 400,Building 500');
	check('a building that is coming soon cannot be opened; one with fish in quarantine says so', tiles()[1].disabled === true && /coming soon/.test(text(tiles()[1])) && /QT 3/.test(text(tiles()[2])) && /2 tanks/.test(text(tiles()[0])));
	check('no Scan button where the browser cannot read codes from the camera', find(home, 'fh-sa-btn--scan') === null);
	check('a button another section added is there', findAll(home, 'fh-sa-btn').some((b) => text(b) === 'Packing' && b.attrs.href === SITE + '/?fh_packing=1'));
	click(tiles()[0]);
	check('then that building\'s tanks', tiles().map((t) => text(find(t, 'fh-sa-tile__name'))).join() === '111,121' && /empty/.test(text(tiles()[0])) && /2 · 20G/.test(text(tiles()[1])));
	click(tiles()[1]);
	check('a tank opens at its label address — the Clean Room\'s own page', page.went.join() === SITE + '/?fh_front_desk=1&tank=121');
	// Somebody saved an arrival in tank 111 (on this tablet or another) and
	// the app asks the state again — coming back from the tank, the screen
	// waking up. The open building must show what the NEW state says.
	const FRESH = JSON.parse(JSON.stringify(APP_LIVE));
	FRESH.home[0].buildings[0].tanks[0] = { number: 111, size: '40G', empty: false, count: 3 };
	const before = text(tiles()[0]);
	page.answer = () => Promise.resolve(reply(200, FRESH));
	(page.listeners.pageshow || []).forEach((fn) => fn({ persisted: true }));
	await settle();
	check('a fresh state while a building is open: its tanks are redrawn from it — the tile no longer says "empty"', /empty/.test(before) && tiles().map((t) => text(find(t, 'fh-sa-tile__name'))).join() === '111,121' && !/empty/.test(text(tiles()[0])) && /3 · 40G/.test(text(tiles()[0])) && !has(tiles()[0], 'is-empty'));
	const GONE = JSON.parse(JSON.stringify(APP_LIVE));
	GONE.home[0].buildings.shift();
	page.answer = () => Promise.resolve(reply(200, GONE));
	(page.listeners.pageshow || []).forEach((fn) => fn({ persisted: true }));
	await settle();
	check('a building that is no longer in the layout is not shown from memory: back to the buildings', /Pick a building/.test(text(home)) && tiles().map((t) => text(find(t, 'fh-sa-tile__name'))).join() === 'Building 400,Building 500');
	page.answer = () => Promise.resolve(reply(200, APP_LIVE));
	(page.listeners.pageshow || []).forEach((fn) => fn({ persisted: true }));
	await settle();
	click(tiles()[0]);
	click(findAll(home, 'fh-sa-btn').filter((b) => /Back/.test(text(b)))[0]);
	check('Back returns to the buildings', tiles().length === 3);
	page.answer = (url) => {
		if (url === URLS.lock) {
			live = false;
			return Promise.resolve(reply(200, { ok: true, state: APP_LOCKED }));
		}
		return Promise.resolve(reply(200, live ? APP_LIVE : APP_LOCKED));
	};
	click(find(home, 'fh-sa-btn--lock'));
	await settle();
	check('Lock: the app tells the server, then shows the keypad — and nobody\'s name', page.calls.some((c) => c.url === URLS.lock && c.init.method === 'POST') && findAll(home, 'fh-sa-key').length === 12 && !/Sam/.test(text(home)) && tiles().length === 0);

	console.log('\n17. The app: a label scanned before the PIN');
	live = false;
	page = boot(APP, { search: '?fh_shop_app=1&tank=521', answer: (url) => {
		if (url === URLS.unlock) {
			live = true;
			return Promise.resolve(reply(200, { ok: true, code: 'ok', state: APP_LIVE }));
		}
		return Promise.resolve(reply(200, live ? APP_LIVE : APP_LOCKED));
	} });
	await settle();
	check('it opens on the keypad', findAll(page.doc.getElementById('fh-sa-app'), 'fh-sa-key').length === 12 && page.went.length === 0);
	['4', '8', '2', '1'].forEach((d) => click(findAll(page.doc.getElementById('fh-sa-app'), 'fh-sa-key').filter((k) => text(k) === d)[0]));
	await settle();
	page.run(0);
	await settle();
	check('and after the PIN goes straight to that tank', page.went.join() === SITE + '/?fh_front_desk=1&tank=521');

	page = boot(APP, { search: '?fh_shop_app=1&tank=521', answer: () => Promise.resolve(reply(200, APP_LIVE)) });
	await settle();
	check('already unlocked: straight to the tank, no keypad', page.went.join() === SITE + '/?fh_front_desk=1&tank=521');

	page = boot(APP, { answer: () => Promise.resolve(reply(200, Object.assign({}, APP_LOCKED, { hasPeople: false }))) });
	await settle();
	check('enrolled, but nobody has a PIN: it says so instead of showing a keypad nobody could use', /Nobody has a PIN yet\./.test(text(page.doc.getElementById('fh-sa-app'))) && findAll(page.doc.getElementById('fh-sa-app'), 'fh-sa-key').length === 0);

	page = boot(APP, { answer: () => Promise.resolve(reply(200, Object.assign({}, APP_LOCKED, { wpLogin: { name: 'Morgan Manager', logoutUrl: SITE + '/wp-login.php?action=logout' } }))) });
	await settle();
	check('a WordPress login on the device is said plainly under the keypad, with a way to log it out', /Also logged in to WordPress as Morgan Manager\./.test(text(page.doc.getElementById('fh-sa-app'))) && findAll(page.doc.getElementById('fh-sa-app'), 'fh-sa-warn').length === 1);

	console.log('\n18. The script itself');
	const source = fs.readFileSync(FILE, 'utf8');
	const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
	check('it builds every screen from elements and text — never innerHTML, document.write or eval', !/innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\(|new Function\(/.test(code));
	check('it keeps nothing on the device: no localStorage, sessionStorage, IndexedDB or cookie', !/localStorage|sessionStorage|indexedDB|document\.cookie/.test(code));
	check('a scanned address is never opened: the only places it goes are built from this site\'s own addresses', !/location\.(href|replace)\s*=?\s*\(?\s*(codes|rawValue|text)\b/.test(code) && (code.match(/rawValue/g) || []).length === 1);
	check('every request to the app\'s own address carries the app header', /init\.headers\[cfg\.header \|\| 'X-FH-Shop-App'\] = '1'/.test(code));

	console.log('\n' + (failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed'));
	process.exit(failures ? 1 : 0);
})().catch((e) => {
	console.error(e);
	process.exit(1);
});
