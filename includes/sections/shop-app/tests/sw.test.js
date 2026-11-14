/**
 * Service worker tests — the worker must never keep a dynamic or an
 * authenticated answer. js/sw.js is loaded into a stand-in worker (its
 * own addEventListener, fetch, Request and caches) and driven event by
 * event:
 *   - it looks at a GET for one of its five static addresses and at
 *     nothing else — not a POST (a PIN, a lock, a save), not the state,
 *     not a tank screen, not admin-ajax, not the shop front;
 *   - what it keeps it fetched itself, without cookies (credentials
 *     "omit") — never the page's own request;
 *   - an answer that says no-store or private, varies by cookie, was
 *     redirected or is not a plain 200 is not kept, whatever its address;
 *   - network first, the kept copy only when the network gives nothing,
 *     and the browser's own request when there is no copy either;
 *   - old caches of its own are cleared, other caches are left alone.
 *
 * Run from the plugin root:
 *   node includes/sections/shop-app/tests/sw.test.js
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

'use strict';

const fs = require('fs');
const path = require('path');

const FILE = path.join(__dirname, '..', 'js', 'sw.js');
const SITE = 'https://shop.example.test';
const SHELL = SITE + '/?fh_shop_app=1';
const MANIFEST = SITE + '/?fh_shop_app=manifest';
const ICON = SITE + '/wp-content/plugins/fishotel-misc-plugin/includes/sections/shop-app/assets/icon-192.png';
const CONFIG = { cache: 'fh-shop-app-aaaabbbbcccc', shell: SHELL, keep: [SHELL, MANIFEST, ICON] };

let failures = 0;
let checks = 0;
function check(label, condition, detail) {
	checks++;
	if (condition) {
		console.log('  ok   ' + label);
		return;
	}
	failures++;
	console.log('  FAIL ' + label + (detail !== undefined ? '\n       ' + detail : ''));
}

/* An answer as the worker sees one. */
function answer(body, opts) {
	const o = Object.assign({ status: 200, headers: { 'cache-control': 'no-cache' }, type: 'basic', redirected: false }, opts || {});
	const res = {
		body: body,
		status: o.status,
		ok: o.status >= 200 && o.status < 300,
		type: o.type,
		redirected: o.redirected,
		headers: { get: (name) => (Object.prototype.hasOwnProperty.call(o.headers, String(name).toLowerCase()) ? o.headers[String(name).toLowerCase()] : null) },
		clone: () => res
	};
	return res;
}

/* A stand-in worker with js/sw.js loaded into it. */
function load(config) {
	const stores = new Map(); // cache name → Map(address → answer)
	const worker = {
		listeners: {},
		fetched: [],  // every request the worker itself made
		puts: [],     // every address it put into a cache
		network: () => Promise.reject(new TypeError('offline')),
		skipped: 0,
		claimed: 0,
		addEventListener(type, fn) { this.listeners[type] = fn; },
		Request: class {
			constructor(url, init) {
				this.url = url;
				this.method = (init && init.method) || 'GET';
				this.credentials = (init && init.credentials) || 'same-origin';
				this.cache = init && init.cache;
				this.made = 'by the worker';
			}
		},
		fetch(request) {
			worker.fetched.push(request);
			return worker.network(request);
		},
		caches: {
			open(name) {
				if (!stores.has(name)) {
					stores.set(name, new Map());
				}
				const map = stores.get(name);
				return Promise.resolve({
					put(key, res) { worker.puts.push(key); map.set(key, res); return Promise.resolve(); },
					match(key) { return Promise.resolve(map.get(key)); }
				});
			},
			keys() { return Promise.resolve([...stores.keys()]); },
			delete(name) { return Promise.resolve(stores.delete(name)); }
		},
		skipWaiting() { this.skipped++; return Promise.resolve(); },
		clients: { claim() { worker.claimed++; return Promise.resolve(); } },
		stores: stores
	};

	global.self = worker;
	global.FH_SW = config;
	delete require.cache[require.resolve(FILE)];
	worker.api = require(FILE);
	delete global.self;
	delete global.FH_SW;
	return worker;
}

/* Fire an event and wait for everything it asked to wait for. */
async function fire(worker, type, request) {
	const waits = [];
	let responded = null;
	const event = {
		request: request,
		waitUntil(p) { waits.push(Promise.resolve(p)); },
		respondWith(p) { responded = Promise.resolve(p); }
	};
	worker.listeners[type](event);
	const result = responded ? await responded : undefined;
	await Promise.all(waits);
	return { handled: responded !== null, response: result };
}

function pageRequest(url, method) {
	return { url: url, method: method || 'GET', credentials: 'include', made: 'by the page' };
}

