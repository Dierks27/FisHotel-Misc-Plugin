/**
 * Gift Cards at checkout: the "How much to use" box and "Have a gift
 * card?". The theme already sends #use_gift_card_balance changes to
 * WooCommerce (update_checkout); this script does the same for the amount
 * (when it is typed and the field is left) and shows / hides the amount
 * with the checkbox. A typed code is sent with its nonce; WooCommerce then
 * recalculates. The pure helpers are tested in node
 * (tests/gift-cards-checkout.test.js).
 */
(function (root) {
	'use strict';

	/**
	 * "12.5", "$12.50", "1,000" → "12.50"; '' when it is not an amount.
	 */
	function cleanAmount(text) {
		var t = String(text == null ? '' : text).replace(/[$,\s]/g, '');
		if (!/^\d{1,9}(\.\d{1,2})?$/.test(t)) {
			return '';
		}
		var parts = t.split('.');
		return parts[0] + '.' + ((parts[1] || '') + '00').slice(0, 2);
	}

	/**
	 * Never more than the most the order can take.
	 */
	function clampAmount(text, max) {
		var a = cleanAmount(text);
		var m = cleanAmount(max);
		if (a === '' || m === '') {
			return a;
		}
		return parseFloat(a) > parseFloat(m) ? m : a;
	}

	var api = { cleanAmount: cleanAmount, clampAmount: clampAmount };

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
		return;
	}

	var $ = root.jQuery;
	var cfg = root.fhGiftCards || {};
	if (!$) {
		return;
	}

	function update() {
		$(document.body).trigger('update_checkout');
	}

	$(document.body).on('change', '#use_gift_card_balance', function () {
		$(this).closest('.fh-gcard-checkout').find('.fh-gcard-checkout__amount').prop('hidden', !this.checked);
		update();
	});

	$(document.body).on('change', '#fh_gcard_amount', function () {
		var box = $(this).closest('.fh-gcard-checkout');
		var v = clampAmount($(this).val(), box.data('max'));
		$(this).val(v);
		update();
	});

	$(document.body).on('keydown', '#fh_gcard_amount, #fh_gcard_code', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			if (this.id === 'fh_gcard_code') {
				$(this).closest('.fh-gcard-code').find('.fh-gcard-code__apply').trigger('click');
			} else {
				$(this).trigger('change');
			}
		}
	});

	$(document.body).on('click', '.fh-gcard-code__apply', function () {
		var wrap = $(this).closest('.fh-gcard-code');
		var input = wrap.find('#fh_gcard_code');
		var msg = wrap.find('.fh-gcard-code__message');
		var words = cfg.words || {};
		var code = $.trim(input.val());
		if (!code) {
			msg.text(words.empty || '');
			return;
		}
		msg.text(words.working || '');
		$.post(cfg.ajaxUrl, { action: cfg.action, nonce: cfg.nonce, code: code })
			.done(function (r) {
				var text = r && r.data && r.data.message ? r.data.message : (words.failed || '');
				msg.text(text);
				if (r && r.success) {
					input.val('');
					// The balance box is drawn once with the page: draw it again.
					root.location.reload();
				}
			})
			.fail(function (x) {
				var r = x && x.responseJSON;
				msg.text(r && r.data && r.data.message ? r.data.message : (words.failed || ''));
			});
	});
}(typeof window !== 'undefined' ? window : this));
