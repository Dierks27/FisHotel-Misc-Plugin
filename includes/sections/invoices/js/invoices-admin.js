/**
 * Invoices admin — the picker (the customer, the one search box, the
 * lines with their quantity steppers and prices, credits and notes, the
 * flat total, the live preview, Save draft, the pre-check and Publish),
 * one invoice's page (Withdraw, Trash, the deliberate "Edit this unpaid
 * invoice"), the list (Copy link, the ticks for the forum list), the
 * forum list and Settings.
 *
 * The server decides everything: this file shows, adds up as it is
 * typed, and posts. The pure helpers at the top mirror the PHP exactly —
 * money() / cents() / moneyText() are Lines::money() / cents() /
 * money_text(), normalizeLine() / normalize() / withFlatTotal() /
 * lineText() / themeRows() are Lines' of the same names, title() /
 * excerpt() are Format's — and both sides are tested against
 * tests/fixtures/invoice-maths.json, so the preview shows what the
 * server will write. They are exported for the node test; the rest
 * needs jQuery and the page.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

/* global jQuery, fhInvoices, module, wp */
(function (root, $) {
	'use strict';

	var DASH = '\u2014';
	var KINDS = ['fish', 'credit', 'discount', 'note'];
	var SOURCES = ['tank', 'product', 'species', 'typed'];
	var MAX_QTY = 999;
	var MAX_NAME = 200;
	var MAX_LINES = 200;
	var MAX_MONEY = 999999.99;

	/* ============================================================== */
	/*  Pure helpers (mirrors of Lines and Format)                     */
	/* ============================================================== */

	/**
	 * An amount in whole cents.
	 *
	 * @param {number|null} amount Amount.
	 * @return {number}
	 */
	function cents(amount) {
		if (amount === null || amount === undefined || amount === '') {
			return 0;
		}
		var n = Number(amount);
		if (!isFinite(n)) {
			return 0;
		}
		return (n < 0 ? -1 : 1) * Math.round(Math.abs(n) * 100);
	}

	/**
	 * Whole cents as an amount (never -0).
	 *
	 * @param {number} c Cents.
	 * @return {number}
	 */
	function fromCents(c) {
		return c === 0 ? 0 : c / 100;
	}

	/**
	 * A typed amount as a number rounded to the cent, or null when it is
	 * no amount. Typed text is read digit by digit — the third decimal
	 * rounds half up — exactly as the server reads it.
	 *
	 * @param {*} value What was typed (or a number).
	 * @return {number|null}
	 */
	function money(value) {
		if (typeof value === 'number') {
			if (!isFinite(value) || Math.abs(value) > MAX_MONEY) {
				return null;
			}
			return fromCents(cents(value));
		}
		if (typeof value !== 'string') {
			return null;
		}
		var m = /^\s*(-|\u2212)?\s*\$?\s*(-|\u2212)?\s*(\d[\d,]*(?:\.\d+)?|\.\d+)\s*$/.exec(value);
		if (!m) {
			return null;
		}
		var parts = m[3].replace(/,/g, '').split('.');
		var whole = parts[0].replace(/^0+/, '');
		var frac = parts.length > 1 ? parts[1] : '';
		if (whole.length > 6) {
			return null;
		}
		var c = (whole === '' ? 0 : parseInt(whole, 10)) * 100 + parseInt((frac + '00').slice(0, 2), 10);
		if (frac.length > 2 && parseInt(frac.charAt(2), 10) >= 5) {
			c++;
		}
		if (m[1] || m[2]) {
			c = -c;
		}
		return fromCents(c);
	}

	/**
	 * An amount as the invoices print it, without sign and dollar: "82",
	 * "82.50", "1,250".
	 *
	 * @param {number|null} amount Amount.
	 * @return {string}
	 */
	function moneyText(amount) {
		var c = Math.abs(cents(amount));
		var whole = String(Math.floor(c / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		var rest = c % 100;
		return rest === 0 ? whole : whole + '.' + (rest < 10 ? '0' : '') + rest;
	}

	/**
	 * An amount with its dollar and, when negative, a leading minus.
	 *
	 * @param {number|null} amount Amount.
	 * @return {string}
	 */
	function dollars(amount) {
		return (cents(amount) < 0 ? '-' : '') + '$' + moneyText(amount);
	}

	/**
	 * Plain single-line text: no tags, whitespace collapsed, cut to a
	 * length.
	 *
	 * @param {*}      value Value.
	 * @param {number} max   Longest result in characters.
	 * @return {string}
	 */
	function text(value, max) {
		if (value === null || value === undefined || typeof value === 'object') {
			return '';
		}
		var t = String(value)
			.replace(/<\/?[a-zA-Z!][^>]*>/g, '')
			.replace(/[\u00A0\u202F]/g, ' ')
			.replace(/[\x00-\x1F\x7F]+/g, ' ')
			.replace(/\s+/g, ' ')
			.trim();
		return Array.from(t).slice(0, max || MAX_NAME).join('').trim();
	}

	/**
	 * A positive id, or null.
	 *
	 * @param {*} value Value.
	 * @return {number|null}
	 */
	function id(value) {
		if (typeof value === 'number' && Math.floor(value) === value) {
			return value > 0 ? value : null;
		}
		if (typeof value === 'string' && /^\d{1,18}$/.test(value)) {
			return parseInt(value, 10) > 0 ? parseInt(value, 10) : null;
		}
		return null;
	}

	/**
	 * Whether a flag is on.
	 *
	 * @param {*} value Value.
	 * @return {boolean}
	 */
	function truthy(value) {
		if (typeof value === 'boolean') {
			return value;
		}
		if (typeof value === 'number') {
			return value !== 0;
		}
		return typeof value === 'string' && ['1', 'true', 'on', 'yes'].indexOf(value.trim().toLowerCase()) !== -1;
	}

	/**
	 * One line from a form row, or null when it carries nothing.
	 * Mirrors Lines::normalize_line().
	 *
	 * @param {Object} raw  The row.
	 * @param {number} sort Its position.
	 * @return {Object|null}
	 */
	function normalizeLine(raw, sort) {
		if (!raw || typeof raw !== 'object') {
			return null;
		}
		var kind = typeof raw.kind === 'string' ? raw.kind.trim().toLowerCase() : 'fish';
		if (KINDS.indexOf(kind) === -1) {
			kind = 'fish';
		}
		var name = text(raw.name, MAX_NAME);
		var price = money(raw.unit_price === undefined ? null : raw.unit_price);
		var line = {
			id: id(raw.id),
			kind: kind,
			name: name,
			qty: 1,
			unit_price: null,
			product_id: null,
			variation_id: null,
			species_id: null,
			stay_id: null,
			size_slug: null,
			size_label: null,
			source: 'typed',
			take_stock: false,
			sort: sort || 0
		};

		if (kind === 'note') {
			return name === '' ? null : line;
		}
		if (kind === 'credit') {
			line.name = name.replace(/^credit(?![\p{L}\p{N}_])[\s:\u2014\u2013-]*/iu, '').trim();
			line.unit_price = price === null ? null : fromCents(-Math.abs(cents(price)));
			return line.name === '' && line.unit_price === null ? null : line;
		}
		if (kind === 'discount') {
			line.unit_price = price;
			line.name = cents(price) > 0 ? 'Adjustment' : 'Discount';
			return cents(price) === 0 ? null : line;
		}
		if (name === '') {
			return null;
		}

		var qty = raw.qty !== undefined && raw.qty !== null && raw.qty !== '' && isFinite(Number(raw.qty)) ? parseInt(raw.qty, 10) : 1;
		if (isNaN(qty)) {
			qty = 1;
		}
		line.qty = Math.max(1, Math.min(MAX_QTY, qty));
		line.unit_price = price;
		line.product_id = id(raw.product_id);
		line.variation_id = line.product_id !== null ? id(raw.variation_id) : null;
		line.species_id = id(raw.species_id);
		line.stay_id = id(raw.stay_id);
		line.size_slug = raw.size_slug ? String(raw.size_slug) : null;
		line.size_label = text(raw.size_label, 100) || null;

		var source = typeof raw.source === 'string' ? raw.source.trim().toLowerCase() : 'typed';
		line.source = SOURCES.indexOf(source) === -1 ? 'typed' : source;
		if (line.source === 'tank' && line.stay_id === null) {
			line.source = 'typed';
		}
		if (line.source === 'product' && line.product_id === null) {
			line.source = 'typed';
		}
		if (line.source === 'species' && line.species_id === null) {
			line.source = 'typed';
		}
		line.take_stock = line.source === 'product' && truthy(raw.take_stock);

		return line;
	}

	/**
	 * The typed lines in their order: empty rows dropped, a discount row
	 * ignored, positions renumbered. Mirrors Lines::normalize().
	 *
	 * @param {Object[]} raw Rows.
	 * @return {Object[]}
	 */
	function normalize(raw) {
		var out = [];
		(Array.isArray(raw) ? raw : []).some(function (row) {
			var line = normalizeLine(row, out.length);
			if (line && line.kind !== 'discount') {
				out.push(line);
			}
			return out.length >= MAX_LINES;
		});
		return out;
	}

	/**
	 * What one line adds, in cents. Mirrors Lines::amount_cents().
	 *
	 * @param {Object} line Line.
	 * @return {number}
	 */
	function amountCents(line) {
		var price = line.unit_price === null || line.unit_price === undefined || line.unit_price === '' || !isFinite(Number(line.unit_price)) ? null : Number(line.unit_price);
		if (line.kind === 'note' || price === null) {
			return 0;
		}
		if (line.kind === 'credit') {
			return -Math.abs(cents(price));
		}
		if (line.kind === 'discount') {
			return cents(price);
		}
		return Math.max(1, parseInt(line.qty, 10) || 1) * cents(price);
	}

	/**
	 * The lines with the stated price applied: one Discount / Adjustment
	 * line makes them add up to it. Mirrors Lines::with_flat_total().
	 *
	 * @param {Object[]}           lines Lines (normalised).
	 * @param {string|number|null} flat  The stated price, or null.
	 * @return {{lines: Object[], subtotal: number, discount: number, total: number}}
	 */
	function withFlatTotal(lines, flat) {
		var kept = [];
		var subtotal = 0;
		(lines || []).forEach(function (line) {
			if (!line || line.kind === 'discount') {
				return;
			}
			var copy = {};
			Object.keys(line).forEach(function (key) {
				copy[key] = line[key];
			});
			copy.sort = kept.length;
			kept.push(copy);
			subtotal += amountCents(copy);
		});

		var amount = flat === null || flat === undefined || flat === '' ? null : money(typeof flat === 'number' ? flat : String(flat));
		var diff = amount === null ? 0 : cents(amount) - subtotal;

		if (diff !== 0) {
			kept.push({
				id: null,
				kind: 'discount',
				name: diff > 0 ? 'Adjustment' : 'Discount',
				qty: 1,
				unit_price: fromCents(diff),
				product_id: null,
				variation_id: null,
				species_id: null,
				stay_id: null,
				size_slug: null,
				size_label: null,
				source: 'typed',
				take_stock: false,
				sort: kept.length
			});
		}

		return {
			lines: kept,
			subtotal: fromCents(subtotal),
			discount: fromCents(diff),
			total: amount === null ? fromCents(subtotal) : amount
		};
	}

	/**
	 * The name a line goes by outside the section. Mirrors
	 * Lines::public_name().
	 *
	 * @param {Object} line Line.
	 * @return {string}
	 */
	function publicName(line) {
		var name = (line.name || '').trim();
		if (line.kind === 'credit') {
			return name === '' ? 'Credit' : 'Credit: ' + name;
		}
		if (line.kind === 'discount') {
			return amountCents(line) > 0 ? 'Adjustment' : 'Discount';
		}
		return name;
	}

	/**
	 * One line as the description prints it. Mirrors Lines::line_text().
	 *
	 * @param {Object} line Line.
	 * @return {string}
	 */
	function lineText(line) {
		var name = publicName(line);
		if (line.kind === 'note') {
			return name;
		}
		var c = amountCents(line);
		if (line.kind === 'credit' || (line.kind === 'discount' && c <= 0)) {
			return name + ' ' + DASH + ' -$' + moneyText(c / 100);
		}
		if (line.kind === 'discount') {
			return name + ' x1 ' + DASH + ' $' + moneyText(c / 100);
		}
		var out = name + ' x' + Math.max(1, parseInt(line.qty, 10) || 1);
		if (line.unit_price !== null && line.unit_price !== undefined && line.unit_price !== '' && isFinite(Number(line.unit_price))) {
			out += ' ' + DASH + ' $' + moneyText(Number(line.unit_price));
		}
		return out;
	}

	/**
	 * The rows of the theme's JSON. Mirrors Lines::theme_rows().
	 *
	 * @param {Object[]} lines Lines (with the discount line).
	 * @return {Object[]}
	 */
	function themeRows(lines) {
		var rows = [];
		(lines || []).forEach(function (line) {
			var name = publicName(line);
			if (name === '') {
				return;
			}
			if (line.kind === 'note') {
				rows.push({ name: name, qty: 1, price: null, kind: 'note' });
				return;
			}
			var c = amountCents(line);
			if (line.kind === 'credit' || line.kind === 'discount') {
				rows.push({ name: name, qty: 1, price: fromCents(c), kind: line.kind === 'discount' && c > 0 ? 'fish' : 'credit' });
				return;
			}
			var priced = line.unit_price !== null && line.unit_price !== undefined && line.unit_price !== '' && isFinite(Number(line.unit_price));
			var row = {
				name: name,
				qty: Math.max(1, parseInt(line.qty, 10) || 1),
				price: priced ? fromCents(cents(Number(line.unit_price))) : null,
				kind: 'fish'
			};
			if (line.take_stock && line.source === 'product' && line.product_id) {
				row.product_id = line.variation_id ? line.variation_id : line.product_id;
			}
			rows.push(row);
		});
		return rows;
	}

	/**
	 * Fill a format's {handle} and {date}.
	 *
	 * @param {string} format Format.
	 * @param {string} handle Handle.
	 * @param {string} date   The date text.
	 * @return {string}
	 */
	function fill(format, handle, date) {
		return String(format).split('{handle}').join(handle).split('{date}').join(date).replace(/\s+/g, ' ').trim();
	}

	/**
	 * The product title. Mirrors Format::title() (the date is handed in
	 * as text: the server knows the site's today).
	 *
	 * @param {string} format Title format.
	 * @param {string} handle Handle.
	 * @param {string} date   The date as n/j/y.
	 * @return {string}
	 */
	function title(format, handle, date) {
		var f = typeof format === 'string' && format.indexOf('{handle}') !== -1 ? format : '{handle} Invoice ({date})';
		return fill(f, handle, date);
	}

	/**
	 * The short description. Mirrors Format::excerpt().
	 *
	 * @param {string} format Excerpt format.
	 * @param {string} handle Handle.
	 * @return {string}
	 */
	function excerpt(format, handle) {
		var f = typeof format === 'string' && format.indexOf('{handle}') !== -1 ? format : '{handle} Only';
		return fill(f, handle, '');
	}

	/**
	 * A Y-m-d date as n/j/y. Mirrors Format::date_njy().
	 *
	 * @param {string} ymd Y-m-d.
	 * @return {string}
	 */
	function dateNjy(ymd) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(typeof ymd === 'string' ? ymd : '');
		if (!m) {
			return '';
		}
		var y = parseInt(m[1], 10);
		var mo = parseInt(m[2], 10);
		var d = parseInt(m[3], 10);
		var days = [31, (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
		if (mo < 1 || mo > 12 || d < 1 || d > days[mo - 1]) {
			return '';
		}
		return mo + '/' + d + '/' + m[1].slice(2);
	}

	/**
	 * %s / %1$s placeholders filled in.
	 *
	 * @param {string} template Text.
	 * @return {string}
	 */
	function sprintf(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var next = 0;
		return String(template).replace(/%(?:(\d+)\$)?[sd]/g, function (match, n) {
			var index = n ? parseInt(n, 10) - 1 : next++;
			return args[index] === undefined ? '' : String(args[index]);
		});
	}

	var api = {
		cents: cents,
		fromCents: fromCents,
		money: money,
		moneyText: moneyText,
		dollars: dollars,
		text: text,
		normalizeLine: normalizeLine,
		normalize: normalize,
		amountCents: amountCents,
		withFlatTotal: withFlatTotal,
		publicName: publicName,
		lineText: lineText,
		themeRows: themeRows,
		title: title,
		excerpt: excerpt,
		dateNjy: dateNjy,
		sprintf: sprintf
	};

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}

	if (!$ || typeof fhInvoices === 'undefined') {
		return;
	}

	/* ============================================================== */
	/*  Page: shared                                                   */
	/* ============================================================== */

	var i18n = fhInvoices.i18n || {};

	function t(key) {
		return i18n[key] || key;
	}

	/**
	 * POST one of the section's AJAX operations.
	 *
	 * @param {string} op   Operation.
	 * @param {Object} data Fields.
	 * @return {jqXHR}
	 */
	function post(op, data) {
		return $.ajax({
			url: fhInvoices.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend({ action: 'fishotel_invoices_' + op, nonce: fhInvoices.nonce }, data || {})
		});
	}

	/**
	 * The data of a reply, whether it succeeded or was refused.
	 *
	 * @param {Object} reply A JSON body or a jqXHR.
	 * @return {Object}
	 */
	function replyData(reply) {
		var body = reply && reply.responseJSON ? reply.responseJSON : reply;
		return body && body.data && typeof body.data === 'object' ? body.data : {};
	}

	function replyMessage(reply) {
		var data = replyData(reply);
		return data.message ? String(data.message) : t('error');
	}

	function setStatus($el, message, kind) {
		$el.text(message || '').removeClass('is-error is-ok').addClass(kind === 'error' ? 'is-error' : kind === 'ok' ? 'is-ok' : '');
	}

	/**
	 * Copy text to the clipboard, saying so next to the button.
	 *
	 * @param {string} value   Text.
	 * @param {jQuery} $button The button pressed.
	 */
	function copy(value, $button) {
		var done = function (ok) {
			var was = $button.data('label') || $button.text();
			$button.data('label', was).text(ok ? t('copied') : t('copyFailed'));
			setTimeout(function () {
				$button.text(was);
			}, ok ? 1500 : 4000);
		};
		if (root.navigator && root.navigator.clipboard && root.navigator.clipboard.writeText) {
			root.navigator.clipboard.writeText(value).then(function () {
				done(true);
			}, function () {
				done(false);
			});
			return;
		}
		var $area = $('<textarea>').val(value).css({ position: 'fixed', top: 0, left: '-9999px' }).appendTo(document.body);
		$area[0].select();
		var ok = false;
		try {
			ok = document.execCommand('copy');
		} catch (e) {
			ok = false;
		}
		$area.remove();
		done(ok);
	}

	$(document).on('click', '.fh-inv-copy', function () {
		var $button = $(this);
		var value = $button.attr('data-copy') || '';
		if (value !== '') {
			copy(value, $button);
		}
	});

	$(document).on('change', '.fh-inv-check-all', function () {
		$(this).closest('table').find('.fh-inv-pick').prop('checked', this.checked);
	});

	function picked() {
		return $('.fh-inv-pick:checked').map(function () {
			return this.value;
		}).get();
	}

	/* ============================================================== */
	/*  Page: the list and one invoice                                 */
	/* ============================================================== */

	$(document).on('click', '.fh-inv-forum-picked', function () {
		var ids = picked();
		if (!ids.length) {
			setStatus($(this).siblings('.fh-inv-status'), t('pickSome'), 'error');
			return;
		}
		root.location.href = String($(this).attr('data-url')).replace('__IDS__', ids.join(','));
	});

	$(document).on('click', '.fh-inv-edit-unpaid', function () {
		if (root.confirm(t('confirmEdit'))) {
			root.location.href = $(this).attr('data-url');
		}
	});

	$(document).on('click', '.fh-inv-view .fh-inv-discard, .fh-inv-view .fh-inv-trash', function () {
		var $button = $(this);
		var trash = $button.hasClass('fh-inv-trash');
		var $status = $button.siblings('.fh-inv-status');
		if (!root.confirm(trash ? t('confirmTrash') : t('confirmDiscard'))) {
			return;
		}
		$button.prop('disabled', true);
		setStatus($status, t('saving'));
		post(trash ? 'trash' : 'discard', { id: $button.attr('data-id') }).done(function (reply) {
			setStatus($status, replyMessage(reply), 'ok');
			root.location.reload();
		}).fail(function (xhr) {
			$button.prop('disabled', false);
			setStatus($status, replyMessage(xhr), 'error');
		});
	});

	/* ============================================================== */
	/*  Page: the forum list                                           */
	/* ============================================================== */

	(function () {
		var $page = $('#fh-inv-forum');
		if (!$page.length) {
			return;
		}
		var $text = $('#fh-inv-forum-text');
		var $status = $('#fh-inv-forum-status');
		var $copy = $('#fh-inv-forum-copy');
		var $skipped = $('#fh-inv-forum-skipped');

		function build(data) {
			setStatus($status, t('saving'));
			post('forum', data).done(function (reply) {
				var out = replyData(reply);
				$text.val(out.text || '');
				$copy.prop('disabled', !out.text);
				$skipped.empty();
				(out.skipped || []).forEach(function (line) {
					$('<li>').text(line).appendTo($skipped);
				});
				setStatus($status, out.text ? '' : t('noResults'), out.text ? '' : 'error');
			}).fail(function (xhr) {
				setStatus($status, replyMessage(xhr), 'error');
			});
		}

		$('#fh-inv-forum-today').on('click', function () {
			build({ today: '1' });
		});
		$('#fh-inv-forum-picked').on('click', function () {
			var ids = picked();
			if (!ids.length) {
				setStatus($status, t('pickSome'), 'error');
				return;
			}
			build({ ids: ids });
		});
		$copy.on('click', function () {
			copy($text.val(), $copy);
		});
		if ($page.attr('data-auto') === '1' && picked().length) {
			build({ ids: picked() });
		}
	})();

	/* ============================================================== */
	/*  Page: Settings                                                 */
	/* ============================================================== */

	(function () {
		var $form = $('#fh-inv-settings');
		if (!$form.length) {
			return;
		}
		var $status = $('#fh-inv-settings-status');
		var $notes = $('#fh-inv-settings-notes');

		$form.on('submit', function (event) {
			event.preventDefault();
			setStatus($status, t('saving'));
			$.ajax({
				url: fhInvoices.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: $form.serialize() + '&action=fishotel_invoices_settings&nonce=' + encodeURIComponent(fhInvoices.nonce)
			}).done(function (reply) {
				var out = replyData(reply);
				setStatus($status, out.message || t('saved'), 'ok');
				$notes.empty();
				(out.notes || []).forEach(function (line) {
					$('<li>').text(line).appendTo($notes);
				});
				if (!(out.notes || []).length) {
					root.location.reload();
				}
			}).fail(function (xhr) {
				setStatus($status, replyMessage(xhr), 'error');
			});
		});

		$form.on('click', '.fh-inv-media', function () {
			var $input = $(this).closest('.fh-inv-image').find('input[type="number"]');
			if (!root.wp || !wp.media) {
				$input.trigger('focus');
				return;
			}
			var frame = wp.media({ multiple: false, library: { type: 'image' } });
			frame.on('select', function () {
				var chosen = frame.state().get('selection').first();
				if (chosen) {
					$input.val(chosen.id);
				}
			});
			frame.open();
		});
	})();

	/* ============================================================== */
	/*  Page: the picker                                               */
	/* ============================================================== */

	(function () {
		var $editor = $('#fh-inv-editor');
		var $stateEl = $('#fh-inv-state');
		if (!$editor.length || !$stateEl.length) {
			return;
		}

		var state;
		try {
			state = JSON.parse($stateEl.text());
		} catch (e) {
			return;
		}

		var update = state.mode === 'update';
		var dirty = false;
		var busy = false;
		var done = false;
		var check = null; // { token, snapshot, blocked }
		var $lines = $('#fh-inv-lines');
		var $status = $('#fh-inv-status');
		var $results = $('#fh-inv-results');
		var $panel = $('#fh-inv-precheck-panel');
		var $publish = $('#fh-inv-publish');
		var $publishStatus = $('#fh-inv-publish-status');

		/** A stored price as the text an input holds. */
		function priceText(value) {
			if (value === null || value === undefined || value === '') {
				return '';
			}
			var n = Number(value);
			if (!isFinite(n)) {
				return String(value);
			}
			return cents(n) % 100 === 0 ? String(Math.round(n)) : n.toFixed(2);
		}

		function adopt(invoice) {
			state = invoice;
			state.lines = (state.lines || []).map(function (line) {
				line.unit_price = priceText(line.unit_price);
				return line;
			});
		}
		adopt(state);

		/** What the form holds, as the server takes it. */
		function payload() {
			return {
				id: state.id,
				version: state.version,
				mode: state.mode,
				handle: $('#fh-inv-handle').val(),
				forum_user_id: $('#fh-inv-forum').val(),
				customer_id: state.customer_id || 0,
				customer_name: $('#fh-inv-name').val(),
				customer_email: $('#fh-inv-email').val(),
				shipping: $('input[name="fh-inv-shipping"]:checked').val() || 'premium',
				shipping_slug: $('#fh-inv-shipping-slug').val() || '',
				flat_on: $('#fh-inv-flat-on').prop('checked'),
				flat_total: $('#fh-inv-flat').val(),
				notes: $('#fh-inv-notes').val(),
				lines: state.lines.map(function (line) {
					return {
						id: line.id || 0,
						kind: line.kind,
						name: line.name,
						qty: line.qty,
						unit_price: line.unit_price,
						product_id: line.product_id || 0,
						variation_id: line.variation_id || 0,
						species_id: line.species_id || 0,
						stay_id: line.stay_id || 0,
						size_slug: line.size_slug || '',
						size_label: line.size_label || '',
						source: line.source || 'typed',
						take_stock: !!line.take_stock
					};
				})
			};
		}

		/** The content only — what a pre-check is good for. */
		function snapshot() {
			var p = payload();
			delete p.id;
			delete p.version;
			delete p.notes;
			p.lines = p.lines.map(function (line) {
				delete line.id;
				return line;
			});
			return JSON.stringify(p);
		}

		function flat() {
			return $('#fh-inv-flat-on').prop('checked') ? $('#fh-inv-flat').val() : null;
		}

		function sourceLabel(line) {
			if (line.kind !== 'fish') {
				return '';
			}
			return { tank: t('fromTank'), product: t('fromProduct'), species: t('fromSpecies') }[line.source] || t('fromTyped');
		}

		/** What is worth saying under a line right now. */
		function warnings(line) {
			var out = [];
			if (line.kind !== 'fish') {
				return out;
			}
			var qty = Math.max(1, parseInt(line.qty, 10) || 1);
			if (line.source === 'product' && line.gone) {
				out.push(t('productGone'));
			} else if (line.source === 'product' && line.managed && line.take_stock && line.stock !== null && line.stock !== undefined && Number(line.stock) < qty) {
				out.push(sprintf(t('stockLow'), line.stock, qty));
			}
			if (line.source === 'tank' && line.alive !== null && line.alive !== undefined && Number(line.alive) < qty) {
				out.push(sprintf(t('aliveLow'), line.alive, qty));
			}
			return out;
		}

		function lineRow(line, index) {
			var $row = $('<tr>').addClass('fh-inv-line fh-inv-line--' + line.kind).attr('data-index', index);
			var $main = $('<td>').appendTo($row);

			$('<span>').addClass('fh-inv-kind').text(t(line.kind)).appendTo($main);
			$('<input type="text">')
				.addClass('fh-inv-input fh-inv-line-name')
				.attr({ maxlength: MAX_NAME, 'aria-label': t(line.kind), placeholder: line.kind === 'credit' ? t('reason') : line.kind === 'note' ? t('noteText') : t('fishName') })
				.val(line.name)
				.appendTo($main);

			if (line.kind === 'fish') {
				var meta = [sourceLabel(line)];
				if (line.size_label) {
					meta.push(line.size_label);
				}
				if (line.detail) {
					meta.push(line.detail);
				}
				$('<div>').addClass('fh-inv-line-meta').text(meta.join(' · ')).appendTo($main);

				if (line.source === 'product' && line.managed) {
					var $take = $('<label>').addClass('fh-inv-take').appendTo($main);
					$('<input type="checkbox">').addClass('fh-inv-line-take').prop('checked', !!line.take_stock).appendTo($take);
					$take.append(document.createTextNode(' ' + t('takeStock')));
					$('<span>').addClass('fh-inv-line-stock').text(' · ' + sprintf(t('stockHas'), line.stock === null || line.stock === undefined ? '—' : line.stock)).appendTo($take);
				} else if (line.source === 'product') {
					$('<div>').addClass('fh-inv-line-meta').text(t('stockNone')).appendTo($main);
				}
			}
			$('<div>').addClass('fh-inv-line-warn').appendTo($main);

			var $qty = $('<td>').addClass('fh-inv-col-qty').appendTo($row);
			var $price = $('<td>').addClass('fh-inv-col-price').appendTo($row);
			if (line.kind === 'fish') {
				$('<button type="button">').addClass('button fh-inv-step').attr({ 'data-step': -1, 'aria-label': '−' }).text('−').appendTo($qty);
				$('<input type="number">').addClass('fh-inv-input fh-inv-line-qty').attr({ min: 1, max: MAX_QTY, step: 1, 'aria-label': 'Qty' }).val(line.qty).appendTo($qty);
				$('<button type="button">').addClass('button fh-inv-step').attr({ 'data-step': 1, 'aria-label': '+' }).text('+').appendTo($qty);
			}
			if (line.kind !== 'note') {
				$('<span>').addClass('fh-inv-dollar').text(line.kind === 'credit' ? '−$' : '$').appendTo($price);
				$('<input type="text">').addClass('fh-inv-input fh-inv-line-price').attr({ inputmode: 'decimal', 'aria-label': '$' }).val(line.unit_price).appendTo($price);
			}

			$('<td>').addClass('fh-inv-num fh-inv-line-amount').appendTo($row);

			var $tools = $('<td>').addClass('fh-inv-col-tools').appendTo($row);
			$('<button type="button">').addClass('button-link fh-inv-move').attr({ 'data-move': -1, title: t('up'), 'aria-label': t('up') }).text('↑').appendTo($tools);
			$('<button type="button">').addClass('button-link fh-inv-move').attr({ 'data-move': 1, title: t('down'), 'aria-label': t('down') }).text('↓').appendTo($tools);
			$('<button type="button">').addClass('button-link fh-inv-remove').attr({ title: t('remove'), 'aria-label': t('remove') }).text('✕').appendTo($tools);

			return $row;
		}

		function renderLines() {
			$lines.empty();
			if (!state.lines.length) {
				$('<tr>').addClass('fh-inv-line fh-inv-line--empty').append($('<td>').attr('colspan', 5).text(t('noLines'))).appendTo($lines);
			}
			state.lines.forEach(function (line, index) {
				$lines.append(lineRow(line, index));
			});
			refresh();
		}

		/** Sums, warnings, the preview, and whether the pre-check still stands. */
		function refresh() {
			var normal = normalize(state.lines);
			var sum = withFlatTotal(normal, flat());

			$lines.children('.fh-inv-line').each(function () {
				var $row = $(this);
				var line = state.lines[parseInt($row.attr('data-index'), 10)];
				if (!line) {
					return;
				}
				var one = normalizeLine(line, 0);
				var $amount = $row.find('.fh-inv-line-amount');
				if (!one || one.kind === 'note') {
					$amount.text('');
				} else if (one.kind === 'fish' && one.unit_price === null) {
					$amount.text(t('noPrice')).addClass('fh-inv-warn');
				} else {
					$amount.text(dollars(amountCents(one) / 100)).removeClass('fh-inv-warn');
				}
				var typed = String(line.unit_price === null || line.unit_price === undefined ? '' : line.unit_price).trim();
				$row.find('.fh-inv-line-price').toggleClass('is-bad', typed !== '' && money(typed) === null);

				var $warn = $row.find('.fh-inv-line-warn').empty();
				warnings(line).forEach(function (message) {
					$('<div>').text(message).appendTo($warn);
				});
				if (line.error) {
					$('<div>').addClass('is-error').text(line.error).appendTo($warn);
				}
			});

			$('#fh-inv-subtotal').text(dollars(sum.subtotal));
			var hasDiscount = cents(sum.discount) !== 0;
			$('#fh-inv-discount-label').prop('hidden', !hasDiscount).text(cents(sum.discount) > 0 ? t('adjustment') : t('discount'));
			$('#fh-inv-discount').prop('hidden', !hasDiscount).text(dollars(sum.discount));
			$('#fh-inv-total').text(dollars(sum.total)).toggleClass('fh-inv-warn', cents(sum.total) < 0).attr('title', cents(sum.total) < 0 ? t('negativeTotal') : '');
			var flatTyped = String($('#fh-inv-flat').val() || '').trim();
			$('#fh-inv-flat').toggleClass('is-bad', $('#fh-inv-flat-on').prop('checked') && (flatTyped === '' || money(flatTyped) === null || money(flatTyped) < 0));

			// The preview.
			var handle = text($('#fh-inv-handle').val(), 120);
			$('#fh-inv-preview-title').text(update && state.title ? state.title : title(fhInvoices.titleFormat, handle || '…', fhInvoices.titleDate));
			$('#fh-inv-preview-excerpt').text(excerpt(fhInvoices.excerptFormat, handle || '…'));
			var $list = $('#fh-inv-preview-list').empty();
			sum.lines.forEach(function (line) {
				$('<li>').text(lineText(line)).appendTo($list);
			});
			$('#fh-inv-preview-price').text(dollars(sum.total));

			gate();
		}

		/** Publish is open only for exactly what was checked, with every match confirmed. */
		function gate() {
			if (!check) {
				return;
			}
			var stale = snapshot() !== check.snapshot;
			var open = $panel.find('.fh-inv-dup-box').length === $panel.find('.fh-inv-dup-box:checked').length;
			$publish.prop('disabled', busy || stale || check.blocked || !open);
			if (stale) {
				setStatus($publishStatus, t('changed'), 'error');
			} else if (!check.blocked && !open) {
				setStatus($publishStatus, t('confirmEach'));
			} else if (!busy) {
				setStatus($publishStatus, '');
			}
		}

		function touched() {
			dirty = true;
			refresh();
		}

		/* ---- the lines ---- */

		function lineOf(el) {
			return state.lines[parseInt($(el).closest('tr').attr('data-index'), 10)];
		}

		$lines.on('input change', '.fh-inv-line-name', function () {
			var line = lineOf(this);
			if (line) {
				line.name = this.value;
				delete line.error;
				touched();
			}
		});
		$lines.on('input change', '.fh-inv-line-qty', function () {
			var line = lineOf(this);
			if (line) {
				line.qty = Math.max(1, Math.min(MAX_QTY, parseInt(this.value, 10) || 1));
				touched();
			}
		});
		$lines.on('blur', '.fh-inv-line-qty', function () {
			var line = lineOf(this);
			if (line) {
				this.value = line.qty;
			}
		});
		$lines.on('click', '.fh-inv-step', function () {
			var line = lineOf(this);
			if (line) {
				line.qty = Math.max(1, Math.min(MAX_QTY, (parseInt(line.qty, 10) || 1) + parseInt($(this).attr('data-step'), 10)));
				$(this).siblings('.fh-inv-line-qty').val(line.qty);
				touched();
			}
		});
		$lines.on('input change', '.fh-inv-line-price', function () {
			var line = lineOf(this);
			if (line) {
				line.unit_price = this.value;
				delete line.error;
				touched();
			}
		});
		$lines.on('change', '.fh-inv-line-take', function () {
			var line = lineOf(this);
			if (line) {
				line.take_stock = this.checked;
				touched();
			}
		});
		$lines.on('click', '.fh-inv-remove', function () {
			var index = parseInt($(this).closest('tr').attr('data-index'), 10);
			state.lines.splice(index, 1);
			dirty = true;
			renderLines();
		});
		$lines.on('click', '.fh-inv-move', function () {
			var index = parseInt($(this).closest('tr').attr('data-index'), 10);
			var to = index + parseInt($(this).attr('data-move'), 10);
			if (to < 0 || to >= state.lines.length) {
				return;
			}
			var moved = state.lines.splice(index, 1)[0];
			state.lines.splice(to, 0, moved);
			dirty = true;
			renderLines();
		});

		function addLine(line, focus) {
			state.lines.push(line);
			dirty = true;
			renderLines();
			var $row = $lines.children().last();
			$row.find(focus).first().trigger('focus');
		}

		$('#fh-inv-add-credit').on('click', function () {
			addLine({ id: 0, kind: 'credit', name: '', qty: 1, unit_price: '' }, '.fh-inv-line-name');
		});
		$('#fh-inv-add-note').on('click', function () {
			addLine({ id: 0, kind: 'note', name: '', qty: 1, unit_price: '' }, '.fh-inv-line-name');
		});

		/* ---- the search box ---- */

		var searchTimer = null;
		var searchSeq = 0;

		function hideResults() {
			$results.prop('hidden', true).empty();
		}

		function pick(item) {
			var price = item.price === null || item.price === undefined ? '' : priceText(Math.abs(item.price));
			addLine({
				id: 0,
				kind: item.kind || 'fish',
				name: item.name,
				qty: item.qty || 1,
				unit_price: price,
				product_id: item.product_id || 0,
				variation_id: item.variation_id || 0,
				species_id: item.species_id || 0,
				stay_id: item.stay_id || 0,
				size_slug: item.size_slug || '',
				size_label: item.size_label || '',
				source: item.source || 'typed',
				take_stock: item.source === 'product' && !!item.managed,
				managed: !!item.managed,
				stock: item.stock,
				alive: item.alive === null || item.alive === undefined ? null : item.alive,
				detail: item.detail || ''
			}, price === '' && (item.kind || 'fish') !== 'note' ? '.fh-inv-line-price' : '.fh-inv-line-qty');
			$('#fh-inv-search').val('');
			hideResults();
		}

		function renderResults(groups) {
			$results.empty();
			var any = false;
			(groups || []).forEach(function (group) {
				var $group = $('<div>').addClass('fh-inv-results__group').appendTo($results);
				$('<div>').addClass('fh-inv-results__label').text(group.label).appendTo($group);
				if (group.note) {
					$('<div>').addClass('fh-inv-results__note').text(group.note).appendTo($group);
				}
				if (group.available && !group.items.length && !group.note) {
					$('<div>').addClass('fh-inv-results__none').text(t('noResults')).appendTo($group);
				}
				group.items.forEach(function (item) {
					any = true;
					var $item = $('<button type="button">').addClass('fh-inv-results__item').appendTo($group);
					$('<span>').addClass('fh-inv-results__name').text(item.name).appendTo($item);
					var detail = [item.detail, item.warn].filter(Boolean).join(' · ');
					if (detail) {
						$('<span>').addClass('fh-inv-results__detail').toggleClass('fh-inv-warn', !!item.warn).text(detail).appendTo($item);
					}
					$('<span>').addClass('fh-inv-results__price').text(item.price === null || item.price === undefined ? t('noPrice') : dollars(item.price)).appendTo($item);
					$item.on('click', function () {
						pick(item);
					});
				});
			});
			$results.prop('hidden', !(groups || []).length);
			return any;
		}

		$('#fh-inv-search').on('input', function () {
			var term = this.value;
			clearTimeout(searchTimer);
			if (term.trim().length < 2) {
				searchSeq++;
				hideResults();
				return;
			}
			searchTimer = setTimeout(function () {
				var seq = ++searchSeq;
				post('search', { term: term, invoice_id: state.id }).done(function (reply) {
					if (seq === searchSeq) {
						renderResults(replyData(reply).groups);
					}
				});
			}, 250);
		}).on('keydown', function (event) {
			if (event.key === 'Escape') {
				hideResults();
			} else if (event.key === 'ArrowDown' || event.key === 'Enter') {
				var $first = $results.find('.fh-inv-results__item').first();
				if ($first.length) {
					event.preventDefault();
					$first.trigger('focus');
				}
			}
		});
		$results.on('keydown', '.fh-inv-results__item', function (event) {
			var $items = $results.find('.fh-inv-results__item');
			var at = $items.index(this);
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				$items.eq(Math.min($items.length - 1, at + 1)).trigger('focus');
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				if (at === 0) {
					$('#fh-inv-search').trigger('focus');
				} else {
					$items.eq(at - 1).trigger('focus');
				}
			} else if (event.key === 'Escape') {
				hideResults();
				$('#fh-inv-search').trigger('focus');
			}
		});
		$(document).on('click', function (event) {
			if (!$(event.target).closest('.fh-inv-searchbox, .fh-inv-suggest-wrap').length) {
				hideResults();
				$('.fh-inv-suggest').remove();
			}
		});

		/* ---- the customer ---- */

		function suggest($input, op, key, label, choose) {
			var timer = null;
			var seq = 0;
			$input.on('input', function () {
				var term = this.value;
				var $wrap = $input.closest('.fh-inv-suggest-wrap');
				clearTimeout(timer);
				$wrap.find('.fh-inv-suggest').remove();
				if (term.trim().length < 2) {
					seq++;
					return;
				}
				timer = setTimeout(function () {
					var mine = ++seq;
					post(op, { term: term }).done(function (reply) {
						var rows = replyData(reply)[key] || [];
						$wrap.find('.fh-inv-suggest').remove();
						if (mine !== seq || !rows.length) {
							return;
						}
						var $list = $('<div>').addClass('fh-inv-suggest').appendTo($wrap);
						rows.forEach(function (row) {
							$('<button type="button">').addClass('fh-inv-suggest__item').text(label(row)).on('click', function () {
								$list.remove();
								choose(row);
								touched();
							}).appendTo($list);
						});
					});
				}, 250);
			});
		}

		function showCustomer(name, email) {
			var $linked = $('#fh-inv-customer-linked').empty();
			if (!state.customer_id) {
				$linked.prop('hidden', true);
				return;
			}
			$linked.prop('hidden', false);
			$('<span>').text('#' + state.customer_id + (name ? ' ' + name : '') + (email ? ' <' + email + '>' : '')).appendTo($linked);
			$('<button type="button">').addClass('button-link').text(t('unlink')).on('click', function () {
				state.customer_id = 0;
				showCustomer();
				touched();
			}).appendTo($linked);
		}

		if (!update) {
			suggest($('#fh-inv-handle'), 'handles', 'handles', function (row) {
				return row.handle + (row.customer_name ? ' — ' + row.customer_name : '');
			}, function (row) {
				$('#fh-inv-handle').val(row.handle);
				$('#fh-inv-forum').val(row.forum_user_id || '');
				$('#fh-inv-name').val(row.customer_name || '');
				$('#fh-inv-email').val(row.customer_email || '');
				state.customer_id = row.customer_id || 0;
				showCustomer(row.customer_name, row.customer_email);
			});
		}
		suggest($('#fh-inv-customer-search'), 'customers', 'customers', function (row) {
			return row.name + ' <' + row.email + '>';
		}, function (row) {
			state.customer_id = row.id;
			$('#fh-inv-customer-search').val('');
			if (!$('#fh-inv-name').val()) {
				$('#fh-inv-name').val(row.name);
			}
			if (!$('#fh-inv-email').val()) {
				$('#fh-inv-email').val(row.email);
			}
			showCustomer(row.name, row.email);
		});
		showCustomer(state.customer_name, state.customer_email);

		/* ---- the other fields ---- */

		$editor.on('input change', '#fh-inv-handle, #fh-inv-forum, #fh-inv-name, #fh-inv-email, #fh-inv-flat, #fh-inv-notes, #fh-inv-shipping-slug', touched);
		$editor.on('change', '#fh-inv-flat-on', function () {
			$('#fh-inv-flat').prop('disabled', !this.checked);
			if (this.checked) {
				$('#fh-inv-flat').trigger('focus');
			}
			touched();
		});
		$editor.on('change', 'input[name="fh-inv-shipping"]', function () {
			$('#fh-inv-shipping-slug').prop('disabled', this.value !== 'other');
			touched();
		});

		/* ---- save, pre-check, publish ---- */

		function lock(on) {
			busy = on;
			$('#fh-inv-save, #fh-inv-precheck, #fh-inv-discard').prop('disabled', on);
			gate();
		}

		function follow(data) {
			if (data.id) {
				state.id = data.id;
			}
			if (data.version) {
				state.version = data.version;
			}
			if (data.invoice) {
				adopt(data.invoice);
				renderLines();
			}
			if (state.id && !update) {
				$('#fh-inv-discard').prop('hidden', false);
				if (root.history && root.history.replaceState) {
					root.history.replaceState(null, '', String(fhInvoices.editUrl).replace('__ID__', state.id));
				}
			}
		}

		function showReport(data) {
			if (!data.html) {
				return;
			}
			$('#fh-inv-precheck-body').html(data.html);
			$panel.prop('hidden', false);

			// A line the server refuses is marked on its row.
			state.lines.forEach(function (line) {
				delete line.error;
			});
			var errors = data.lineErrs || {};
			var at = 0;
			state.lines.forEach(function (line) {
				var one = normalizeLine(line, 0);
				if (!one || one.kind === 'discount') {
					return;
				}
				if (errors[at] !== undefined) {
					line.error = errors[at];
				}
				at++;
			});

			check = { token: data.token || '', snapshot: snapshot(), blocked: !!data.blocked };
			refresh();
		}

		$('#fh-inv-save').on('click', function () {
			if (busy) {
				return;
			}
			if (!text($('#fh-inv-handle').val(), 120)) {
				setStatus($status, t('handleFirst'), 'error');
				$('#fh-inv-handle').trigger('focus');
				return;
			}
			lock(true);
			setStatus($status, t('saving'));
			post('save', { payload: JSON.stringify(payload()) }).done(function (reply) {
				var data = replyData(reply);
				follow(data);
				dirty = false;
				setStatus($status, data.message || t('saved'), 'ok');
			}).fail(function (xhr) {
				follow(replyData(xhr));
				setStatus($status, replyMessage(xhr), 'error');
			}).always(function () {
				lock(false);
			});
		});

		$('#fh-inv-precheck').on('click', function () {
			if (busy) {
				return;
			}
			if (!text($('#fh-inv-handle').val(), 120)) {
				setStatus($status, t('handleFirst'), 'error');
				$('#fh-inv-handle').trigger('focus');
				return;
			}
			lock(true);
			setStatus($status, t('checking'));
			post('precheck', { payload: JSON.stringify(payload()) }).done(function (reply) {
				var data = replyData(reply);
				follow(data);
				if (!update) {
					dirty = false;
				}
				setStatus($status, '');
				showReport(data);
				if ($panel[0].scrollIntoView) {
					$panel[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			}).fail(function (xhr) {
				follow(replyData(xhr));
				setStatus($status, replyMessage(xhr), 'error');
			}).always(function () {
				lock(false);
			});
		});

		$panel.on('change', '.fh-inv-dup-box', gate);

		$publish.on('click', function () {
			if (busy || !check || $publish.prop('disabled')) {
				return;
			}
			var confirmed = $panel.find('.fh-inv-dup-box:checked').map(function () {
				return this.value;
			}).get();
			var data = update
				? { payload: JSON.stringify(payload()), token: check.token, confirmed: confirmed }
				: { id: state.id, token: check.token, confirmed: confirmed };

			lock(true);
			setStatus($publishStatus, update ? t('saving') : t('publishing'));
			post(update ? 'update' : 'publish', data).done(function (reply) {
				var out = replyData(reply);
				done = true;
				dirty = false;
				check = null;
				$panel.prop('hidden', true);
				$('.fh-inv-actionbar').first().prop('hidden', true);
				$editor.find('input, select, textarea, button').not('#fh-inv-done *').prop('disabled', true);

				$('#fh-inv-done-title').text((out.message || '') + ' ' + (out.title || '') + ' ' + DASH + ' ' + dollars(out.total));
				$('#fh-inv-done-link').val(out.url || '');
				$('#fh-inv-done-copy').attr('data-copy', out.url || '');
				$('#fh-inv-done-view').attr('href', out.viewUrl || String(fhInvoices.viewUrl).replace('__ID__', out.id));
				$('#fh-inv-done-forum').attr('href', String(fhInvoices.forumUrl).replace('__IDS__', out.id));
				var $warnings = $('#fh-inv-done-warnings').empty();
				(out.warnings || []).forEach(function (line) {
					$('<li>').text(line).appendTo($warnings);
				});
				$('#fh-inv-done').prop('hidden', false);
				$('#fh-inv-done-link').trigger('focus').trigger('select');
				if ($('#fh-inv-done')[0].scrollIntoView) {
					$('#fh-inv-done')[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
			}).fail(function (xhr) {
				var out = replyData(xhr);
				follow(out);
				lock(false);
				showReport(out);
				setStatus($publishStatus, replyMessage(xhr), 'error');
				if (out.code === 'precheck') {
					check = null;
					$publish.prop('disabled', true);
				}
			}).always(function () {
				if (!done) {
					lock(false);
				}
			});
		});

		$('#fh-inv-discard').on('click', function () {
			if (busy || !state.id || !root.confirm(t('confirmDiscard'))) {
				return;
			}
			lock(true);
			post('discard', { id: state.id }).done(function () {
				dirty = false;
				root.location.href = fhInvoices.listUrl;
			}).fail(function (xhr) {
				lock(false);
				setStatus($status, replyMessage(xhr), 'error');
			});
		});

		root.addEventListener('beforeunload', function (event) {
			if (dirty && !done) {
				event.preventDefault();
				event.returnValue = t('leave');
			}
		});

		renderLines();
	})();
})(this, typeof jQuery !== 'undefined' ? jQuery : null);
