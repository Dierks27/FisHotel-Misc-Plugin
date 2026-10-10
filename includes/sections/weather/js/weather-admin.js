/**
 * Weather admin — settings save, "Check weather now", copy-email, run-scan,
 * and the overview totals.
 *
 * The server renders every piece of HTML (meta box body, badge); this file
 * only swaps it in and updates text cells. The pure helpers (tally,
 * escapeHtml, badgeClass) are exported for tests/admin.test.js.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

/* global jQuery, module */
(function (root, $) {
	'use strict';

	/**
	 * Count overview rows per total tile from their action keys.
	 *
	 * @param {string[]} actions One action per row ('' = not checked).
	 * @return {Object} Counts keyed like Admin::overview_totals().
	 */
	function tally(actions) {
		var totals = {
			orders: 0,
			heat_pack: 0,
			none: 0,
			cooling: 0,
			ups_store: 0,
			hold: 0,
			unavailable: 0,
			unchecked: 0
		};

		(actions || []).forEach(function (action) {
			action = String(action || '');
			totals.orders++;

			if (action === 'ups_store_cold' || action === 'ups_store_hot') {
				totals.ups_store++;
			} else if (action === 'hold_cold' || action === 'hold_hot') {
				totals.hold++;
			} else if (action === '') {
				totals.unchecked++;
			} else if (Object.prototype.hasOwnProperty.call(totals, action)) {
				totals[action]++;
			}
		});

		return totals;
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

	/**
	 * The status-text class for a note status.
	 *
	 * @param {string} status noted | unavailable | stale | none.
	 * @return {string}
	 */
	function noteStatusClass(status) {
		var known = ['noted', 'unavailable', 'stale', 'none'];
		status = String(status || 'none');
		return 'fh-weather-note-status fh-weather-note-status--' + (known.indexOf(status) === -1 ? 'none' : status);
	}

	var api = {
		tally: tally,
		escapeHtml: escapeHtml,
		noteStatusClass: noteStatusClass
	};

	root.fhWeatherAdmin = root.fhWeatherAdmin || {};
	root.fhWeatherAdmin.helpers = api;

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}

	if (!$) {
		return;
	}

	/* ---------------------------------------------------------------- */
	/*  DOM wiring                                                       */
	/* ---------------------------------------------------------------- */

	var cfg  = root.fhWeatherAdmin || {};
	var i18n = cfg.i18n || {};

	function fmt(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(template || '').replace(/%(\d+\$)?[ds]/g, function (match, pos) {
			var index = pos ? parseInt(pos, 10) - 1 : i++;
			return typeof args[index] === 'undefined' ? '' : args[index];
		});
	}

	function notice(cls, msg) {
		var $wrap = $('.fh-weather-wrap');
		if (!$wrap.length) {
			return;
		}
		$wrap.find('.fishotel-misc-notice--ajax').remove();
		$('<div class="fishotel-misc-notice fishotel-misc-notice--ajax ' + cls + '"></div>')
			.text(msg)
			.insertAfter($wrap.find('.fh-weather-tabs'))
			.delay(4000)
			.fadeOut(300, function () { $(this).remove(); });
	}

	function setStatus($el, msg, state) {
		$el.removeClass('is-ok is-error').text(msg || '');
		if (state) {
			$el.addClass(state);
		}
	}

	function refreshTotals() {
		var actions = [];
		$('#fh-weather-table tbody tr[data-order-id]').each(function () {
			actions.push($(this).attr('data-action') || '');
		});
		var totals = tally(actions);
		$('#fh-weather-totals [data-total]').each(function () {
			var key = $(this).attr('data-total');
			if (Object.prototype.hasOwnProperty.call(totals, key)) {
				$(this).text(totals[key]);
			}
		});
	}

	/**
	 * Apply a check result to the overview row (text cells + server-rendered badge).
	 */
	function applyRow(row) {
		if (!row || !row.order_id) {
			return;
		}
		var $tr = $('#fh-weather-table tbody tr[data-order-id="' + parseInt(row.order_id, 10) + '"]');
		if (!$tr.length) {
			return;
		}

		$tr.attr('data-action', row.action || '');
		$tr.find('[data-cell="high"]').text(row.high === null || typeof row.high === 'undefined' ? '—' : row.high + '°F');
		$tr.find('[data-cell="low"]').text(row.low === null || typeof row.low === 'undefined' ? '—' : row.low + '°F');
		$tr.find('[data-cell="action"]').attr('title', row.summary || '').html(row.badge_html || '');
		$tr.find('[data-cell="note_status"]')
			.attr('class', noteStatusClass(row.note_status))
			.text(row.note_status_label || '');

		refreshTotals();
	}

	function checkOrder($btn) {
		var orderId = parseInt($btn.attr('data-order-id'), 10);
		var $status = $btn.closest('.fh-weather-box, td, p').find('.fh-weather-status').first();

		if (!orderId) {
			return;
		}

		$btn.prop('disabled', true);
		setStatus($status, i18n.checking || 'Checking…');

		$.post(cfg.ajaxUrl || root.ajaxurl, {
			action: 'fishotel_weather_check',
			nonce: cfg.nonce,
			order_id: orderId
		})
			.done(function (res) {
				var ok   = res && res.success;
				var data = (res && res.data) || {};
				var msg  = data.message || (ok ? (i18n.checked || 'Checked.') : (i18n.error || 'Something went wrong.'));

				if (data.box_html) {
					var $box = $('.fh-weather-box[data-order-id="' + orderId + '"]');
					if ($box.length) {
						$box.replaceWith(data.box_html);
						$status = $('.fh-weather-box[data-order-id="' + orderId + '"] .fh-weather-status');
					}
				}

				if (ok && data.row) {
					applyRow(data.row);
				}

				setStatus($status, msg, ok && data.ok ? 'is-ok' : 'is-error');
			})
			.fail(function () {
				setStatus($status, i18n.error || 'Something went wrong.', 'is-error');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	}

	function copyText(text, $status) {
		function fallback() {
			var $ta = $('<textarea readonly></textarea>').css({ position: 'fixed', top: 0, left: 0, opacity: 0 }).val(text).appendTo('body');
			var done = false;
			try {
				$ta[0].select();
				done = document.execCommand && document.execCommand('copy');
			} catch (e) {
				done = false;
			}
			$ta.remove();
			setStatus($status, done ? (i18n.copied || 'Copied.') : (i18n.copyFail || 'Could not copy.'), done ? 'is-ok' : 'is-error');
		}

		if (root.navigator && root.navigator.clipboard && root.navigator.clipboard.writeText) {
			root.navigator.clipboard.writeText(text).then(function () {
				setStatus($status, i18n.copied || 'Copied.', 'is-ok');
			}, fallback);
			return;
		}

		fallback();
	}

	$(function () {
		// "Check weather now" — meta box button and overview row buttons.
		$(document).on('click', '.fh-weather-check', function (e) {
			e.preventDefault();
			checkOrder($(this));
		});

		// Copy the email draft (subject + blank line + body).
		$(document).on('click', '.fh-weather-copy', function (e) {
			e.preventDefault();
			var $btn    = $(this);
			var subject = $($btn.attr('data-subject')).val() || '';
			var body    = $($btn.attr('data-body')).val() || '';
			var $status = $btn.closest('.fh-weather-box').find('.fh-weather-status').first();
			copyText(subject + '\n\n' + body, $status);
		});

		// Run the nightly scan on demand (overview).
		$(document).on('click', '.fh-weather-run-scan', function (e) {
			e.preventDefault();
			var $btn    = $(this);
			var $status = $btn.closest('.fh-weather-run').find('.fh-weather-status').first();

			$btn.prop('disabled', true);
			setStatus($status, i18n.scanning || 'Running…');

			$.post(cfg.ajaxUrl || root.ajaxurl, {
				action: 'fishotel_weather_run_scan',
				nonce: cfg.nonce
			})
				.done(function (res) {
					var ok = res && res.success;
					var s  = (ok && res.data && res.data.summary) || {};
					setStatus($status, ok ? fmt(i18n.scanDone || 'Done: %1$d checked, %2$d unavailable, %3$d skipped.', s.checked || 0, s.unavailable || 0, s.skipped || 0) : (i18n.error || 'Something went wrong.'), ok ? 'is-ok' : 'is-error');
					if (ok) {
						root.setTimeout(function () { root.location.reload(); }, 1200);
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
		var $form = $('#fishotel-weather-form');
		if (!$form.length) {
			return;
		}

		$form.on('click', '.fh-weather-reset', function (e) {
			e.preventDefault();
			$form.find('input[name^="thresholds["]').each(function () {
				var fallback = $(this).attr('data-default');
				var key      = (this.name.match(/\[([a-z_]+)\]/) || [])[1];
				var value    = cfg.defaults && key && typeof cfg.defaults[key] !== 'undefined' ? cfg.defaults[key] : fallback;
				$(this).val(value);
			});
		});

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
						$form.find('[name="run_time"]').val(s.run_time || '');
						$form.find('[name="contact_email"]').val(s.contact_email || '');
						$form.find('[name="enabled"]').prop('checked', !!s.enabled);
						$form.find('[name="note_sources"]').prop('checked', !!s.note_sources);
						$form.find('input[name="statuses[]"]').each(function () {
							this.checked = $.inArray(this.value, s.statuses || []) !== -1;
						});
						$form.find('input[name^="thresholds["]').each(function () {
							var key = (this.name.match(/\[([a-z_]+)\]/) || [])[1];
							if (s.thresholds && key && typeof s.thresholds[key] !== 'undefined') {
								$(this).val(s.thresholds[key]);
							}
						});
						$form.find('.fh-weather-next-run').text(data.next_run ? fmt(i18n.nextRun || 'Next run: %s', data.next_run) : '');
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
