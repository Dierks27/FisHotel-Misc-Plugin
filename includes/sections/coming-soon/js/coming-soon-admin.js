/**
 * Coming Soon admin — metabox interactions on the product edit screen.
 *
 *   - "Release Now" button: confirm → set datetime input to right-now in
 *     the user's local clock (which the server then re-interprets as
 *     site timezone — same as any manually entered datetime).
 *   - "Clear" button: confirm → blank out the datetime input.
 *   - Live "Releases in Xd Xh Xm Xs" countdown inside the status box.
 *
 * @package FisHotel\Misc\Sections\Coming_Soon
 */

/* global jQuery */
(function ($) {
	'use strict';

	var data = window.fhComingSoonAdmin || {};
	var i18n = data.i18n || {};
	var serverTime = parseInt(data.serverTime, 10) || (Date.now() / 1000);
	var offset = (Date.now() / 1000) - serverTime;

	function authoritativeNow() {
		return (Date.now() / 1000) - offset;
	}

	function pad2(n) {
		return n < 10 ? '0' + n : '' + n;
	}

	/**
	 * Format a datetime for the datetime-local input: YYYY-MM-DDTHH:MM:SS
	 * in the user's local time. The PHP save handler reinterprets this
	 * as the site timezone, matching how a manually typed value is
	 * handled — there's no separate "now in site TZ" path needed.
	 */
	function nowAsLocalDatetimeString() {
		var d = new Date();
		return d.getFullYear() + '-' +
			pad2(d.getMonth() + 1) + '-' +
			pad2(d.getDate()) + 'T' +
			pad2(d.getHours()) + ':' +
			pad2(d.getMinutes()) + ':' +
			pad2(d.getSeconds());
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
		parts.push(m + (i18n.minutes || 'm'));
		parts.push(s + (i18n.seconds || 's'));

		return parts.join(' ');
	}

	$(function () {
		var $input  = $('#fh-cs-datetime');
		var $status = $('.fh-cs-mb__status');

		/* Release Now */
		$(document).on('click', '.fh-cs-mb__btn-release-now', function (e) {
			e.preventDefault();
			if (!window.confirm(i18n.confirmReleaseNow || 'Set release datetime to now?')) {
				return;
			}
			$input.val(nowAsLocalDatetimeString());
		});

		/* Clear */
		$(document).on('click', '.fh-cs-mb__btn-clear', function (e) {
			e.preventDefault();
			if (!window.confirm(i18n.confirmClear || 'Clear the datetime?')) {
				return;
			}
			$input.val('');
		});

		/* Live countdown for the status box (future status only) */
		var $countdown = $status.find('.fh-cs-mb__status-countdown');
		var releaseTs  = parseInt($status.attr('data-release-ts'), 10) || 0;
		var status     = $status.attr('data-status') || '';

		if ('future' === status && releaseTs > 0 && $countdown.length) {
			function tick() {
				var remaining = releaseTs - authoritativeNow();
				if (remaining <= 0) {
					$countdown.text(formatRemaining(0));
					return false;
				}
				$countdown.text(formatRemaining(remaining));
				return true;
			}

			tick();
			var iv = setInterval(function () {
				if (!tick()) {
					clearInterval(iv);
				}
			}, 1000);
		}
	});
})(jQuery);