(async () => {
	console.log('1. Which addresses it looks at');
	let w = load(CONFIG);
	const keyFor = w.api.keyFor;
	check('the shell, the manifest and an icon are its own', keyFor(SHELL) === SHELL && keyFor(MANIFEST) === MANIFEST && keyFor(ICON) === ICON);
	check('the shell with a tank number after it is still the shell', keyFor(SHELL + '&tank=521') === SHELL);
	const strangers = [
		SITE + '/?fh_shop_app=state',
		SITE + '/?fh_shop_app=unlock',
		SITE + '/?fh_shop_app=lock',
		SITE + '/?fh_shop_app=enrol&k=0123456789abcdef0123456789abcdef',
		SITE + '/?fh_shop_app=sw',
		SITE + '/?fh_front_desk=1&tank=521',
		SITE + '/?fh_front_desk=1',
		SITE + '/wp-admin/admin-ajax.php',
		SITE + '/wp-admin/',
		SITE + '/wp-json/wp/v2/users/me',
		SITE + '/',
		SITE + '/my-account/',
		SITE + '/checkout/',
		SHELL + '&tank=abc',
		SHELL + '&tank=521&x=1',
		SHELL + '&state=1',
		SHELL + '#x',
		SHELL.replace('https:', 'http:'),
		'https://elsewhere.example/?fh_shop_app=1',
		ICON + '?v=2',
		''
	];
	check('nothing else is: ' + strangers.length + ' other addresses tried', strangers.every((u) => keyFor(u) === ''), strangers.filter((u) => keyFor(u) !== '').join(', '));

	console.log('\n2. What it leaves entirely alone');
	w.network = () => Promise.resolve(answer('should never be asked'));
	for (const [what, request] of [
		['a POST of the PIN', pageRequest(SITE + '/?fh_shop_app=unlock', 'POST')],
		['a POST to lock', pageRequest(SITE + '/?fh_shop_app=lock', 'POST')],
		['a POST to the shell\'s own address', pageRequest(SHELL, 'POST')],
		['a save (POST admin-ajax)', pageRequest(SITE + '/wp-admin/admin-ajax.php', 'POST')],
		['the state (who is unlocked, the tanks)', pageRequest(SITE + '/?fh_shop_app=state')],
		['a tank screen', pageRequest(SITE + '/?fh_front_desk=1&tank=521')],
		['the shop front', pageRequest(SITE + '/')],
		['My Account', pageRequest(SITE + '/my-account/')],
		['the REST API', pageRequest(SITE + '/wp-json/wc/v3/orders')],
		['the enrolment link', pageRequest(SITE + '/?fh_shop_app=enrol&k=0123456789abcdef0123456789abcdef')]
	]) {
		const out = await fire(w, 'fetch', request);
		check('not touched: ' + what, out.handled === false);
	}
	check('for none of them did the worker make a request or keep anything', w.fetched.length === 0 && w.puts.length === 0);

	console.log('\n3. The shell: fetched by the worker itself, without cookies');
	w = load(CONFIG);
	w.network = (request) => Promise.resolve(answer('shell from the network for ' + request.url));
	const page = pageRequest(SHELL);
	let out = await fire(w, 'fetch', page);
	check('a GET of the shell is answered by the worker', out.handled && out.response.body === 'shell from the network for ' + SHELL);
	check('it made ONE request of its own — not the page\'s request with its cookies', w.fetched.length === 1 && w.fetched[0] !== page && w.fetched[0].made === 'by the worker');
	check('… with credentials "omit": no cookie travels, so the answer cannot be anybody\'s', w.fetched[0].credentials === 'omit' && w.fetched[0].url === SHELL);
	check('the answer is kept under the shell\'s address', w.puts.length === 1 && w.puts[0] === SHELL && w.stores.get(CONFIG.cache).get(SHELL).body === 'shell from the network for ' + SHELL);

	out = await fire(w, 'fetch', pageRequest(SHELL + '&tank=521'));
	check('the shell with a tank number: the same clean request, kept under the same one address', out.handled && w.fetched[1].url === SHELL && w.fetched[1].credentials === 'omit' && w.puts[1] === SHELL && w.stores.get(CONFIG.cache).size === 1);

	console.log('\n4. Network first; the copy only when the network gives nothing');
	w.network = () => Promise.resolve(answer('a newer shell'));
	out = await fire(w, 'fetch', pageRequest(SHELL));
	check('with a copy in hand and the network up: the network\'s answer, and the copy is renewed', out.response.body === 'a newer shell' && w.stores.get(CONFIG.cache).get(SHELL).body === 'a newer shell');
	w.network = () => Promise.reject(new TypeError('offline'));
	out = await fire(w, 'fetch', pageRequest(SHELL));
	check('with the network down: the kept copy', out.handled && out.response.body === 'a newer shell');
	out = await fire(w, 'fetch', pageRequest(SHELL + '&tank=521'));
	check('… also for the shell with a tank number', out.response.body === 'a newer shell');

	w = load(CONFIG);
	const seen = [];
	w.network = (request) => {
		seen.push(request);
		return request.made === 'by the page' ? Promise.resolve(answer('the browser\'s own answer')) : Promise.reject(new TypeError('offline'));
	};
	const original = pageRequest(MANIFEST);
	out = await fire(w, 'fetch', original);
	check('no copy and its own request fails: the browser\'s own request is made, as if there were no worker', out.response.body === 'the browser\'s own answer' && seen[seen.length - 1] === original);
	check('… and that answer (made with the page\'s cookies) is NOT kept', w.puts.length === 0);

	console.log('\n5. An answer that may not be kept is not kept — whatever its address');
	const refusals = [
		['says no-store', { headers: { 'cache-control': 'no-store, private, max-age=0' } }],
		['says private', { headers: { 'cache-control': 'private, max-age=600' } }],
		['says No-Store in capitals', { headers: { 'cache-control': 'No-Store' } }],
		['varies by cookie', { headers: { 'cache-control': 'no-cache', vary: 'Accept-Encoding, Cookie' } }],
		['varies by everything', { headers: { 'cache-control': 'no-cache', vary: '*' } }],
		['was redirected (to a login page, say)', { redirected: true }],
		['is a 500', { status: 500 }],
		['is a 404', { status: 404 }],
		['is a 204', { status: 204 }],
		['is opaque (not this site\'s)', { type: 'opaque' }]
	];
	for (const [what, opts] of refusals) {
		w = load(CONFIG);
		w.network = (request) => (request.made === 'by the page' ? Promise.resolve(answer('own')) : Promise.resolve(answer('bad', opts)));
		out = await fire(w, 'fetch', pageRequest(SHELL));
		check('not kept, and not served: an answer that ' + what, w.puts.length === 0 && out.response.body === 'own', JSON.stringify(w.puts));
		check('  … storable() says no', w.api.storable(answer('x', opts)) === false);
	}
	check('a plain 200 that may be stored: storable() says yes', w.api.storable(answer('x')) === true && w.api.storable(answer('x', { headers: {} })) === true);
	check('nothing at all is not storable', w.api.storable(null) === false && w.api.storable(undefined) === false);

	console.log('\n6. Install and activate');
	w = load(CONFIG);
	w.network = (request) => {
		if (request.url === MANIFEST) {
			return Promise.resolve(answer('state, by mistake', { headers: { 'cache-control': 'no-store' } }));
		}
		if (request.url === ICON) {
			return Promise.reject(new TypeError('offline'));
		}
		return Promise.resolve(answer('the shell'));
	};
	await fire(w, 'install', null);
	check('install asks for each of its addresses itself, without cookies', w.fetched.length === 3 && w.fetched.every((r) => r.credentials === 'omit' && r.made === 'by the worker') && w.fetched.map((r) => r.url).join() === CONFIG.keep.join());
	check('… keeps what may be kept, skips what may not, and survives one that fails', w.puts.join() === SHELL && w.skipped === 1);

	w.stores.set('fh-shop-app-000000000000', new Map([[SHELL, answer('an old shell')]]));
	w.stores.set('some-other-cache', new Map([['x', answer('not ours')]]));
	await fire(w, 'activate', null);
	check('activate clears this app\'s older caches', !w.stores.has('fh-shop-app-000000000000'));
	check('… keeps the current one and leaves other caches alone', w.stores.has(CONFIG.cache) && w.stores.has('some-other-cache') && w.claimed === 1);

	console.log('\n7. Whatever is asked for, only the listed addresses are ever kept');
	w = load(CONFIG);
	w.network = () => Promise.resolve(answer('anything'));
	const tried = strangers.concat(CONFIG.keep, [SHELL + '&tank=7', SHELL + '&tank=99999']);
	for (const url of tried) {
		if (url) {
			await fire(w, 'fetch', pageRequest(url));
			await fire(w, 'fetch', pageRequest(url, 'POST'));
		}
	}
	check('after ' + (tried.length * 2) + ' requests of every kind the cache holds exactly the listed addresses', [...w.stores.get(CONFIG.cache).keys()].sort().join() === CONFIG.keep.slice().sort().join(), [...w.stores.get(CONFIG.cache).keys()].join());
	check('… and every request the worker made was without cookies', w.fetched.every((r) => r.credentials === 'omit'));

	console.log('\n8. The script itself');
	const source = fs.readFileSync(FILE, 'utf8');
	const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
	check('it never uses cache.add / addAll (they would fetch with the device\'s cookies)', !/\.add(All)?\(/.test(code));
	check('the page\'s request is never put into a cache', !/put\(\s*(event\.)?request/.test(code) && (code.match(/\.put\(/g) || []).length === 2);
	check('the one place it fetches for itself omits credentials', (code.match(/credentials:\s*'omit'/g) || []).length === 1 && (code.match(/new worker\.Request\(/g) || []).length === 1);
	check('it reads no cookie, no storage and posts no message', !/cookie|localStorage|indexedDB|postMessage|importScripts/i.test(code.replace(/vary\.indexOf\('cookie'\)/, '')));
	check('without its config it keeps nothing', load(undefined).api.keyFor(SHELL) === '');

	console.log('\n' + (failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed'));
	process.exit(failures ? 1 : 0);
})().catch((e) => {
	console.error(e);
	process.exit(1);
});
