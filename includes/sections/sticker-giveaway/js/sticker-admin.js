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
		parts.push(slugs.length ? fmt(i18n.freeWith, slugs.join(', ')) : (i18n.freeNone || 'no categories ticked — no free stickers'));

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

	/**
	 * Mirror what the server actually stored back into the form, so a
	 * product or category that sanitize() dropped does not linger in the
	 * UI (and in the summary) after "Settings saved.".
	 */
	function applySaved(settings) {
		var $form = $('#fishotel-sticker-giveaway-form');
		if (!settings) {
			return;
		}

		var pool = $.map(settings.sticker_product_ids || [], function (id) { return String(id); });
		var $pool = $form.find('#fh-sg-pool');
		var changed = false;
		$pool.find('option').each(function () {
			if ($.inArray(String(this.value), pool) === -1) {
				$(this).remove();
				changed = true;
			}
		});
		if (changed) {
			$pool.trigger('change');
		}

		var slugs = settings.eligible_category_slugs || [];
		$form.find('input[name="eligible_category_slugs[]"]').each(function () {
			this.checked = $.inArray(this.value, slugs) !== -1;
		});

		if (typeof settings.min_subtotal !== 'undefined') {
			$form.find('[name="min_subtotal"]').val(parseFloat(settings.min_subtotal).toFixed(2));
		}
		if (typeof settings.upsell_price !== 'undefined') {
			$form.find('[name="upsell_price"]').val(parseFloat(settings.upsell_price).toFixed(2));
		}
		if (typeof settings.upsell_max_qty !== 'undefined') {
			$form.find('[name="upsell_max_qty"]').val(parseInt(settings.upsell_max_qty, 10) || 1);
		}

		$form.find('input[type="text"][name]').each(function () {
			if (typeof settings[this.name] === 'string') {
				this.value = settings[this.name];
			}
		});

		updateSummary();
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

			// A blank price would otherwise be saved as the default without
			// Jeff noticing; make him type one.
			var $price = $form.find('[name="upsell_price"]');
			if ($price.length && $.trim(String($price.val())) === '') {
				notice('fishotel-misc-notice--error', i18n.priceRequired || 'Enter a price per add-on sticker (0 is allowed).');
				$price.trigger('focus');
				return false;
			}

			var $btn = $form.find('button[type="submit"]');
			$btn.prop('disabled', true);

			$.post(cfg.ajaxUrl || window.ajaxurl, $form.serialize())
				.done(function (res) {
					var ok  = res && res.success;
					var msg = (res && res.data && res.data.message) ? res.data.message
						: (ok ? (i18n.saved || 'Settings saved.') : (i18n.error || 'Something went wrong.'));
					var dropped = ok && res.data && res.data.dropped && res.data.dropped.length;
					notice(ok ? (dropped ? 'fishotel-misc-notice--warning' : 'fishotel-misc-notice--success') : 'fishotel-misc-notice--error', msg);
					if (ok && res.data) {
						applySaved(res.data.settings);
					} else {
						updateSummary();
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
})(jQuery);
