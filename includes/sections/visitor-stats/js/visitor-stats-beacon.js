(function () {
	'use strict';

	var data = window.fishotelStatsBeacon;
	if (!data || !data.restUrl) {
		return;
	}

	// Visitor ID — 32-char hex stored in localStorage. Stable per browser.
	var STORAGE_KEY = 'fh_stats_vid';
	var vid;

	try {
		vid = localStorage.getItem(STORAGE_KEY);
		if (!vid || !/^[a-f0-9]{32}$/.test(vid)) {
			// crypto.getRandomValues -> 16 bytes -> 32 hex chars.
			var bytes = new Uint8Array(16);
			(window.crypto || window.msCrypto).getRandomValues(bytes);
			vid = Array.prototype.map.call(bytes, function (b) {
				return ('0' + b.toString(16)).slice(-2);
			}).join('');
			localStorage.setItem(STORAGE_KEY, vid);
		}
	} catch (e) {
		// Private mode / storage disabled — generate an ephemeral ID.
		// Online-now still works; unique counts may double-count this user.
		vid = String(Date.now()) + Math.random().toString(16).slice(2, 18).padEnd(16, '0');
	}

	var payload = JSON.stringify({
		url: data.url,
		title: data.title,
		referrer: document.referrer || '',
		visitor_id: vid,
		post_id: data.postId || 0
	});

	// Prefer sendBeacon — survives page navigation, fire-and-forget.
	if (navigator.sendBeacon) {
		try {
			var blob = new Blob([payload], { type: 'application/json' });
			if (navigator.sendBeacon(data.restUrl, blob)) {
				return;
			}
		} catch (e) {}
	}

	// Fallback: fetch with keepalive.
	try {
		fetch(data.restUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: payload,
			keepalive: true,
			credentials: 'omit'
		}).catch(function () {});
	} catch (e) {}
})();
