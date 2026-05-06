/**
 * Coming Soon frontend — countdown ticker driven by a server-time
 * offset so the customer's local clock cannot affect the displayed
 * remaining time.
 *
 * @package FisHotel\Misc\Sections\Coming_Soon
 */

(function () {
	'use strict';

	var data = window.fhComingSoon || {};
	var serverTime    = parseInt(data.serverTime, 10) || (Date.now() / 1000);
	var autoReload    = !!data.autoReload;
	var jitterSeconds = parseInt(data.jitterSeconds, 10) || 0;
	if (jitterSeconds < 0) { jitterSeconds = 0; }
	var i18n = data.i18n || {};

	// Compute clock offset once on load. Authoritative "now" for the
	// rest of the page lifecycle is then `(Date.now()/1000) - offset`.
	var offset = (Date.now() / 1000) - serverTime;

	// Single shared reload-scheduling guard so multiple countdowns
	// reaching zero on the same page only trigger one reload.
	var reloadScheduled = false;

	function authoritativeNow() {
		return (Date.now() / 1000) - offset;
	}

	function scheduleReload() {
		if (reloadScheduled) {
			return;
		}
		reloadScheduled = true;

		var jitterMs = Math.random() * jitterSeconds * 1000;
		setTimeout(function () {
			window.location.reload();
		}, jitterMs);
	}

	function formatRemaining(seconds) {
		seconds = Math.max(0, Math.floor(seconds));

		var d = Math.floor(seconds / 86400);
		var h = Math.floor((seconds % 86400) / 3600);
		var m = Math.floor((seconds % 3600) / 60);
		var s = seconds % 60;

		var parts = [];
		if (d > 0) {
			parts.push(d + (i18n.days || 'd'));
		}
		if (d > 0 || h > 0) {
			parts.push(h + (i18n.hours || 'h'));
		}
		// Always show minutes and seconds.
		parts.push(m + (i18n.minutes || 'm'));
		parts.push(s + (i18n.seconds || 's'));

		return parts.join(' ');
	}

	function tickCountdown(el) {
		var releaseTs = parseInt(el.getAttribute('data-release-ts'), 10);
		if (!releaseTs) {
			return true; // done, drop from list
		}

		var digits = el.querySelector('.fh-cs-countdown__digits');
		var remaining = releaseTs - authoritativeNow();

		if (remaining <= 0) {
			el.classList.add('fh-cs-countdown--ended');
			if (autoReload) {
				if (digits) { digits.textContent = formatRemaining(0); }
				scheduleReload();
			} else {
				if (digits) {
					digits.innerHTML = i18n.available || 'Now available — refresh page';
				}
			}
			return true; // done
		}

		if (digits) {
			digits.textContent = formatRemaining(remaining);
		}

		return false;
	}

	function init() {
		var elements = Array.prototype.slice.call(
			document.querySelectorAll('.fh-cs-countdown[data-release-ts]')
		);

		if (!elements.length) {
			return;
		}

		// Initial paint so users see digits immediately.
		elements = elements.filter(function (el) {
			return !tickCountdown(el);
		});

		if (!elements.length) {
			return;
		}

		// Tick every second.
		var interval = setInterval(function () {
			elements = elements.filter(function (el) {
				return !tickCountdown(el);
			});
			if (!elements.length) {
				clearInterval(interval);
			}
		}, 1000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
