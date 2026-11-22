/**
 * Sticker Giveaway admin — AJAX save, live summary line, the variations
 * of a product picked just now, and the two acts on a design's stock
 * ("Add stickers", "Recount").
 *
 * The summary mirrors Admin::summary() in PHP so what the owner reads
 * while editing matches what the page shows after a reload. The lists
 * that depend on what is stored — the variations, the stock table, the
 * warnings — are never built here: the server sends them back with every
 * answer (Admin::fragments()) and they are put in place as they come.
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

	function notice(cls, msg, ms) {
		var $wrap = $('.fh-sg-wrap');
		$wrap.find('.fishotel-misc-notice').remove();
		$('<div class="fishotel-misc-notice ' + cls + '"></div>')
			.text(msg)
			.insertAfter($wrap.find('.fishotel-misc-header'))
			.delay(ms || 3000)
			.fadeOut(300, function () { $(this).remove(); });
	}

	function nonce() {
		return $('#fishotel-sticker-giveaway-form').find('input[name="_wpnonce"]').val();
	}

	/**
	 * Put the server's lists in place. `which` names the ones to replace;
	 * the stock table keeps the "Reorder at" numbers typed into it and not
	 * yet saved when it is redrawn after a stock act.
	 */
	function applyFragments(fragments, which, keepEdits) {
		if (!fragments) {
			return;
		}

		var edits = {};
		if (keepEdits) {
			$('#fh-sg-stock').find('input[name^="reorder_at["]').each(function () {
				edits[this.name] = this.value;
			});
		}

		$.each(which, function (i, key) {
			if (typeof fragments[key] === 'string') {
				$('#fh-sg-' + key).html(fragments[key]);
			}
		});

		if (keepEdits) {
			$('#fh-sg-stock').find('input[name^="reorder_at["]').each(function () {
				if (typeof edits[this.name] !== 'undefined') {
					this.value = edits[this.name];
				}
			});
		}
	}

	function updateSummary() {
		var $form = $('#fishotel-sticker-giveaway-form');
		var parts = [];

		// Designs: every ticked variation, plus every picked product that
		// lists no variations (a simple product is one design).
		var poolCount = $form.find('input[name="sticker_variation_ids[]"]:checked').length;
		$.each($form.find('#fh-sg-pool').val() || [], function (i, id) {
			if (!$form.find('.fh-sg-variations[data-parent="' + id + '"]').length) {
				poolCount++;
			}
		});
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
	 * A product was picked in the search: ask the server for its
	 * variations and list them under the search, every one ticked. A
	 * simple product has none and nothing is drawn.
	 */
	function loadVariations(productId) {
		var $box = $('#fh-sg-variations');
		if (!productId || $box.find('.fh-sg-variations[data-parent="' + productId + '"]').length) {
			return;
		}

		$.post(cfg.ajaxUrl || window.ajaxurl, {
			action: 'fishotel_sticker_giveaway_variations',
			_wpnonce: nonce(),
			product_id: productId
		}).done(function (res) {
			var html = res && res.success && res.data ? res.data.html : '';
			var stillPicked = $.inArray(String(productId), $('#fh-sg-pool').val() || []) !== -1;
			if (html && stillPicked && !$box.find('.fh-sg-variations[data-parent="' + productId + '"]').length) {
				$box.append(html);
			}
			updateSummary();
		});
	}

	/**
	 * Mirror what the server actually stored back into the form, so a
	 * product or category that sanitize() dropped does not linger in the
	 * UI (and in the summary) after "Settings saved.".
	 */
	function applySaved(settings, fragments) {
		var $form = $('#fishotel-sticker-giveaway-form');
		if (!settings) {
			return;
		}

		// What stays in the search: the simple-product designs and the variable products.
		var pool = $.map((settings.sticker_product_ids || []).concat(settings.sticker_parent_ids || []), function (id) { return String(id); });
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

		// The variations, the stock table (a design picked just now has
		// its row) and the warnings, as stored.
		applyFragments(fragments, ['variations', 'stock', 'warnings'], false);

		updateSummary();
	}

	$(function () {
		var $form = $('#fishotel-sticker-giveaway-form');
		if (!$form.length) {
			return;
		}

		$form.on('change input', 'input, select', updateSummary);
		$form.find('#fh-sg-pool')
			.on('select2:select', function (e) {
				loadVariations(e.params && e.params.data ? e.params.data.id : '');
				updateSummary();
			})
			.on('select2:unselect', function (e) {
				var id = e.params && e.params.data ? e.params.data.id : '';
				$('#fh-sg-variations').find('.fh-sg-variations[data-parent="' + id + '"]').remove();
				updateSummary();
			});

		// "Add stickers" / "Recount": done at once, for one design. Enter
		// in the number box presses its own button, never Save.
		$form.on('keydown', '.fh-sg-stock-n', function (e) {
			if (e.key === 'Enter' || e.which === 13) {
				e.preventDefault();
				$(this).closest('.fh-sg-stock__act').find('.fh-sg-stock-btn').trigger('click');
			}
		});

		$form.on('click', '.fh-sg-stock-btn', function (e) {
			e.preventDefault();

			var $btn   = $(this);
			var $input = $btn.closest('.fh-sg-stock__act').find('.fh-sg-stock-n');
			var typed  = $.trim(String($input.val()));

			if (typed === '') {
				notice('fishotel-misc-notice--error', i18n.countRequired || 'Type a number first.');
				$input.trigger('focus');
				return;
			}

			$btn.prop('disabled', true);

			$.post(cfg.ajaxUrl || window.ajaxurl, {
				action: 'fishotel_sticker_giveaway_stock',
				_wpnonce: nonce(),
				count_id: $btn.closest('.fh-sg-stock__row').data('count'),
				act: $btn.data('act'),
				n: typed
			}).done(function (res) {
				var ok  = res && res.success;
				var msg = (res && res.data && res.data.message) ? res.data.message : (i18n.error || 'Something went wrong.');
				// What the stock now reads stays up long enough to be read.
				notice(ok ? 'fishotel-misc-notice--success' : 'fishotel-misc-notice--error', msg, 8000);
				if (ok && res.data) {
					applyFragments(res.data.fragments, ['stock', 'warnings'], true);
				}
			}).fail(function (xhr) {
				var res = xhr && xhr.responseJSON;
				notice('fishotel-misc-notice--error', (res && res.data && res.data.message) ? res.data.message : (i18n.error || 'Something went wrong.'), 8000);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		$form.on('submit', function (e) {
			e.preventDefault();

			// A blank price would otherwise be saved as the default without
			// the owner noticing; make them type one.
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
						applySaved(res.data.settings, res.data.fragments);
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
