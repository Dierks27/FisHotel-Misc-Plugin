/**
 * FisHotel Shop — the service worker. Served at /?fh_shop_app=sw with
 * FH_SW = { cache, shell, keep } put in front of it by the server, and
 * registered for the app's own address only (scope "/?fh_shop_app="), so
 * the shop front and the tank screens never pass through it.
 *
 * It does two things: it is what "Install app" asks for, and it keeps a
 * copy of the STATIC SHELL — the app's one page, the manifest and the
 * icons, which are the same bytes for every visitor — so the app still
 * opens (and says "No connection") when the network is down.
 *
 * It must never keep anything that came from an authenticated request,
 * and it cannot:
 *   1. only a GET is ever looked at — a PIN, a lock and a save are POSTs;
 *   2. only the exact addresses in FH_SW.keep are handled; the state
 *      (who is unlocked, the tanks), the tank screens and everything else
 *      go straight to the network, untouched and unseen;
 *   3. what it keeps it fetches ITSELF, without the device's cookies
 *      (credentials: "omit") — the page's own request is never stored;
 *   4. and even then an answer is stored only when it is a plain 200 that
 *      does not say no-store or private and does not vary by cookie.
 *
 * Network first: the copy is only used when the network gives no answer.
 * If anything about the copy goes wrong the browser's own request is
 * made, so the worker can never make the app worse than having none.
 *
 * keyFor() and storable() are exported for tests/sw.test.js.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

/* global FH_SW, module */
(function (worker) {
	'use strict';

	var CONFIG = typeof FH_SW !== 'undefined' && FH_SW ? FH_SW : { cache: 'fh-shop-app-none', shell: '', keep: [] };
	var PREFIX = 'fh-shop-app-';

	/**
	 * The kept address a request is for, or '' when it is none of ours.
	 *
	 * @param {string} url The request's address.
	 * @return {string}
	 */
	function keyFor(url) {
		var keep = CONFIG.keep || [];
		if (keep.indexOf(url) !== -1) {
			return url;
		}
		// The shell with a tank number after it (a label scanned before the
		// PIN) is still the shell: the page reads the number itself.
		var match = /^(.*)&tank=\d{1,9}$/.exec(String(url));
		if (match && CONFIG.shell && match[1] === CONFIG.shell) {
			return CONFIG.shell;
		}
		return '';
	}

	/**
	 * Whether an answer may be kept.
	 *
	 * @param {Response} response The answer.
	 * @return {boolean}
	 */
	function storable(response) {
		if (!response || !response.ok || response.status !== 200 || response.type !== 'basic' || response.redirected) {
			return false;
		}
		var control = String(response.headers.get('Cache-Control') || '').toLowerCase();
		if (control.indexOf('no-store') !== -1 || control.indexOf('private') !== -1) {
			return false;
		}
		var vary = String(response.headers.get('Vary') || '').toLowerCase();
		if (vary.indexOf('cookie') !== -1 || vary.indexOf('*') !== -1) {
			return false;
		}
		return true;
	}

	/* The worker's own request for a kept address: never with the device's cookies. */
	function fetchClean(url) {
		return worker.fetch(new worker.Request(url, { credentials: 'omit', cache: 'no-cache' }));
	}

	function open() {
		return worker.caches.open(CONFIG.cache);
	}

	if (worker.addEventListener) {
		worker.addEventListener('install', function (event) {
			event.waitUntil(
				open().then(function (cache) {
					return Promise.all((CONFIG.keep || []).map(function (url) {
						return fetchClean(url).then(function (response) {
							return storable(response) ? cache.put(url, response) : null;
						}).catch(function () { return null; });
					}));
				}).then(function () {
					return worker.skipWaiting ? worker.skipWaiting() : null;
				})
			);
		});

		worker.addEventListener('activate', function (event) {
			event.waitUntil(
				worker.caches.keys().then(function (names) {
					return Promise.all(names.filter(function (name) {
						return name.indexOf(PREFIX) === 0 && name !== CONFIG.cache;
					}).map(function (name) {
						return worker.caches.delete(name);
					}));
				}).then(function () {
					return worker.clients && worker.clients.claim ? worker.clients.claim() : null;
				})
			);
		});

		worker.addEventListener('fetch', function (event) {
			var request = event.request;
			if (!request || request.method !== 'GET') {
				return;
			}
			var key = keyFor(request.url);
			if (!key) {
				return;
			}

			event.respondWith(
				fetchClean(key).then(function (response) {
					if (!storable(response)) {
						throw new Error('not the static shell');
					}
					var copy = response.clone();
					event.waitUntil(open().then(function (cache) { return cache.put(key, copy); }).catch(function () { return null; }));
					return response;
				}).catch(function () {
					return open().then(function (cache) {
						return cache.match(key);
					}).then(function (hit) {
						// No copy either: the browser's own request, as if there were no worker.
						return hit || worker.fetch(request);
					});
				})
			);
		});
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { keyFor: keyFor, storable: storable };
	}
})(typeof self !== 'undefined' ? self : globalThis);
