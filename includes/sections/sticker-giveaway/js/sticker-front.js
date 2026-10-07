/**
 * Sticker Giveaway — checkout picker bridge.
 *
 * On checkout the picker lives inside <form name="checkout">, so all we
 * do is nudge WooCommerce's own update_checkout whenever a radio or
 * stepper changes; WC serialises the form and the server syncs the cart.
 *
 * On the cart page (opt-in) nothing posts our fields, so we send them to
 * a small AJAX action ourselves and then ask WC's cart.js to refresh.
 *
 * Degrades quietly: no jQuery → no trigger, no WC → nothing breaks.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

(function () {
	'use strict';

	var cfg = window.fhStickerFront || {};

	function closest(el, selector) {
		while (el && el.nodeType === 1) {
			if (el.matches && el.matches(selector)) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function clamp(value, min, max) {
		value = parseInt(value, 10);
		if (isNaN(value)) {
			value = 0;
		}
		return Math.min(max, Math.max(min, value));
	}

	function refreshStepper(input) {
		var min = clamp(input.getAttribute('min') || 0, 0, 999);
		var max = clamp(input.getAttribute('max') || 0, 0, 999);
		var val = clamp(input.value, min, max);
		var wrap = closest(input, '.fh-sticker__stepper');

		if (String(val) !== input.value) {
			input.value = val;
		}

		if (!wrap) {
			return;
		}

		var buttons = wrap.querySelectorAll('.fh-sticker__step');
		for (var i = 0; i < buttons.length; i++) {
			var step = parseInt(buttons[i].getAttribute('data-step'), 10) || 0;
			buttons[i].disabled = (step < 0 && val <= min) || (step > 0 && val >= max);
		}
	}

	function triggerCheckoutUpdate() {
		if (window.jQuery && window.jQuery(document.body).trigger) {
			window.jQuery(document.body).trigger('update_checkout');
		}
	}

	function syncCart(picker) {
		if (!cfg.ajaxUrl || !window.FormData || !window.fetch) {
			return;
		}

		var data = new FormData();
		data.append('action', 'fishotel_sticker_sync');
		data.append('nonce', cfg.nonce || '');

		var inputs = picker.querySelectorAll('input[name^="fh_sticker"]');
		for (var i = 0; i < inputs.length; i++) {
			var input = inputs[i];
			if (input.type === 'radio' && !input.checked) {
				continue;
			}
			data.append(input.name, input.value);
		}

		picker.classList.add('fh-sticker--busy');

		window.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function () {
				if (window.jQuery && window.jQuery('.woocommerce-cart-form').length) {
					window.jQuery(document.body).trigger('wc_update_cart');
				} else {
					window.location.reload();
				}
			})
			.catch(function () {})
			.then(function () {
				picker.classList.remove('fh-sticker--busy');
			});
	}

	function onChange(picker) {
		if (picker.getAttribute('data-context') === 'cart') {
			syncCart(picker);
		} else {
			triggerCheckoutUpdate();
		}
	}

	function init() {
		var pickers = document.querySelectorAll('[data-fh-sticker]');
		if (!pickers.length) {
			return;
		}

		for (var p = 0; p < pickers.length; p++) {
			var qtys = pickers[p].querySelectorAll('.fh-sticker__qty');
			for (var q = 0; q < qtys.length; q++) {
				refreshStepper(qtys[q]);
			}
		}

		document.addEventListener('click', function (e) {
			var btn = closest(e.target, '.fh-sticker__step');
			if (!btn) {
				return;
			}

			e.preventDefault();

			var wrap = closest(btn, '.fh-sticker__stepper');
			var input = wrap ? wrap.querySelector('.fh-sticker__qty') : null;
			if (!input) {
				return;
			}

			var step = parseInt(btn.getAttribute('data-step'), 10) || 0;
			var before = input.value;
			input.value = clamp(input.value, 0, 999) + step;
			refreshStepper(input);

			if (input.value !== before) {
				var picker = closest(input, '[data-fh-sticker]');
				if (picker) {
					onChange(picker);
				}
			}
		});

		document.addEventListener('change', function (e) {
			var picker = closest(e.target, '[data-fh-sticker]');
			if (!picker) {
				return;
			}

			if (e.target.classList && e.target.classList.contains('fh-sticker__qty')) {
				refreshStepper(e.target);
			}

			onChange(picker);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
