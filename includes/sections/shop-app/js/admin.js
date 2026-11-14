/**
 * Shop App admin — the one-time enrolment link (made by AJAX, shown once
 * with its QR code and a countdown, never reloaded from anywhere) and the
 * "are you sure?" of Revoke and Switch off.
 *
 * Text is set with textContent only; the QR code arrives as a data: image.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

/* global module */
(function (root) {
	'use strict';

	/**
	 * Seconds as m:ss.
	 *
	 * @param {number} seconds Seconds.
	 * @return {string}
	 */
	function clock(seconds) {
		var s = Math.max(0, Math.ceil(Number(seconds) || 0));
		var r = s % 60;
		return Math.floor(s / 60) + ':' + (r < 10 ? '0' : '') + r;
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { clock: clock };
	}

	if (typeof document === 'undefined' || !root.fhShopAppAdmin) {
		return;
	}

	var cfg = root.fhShopAppAdmin;
	var i18n = cfg.i18n || {};

	function t(key, fallback) {
		return i18n[key] || fallback;
	}

	/* Revoke / Switch off: ask first. */
	Array.prototype.forEach.call(document.querySelectorAll('form.fh-sa-confirm'), function (form) {
		form.addEventListener('submit', function (e) {
			var key = form.getAttribute('data-confirm') || 'confirm';
			if (!root.confirm(t(key, 'Are you sure?'))) {
				e.preventDefault();
			}
		});
	});

	var form = document.getElementById('fh-sa-link-form');
	var panel = document.getElementById('fh-sa-link');
	if (!form || !panel) {
		return;
	}

	var status = panel.querySelector('.fh-sa-link__status');
	var qr = panel.querySelector('.fh-sa-link__qr');
	var note = panel.querySelector('.fh-sa-link__note');
	var urlBox = panel.querySelector('.fh-sa-link__url');
	var copy = panel.querySelector('.fh-sa-link__copy');
	var timer = null;

	function say(text, bad) {
		status.textContent = text;
		status.className = 'fh-sa-link__status' + (bad ? ' is-bad' : '');
	}

	function show(data) {
		var until = Date.now() + (Number(data.seconds) || 600) * 1000;

		urlBox.value = data.url || '';
		urlBox.hidden = false;
		copy.hidden = false;
		if (data.qr) {
			qr.src = data.qr;
			qr.hidden = false;
		} else {
			qr.removeAttribute('src');
			qr.hidden = true;
		}
		note.textContent = data.qrNote || '';
		note.hidden = !data.qrNote;

		root.clearInterval(timer);
		function tick() {
			var left = (until - Date.now()) / 1000;
			if (left <= 0) {
				root.clearInterval(timer);
				// The link is dead: take it off the screen.
				urlBox.value = '';
				urlBox.hidden = true;
				copy.hidden = true;
				qr.removeAttribute('src');
				qr.hidden = true;
				say(t('expired', 'This link has run out. Make a new one.'), true);
				return;
			}
			say((data.name ? data.name + ' — ' : '') + t('goodFor', 'Good for %s more, and for one use.').replace('%s', clock(left)));
		}
		timer = root.setInterval(tick, 1000);
		tick();
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var name = form.querySelector('input[name="name"]');
		var button = form.querySelector('button[type="submit"]');
		var body = new URLSearchParams();
		body.append('action', 'fishotel_shop_app_link');
		body.append('nonce', cfg.nonce);
		body.append('name', name ? name.value : '');

		panel.hidden = false;
		say(t('making', 'Making the link…'));
		button.disabled = true;

		root.fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json();
		}).then(function (json) {
			button.disabled = false;
			if (!json || !json.success) {
				say(json && json.data && json.data.message ? json.data.message : t('failed', 'The link could not be made.'), true);
				return;
			}
			show(json.data);
		}).catch(function () {
			button.disabled = false;
			say(t('failed', 'The link could not be made.'), true);
		});
	});

	copy.addEventListener('click', function () {
		urlBox.select();
		var done = function () {
			copy.textContent = t('copied', 'Copied');
			root.setTimeout(function () { copy.textContent = t('copy', 'Copy the link'); }, 1500);
		};
		if (root.navigator.clipboard && root.navigator.clipboard.writeText) {
			root.navigator.clipboard.writeText(urlBox.value).then(done).catch(function () { /* select() left it ready to copy by hand */ });
		} else {
			try {
				document.execCommand('copy');
				done();
			} catch (err) { /* select() left it ready to copy by hand */ }
		}
	});
})(typeof window !== 'undefined' ? window : globalThis);
