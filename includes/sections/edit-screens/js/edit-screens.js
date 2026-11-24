/**
 * Edit screens — the product edit screen, tidied.
 *
 *   - Fish essentials and WooCommerce's product data box move under the
 *     title, above the description — right here, as the page is drawn
 *     (this file loads in the footer), before any ready handler runs, so
 *     WooCommerce's own script starts on its box where it now stands.
 *   - The kind of product on the page (a class on <body>) follows the
 *     categories, "Livestock — packed in a bag" and "Who ships it" as they
 *     are ticked: the boxes not for that kind are not shown (still posted).
 *   - The scientific name: "Use this one", and the theme's own field of
 *     the facts box kept equal and out of sight (the save writes both).
 *     The theme's "Copy them into these fields" hands the name on (the
 *     event `fishotel-copy-facts`): it fills the field when it is empty;
 *     when the field holds another name, that name is kept and the copied
 *     one is offered with "Use this one" — nothing typed is replaced.
 *   - The Shipping class first in its tab.
 *
 * The pure helpers are exported for node (tests/edit-screens.test.js).
 */
(function (root, $) {
	'use strict';

	var KINDS = ['fish', 'med', 'dry', 'invoice', 'none'];

	/**
	 * Whole numbers of a list, each once.
	 *
	 * @param {Array} list Values.
	 * @return {Array<number>}
	 */
	function ids(list) {
		var out = [];
		(list || []).forEach(function (v) {
			var n = /^\d+$/.test(String(v)) ? parseInt(v, 10) : 0;
			if (n > 0 && out.indexOf(n) === -1) {
				out.push(n);
			}
		});
		return out;
	}

	/**
	 * The kind of product from what is ticked — the rule of Kinds::kind().
	 *
	 * @param {Array}  checked     The ticked category term ids.
	 * @param {Object} terms       { fish: [ids], med: [ids], invoice: [ids] }.
	 * @param {*}      packs       The Livestock checkbox: true / false, or null when it is not on the page.
	 * @param {string} fulfillment "Who ships it" ('' or 'none' = nobody else).
	 * @return {string} fish | med | dry | invoice | none.
	 */
	function kindFrom(checked, terms, packs, fulfillment) {
		var on = ids(checked);
		terms = terms || {};
		var has = function (kind) {
			var list = ids(terms[kind]);
			return on.some(function (id) {
				return list.indexOf(id) !== -1;
			});
		};
		var ships = String(fulfillment === null || typeof fulfillment === 'undefined' ? '' : fulfillment).trim().toLowerCase();

		if (has('invoice')) {
			return 'invoice';
		}
		if (packs === true || (packs !== false && has('fish'))) {
			return 'fish';
		}
		if (has('med') || (ships !== '' && ships !== 'none')) {
			return 'med';
		}
		return on.length ? 'dry' : 'none';
	}

	/**
	 * The <body> classes with the kind swapped in.
	 *
	 * @param {string} classes The body's class attribute.
	 * @param {string} kind    A kind.
	 * @return {string}
	 */
	function withKind(classes, kind) {
		var list = String(classes || '').split(/\s+/).filter(function (c) {
			return c !== '' && c.indexOf('fh-es-kind-') !== 0;
		});
		if (KINDS.indexOf(kind) !== -1) {
			list.push('fh-es-kind-' + kind);
		}
		return list.join(' ');
	}

	/**
	 * The ids of the boxes to move under the title, in order.
	 *
	 * @param {string} attr The area's data-boxes.
	 * @return {Array<string>}
	 */
	function topBoxes(attr) {
		return String(attr || '').split(',').map(function (s) {
			return s.trim();
		}).filter(function (s) {
			return /^[A-Za-z0-9_-]+$/.test(s);
		});
	}

	/**
	 * What to do with a scientific name the theme's copy hands on.
	 *
	 * @param {*} current The field's value now.
	 * @param {*} copied  The name read from the description.
	 * @return {string} fill (the field is empty) | same (it already says so) | offer (it holds another name) | none (nothing handed on).
	 */
	function copiedName(current, copied) {
		var norm = function (v) {
			return String(v === null || typeof v === 'undefined' ? '' : v).replace(/\s+/g, ' ').trim();
		};
		var now = norm(current);
		var add = norm(copied);
		if (add === '') {
			return 'none';
		}
		if (now === '') {
			return 'fill';
		}
		return now.toLowerCase() === add.toLowerCase() ? 'same' : 'offer';
	}

	var api = { kindFrom: kindFrom, withKind: withKind, topBoxes: topBoxes, ids: ids, copiedName: copiedName };
	root.fhEditScreensHelpers = api;
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
	if (!$ || !root.document) {
		return;
	}

	/* ---- Now, as the page is drawn: the boxes under the title ------- */
	var area = root.document.getElementById('fh-es-top');
	if (area) {
		topBoxes(area.getAttribute('data-boxes')).forEach(function (id) {
			var box = root.document.getElementById(id);
			if (box) {
				area.appendChild(box);
			}
		});
	}

	$(function () {
		var cfg = root.fhEditScreens || {};
		var $body = $('body');

		/* ---- The kind follows what is ticked ------------------------- */
		var applyKind = function () {
			var checked = $('#product_catchecklist input[type="checkbox"]:checked').map(function () {
				return this.value;
			}).get();
			var $packs = $('#fh_packs_in_bag');
			var packs = $packs.length ? $packs.prop('checked') : null;
			var ships = $('[name="_fishotel_fulfillment"]').val();
			var kind = kindFrom(checked, cfg.kindTerms, packs, ships);
			// Only this section's own class changes: other code's classes on <body> are left alone.
			$body.removeClass(KINDS.map(function (k) {
				return 'fh-es-kind-' + k;
			}).join(' ')).addClass('fh-es-kind-' + kind);
		};
		$(root.document).on('change', '#product_catchecklist input[type="checkbox"], #fh_packs_in_bag, [name="_fishotel_fulfillment"]', function () {
			// After the Packing script let the Livestock box follow the categories.
			root.setTimeout(applyKind, 0);
		});

		/* ---- The scientific name ------------------------------------- */
		var $sci = $('#fh-es-sci');
		var $themeSci = $('#fishotel_hotel_data input[name="fh_scientific_name"]');
		if ($sci.length && $themeSci.length) {
			$themeSci.closest('.fh-meta-field').addClass('fh-es-moved').attr('title', 'Scientific name: in Fish essentials, under the title');
			$sci.on('input change', function () {
				$themeSci.val($sci.val());
			});
		}
		$(root.document).on('click', '.fh-es-use', function (e) {
			e.preventDefault();
			$sci.val($(this).attr('data-value')).trigger('change').trigger('focus');
		});
		// The theme's "Copy them into these fields": the name it hands on.
		$(root.document).on('fishotel-copy-facts', function (e, values) {
			if (!$sci.length || !values || typeof values !== 'object' || !Object.prototype.hasOwnProperty.call(values, 'scientific_name')) {
				return;
			}
			var copied = String(values.scientific_name);
			var what = copiedName($sci.val(), copied);
			if (what === 'none') {
				return;
			}
			if (what === 'fill') {
				$sci.val(copied.trim()).trigger('change');
			} else if (what === 'offer') {
				var $box = $sci.closest('.fh-es-sci');
				$box.find('.fh-es-copied').remove();
				var $line = $('<p class="fh-es-copied"></p>');
				$line.append($('<span></span>').text('The description says: '));
				$line.append($('<em></em>').text(copied.trim()));
				$line.append(' ');
				$line.append($('<button type="button" class="button-link fh-es-use"></button>').attr('data-value', copied.trim()).text('Use this one'));
				$box.append($line);
			}
			// Tell the theme's box this field took the name (filled, already there, or offered).
			if (e.taken && typeof e.taken === 'object') {
				e.taken.scientific_name = true;
			}
		});

		/* ---- The Shipping class first in its tab --------------------- */
		var $class = $('#shipping_product_data .shipping_class_field').first();
		var $first = $('#shipping_product_data .options_group').first();
		if ($class.length && $first.length && !$class.parent().is($first)) {
			$first.prepend($class);
		}
	});
})(typeof window !== 'undefined' ? window : globalThis, typeof jQuery !== 'undefined' ? jQuery : null);
