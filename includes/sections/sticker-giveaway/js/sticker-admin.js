/**
 * Sticker Giveaway admin — AJAX save + live summary line.
 *
 * The summary mirrors Admin::summary() in PHP so what Jeff reads while
 * editing matches what he sees after a reload.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

/* global jQuery */
(function ($) {
	'use strict';

	var cfg  = window.fhStickerAdmin || {};
	var i18n = cfg.i18n || {};
	var symbol = cfg.currencySymbol || '$';

	function fmt(template, value) {
		return String(template || '%s').replace(/%d|%s/, value);
	}

	function money(value) {
		var n = parseFloat(value);
		if (isNaN(n) || n < 0) {
			n = 0;
		}
		return symbol + n.toFixed(2);
	}

	function notice(cls, msg) {
		var $wrap = $('.fh-sg-wrap');
		$wrap.find('.fishotel-misc-notice').remove();
		$('<div class="fishotel-misc-notice ' + cls + '"></div>')
			.text(msg)
			.insertAfter($wrap.find('.fishotel-misc-header'))
			.delay(3000)
			.fadeOut(300, function () { $(this).remove(); });
	}

	function updateSummary() {
		var $form = $('#fishotel-sticker-giveaway-form');
		var parts = [];

		var poolCount = ($form.find('#fh-sg-pool').val() || []).length;
		if (poolCount < 1) {
			parts.push(i18n.noPool || 'no stickers in pool');
		} else {
			parts.push(fmt(poolCount === 1 ? i18n.sticker : i18n.stickers, poolCount));
		}

		var slugs = [];
		$form.find('input[name="eligible_category_slugs[]"]:checked').each(function () {
			slugs.push(this.value);
		});
		parts.push(slugs.length ? fmt(i18n.freeWith, slugs.join(', ')) : (i18n.freeAll || 'free with every order'));

		var minSub = parseFloat($form.find('[name="min_subtotal"]').val());
		if (!isNaN(minSub) && minSub > 0) {
			parts.push(fmt(i18n.minSub, money(minSub)));
		}

		if ($form.find('[name="upsell_enabled"]').is(':checked')) {
			parts.push(fmt(i18n.addons, money($form.find('[name="upsell_price"]').val())));
			parts.push(fmt(i18n.max, parseInt($form.find('[name="upsell_max_qty"]').val(), 10) || 0));
		} else {
			parts.push(i18n.addonsOff || 'add-ons off');
		}

		$('#fh-sg-summary .fh-sg-summary__text').text(parts.join(' · '));
	}

	$(function () {
		var $form = $('#fishotel-sticker-giveaway-form');
		if (!$form.length) {
			return;
		}

		$form.on('change input', 'input, select', updateSummary);
		$form.find('#fh-sg-pool').on('select2:select select2:unselect', updateSummary);

		$form.on('submit', function (e) {
			e.preventDefault();

			var $btn = $form.find('button[type="submit"]');
			$btn.prop('disabled', true);

			$.post(cfg.ajaxUrl || window.ajaxurl, $form.serialize())
				.done(function (res) {
					var ok  = res && res.success;
					var msg = (res && res.data && res.data.message) ? res.data.message
						: (ok ? (i18n.saved || 'Settings saved.') : (i18n.error || 'Something went wrong.'));
					notice(ok ? 'fishotel-misc-notice--success' : 'fishotel-misc-notice--error', msg);
					updateSummary();
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
})(jQuery);
