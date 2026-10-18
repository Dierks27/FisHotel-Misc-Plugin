/**
 * Snippets admin — the on/off switch. Posts the change, then sets the
 * row's status line and the page message from the server's answer (text
 * only; the server renders every piece of markup). A refused or failed
 * switch flips back.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

/* global jQuery, fhSnippetsAdmin */
(function ($) {
	'use strict';

	var cfg  = window.fhSnippetsAdmin || {};
	var i18n = cfg.i18n || {};

	/**
	 * Show a message above the table.
	 *
	 * @param {string} kind ok|warn|error.
	 * @param {string} text Message.
	 */
	function say(kind, text) {
		$('.fh-snip-status')
			.removeClass('is-ok is-warn is-error')
			.addClass('is-' + kind)
			.text(text || '');
	}

	$(function () {
		$(document).on('change', '.fh-snip-table input[data-snippet]', function () {
			var $input = $(this);
			var $row   = $input.closest('.fh-snip-row');
			var slug   = String($input.data('snippet'));
			var on     = $input.is(':checked');

			$row.addClass('is-saving');
			say('ok', i18n.saving || 'Saving…');

			$.post(cfg.ajaxUrl, {
				action: 'fishotel_snippets_toggle',
				nonce: cfg.nonce,
				slug: slug,
				enabled: on ? '1' : '0'
			})
				.done(function (response) {
					if (response && response.success && response.data) {
						var status = response.data.status || {};
						$row.find('[data-role="status"]')
							.attr('class', 'fh-snip-state fh-snip-state--' + String(status.key || 'off'))
							.text(String(status.label || ''));
						say('held' === status.key ? 'warn' : 'ok', String(response.data.message || ''));
						return;
					}
					$input.prop('checked', !on);
					say('error', (response && response.data && response.data.message) ? String(response.data.message) : (i18n.error || 'Error'));
				})
				.fail(function (xhr) {
					var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
					$input.prop('checked', !on);
					say('error', msg ? String(msg) : (i18n.error || 'Error'));
				})
				.always(function () {
					$row.removeClass('is-saving');
				});
		});
	});
})(jQuery);
