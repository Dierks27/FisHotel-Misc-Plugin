/**
 * Today admin — the Refresh button (drops the cache, reloads), the ticking
 * "Cached N min ago" label and the settings save.
 *
 * The server renders every piece of HTML; this file only posts and updates
 * text. The pure helpers (ageLabel, fmt, escapeHtml) are exported for
 * tests/admin.test.js.
 *
 * @package FisHotel\Misc\Sections\Today
 */

/* global jQuery, module */
(function (root, $) {
	'use strict';

	/**
	 * printf-lite: %s / %d, positional (%1$s) or sequential.
	 *
	 * @param {string} template Template.
	 * @return {string}
	 */
	function fmt(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(template || '').replace(/%(\d+\$)?[ds]/g, function (match, pos) {
			var index = pos ? parseInt(pos, 10) - 1 : i++;
			return typeof args[index] === 'undefined' ? '' : args[index];
		});
	}

	/**
	 * "Cached just now" / "Cached 3 min ago" / "Cached 2 h ago" from an age
	 * in seconds. A freshly built page ("cached" false) says "Built …".
	 *
	 * @param {number}  seconds Age in seconds (negative = 0).
	 * @param {boolean} cached  Whether the data came from the cache.
	 * @param {Object}  i18n    Optional { justNow, minAgo, hoursAgo, built, cached }.
	 * @return {string}
	 */
	function ageLabel(seconds, cached, i18n) {
		i18n = i18n || {};
		var prefix = cached ? (i18n.cached || 'Cached') : (i18n.built || 'Built');
		var age    = Math.max(0, parseInt(seconds, 10) || 0);

		if (age < 60) {
			return prefix + ' ' + (i18n.justNow || 'just now');
		}
		if (age < 3600) {
			return prefix + ' ' + fmt(i18n.minAgo || '%d min ago', Math.floor(age / 60));
		}
		return prefix + ' ' + fmt(i18n.hoursAgo || '%d h ago', Math.floor(age / 3600));
	}

	/**
	 * Escape text for insertion into HTML.
	 *
	 * @param {*} text Text.
	 * @return {string}
	 */
	function escapeHtml(text) {
		return String(text === null || typeof text === 'undefined' ? '' : text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	var api = {
		fmt: fmt,
		ageLabel: ageLabel,
		escapeHtml: escapeHtml
	};

	root.fhTodayAdmin = root.fhTodayAdmin || {};
	root.fhTodayAdmin.helpers = api;

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}

	if (!$) {
		return;
	}

	/* ---------------------------------------------------------------- */
	/*  DOM wiring                                                       */
	/* ---------------------------------------------------------------- */

	var cfg  = root.fhTodayAdmin || {};
	var i18n = cfg.i18n || {};

	function notice(cls, msg) {
		var $wrap = $('.fh-today-wrap');
		if (!$wrap.length) {
			return;
		}
		$wrap.find('.fishotel-misc-notice--ajax').remove();
		$('<div class="fishotel-misc-notice fishotel-misc-notice--ajax ' + cls + '"></div>')
			.text(msg)
			.insertAfter($wrap.find('.fh-today-tabs'))
			.delay(4000)
			.fadeOut(300, function () { $(this).remove(); });
	}

	function setStatus($el, msg, state) {
		$el.removeClass('is-ok is-error').text(msg || '');
		if (state) {
			$el.addClass(state);
		}
	}

	function tickFreshness() {
		var $fresh = $('.fh-today-fresh');
		if (!$fresh.length) {
			return;
		}
		var at     = parseInt($fresh.attr('data-generated-at'), 10) || 0;
		var cached = $fresh.attr('data-cached') === '1';
		if (!at || !cached) {
			return; // "Built just now" stays as the server wrote it.
		}
		var age = Math.floor(Date.now() / 1000) - at;
		$fresh.text(ageLabel(age, true, i18n));
	}

	$(function () {
		tickFreshness();
		root.setInterval(tickFreshness, 30000);

		// Refresh: drop the cache, then reload so the page is rebuilt.
		$(document).on('click', '.fh-today-refresh', function (e) {
			e.preventDefault();
			var $btn    = $(this);
			var $status = $btn.closest('.fh-today-hero__actions').find('.fh-today-status').first();

			$btn.prop('disabled', true);
			setStatus($status, i18n.refreshing || 'Refreshing…');

			$.post(cfg.ajaxUrl || root.ajaxurl, {
				action: 'fishotel_today_refresh',
				nonce: cfg.nonce
			})
				.done(function (res) {
					var ok = res && res.success;
					setStatus($status, ok ? (i18n.refreshed || 'Refreshed. Reloading…') : (i18n.error || 'Something went wrong.'), ok ? 'is-ok' : 'is-error');
					if (ok) {
						root.setTimeout(function () { root.location.reload(); }, 400);
					}
				})
				.fail(function () {
					setStatus($status, i18n.error || 'Something went wrong.', 'is-error');
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
		});

		// Settings form.
		var $form = $('#fishotel-today-form');
		if (!$form.length) {
			return;
		}

		$form.on('submit', function (e) {
			e.preventDefault();

			var $btn = $form.find('button[type="submit"]');
			$btn.prop('disabled', true);

			$.post(cfg.ajaxUrl || root.ajaxurl, $form.serialize())
				.done(function (res) {
					var ok      = res && res.success;
					var data    = (res && res.data) || {};
					var msg     = data.message || (ok ? (i18n.saved || 'Settings saved.') : (i18n.error || 'Something went wrong.'));
					var dropped = ok && data.dropped && data.dropped.length;

					notice(ok ? (dropped ? 'fishotel-misc-notice--warning' : 'fishotel-misc-notice--success') : 'fishotel-misc-notice--error', msg);

					if (ok && data.settings) {
						var s = data.settings;
						$form.find('[name="lookahead_days"]').val(s.lookahead_days);
						$form.find('[name="dates_shown"]').val(s.dates_shown);
						$form.find('[name="cache_minutes"]').val(s.cache_minutes);
						$form.find('[name="show_weather"]').prop('checked', !!s.show_weather);
						$form.find('[name="show_invoice"]').prop('checked', !!s.show_invoice);
						$form.find('[name="show_tracking"]').prop('checked', !!s.show_tracking);
						$form.find('input[name="statuses[]"]').each(function () {
							this.checked = $.inArray(this.value, s.statuses || []) !== -1;
						});
					}
				})
				.fail(function () {
					notice('fishotel-misc-notice--error', i18n.error || 'Something went wrong.');
				})
				.always(function () {
					$btn.prop('disabled', false);
				});

			return false;
		});
	});
})(typeof window !== 'undefined' ? window : globalThis, typeof jQuery !== 'undefined' ? jQuery : null);
