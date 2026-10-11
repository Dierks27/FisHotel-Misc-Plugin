/**
 * Health admin — settings save, "Clear log", context toggles and the
 * "narrow this page" box on the Log tab.
 *
 * The server renders every row; this file only shows/hides them. The pure
 * helpers (filterRows, escapeHtml, levelClass) are exported for
 * tests/admin.test.js.
 *
 * @package FisHotel\Misc\Sections\Health
 */

/* global jQuery, module */
(function (root, $) {
	'use strict';

	/**
	 * Keep the rows that match every given filter.
	 *
	 * @param {Object[]} rows    [{ level, source, text }] — text is the
	 *                           lower-cased message + context the server
	 *                           put in data-text.
	 * @param {Object}   filters { level, source, search } — each optional.
	 * @return {Object[]}
	 */
	function filterRows(rows, filters) {
		filters = filters || {};
		var level  = String(filters.level || '').toLowerCase();
		var source = String(filters.source || '').toLowerCase();
		var terms  = String(filters.search || '').toLowerCase().split(/\s+/).filter(Boolean);

		return (rows || []).filter(function (row) {
			if (!row) {
				return false;
			}
			if (level && String(row.level || '').toLowerCase() !== level) {
				return false;
			}
			if (source && String(row.source || '').toLowerCase() !== source) {
				return false;
			}
			if (terms.length) {
				var haystack = String(row.text || '').toLowerCase() + ' ' + String(row.source || '').toLowerCase();
				for (var i = 0; i < terms.length; i++) {
					if (haystack.indexOf(terms[i]) === -1) {
						return false;
					}
				}
			}
			return true;
		});
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
	 * The badge class for a level (unknown → info).
	 *
	 * @param {string} level Level.
	 * @return {string}
	 */
	function levelClass(level) {
		var known = ['debug', 'info', 'warning', 'error', 'critical'];
		level = String(level || 'info').toLowerCase();
		return 'fh-health-level fh-health-level--' + (known.indexOf(level) === -1 ? 'info' : level);
	}

	var api = {
		filterRows: filterRows,
		escapeHtml: escapeHtml,
		levelClass: levelClass
	};

	root.fhHealthAdmin = root.fhHealthAdmin || {};
	root.fhHealthAdmin.helpers = api;

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}

	if (!$) {
		return;
	}

	/* ---------------------------------------------------------------- */
	/*  DOM wiring                                                       */
	/* ---------------------------------------------------------------- */

	var cfg  = root.fhHealthAdmin || {};
	var i18n = cfg.i18n || {};

	function notice(cls, msg) {
		var $wrap = $('.fh-health-wrap');
		if (!$wrap.length) {
			return;
		}
		$wrap.find('.fishotel-misc-notice--ajax').remove();
		$('<div class="fishotel-misc-notice fishotel-misc-notice--ajax ' + cls + '"></div>')
			.text(msg)
			.insertAfter($wrap.find('.fh-health-tabs'))
			.delay(4000)
			.fadeOut(300, function () { $(this).remove(); });
	}

	function setStatus($el, msg, state) {
		$el.removeClass('is-ok is-error').text(msg || '');
		if (state) {
			$el.addClass(state);
		}
	}

	/**
	 * Apply the quick box to the rows on this page.
	 */
	function narrowPage(search) {
		var $rows = $('#fh-health-table tbody tr.fh-health-row');
		var rows  = [];
		$rows.each(function () {
			rows.push({
				id: $(this).attr('data-id'),
				level: $(this).attr('data-level'),
				source: $(this).attr('data-source'),
				text: $(this).attr('data-text')
			});
		});

		var keep = {};
		filterRows(rows, { search: search }).forEach(function (row) {
			keep[row.id] = true;
		});

		$rows.each(function () {
			$(this).toggleClass('is-hidden', !keep[$(this).attr('data-id')]);
		});
	}

	$(function () {
		// Context toggles (Log tab).
		$(document).on('click', '.fh-health-ctx-toggle', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $ctx = $btn.closest('tr').find('.fh-health-ctx');
			var open = $ctx.prop('hidden');
			$ctx.prop('hidden', !open);
			$btn.attr('aria-expanded', open ? 'true' : 'false')
				.text(open ? (i18n.hideContext || 'Hide') : (i18n.showContext || 'Context'));
		});

		// Narrow this page.
		$(document).on('input', '.fh-health-quick', function () {
			narrowPage($(this).val());
		});

		// Clear log.
		$(document).on('click', '.fh-health-clear', function (e) {
			e.preventDefault();
			var $btn    = $(this);
			var $status = $btn.closest('.fh-health-actions').find('.fh-health-status').first();

			if (!root.confirm(i18n.confirmClear || 'Clear the log?')) {
				return;
			}

			$btn.prop('disabled', true);

			$.post(cfg.ajaxUrl || root.ajaxurl, {
				action: 'fishotel_health_clear',
				nonce: cfg.nonce
			})
				.done(function (res) {
					var ok  = res && res.success;
					var msg = (res && res.data && res.data.message) || (ok ? (i18n.cleared || 'Cleared.') : (i18n.error || 'Something went wrong.'));
					setStatus($status, ok ? (i18n.cleared || msg) : msg, ok ? 'is-ok' : 'is-error');
					if (ok) {
						root.setTimeout(function () { root.location.reload(); }, 900);
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
		var $form = $('#fishotel-health-form');
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
						$form.find('[name="retention_days"]').val(s.retention_days);
						$form.find('[name="max_rows"]').val(s.max_rows);
						$form.find('[name="alert_cooldown_minutes"]').val(s.alert_cooldown_minutes);
						$form.find('[name="alert_email"]').val(s.alert_email || '');
						$form.find('[name="extra_hosts"]').val((s.extra_hosts || []).join(', '));
						$form.find('[name="store_debug"]').prop('checked', !!s.store_debug);
						$form.find('[name="capture_fatals"]').prop('checked', !!s.capture_fatals);
						$form.find('[name="capture_http"]').prop('checked', !!s.capture_http);
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
