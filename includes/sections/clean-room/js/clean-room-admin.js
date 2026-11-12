/**
 * Clean Room admin — the settings save, the protocol editor, the layout
 * editor, the alias list, the box-rates editor, the Stays-tab edits
 * (counts, dates, a move with an optional reason, the fields incl. the
 * check-in date), the Events-tab Undo, the Coming Soon release button,
 * the order editor (lines, species suggestions, Save with the recomputed
 * figures, Check in, Delete, the import-fees suggestion; the Boxes card,
 * which adds the lines' percents of a box up per box number as they are
 * typed, the "suggested N%" chips from the remembered box shares and the
 * "How full" line), the Species tab (inline edit, add, delete, the
 * product link), Settings → Sizes and Box shares, and the "suggested"
 * fill of the sale-date pickers (order page, product page).
 *
 * The server renders every row; this file posts and fills in. The pure
 * helpers are exported for the node test; the rest needs jQuery and the
 * page. The box helpers mirror the PHP exactly — boxPct() / boxNo() /
 * boxState() / boxSummary() are Costing::box_pct_in() / box_no_in() /
 * box_state() / box_summary(), boxSummaryRows() / boxTotalText() are
 * Admin_Workbook's, findShare() / suggestPct() / suggestFor() /
 * howFull() and their texts are Box_Shares' — and both sides are tested
 * against tests/fixtures/box-maths.json.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

/* global jQuery, module */
(function (root, $) {
	'use strict';

	/**
	 * Renumber the prefix[n][…] names of a table so the posted array is
	 * dense after rows were removed (or a template row's __i__ was added).
	 *
	 * @param {string[]} names  The input names in row order.
	 * @param {number}   row    The row index to stamp.
	 * @param {string}   prefix The array name (default 'steps').
	 * @return {string[]}
	 */
	function renumber(names, row, prefix) {
		var p = prefix || 'steps';
		var re = new RegExp('^' + p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\[(\\d+|__i__)\\]');
		return (names || []).map(function (name) {
			return String(name).replace(re, p + '[' + row + ']');
		});
	}

	/**
	 * The import & re-ox fees a box count suggests: boxes × (per box +
	 * re-ox) for the supplier / origin (case-insensitive; a rate with no
	 * origin stands for every origin of the supplier), or null when no
	 * rate is known or there is no box count. Mirrors
	 * Settings::suggest_import_fees().
	 *
	 * @param {Object[]} rates    {supplier, origin, per_box, reox}.
	 * @param {string}   supplier Supplier.
	 * @param {string}   origin   Origin.
	 * @param {*}        boxes    Boxes.
	 * @return {number|null}
	 */
	function suggestImportFees(rates, supplier, origin, boxes) {
		var s = String(supplier || '').trim().toLowerCase();
		var o = String(origin || '').trim().toLowerCase();
		var n = parseInt(boxes, 10);
		if (!(n > 0)) {
			return null;
		}
		var exact = null;
		var fallback = null;
		(rates || []).forEach(function (rate) {
			if (!rate || String(rate.supplier || '').trim().toLowerCase() !== s) {
				return;
			}
			var ro = String(rate.origin || '').trim().toLowerCase();
			if (ro === o && !exact) {
				exact = rate;
			} else if (ro === '' && !fallback) {
				fallback = rate;
			}
		});
		var rate = exact || fallback;
		if (!rate) {
			return null;
		}
		var total = n * ((parseFloat(rate.per_box) || 0) + (parseFloat(rate.reox) || 0));
		return Math.round(total * 100) / 100;
	}

	/* ---------------------------------------------------------------- */
	/*  Percent of a box (mirrors Costing / Admin_Workbook / Box_Shares)  */
	/* ---------------------------------------------------------------- */

	var BOX_PCT_MAX = 400;
	var BOX_NO_MAX = 999;
	var BOX_FULL_MIN = 90;
	var BOX_FULL_MAX = 110;
	var NUMERIC = /^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i;

	/* The English templates; the page passes the translated table (cfg.i18n.box). */
	var BOX_STRINGS = {
		box: 'Box %1$s · %2$s%% · %3$s',
		unboxed: 'no box number · %1$s%% · lines: %2$d',
		under: 'under',
		full: 'about full',
		over: 'over',
		none: 'No line has a percent yet: every line uses its per-box count.',
		total: 'Boxes by the percents: %1$s · Boxes field: not set',
		totalField: 'Boxes by the percents: %1$s · Boxes field: %2$s',
		suggest1: 'suggested %1$s%% (from %2$d order)',
		suggestN: 'suggested %1$s%% (from %2$d orders)',
		howNone: 'How full: nothing to add up yet — no line has an ordered count.',
		howNothing: 'How full: nothing is remembered for this supplier and origin yet · lines with no remembered share yet: %1$d',
		howFull: 'How full: boxes for the ordered counts: about %1$s · lines with a remembered share or a typed %%: %2$d · lines with no remembered share yet: %3$d'
	};

	function strings(table) {
		var out = {};
		Object.keys(BOX_STRINGS).forEach(function (key) {
			out[key] = table && typeof table[key] === 'string' && table[key] ? table[key] : BOX_STRINGS[key];
		});
		return out;
	}

	/**
	 * sprintf for the templates above: %s / %d, %1$s / %2$d, %%.
	 *
	 * @param {string} template Template.
	 * @return {string}
	 */
	function fmt(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var next = 0;
		return String(template || '').replace(/%(?:(\d+)\$)?([sd%])/g, function (match, pos, type) {
			if (type === '%') {
				return '%';
			}
			var value = args[pos ? parseInt(pos, 10) - 1 : next++];
			if (typeof value === 'undefined' || value === null) {
				return '';
			}
			return type === 'd' ? String(parseInt(value, 10) || 0) : String(value);
		});
	}

	/**
	 * A number without trailing zeros, to two decimals (Costing::num()).
	 * Only ever given a whole number of hundredths, so it rounds as PHP does.
	 *
	 * @param {number} value Value.
	 * @return {string}
	 */
	function num(value) {
		var n = Number(value);
		if (value === null || typeof value === 'undefined' || !isFinite(n)) {
			return '';
		}
		var text = (Math.round(n * 100) / 100).toFixed(2);
		text = text.replace(/0+$/, '').replace(/\.$/, '');
		return text === '' || text === '-0' ? '0' : text;
	}

	/**
	 * A typed percent of a box: above 0 and at most 400, in whole
	 * hundredths — else null (Costing::box_pct_in()).
	 *
	 * @param {*} value Raw.
	 * @return {number|null}
	 */
	function boxPct(value) {
		if (value === null || typeof value === 'undefined' || typeof value === 'boolean') {
			return null;
		}
		var text = String(value).replace(/[% ]/g, '').replace(/^\s+|\s+$/g, '');
		if (text === '' || !NUMERIC.test(text)) {
			return null;
		}
		var n = parseFloat(text);
		if (!isFinite(n) || n <= 0 || n > BOX_PCT_MAX + 1) {
			return null;
		}
		var hundredths = Math.round(n * 100);
		if (hundredths < 1 || hundredths > BOX_PCT_MAX * 100) {
			return null;
		}
		return hundredths / 100;
	}

	/**
	 * A typed box number: a whole number from 1 to 999, else null
	 * (Costing::box_no_in()).
	 *
	 * @param {*} value Raw.
	 * @return {number|null}
	 */
	function boxNo(value) {
		if (value === null || typeof value === 'undefined' || typeof value === 'boolean') {
			return null;
		}
		var text = String(value).replace(/^\s+|\s+$/g, '');
		if (!/^\d{1,4}$/.test(text)) {
			return null;
		}
		var n = parseInt(text, 10);
		return n < 1 || n > BOX_NO_MAX ? null : n;
	}

	/**
	 * under / full (about full: 90 to 110) / over (Costing::box_state()).
	 *
	 * @param {number} pct The sum of a box's percents.
	 * @return {string}
	 */
	function boxState(pct) {
		var hundredths = Math.round(Number(pct) * 100);
		if (hundredths < BOX_FULL_MIN * 100) {
			return 'under';
		}
		return hundredths > BOX_FULL_MAX * 100 ? 'over' : 'full';
	}

	/**
	 * The percents added up per box number, the lines without a box
	 * number together, and the boxes they add up to beside the order's
	 * Boxes field (Costing::box_summary()).
	 *
	 * @param {Object[]} lines {box_no, box_pct}.
	 * @param {*}        boxes The order's Boxes field.
	 * @return {Object}
	 */
	function boxSummary(lines, boxes) {
		var byBox = {};
		var unboxed = 0;
		var unboxedN = 0;
		var total = 0;
		var count = 0;
		(lines || []).forEach(function (line) {
			var pct = line && typeof line === 'object' ? boxPct(line.box_pct) : null;
			if (pct === null) {
				return;
			}
			var hundredths = Math.round(pct * 100);
			total += hundredths;
			count++;
			var number = boxNo(line.box_no);
			if (number === null) {
				unboxed += hundredths;
				unboxedN++;
				return;
			}
			if (!byBox[number]) {
				byBox[number] = [0, 0];
			}
			byBox[number][0] += hundredths;
			byBox[number][1]++;
		});

		var out = Object.keys(byBox).map(function (key) { return parseInt(key, 10); }).sort(function (a, b) { return a - b; }).map(function (number) {
			return { box_no: number, pct: byBox[number][0] / 100, lines: byBox[number][1], state: boxState(byBox[number][0] / 100) };
		});

		var orderBoxes = null;
		if (boxes !== null && typeof boxes !== 'undefined' && typeof boxes !== 'boolean' && typeof boxes !== 'object') {
			var text = String(boxes).replace(/^\s+|\s+$/g, '');
			if (text !== '' && NUMERIC.test(text)) {
				orderBoxes = Math.max(0, Math.trunc(parseFloat(text)));
			}
		}

		return {
			boxes: out,
			unboxed: { pct: unboxed / 100, lines: unboxedN },
			lines: count,
			pct_total: total / 100,
			boxes_total: total / 10000,
			order_boxes: orderBoxes
		};
	}

	/**
	 * The Boxes card's chips (Admin_Workbook::box_summary_rows()).
	 *
	 * @param {Object} summary boxSummary().
	 * @param {Object} table   The translated strings (optional).
	 * @return {Object[]} {state, text}.
	 */
	function boxSummaryRows(summary, table) {
		var s = strings(table);
		var rows = ((summary && summary.boxes) || []).map(function (box) {
			var state = ['under', 'full', 'over'].indexOf(box.state) !== -1 ? box.state : 'under';
			return { state: state, text: fmt(s.box, String(parseInt(box.box_no, 10) || 0), num(box.pct), s[state]) };
		});
		if (summary && summary.unboxed && summary.unboxed.lines > 0) {
			rows.push({ state: 'unboxed', text: fmt(s.unboxed, num(summary.unboxed.pct), summary.unboxed.lines) });
		}
		return rows;
	}

	/**
	 * The Boxes card's total (Admin_Workbook::box_total_text()).
	 *
	 * @param {Object} summary boxSummary().
	 * @param {Object} table   The translated strings (optional).
	 * @return {string}
	 */
	function boxTotalText(summary, table) {
		var s = strings(table);
		if (!summary || !(summary.lines > 0)) {
			return s.none;
		}
		var boxes = num(Math.round(summary.pct_total) / 100);
		return summary.order_boxes === null || typeof summary.order_boxes === 'undefined' ? fmt(s.total, boxes) : fmt(s.totalField, boxes, String(summary.order_boxes));
	}

	/* A name as the PHP keys it (Species::key()): spaces collapsed, trimmed, ASCII lower-cased. */
	function nameKeyOf(name) {
		var text = String(name === null || typeof name === 'undefined' ? '' : name).replace(/[ \t\n\r\f\v]+/g, ' ').replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
		text = text.replace(/[A-Z]/g, function (c) { return c.toLowerCase(); });
		return text === '' ? '' : 'n:' + text.substring(0, 120);
	}

	function fishKeyOf(line) {
		var id = parseInt(line && line.species_id, 10) || 0;
		return id > 0 ? 's:' + id : nameKeyOf(line && line.species);
	}

	function sizeKeyOf(line) {
		var size = line && line.size_slug;
		return size === null || typeof size === 'undefined' ? '' : String(size).replace(/^\s+|\s+$/g, '');
	}

	function qty(value) {
		return Math.max(0, parseInt(value, 10) || 0);
	}

	/**
	 * The remembered share of a line among its location's shares: the fish
	 * by its species list id first, then by its name, with the same size
	 * (Box_Shares::find()).
	 *
	 * @param {Object[]} shares {fish_key, size_slug, pct_per_fish, order_count}.
	 * @param {Object}   line   {species_id, species, size_slug}.
	 * @return {Object|null}
	 */
	function findShare(shares, line) {
		var size = sizeKeyOf(line);
		var keys = [fishKeyOf(line), nameKeyOf(line && line.species)].filter(function (key, i, all) { return key !== '' && all.indexOf(key) === i; });
		for (var k = 0; k < keys.length; k++) {
			for (var i = 0; i < (shares || []).length; i++) {
				var share = shares[i];
				if (share && String(share.fish_key || '') === keys[k] && String(share.size_slug || '') === size) {
					return share;
				}
			}
		}
		return null;
	}

	/**
	 * The percent a share suggests for a line: per fish × the received
	 * count, or × the ordered count while nothing is received
	 * (Box_Shares::suggest()).
	 *
	 * @param {Object|null} share {pct_per_fish, order_count}.
	 * @param {Object}      line  {qty_received, qty_ordered}.
	 * @return {{pct: number, orders: number, count: number}|null}
	 */
	function suggestPct(share, line) {
		if (!share || typeof share !== 'object') {
			return null;
		}
		var received = parseInt(line && line.qty_received, 10) || 0;
		var count = received > 0 ? received : qty(line && line.qty_ordered);
		var perFish = parseFloat(share.pct_per_fish) || 0;
		if (count < 1 || perFish <= 0) {
			return null;
		}
		var hundredths = Math.min(BOX_PCT_MAX * 100, Math.round(perFish * count * 100));
		if (hundredths < 1) {
			return null;
		}
		return { pct: hundredths / 100, orders: Math.max(0, parseInt(share.order_count, 10) || 0), count: count };
	}

	/**
	 * The suggestion of a line that has no percent yet
	 * (Box_Shares::suggest_for()).
	 *
	 * @param {Object[]} shares The location's shares.
	 * @param {Object}   line   The line.
	 * @return {Object|null}
	 */
	function suggestFor(shares, line) {
		if (boxPct(line && line.box_pct) !== null) {
			return null;
		}
		return suggestPct(findShare(shares, line), line || {});
	}

	/**
	 * "suggested 12.5% (from 3 orders)" (Box_Shares::suggest_text()).
	 *
	 * @param {Object} suggestion suggestPct().
	 * @param {Object} table      The translated strings (optional).
	 * @return {string}
	 */
	function suggestText(suggestion, table) {
		var s = strings(table);
		var orders = parseInt(suggestion && suggestion.orders, 10) || 0;
		return fmt(orders === 1 ? s.suggest1 : s.suggestN, num(suggestion ? suggestion.pct : 0) || '0', orders);
	}

	/**
	 * How full the boxes of an order that has not arrived would be: the
	 * typed percents, else the remembered share × the ordered count
	 * (Box_Shares::how_full()).
	 *
	 * @param {Object[]} lines  {qty_ordered, box_pct, species_id, species, size_slug}.
	 * @param {Object[]} shares The location's shares.
	 * @return {Object}
	 */
	function howFull(lines, shares) {
		var sum = 0;
		var known = 0;
		var typed = 0;
		var unknown = 0;
		(lines || []).forEach(function (line) {
			if (!line || typeof line !== 'object') {
				return;
			}
			var ordered = qty(line.qty_ordered);
			if (ordered < 1) {
				return;
			}
			var pct = boxPct(line.box_pct);
			if (pct !== null) {
				sum += Math.round(pct * 100);
				typed++;
				return;
			}
			var share = findShare(shares, line);
			var perFish = share ? parseFloat(share.pct_per_fish) || 0 : 0;
			if (perFish <= 0) {
				unknown++;
				return;
			}
			sum += Math.round(perFish * ordered * 100);
			known++;
		});
		return { pct: sum / 100, boxes: sum / 10000, known: known, typed: typed, unknown: unknown, lines: known + typed + unknown };
	}

	/**
	 * The "How full" line (Box_Shares::how_full_text()).
	 *
	 * @param {Object} full  howFull().
	 * @param {Object} table The translated strings (optional).
	 * @return {string}
	 */
	function howFullText(full, table) {
		var s = strings(table);
		var unknown = parseInt(full && full.unknown, 10) || 0;
		var withShare = (parseInt(full && full.known, 10) || 0) + (parseInt(full && full.typed, 10) || 0);
		if (withShare === 0 && unknown === 0) {
			return s.howNone;
		}
		if (withShare === 0) {
			return fmt(s.howNothing, unknown);
		}
		return fmt(s.howFull, num(Math.round(Number(full.pct) || 0) / 100) || '0', withShare, unknown);
	}

	var api = {
		renumber: renumber,
		suggestImportFees: suggestImportFees,
		fmt: fmt,
		num: num,
		boxPct: boxPct,
		boxNo: boxNo,
		boxState: boxState,
		boxSummary: boxSummary,
		boxSummaryRows: boxSummaryRows,
		boxTotalText: boxTotalText,
		findShare: findShare,
		suggestPct: suggestPct,
		suggestFor: suggestFor,
		suggestText: suggestText,
		howFull: howFull,
		howFullText: howFullText
	};

	root.fhCleanRoomAdmin = root.fhCleanRoomAdmin || {};
	root.fhCleanRoomAdmin.helpers = api;

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}

	if (!$) {
		return;
	}

	var cfg = root.fhCleanRoomAdmin || {};
	var i18n = cfg.i18n || {};

	// "suggested <date>" beside a sale-date picker (the order page, the
	// product page): fills that picker — nothing is saved until Save /
	// Update, and the suggestion is never stored by itself.
	$(document).on('click', '.fh-cr-suggest-date', function (e) {
		e.preventDefault();
		var target = root.document.getElementById(String($(this).data('target') || ''));
		if (target) {
			target.value = String($(this).data('date') || '');
			$(target).trigger('change');
		}
	});

	function notice(cls, msg) {
		var $wrap = $('.fh-cr-wrap');
		if (!$wrap.length) {
			root.alert(msg);
			return;
		}
		$wrap.find('.fishotel-misc-notice--ajax').remove();
		$('<div class="fishotel-misc-notice fishotel-misc-notice--ajax ' + cls + '"></div>')
			.text(msg)
			.insertAfter($wrap.find('.fh-cr-tabs'))
			.delay(4000)
			.fadeOut(300, function () { $(this).remove(); });
	}

	function fail(res) {
		var msg = (res && res.data && res.data.message) || (res && res.responseJSON && res.responseJSON.data && res.responseJSON.data.message) || (i18n.error || 'Something went wrong.');
		notice('fishotel-misc-notice--error', msg);
	}

	function ajaxUrl() {
		return cfg.ajaxUrl || root.ajaxurl;
	}

	/**
	 * A suggestion dropdown under an input: posts the species action
	 * (op 'suggest' or 'products') after a pause and lists the items;
	 * mousedown on an item hands it to onPick (so the input's blur does
	 * not close the list first).
	 */
	function closeSuggest() {
		$('.fh-cr-suggest').remove();
	}

	function renderSuggest($input, items, onPick) {
		closeSuggest();
		var $list = $('<ul class="fh-cr-suggest"></ul>');
		if (!items.length) {
			$list.append($('<li class="fh-cr-suggest__empty"></li>').text(i18n.noMatch || 'No match.'));
		}
		items.forEach(function (item) {
			var $li = $('<li class="fh-cr-suggest__item"></li>');
			$li.append($('<strong></strong>').text(item.name || ''));
			if (item.sci) {
				$li.append(' ').append($('<em></em>').text(item.sci));
			}
			var tag = item.product_id ? '#' + item.product_id : (item.match === 'species' ? 'list' : (item.match || ''));
			if (item.species_id && item.product_id) {
				tag += ' · list';
			}
			$li.append(' ').append($('<span class="fh-cr-sub fh-cr-sub--inline"></span>').text(tag));
			$li.on('mousedown', function (e) {
				e.preventDefault();
				onPick($input, item);
				closeSuggest();
			});
			$list.append($li);
		});
		$input.after($list);
	}

	function attachSuggest($scope, selector, op, onPick) {
		var timer = null;
		$scope.on('input', selector, function () {
			var $input = $(this);
			var term = $.trim($input.val());
			root.clearTimeout(timer);
			if (term.length < 2) {
				closeSuggest();
				return;
			}
			timer = root.setTimeout(function () {
				$.post(ajaxUrl(), { action: 'fishotel_cleanroom_species', nonce: cfg.nonce, op: op, term: term })
					.done(function (res) {
						if (!res || !res.success || !$input.is(':focus')) {
							return;
						}
						renderSuggest($input, (res.data && res.data.items) || [], onPick);
					});
			}, 220);
		});
		$scope.on('keydown', selector, function (e) {
			if (e.key === 'Escape') {
				closeSuggest();
			}
		});
		$scope.on('blur', selector, function () {
			root.setTimeout(closeSuggest, 150);
		});
	}

	$(function () {
		// Settings form.
		$('#fishotel-cleanroom-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('button[type="submit"]').prop('disabled', true);
			$.post(ajaxUrl(), $form.serialize())
				.done(function (res) {
					if (!res || !res.success) {
						fail(res);
						return;
					}
					var dropped = res.data && res.data.dropped && res.data.dropped.length;
					notice(dropped ? 'fishotel-misc-notice--warning' : 'fishotel-misc-notice--success', res.data.message || (i18n.saved || 'Saved.'));
					if (res.data.settings) {
						var s = res.data.settings;
						$form.find('[name="sale_ready_days"]').val(s.sale_ready_days);
						$form.find('[name="copper_floor"]').val(s.copper_floor);
						$form.find('[name="copper_target"]').val(s.copper_target);
						$form.find('[name="auto_confirm_seconds"]').val(s.auto_confirm_seconds);
						$form.find('[name="voice_enabled"]').prop('checked', !!s.voice_enabled);
					}
				})
				.fail(fail)
				.always(function () { $btn.prop('disabled', false); });
			return false;
		});

		// Protocol editor.
		var $protocol = $('#fishotel-cleanroom-protocol');

		function renumberRows() {
			$('#fh-cr-protocol-table tbody tr').each(function (row) {
				$(this).find('[name]').each(function () {
					this.name = renumber([this.name], row)[0];
				});
			});
		}

		$protocol.on('click', '.fh-cr-step-remove', function () {
			$(this).closest('tr').remove();
			renumberRows();
		});

		$protocol.on('click', '.fh-cr-step-add', function () {
			var $rows = $('#fh-cr-protocol-table tbody tr');
			var $template = $rows.last();
			var $row;
			if ($template.length) {
				$row = $template.clone();
				$row.find('input[type="text"]').val('');
				$row.find('input[type="number"]').val('');
				$row.find('[name$="[day]"]').val(1);
				$row.find('[name$="[every]"]').val(0);
			} else {
				$row = $('<tr class="fh-cr-step">' +
					'<td><input type="text" class="fh-cr-input" name="steps[0][label]" maxlength="80"></td>' +
					'<td><select class="fh-cr-input" name="steps[0][kind]"><option value="feed">feed</option><option value="dose">dose</option><option value="test">test</option><option value="water_change">water change</option><option value="other" selected>other</option></select></td>' +
					'<td><input type="number" class="fh-cr-input fh-cr-input--num" name="steps[0][day]" min="1" max="120" value="1"></td>' +
					'<td><input type="number" class="fh-cr-input fh-cr-input--num" name="steps[0][every]" min="0" max="60" value="0"></td>' +
					'<td><input type="number" class="fh-cr-input fh-cr-input--num" name="steps[0][until]" min="1" max="120"></td>' +
					'<td><select class="fh-cr-input" name="steps[0][where]"><option value="any">anywhere</option><option value="spa">Spa</option><option value="clean">Clean Room</option></select></td>' +
					'<td><input type="text" class="fh-cr-input" name="steps[0][note]" maxlength="200"></td>' +
					'<td><button type="button" class="button button-small fh-cr-step-remove">&times;</button></td>' +
					'</tr>');
			}
			$('#fh-cr-protocol-table tbody').append($row);
			renumberRows();
			$row.find('input[type="text"]').first().focus();
		});

		$protocol.on('submit', function (e) {
			e.preventDefault();
			renumberRows();
			var $btn = $protocol.find('.fh-cr-protocol-save').prop('disabled', true);
			$.post(ajaxUrl(), $protocol.serialize())
				.done(function (res) {
					if (!res || !res.success) {
						fail(res);
						return;
					}
					notice('fishotel-misc-notice--success', res.data.message || (i18n.saved || 'Saved.'));
				})
				.fail(fail)
				.always(function () { $btn.prop('disabled', false); });
			return false;
		});

		/*
		 * The template operations (add / rename / delete / make default /
		 * reset): one post, then the page reloads on the template the server
		 * names so the picker, the editor and the layout's schedule selects
		 * agree. The picker itself just navigates.
		 */
		function templatePage(name) {
			var base = $protocol.data('page-url') || root.location.href.split('&template=')[0];
			return base + (base.indexOf('?') === -1 ? '?' : '&') + 'template=' + encodeURIComponent(name);
		}

		function templateOp(op, extra) {
			var fields = $.extend({ action: 'fishotel_cleanroom_protocol', _wpnonce: cfg.settingsNonce, op: op, template: $protocol.data('template') || '' }, extra || {});
			$protocol.find('.fh-cr-template-bar button').prop('disabled', true);
			$.post(ajaxUrl(), fields)
				.done(function (res) {
					if (!res || !res.success) {
						fail(res);
						$protocol.find('.fh-cr-template-bar button').prop('disabled', false);
						return;
					}
					notice('fishotel-misc-notice--success', (res.data && res.data.message) || (i18n.reloading || 'Saved. Reloading…'));
					root.setTimeout(function () { root.location.href = templatePage((res.data && res.data.template) || ''); }, 600);
				})
				.fail(function (res) {
					fail(res);
					$protocol.find('.fh-cr-template-bar button').prop('disabled', false);
				});
		}

		$protocol.on('change', '.fh-cr-template-pick', function () {
			root.location.href = templatePage(this.value);
		});

		$protocol.on('click', '.fh-cr-template-add', function () {
			var name = root.prompt(i18n.templateName || 'Name of the new template?', '');
			if (name === null || !$.trim(name)) {
				return;
			}
			templateOp('add', { name: $.trim(name) });
		});

		$protocol.on('click', '.fh-cr-template-rename', function () {
			var name = root.prompt(i18n.templateRename || 'New name?', $protocol.data('template') || '');
			if (name === null || !$.trim(name)) {
				return;
			}
			templateOp('rename', { name: $.trim(name) });
		});

		$protocol.on('click', '.fh-cr-template-default', function () {
			templateOp('make_default');
		});

		$protocol.on('click', '.fh-cr-template-delete', function () {
			if (!root.confirm(i18n.confirmTemplate || 'Delete this template? Buildings that run it go back to the default.')) {
				return;
			}
			templateOp('delete');
		});

		$protocol.on('click', '.fh-cr-protocol-reset', function () {
			if (!root.confirm(i18n.confirmReset || 'Reset?')) {
				return;
			}
			templateOp('reset');
		});

		// Box rates editor.
		var $rates = $('#fishotel-cleanroom-box-rates');

		function renumberRates() {
			$('#fh-cr-box-rates-table tbody tr').each(function (row) {
				$(this).find('[name]').each(function () {
					this.name = renumber([this.name], row, 'rates')[0];
				});
			});
		}

		$rates.on('click', '.fh-cr-rate-remove', function () {
			$(this).closest('tr').remove();
			renumberRates();
		});

		$rates.on('click', '.fh-cr-rate-add', function () {
			var $row = $('<tr class="fh-cr-rate">' +
				'<td><input type="text" class="fh-cr-input" name="rates[0][supplier]" maxlength="80"></td>' +
				'<td><input type="text" class="fh-cr-input" name="rates[0][origin]" maxlength="80"></td>' +
				'<td><input type="number" step="0.01" min="0" class="fh-cr-input fh-cr-input--num" name="rates[0][per_box]" value="0.00"></td>' +
				'<td><input type="number" step="0.01" min="0" class="fh-cr-input fh-cr-input--num" name="rates[0][reox]" value="0.00"></td>' +
				'<td><button type="button" class="button button-small fh-cr-rate-remove">&times;</button></td>' +
				'</tr>');
			$('#fh-cr-box-rates-table tbody').append($row);
			renumberRates();
			$row.find('input').first().focus();
		});

		$rates.on('submit', function (e) {
			e.preventDefault();
			renumberRates();
			var $btn = $rates.find('.fh-cr-box-rates-save').prop('disabled', true);
			$.post(ajaxUrl(), $rates.serialize())
				.done(function (res) {
					if (!res || !res.success) {
						fail(res);
						return;
					}
					notice('fishotel-misc-notice--success', res.data.message || (i18n.saved || 'Saved.'));
					cfg.boxRates = res.data.rates || [];
				})
				.fail(fail)
				.always(function () { $btn.prop('disabled', false); });
			return false;
		});

		// Settings → Sizes: which attribute is the size, or the plain list.
		$('#fishotel-cleanroom-sizes').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('.fh-cr-sizes-save').prop('disabled', true);
			$.post(ajaxUrl(), $form.serialize())
				.done(function (res) {
					if (!res || !res.success) {
						fail(res);
						return;
					}
					notice('fishotel-misc-notice--success', (res.data && res.data.message) || (i18n.saved || 'Saved.'));
					root.setTimeout(function () { root.location.reload(); }, 900);
				})
				.fail(fail)
				.always(function () { $btn.prop('disabled', false); });
			return false;
		});

		// Settings → Box shares: change one remembered percent per fish, or forget the row.
		function sharePost($row, fields, done) {
			fields.action = 'fishotel_cleanroom_box_share';
			fields._wpnonce = cfg.settingsNonce;
			fields.id = $row.data('share');
			var $buttons = $row.find('button').prop('disabled', true);
			$.post(ajaxUrl(), fields)
				.done(function (res) {
					if (!res || !res.success) {
						fail(res);
						return;
					}
					notice('fishotel-misc-notice--success', (res.data && res.data.message) || (i18n.saved || 'Saved.'));
					done(res.data || {});
				})
				.fail(fail)
				.always(function () { $buttons.prop('disabled', false); });
		}

		$(document).on('click', '.fh-cr-share-save', function () {
			var $row = $(this).closest('tr');
			sharePost($row, { op: 'save', pct_per_fish: $row.find('.fh-cr-share-value').val() }, function (data) {
				if (data.value) {
					$row.find('.fh-cr-share-value').val(data.value);
				}
			});
		});

		$(document).on('click', '.fh-cr-share-delete', function () {
			var $row = $(this).closest('tr');
			if (!root.confirm(i18n.confirmShare || 'Forget this remembered share?')) {
				return;
			}
			sharePost($row, { op: 'delete' }, function () { $row.remove(); });
		});

		// Layout editor (buildings / tanks).
		$(document).on('submit', '.fh-cr-layout-form', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $status = $('.fh-cr-layout-status');
			var data = $form.serializeArray();
			data.push({ name: 'action', value: 'fishotel_cleanroom_layout' });
			data.push({ name: '_wpnonce', value: cfg.settingsNonce });
			data.push({ name: 'op', value: $form.data('op') });
			$form.find('button').prop('disabled', true);
			$.post(ajaxUrl(), $.param(data))
				.done(function (res) {
					if (!res || !res.success) {
						$status.removeClass('is-ok').addClass('is-error').text((res && res.data && res.data.message) || (i18n.error || 'Something went wrong.'));
						return;
					}
					$status.removeClass('is-error').addClass('is-ok').text((res.data && res.data.message) || (i18n.saved || 'Saved.'));
					if (String($form.data('op')).indexOf('_add') !== -1) {
						root.setTimeout(function () { root.location.reload(); }, 600);
					}
				})
				.fail(function (res) {
					$status.removeClass('is-ok').addClass('is-error').text((res && res.responseJSON && res.responseJSON.data && res.responseJSON.data.message) || (i18n.error || 'Something went wrong.'));
				})
				.always(function () { $form.find('button').prop('disabled', false); });
			return false;
		});

		// Aliases.
		$(document).on('click', '.fh-cr-alias-delete', function () {
			var $row = $(this).closest('tr');
			if (!root.confirm(i18n.confirmAlias || 'Forget this alias?')) {
				return;
			}
			$.post(ajaxUrl(), { action: 'fishotel_cleanroom_alias_delete', _wpnonce: cfg.settingsNonce, id: $row.data('alias') })
				.done(function (res) {
					if (!res || !res.success) {
						fail(res);
						return;
					}
					$row.remove();
				})
				.fail(fail);
		});

		// Stays tab: quick edits.
		function stayPost(fields, $status) {
			fields.action = 'fishotel_cleanroom_stay';
			fields.nonce = cfg.nonce;
			$.post(ajaxUrl(), fields)
				.done(function (res) {
					if (!res || !res.success) {
						fail(res);
						return;
					}
					$status.removeClass('is-error').addClass('is-ok').text(i18n.reloading || 'Saved. Reloading…');
					root.setTimeout(function () { root.location.reload(); }, 600);
				})
				.fail(fail);
		}

		$(document).on('click', '.fh-cr-act', function () {
			var $row = $(this).closest('tr');
			var op = $(this).data('op');
			var stayId = $row.data('stay');
			var live = parseInt($row.data('live'), 10) || 0;
			var $status = $('.fh-cr-status').first();
			var fields = { op: op, stay_id: stayId };

			if (op === 'loss' || op === 'sold') {
				var qty = root.prompt((i18n.howMany || 'How many?') + ' (1–' + live + ')', '1');
				if (qty === null) {
					return;
				}
				fields.qty = parseInt(qty, 10) || 0;
			} else if (op === 'count') {
				var count = root.prompt(i18n.howMany || 'How many?', String(live));
				if (count === null) {
					return;
				}
				fields.qty = parseInt(count, 10);
				if (fields.qty === 0 && !root.confirm(i18n.confirmZero || 'Close this group?')) {
					return;
				}
			} else if (op === 'clear') {
				var date = root.prompt(i18n.which || 'Which date (YYYY-MM-DD)? Leave empty to clear.', '');
				if (date === null) {
					return;
				}
				fields.date = $.trim(date);
			} else if (op === 'ready') {
				// Pre-filled with the group's own date; empty when the batch has a date (OK keeps the batch's); else the suggestion (check-in + the setting). Nothing is saved until OK, and an unchanged date writes nothing.
				var batch = String($(this).data('batch') || '');
				var ask = batch ? (i18n.whichSaleBatch || 'This group\'s own sale date (YYYY-MM-DD)? Leave empty to use the batch date (%s).').replace('%s', batch) : (i18n.whichSale || 'This group\'s own sale date (YYYY-MM-DD)? Leave empty for none — the batch has no date yet.');
				var sale = root.prompt(ask, String($(this).data('default') || ''));
				if (sale === null) {
					return;
				}
				fields.date = $.trim(sale);
			} else if (op === 'move') {
				var to = root.prompt(i18n.whichTank || 'Move to which tank number?', String($(this).data('tank') || ''));
				if (to === null) {
					return;
				}
				fields.to_tank = parseInt(to, 10) || 0;
				var why = root.prompt(i18n.whyMove || 'Why? (optional: clear building / bullying / pairing / other)', '');
				if (why === null) {
					return;
				}
				fields.reason = $.trim(why).toLowerCase();
			}
			stayPost(fields, $status);
		});

		// Events tab: undo one event (a compensating event; refused a second time).
		$(document).on('click', '.fh-cr-undo', function () {
			var $row = $(this).closest('tr');
			var $status = $('.fh-cr-status').first();
			if (!root.confirm(i18n.confirmUndo || 'Undo this event?')) {
				return;
			}
			$(this).prop('disabled', true);
			stayPost({ op: 'undo', stay_id: $row.data('stay'), event_id: $row.data('event') }, $status);
		});

		$(document).on('click', '.fh-cr-fields-toggle', function () {
			var stayId = $(this).closest('tr').data('stay');
			var $fields = $('.fh-cr-fields[data-stay="' + stayId + '"]');
			$fields.prop('hidden', !$fields.prop('hidden'));
		});

		$(document).on('click', '.fh-cr-fields-save', function () {
			var $tr = $(this).closest('tr');
			var $status = $('.fh-cr-status').first();
			var fields = { op: 'fields', stay_id: $tr.data('stay') };
			$tr.find('input, select').each(function () { fields[this.name] = this.value; });
			var note = $.trim(fields.note || '');
			delete fields.note;
			stayPost(fields, $status);
			if (note) {
				$.post(ajaxUrl(), { action: 'fishotel_cleanroom_stay', nonce: cfg.nonce, op: 'note', stay_id: $tr.data('stay'), note: note });
			}
		});

		$(document).on('click', '.fh-cr-release', function () {
			var $row = $(this).closest('tr');
			var $status = $('.fh-cr-status').first();
			$.post(ajaxUrl(), { action: 'fishotel_cleanroom_release', nonce: cfg.nonce, stay_id: $row.data('stay') })
				.done(function (res) {
					if (!res || !res.success) {
						fail(res);
						return;
					}
					$status.removeClass('is-error').addClass('is-ok').text(res.data.message || (i18n.saved || 'Saved.'));
					notice('fishotel-misc-notice--success', res.data.message || (i18n.saved || 'Saved.'));
				})
				.fail(fail);
		});

		// The order editor.
		var $order = $('#fh-cr-order');
		if ($order.length) {
			var $lines = $('#fh-cr-lines tbody');
			var $orderStatus = $order.find('.fh-cr-status').first();
			var $boxes = $('#fh-cr-boxes');
			var boxStrings = i18n.box || {};
			var boxShares = [];
			try {
				boxShares = JSON.parse($boxes.attr('data-shares') || '[]') || [];
			} catch (err) {
				boxShares = [];
			}

			/* One row of the grid as the box helpers read it. */
			function lineOf($row) {
				var $received = $row.find('input[name$="[qty_received]"]');
				return {
					species_id: $row.find('input[name$="[species_id]"]').val(),
					species: $row.find('.fh-cr-species').val(),
					size_slug: $row.find('select[name$="[size_slug]"]').val() || '',
					qty_ordered: $row.find('input[name$="[qty_ordered]"]').val(),
					qty_received: $received.length ? $received.val() : $row.find('[data-received]').attr('data-received'),
					box_no: $row.find('input[name$="[box_no]"]').val(),
					box_pct: $row.find('input[name$="[box_pct]"]').val()
				};
			}

			/*
			 * The Boxes card, the percent total under the column, the "How
			 * full" line and the "suggested N%" chips — recalculated from the
			 * form as it stands (the server renders the same on load).
			 */
			function refreshBoxes() {
				if (!$boxes.length) {
					return;
				}
				var $rows = $lines.find('tr.fh-cr-line');
				var lines = $rows.map(function () { return lineOf($(this)); }).get();
				var summary = boxSummary(lines, $order.find('[name="boxes"]').val());
				var bag = $order.attr('data-mode') === 'bag';

				var $list = $boxes.find('[data-box="rows"]').empty();
				boxSummaryRows(summary, boxStrings).forEach(function (row) {
					$('<span class="fh-cr-chip"></span>').addClass('fh-cr-chip--box-' + row.state).text(row.text).appendTo($list);
					$list.append(' ');
				});
				$boxes.find('[data-box="total"]').text(boxTotalText(summary, boxStrings));
				$order.find('[data-box="pct_total"]').text(summary.lines > 0 ? num(summary.pct_total) + '%' : '');

				var status = String($order.find('[name="status"]').val() || '');
				var $full = $boxes.find('[data-box="how_full"]');
				if (status === 'draft' || status === 'ordered') {
					$full.prop('hidden', false).text(howFullText(howFull(lines, boxShares), boxStrings));
				} else {
					$full.prop('hidden', true).text('');
				}

				$rows.each(function (i) {
					var $hint = $(this).find('.fh-cr-pct-hint').empty();
					var suggestion = bag ? null : suggestFor(boxShares, lines[i]);
					if (suggestion) {
						$('<button type="button" class="button-link fh-cr-suggest-pct"></button>')
							.attr('data-pct', num(suggestion.pct))
							.text(suggestText(suggestion, boxStrings))
							.appendTo($hint);
					}
				});
			}

			/* The remembered shares belong to a supplier + origin: fetch them again when either changes. */
			function loadShares() {
				$.post(ajaxUrl(), {
					action: 'fishotel_cleanroom_order',
					nonce: cfg.nonce,
					op: 'shares',
					supplier: $order.find('[name="supplier"]').val(),
					origin: $order.find('[name="origin"]').val()
				}).done(function (res) {
					if (res && res.success && res.data && Array.isArray(res.data.shares)) {
						boxShares = res.data.shares;
						refreshBoxes();
					}
				});
			}

			$order.on('input change', 'input[name$="[box_pct]"], input[name$="[box_no]"], input[name$="[qty_ordered]"], input[name$="[qty_received]"], select[name$="[size_slug]"], .fh-cr-species, [name="boxes"], [name="status"]', refreshBoxes);
			$order.on('change', '[name="supplier"], [name="origin"]', loadShares);

			// A tap on "suggested N%" fills the percent; nothing is saved until Save.
			$order.on('click', '.fh-cr-suggest-pct', function () {
				$(this).closest('tr').find('input[name$="[box_pct]"]').val($(this).attr('data-pct')).trigger('input');
			});

			function renumberLines() {
				$lines.find('tr.fh-cr-line').each(function (row) {
					$(this).find('[name]').each(function () {
						this.name = renumber([this.name], row, 'lines')[0];
					});
					$(this).find('.fh-cr-line__num').text(row + 1);
				});
			}

			function fillFigures(fig, ids) {
				if (!fig) {
					return;
				}
				$lines.find('tr.fh-cr-line').each(function (i) {
					var f = (fig.lines || [])[i];
					var $row = $(this);
					if (ids && ids[i]) {
						$row.find('input[name$="[id]"]').val(ids[i]).end().attr('data-line', ids[i]);
					}
					if (!f) {
						return;
					}
					Object.keys(f).forEach(function (key) {
						$row.find('[data-f="' + key + '"]').text(f[key]);
					});
					$row.find('.fh-cr-worth').toggleClass('is-yes', !!f.worth_flag);
					$row.find('input[name$="[per_box]"]').attr('placeholder', f.per_box_used || '');
				});
				Object.keys(fig.order || {}).forEach(function (key) {
					$order.find('[data-t="' + key + '"]').text(fig.order[key]);
				});
				$order.find('[data-t="up_down"]').removeClass('fh-cr-up fh-cr-down').addClass('fh-cr-' + (fig.order.up_down_flag || 'up'));
			}

			/**
			 * Save the order; done(data) runs after a successful save of an
			 * existing order (a new one redirects), failed() after a refused
			 * or failed one.
			 */
			function saveOrder(done, failed) {
				$lines.find('tr.fh-cr-line').not('.is-linked').each(function () {
					if (!$.trim($(this).find('.fh-cr-species').val())) {
						$(this).remove();
					}
				});
				renumberLines();
				var data = $order.serializeArray();
				data.push({ name: 'action', value: 'fishotel_cleanroom_order' });
				data.push({ name: 'nonce', value: cfg.nonce });
				data.push({ name: 'op', value: 'save' });
				var $btn = $order.find('.fh-cr-order-save').prop('disabled', true);
				$orderStatus.removeClass('is-error is-ok').text('…');
				$.post(ajaxUrl(), $.param(data))
					.done(function (res) {
						if (!res || !res.success) {
							$orderStatus.addClass('is-error').text('');
							fail(res);
							if (failed) {
								failed();
							}
							return;
						}
						var d = res.data || {};
						if (d.redirect) {
							root.location.href = d.redirect;
							return;
						}
						fillFigures(d.figures, d.line_ids);
						if (Array.isArray(d.shares)) {
							boxShares = d.shares; // What this save taught: the chips of the lines still without a percent follow.
						}
						refreshBoxes();
						if (d.status) {
							$order.find('.fh-cr-order__status').text(d.status).attr('class', 'fh-cr-badge fh-cr-order__status fh-cr-badge--order-' + d.status);
						}
						if (typeof d.can_checkin !== 'undefined') {
							// Received counts typed and saved: the check-in is offered without a reload.
							$order.find('.fh-cr-checkin').prop('disabled', !d.can_checkin);
						}
						$orderStatus.addClass('is-ok').text(d.message || (i18n.saved || 'Saved.'));
						if (done) {
							done(d);
						}
					})
					.fail(function (res) {
						$orderStatus.addClass('is-error').text('');
						fail(res);
						if (failed) {
							failed();
						}
					})
					.always(function () { $btn.prop('disabled', false); });
			}

			$order.on('submit', function (e) {
				e.preventDefault();
				saveOrder();
				return false;
			});

			$order.on('change', '[name="cost_mode"]', function () {
				$order.attr('data-mode', this.value);
				refreshBoxes();
			});

			$order.on('click', '.fh-cr-line-add', function () {
				var template = root.document.getElementById('fh-cr-line-template');
				if (!template) {
					return;
				}
				var $row = $(template.innerHTML);
				$lines.append($row);
				renumberLines();
				$row.find('.fh-cr-species').focus();
			});

			$order.on('click', '.fh-cr-line-remove', function () {
				$(this).closest('tr').remove();
				renumberLines();
				refreshBoxes();
			});

			$order.on('click', '.fh-cr-suggest-fees', function () {
				var value = suggestImportFees(cfg.boxRates || [], $order.find('[name="supplier"]').val(), $order.find('[name="origin"]').val(), $order.find('[name="boxes"]').val());
				if (value === null) {
					notice('fishotel-misc-notice--warning', i18n.noRate || 'No box rate.');
					return;
				}
				$order.find('[name="import_fees"]').val(value.toFixed(2)).trigger('change');
			});

			$order.on('click', '.fh-cr-checkin', function () {
				if (!root.confirm(i18n.confirmCheckin || 'Check in?')) {
					return;
				}
				var $btn = $(this).prop('disabled', true);
				saveOrder(function () {
					$btn.prop('disabled', true); // The save reply re-enabled it; one check-in at a time.
					$.post(ajaxUrl(), { action: 'fishotel_cleanroom_order', nonce: cfg.nonce, op: 'checkin', order_id: $order.data('order') })
						.done(function (res) {
							if (!res || !res.success) {
								fail(res);
								$btn.prop('disabled', false);
								return;
							}
							root.alert(res.data.message || (i18n.saved || 'Saved.'));
							root.location.reload();
						})
						.fail(function (res) {
							fail(res);
							$btn.prop('disabled', false);
						});
				}, function () {
					$btn.prop('disabled', false); // The save was refused: nothing was checked in, try again.
				});
			});

			$order.on('click', '.fh-cr-order-delete', function () {
				if (!root.confirm(i18n.confirmDelete || 'Delete this order?')) {
					return;
				}
				$.post(ajaxUrl(), { action: 'fishotel_cleanroom_order', nonce: cfg.nonce, op: 'delete', order_id: $order.data('order') })
					.done(function (res) {
						if (!res || !res.success) {
							fail(res);
							return;
						}
						root.location.href = res.data.redirect;
					})
					.fail(fail);
			});

			attachSuggest($lines, '.fh-cr-species', 'suggest', function ($input, item) {
				var $tr = $input.closest('tr');
				$input.val(item.name || '');
				$tr.find('input[name$="[species_id]"]').val(item.species_id || '');
				var $sci = $tr.find('input[name$="[sci_name]"]');
				if (!$.trim($sci.val()) && item.sci) {
					$sci.val(item.sci);
				}
				if (item.per_box) {
					$tr.find('input[name$="[per_box]"]').attr('placeholder', item.per_box);
				}
				var $worth = $tr.find('input[name$="[worth]"]');
				if (!$.trim($worth.val()) && item.my_price !== null && item.my_price !== undefined) {
					$worth.val(Number(item.my_price).toFixed(2));
				}
				refreshBoxes(); // The line has its fish: its remembered share, if any, is offered.
			});
		}

		// The Species tab.
		$(document).on('click', '.fh-cr-species-add-toggle', function () {
			var $card = $('.fh-cr-species-add');
			$card.prop('hidden', !$card.prop('hidden'));
			if (!$card.prop('hidden')) {
				$card.find('[name="name"]').focus();
			}
		});

		$(document).on('click', '.fh-cr-species-toggle', function () {
			var id = $(this).closest('tr').data('species');
			var $fields = $('.fh-cr-species-fields[data-species="' + id + '"]');
			$fields.prop('hidden', !$fields.prop('hidden'));
			if (!$fields.prop('hidden')) {
				($(this).data('focus') === 'product' ? $fields.find('.fh-cr-product-search') : $fields.find('[name="name"]')).focus();
			}
		});

		function speciesPost(fields, $btn) {
			fields.action = 'fishotel_cleanroom_species';
			fields.nonce = cfg.nonce;
			if ($btn) {
				$btn.prop('disabled', true);
			}
			return $.post(ajaxUrl(), fields)
				.fail(fail)
				.always(function () {
					if ($btn) {
						$btn.prop('disabled', false);
					}
				});
		}

		$(document).on('click', '.fh-cr-species-save', function () {
			var $wrap = $(this).closest('.fh-cr-fields__row');
			var fields = { op: 'save' };
			$wrap.find('input[name], select[name]').each(function () { fields[this.name] = this.value; });
			speciesPost(fields, $(this)).done(function (res) {
				if (!res || !res.success) {
					fail(res);
					return;
				}
				notice('fishotel-misc-notice--success', i18n.reloading || 'Saved. Reloading…');
				root.setTimeout(function () { root.location.reload(); }, 600);
			});
		});

		$(document).on('click', '.fh-cr-species-delete', function () {
			var $row = $(this).closest('tr');
			var id = $row.data('species');
			if (!root.confirm(i18n.confirmSpecies || 'Remove this species?')) {
				return;
			}
			speciesPost({ op: 'delete', id: id }, $(this)).done(function (res) {
				if (!res || !res.success) {
					fail(res);
					return;
				}
				$('.fh-cr-species-fields[data-species="' + id + '"]').remove();
				$row.remove();
				notice('fishotel-misc-notice--success', res.data.message || (i18n.saved || 'Saved.'));
			});
		});

		$(document).on('click', '.fh-cr-species-unlink', function () {
			var $wrap = $(this).closest('.fh-cr-fields__row');
			var id = parseInt($wrap.find('input[name="id"]').val(), 10) || 0;
			speciesPost({ op: 'link', id: id, product_id: 0 }, $(this)).done(function (res) {
				if (!res || !res.success) {
					fail(res);
					return;
				}
				notice('fishotel-misc-notice--success', i18n.reloading || 'Saved. Reloading…');
				root.setTimeout(function () { root.location.reload(); }, 600);
			});
		});

		attachSuggest($(document), '.fh-cr-product-search', 'products', function ($input, item) {
			var $wrap = $input.closest('.fh-cr-fields__row');
			$wrap.find('input[name="product_id"]').val(item.product_id || '');
			$input.val(item.name || '');
			var id = parseInt($wrap.find('input[name="id"]').val(), 10) || 0;
			if (id > 0 && item.product_id) {
				speciesPost({ op: 'link', id: id, product_id: item.product_id }).done(function (res) {
					if (!res || !res.success) {
						fail(res);
						return;
					}
					notice('fishotel-misc-notice--success', res.data.message || (i18n.linked || 'Linked.'));
					root.setTimeout(function () { root.location.reload(); }, 600);
				});
			}
		});
	});
})(typeof window !== 'undefined' ? window : globalThis, typeof jQuery !== 'undefined' ? jQuery : null);
