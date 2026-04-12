/**
 * FisHotel Misc Plugin — Admin JavaScript
 *
 * @package FisHotel\Misc
 */

/* global jQuery, fishotelMisc */
(function ($) {
	'use strict';

	/**
	 * Toggle a section on or off via AJAX.
	 */
	function handleToggle() {
		var $toggle  = $(this);
		var $card    = $toggle.closest('.fishotel-misc-card');
		var section  = $toggle.data('section');
		var enabled  = $toggle.is(':checked') ? '1' : '0';
		var $badge   = $card.find('.fishotel-misc-badge');
		var $footer  = $card.find('.fishotel-misc-card-footer');

		// Show spinner.
		$footer.addClass('is-loading');

		$.post(fishotelMisc.ajaxUrl, {
			action:  'fishotel_misc_toggle_section',
			nonce:   fishotelMisc.nonce,
			section: section,
			enabled: enabled
		})
		.done(function (response) {
			if (response.success) {
				if (response.data.enabled) {
					$badge
						.text(fishotelMiscL10n.active)
						.removeClass('fishotel-misc-badge--inactive')
						.addClass('fishotel-misc-badge--active');
				} else {
					$badge
						.text(fishotelMiscL10n.inactive)
						.removeClass('fishotel-misc-badge--active')
						.addClass('fishotel-misc-badge--inactive');
				}
			} else {
				// Revert toggle on failure.
				$toggle.prop('checked', !$toggle.is(':checked'));
				showNotice('error', response.data.message || fishotelMiscL10n.error);
			}
		})
		.fail(function () {
			$toggle.prop('checked', !$toggle.is(':checked'));
			showNotice('error', fishotelMiscL10n.error);
		})
		.always(function () {
			$footer.removeClass('is-loading');
		});
	}

	/**
	 * Show a temporary notice at the top of the dashboard.
	 *
	 * @param {string} type    "success" or "error".
	 * @param {string} message The message to display.
	 */
	function showNotice(type, message) {
		var $wrap   = $('.fishotel-misc-wrap');
		var $notice = $(
			'<div class="fishotel-misc-notice fishotel-misc-notice--' + type + '">' +
			message +
			'</div>'
		);

		$wrap.find('.fishotel-misc-notice').remove();
		$wrap.find('.fishotel-misc-header').after($notice);

		setTimeout(function () {
			$notice.fadeOut(300, function () {
				$(this).remove();
			});
		}, 3000);
	}

	// Localisation defaults.
	var fishotelMiscL10n = window.fishotelMiscL10n || {
		active:   'Active',
		inactive: 'Inactive',
		error:    'Something went wrong. Please try again.'
	};

	// Bind events.
	$(document).on('change', '.fishotel-misc-toggle input[data-section]', handleToggle);
})(jQuery);
