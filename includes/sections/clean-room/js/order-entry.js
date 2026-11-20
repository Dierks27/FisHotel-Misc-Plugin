/**
 * The order page's two views and what belongs to them (Clean Room →
 * Arrivals → an order):
 *
 *   - the Entry / Numbers tabs and the "More columns" switch — both views
 *     are the same rows of the same form (a hidden column is display:none
 *     and still posted), and which one a person left the page in is
 *     remembered for them (the order's "view" op);
 *   - Enter and Tab that walk DOWN a column in Entry: Enter in any number
 *     box, Tab in the receiving columns (Received, then DOA, Box #, % of
 *     box — or Bags), while Tab in Ordered goes on to Cost and from Cost
 *     to the next line's fish — never across into a hidden column, and
 *     never a Save by accident;
 *   - Split in the Tank cell: rows of "how many → which tank" under the
 *     line, "1 per tank", the running "6 of 6 placed" — kept in the
 *     line's hidden `split` field, which Save posts with the rest
 *     (Splits::parts_in() reads it, the check-in carries it out). A line
 *     whose fish was split last time is OFFERED the split opened; it is
 *     written into the field only once the owner has it in front of them
 *     (the Entry view), and "Keep together" takes it away;
 *   - the pencil beside a shop price in Numbers, which types a price of
 *     the owner's own for that line.
 *
 * The pure helpers at the top are exported for node
 * (tests/order-entry.test.js); they mirror Splits (class-splits.php) so
 * the page says what the server will say.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */
(function (root, $) {
	'use strict';

	/* ------------------------------------------------------------------ */
	/*  Pure helpers                                                       */
	/* ------------------------------------------------------------------ */

	var MAX_PARTS = 40;
	var MAX_QTY = 9999;

	/**
	 * A whole number of at most six digits, else 0 (Splits::parts_in()'s
	 * rule: a formula, a negative or a decimal is no count).
	 *
	 * @param {*} value Raw.
	 * @return {number}
	 */
	function whole(value) {
		if (value === null || typeof value === 'undefined' || typeof value === 'boolean' || typeof value === 'object') {
			return 0;
		}
		var text = String(value).replace(/^\s+|\s+$/g, '');
		return /^\d{1,6}$/.test(text) ? parseInt(text, 10) : 0;
	}

	/**
	 * A line's `split` field read back: null when nothing is said, 'none'
	 * when the owner declined an offered split, else the parts — each a
	 * count and a tank id, 0 where one is missing. A row with neither is
	 * dropped. Mirrors Splits::parts_in().
	 *
	 * @param {*} value The field's value (a JSON string) or an array.
	 * @return {Object[]|string|null}
	 */
	function splitParts(value) {
		var raw = value;
		if (typeof raw === 'string') {
			raw = raw.replace(/^\s+|\s+$/g, '');
			if (raw === '') {
				return null;
			}
			if (raw.toLowerCase() === 'none') {
				return 'none';
			}
			try {
				raw = JSON.parse(raw);
			} catch (err) {
				return null;
			}
		}
		if (!Array.isArray(raw)) {
			return null;
		}
		var parts = [];
		raw.forEach(function (row) {
			if (parts.length >= MAX_PARTS || row === null || typeof row !== 'object') {
				return;
			}
			var qty = whole(Array.isArray(row) ? row[0] : row.qty);
			var tank = whole(Array.isArray(row) ? row[1] : row.tank_id);
			if (qty === 0 && tank === 0) {
				return;
			}
			parts.push({ qty: Math.min(MAX_QTY, qty), tank_id: tank });
		});
		return parts.length ? parts : null;
	}

	/**
	 * What a plan is written into the field as: '' for nothing, 'none',
	 * or the JSON of [[count, tank id], …].
	 *
	 * @param {Object[]|string|null} parts splitParts().
	 * @return {string}
	 */
	function splitField(parts) {
		if (parts === 'none') {
			return 'none';
		}
		if (!Array.isArray(parts)) {
			return '';
		}
		var rows = parts.filter(function (part) {
			return whole(part.qty) > 0 || whole(part.tank_id) > 0;
		}).map(function (part) {
			return [whole(part.qty), whole(part.tank_id)];
		});
		return rows.length ? JSON.stringify(rows) : '';
	}

	/**
	 * How many fish a plan places.
	 *
	 * @param {Object[]} parts The parts.
	 * @return {number}
	 */
	function splitPlaced(parts) {
		var sum = 0;
		(Array.isArray(parts) ? parts : []).forEach(function (part) {
			sum += whole(part.qty);
		});
		return sum;
	}

	/**
	 * A line's live fish as typed: received − DOA, never below 0; null
	 * while no received count is typed. Mirrors Splits::live_of().
	 *
	 * @param {*} received The received field.
	 * @param {*} doa      The DOA field.
	 * @return {number|null}
	 */
	function splitLive(received, doa) {
		var text = received === null || typeof received === 'undefined' ? '' : String(received).replace(/^\s+|\s+$/g, '');
		if (!/^\d+$/.test(text)) {
			return null;
		}
		return Math.max(0, parseInt(text, 10) - whole(doa));
	}

	/**
	 * sprintf for "%1$s" / "%s" / "%d" placeholders.
	 *
	 * @param {string} text Template.
	 * @return {string}
	 */
	function fmt(text) {
		var args = Array.prototype.slice.call(arguments, 1);
		var next = 0;
		return String(text || '').replace(/%(?:(\d+)\$)?[sd]/g, function (all, position) {
			var value = position ? args[parseInt(position, 10) - 1] : args[next++];
			return typeof value === 'undefined' ? '' : String(value);
		});
	}

	/**
	 * Tanks by their id.
	 *
	 * @param {Object[]} tanks id, number, label, building_id, area, empty.
	 * @return {Object}
	 */
	function tanksById(tanks) {
		var out = {};
		(tanks || []).forEach(function (tank) {
			out[whole(tank.id)] = tank;
		});
		return out;
	}

	/**
	 * What is wrong with a plan — the sentences Splits::errors() gives: a
	 * part without a count or a tank, a tank that takes no arrivals, a
	 * tank picked twice, more placed than alive.
	 *
	 * @param {Object[]}    parts The parts.
	 * @param {number|null} live  The live fish; null while nothing is received.
	 * @param {Object}      byId  tanksById().
	 * @param {Object}      t     Templates: noQty, noTank, area, twice, live.
	 * @return {string[]}
	 */
	function splitErrors(parts, live, byId, t) {
		var errors = {};
		var order = [];
		var seen = {};
		function add(key, text) {
			if (!Object.prototype.hasOwnProperty.call(errors, key)) {
				order.push(key);
			}
			errors[key] = text;
		}
		(Array.isArray(parts) ? parts : []).forEach(function (part) {
			var qty = whole(part.qty);
			var tank = whole(part.tank_id);
			if (qty < 1) {
				add('qty', t.noQty);
			}
			if (tank < 1) {
				add('tank', t.noTank);
				return;
			}
			if (!Object.prototype.hasOwnProperty.call(byId, tank)) {
				add('area', t.area);
				return;
			}
			if (seen[tank]) {
				add('twice' + tank, fmt(t.twice, byId[tank].number));
			}
			seen[tank] = true;
		});
		if (live !== null && typeof live !== 'undefined' && splitPlaced(parts) > live) {
			add('live', fmt(t.live, splitPlaced(parts), Math.max(0, live)));
		}
		return order.map(function (key) { return errors[key]; });
	}

	/**
	 * "1 per tank": one fish to a tank over the next EMPTY Spa tanks — of
	 * the order's building when it has one —, in rack order, skipping the
	 * tanks other lines already use; the line's own first tank leads when
	 * it is one of them. Stops when the empty tanks run out. Mirrors
	 * Splits::one_per_tank().
	 *
	 * @param {number}   live       Live fish.
	 * @param {Object[]} tanks      id, number, building_id, area, empty.
	 * @param {*}        buildingId The order's building ('' / 0 = any).
	 * @param {number[]} taken      Tank ids other lines use.
	 * @param {number}   first      The line's own first tank (0 = none).
	 * @return {Object[]} Parts of one fish each.
	 */
	function onePerTank(live, tanks, buildingId, taken, first) {
		var want = Math.min(Math.max(0, whole(live)), MAX_PARTS);
		var building = whole(buildingId);
		var used = (taken || []).map(whole);
		var free = (tanks || []).filter(function (tank) {
			if (tank.area !== 'spa' || !tank.empty || used.indexOf(whole(tank.id)) >= 0) {
				return false;
			}
			return building < 1 || whole(tank.building_id) === building;
		}).sort(function (a, b) {
			return whole(a.number) - whole(b.number);
		}).map(function (tank) {
			return whole(tank.id);
		});
		var lead = whole(first);
		if (lead > 0 && free.indexOf(lead) >= 0) {
			free = [lead].concat(free.filter(function (id) { return id !== lead; }));
		}
		return free.slice(0, want).map(function (id) {
			return { qty: 1, tank_id: id };
		});
	}

	/**
	 * The running count under a split: "6 of 6 placed", and whether that
	 * is all of them ('full'), too many ('over') or neither.
	 *
	 * @param {Object[]}    parts The parts.
	 * @param {number|null} live  The live fish; null while nothing is received.
	 * @param {Object}      t     Templates: placed, placedOnly.
	 * @return {{text: string, state: string}}
	 */
	function splitCount(parts, live, t) {
		var placed = splitPlaced(parts);
		if (live === null || typeof live === 'undefined') {
			return { text: fmt(t.placedOnly, placed), state: '' };
		}
		return {
			text: fmt(t.placed, placed, live),
			state: placed > live ? 'over' : (placed === live && live > 0 ? 'full' : '')
		};
	}

	/**
	 * Where Enter or Tab goes from a field of the grid.
	 *
	 * In a receiving column (`down`, in walking order) both keys go to the
	 * next line's field of that column — the one above with Shift. At the
	 * end of the column Tab goes on to the top of the next receiving
	 * column (the bottom of the one before, with Shift) and Enter stays.
	 * In any other number box Enter goes down its column too, while Tab
	 * keeps to the ordering fields: from Ordered to Cost, from Cost to the
	 * next line's fish (and back with Shift).
	 *
	 * @param {string}   field  The field's name (qty_received, unit_price, …).
	 * @param {number}   index  Its place among the fields of its column.
	 * @param {Object}   counts How many fields each column has.
	 * @param {boolean}  back   Shift held.
	 * @param {string}   key    'Enter' or 'Tab'.
	 * @param {string[]} down   The receiving columns, in walking order.
	 * @return {Object|null|undefined} {field, index} to focus that one, {field, same: true}
	 *         for a field of the same line, {field, row: 1} for one of the next line; null to
	 *         stay put (the key does nothing); undefined to leave the key to the browser.
	 */
	function nextField(field, index, counts, back, key, down) {
		var n = (counts && counts[field]) || 0;
		var col = (down || []).indexOf(field);
		var next = index + (back ? -1 : 1);

		if (col >= 0 || key === 'Enter') {
			if (next >= 0 && next < n) {
				return { field: field, index: next };
			}
			if (key === 'Enter' || col < 0) {
				return null;
			}
			var other = col + (back ? -1 : 1);
			while (other >= 0 && other < down.length && !((counts && counts[down[other]]) > 0)) {
				other += back ? -1 : 1;
			}
			if (other < 0 || other >= down.length) {
				return undefined;
			}
			return { field: down[other], index: back ? counts[down[other]] - 1 : 0 };
		}

		if (field === 'qty_ordered' && !back) {
			return { field: 'unit_price', same: true };
		}
		if (field === 'unit_price') {
			return back ? { field: 'qty_ordered', same: true } : { field: 'species', row: 1 };
		}
		return undefined;
	}

	var api = {
		whole: whole,
		splitParts: splitParts,
		splitField: splitField,
		splitPlaced: splitPlaced,
		splitLive: splitLive,
		splitErrors: splitErrors,
		splitCount: splitCount,
		onePerTank: onePerTank,
		tanksById: tanksById,
		nextField: nextField,
		fmt: fmt
	};
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
	if (!$) {
		return;
	}

	/* ------------------------------------------------------------------ */
	/*  The page                                                           */
	/* ------------------------------------------------------------------ */

	$(function () {
		var $order = $('#fh-cr-order');
		var $table = $('#fh-cr-lines');
		if (!$order.length || !$table.length) {
			return;
		}
		var $grid = $table.find('tbody');
		var admin = root.fhCleanRoomAdmin || {};
		var cfg = root.fhCleanRoomEntry || {};
		var t = cfg.i18n || {};
		var cols = parseInt($table.attr('data-cols'), 10) || 26;
		var tanks = [];
		try {
			tanks = JSON.parse($table.attr('data-split-tanks') || '[]') || [];
		} catch (err) {
			tanks = [];
		}
		var byId = tanksById(tanks);

		/* ---- The two views ---- */

		function remember(what) {
			if (!admin.ajaxUrl) {
				return;
			}
			$.post(admin.ajaxUrl, $.extend({ action: 'fishotel_cleanroom_order', nonce: admin.nonce, op: 'view', stage: $order.attr('data-stage') || 'working' }, what));
		}

		var offered = false;

		function setView(view) {
			$order.attr('data-view', view);
			$order.find('.fh-cr-view-tab').each(function () {
				var on = $(this).attr('data-view') === view;
				$(this).toggleClass('is-on', on).attr('aria-selected', on ? 'true' : 'false');
			});
			if (view === 'entry' && !offered) {
				offerSplits();
			}
		}

		$order.on('click', '.fh-cr-view-tab', function () {
			var view = $(this).attr('data-view') === 'numbers' ? 'numbers' : 'entry';
			if ($order.attr('data-view') === view) {
				return;
			}
			setView(view);
			remember({ view: view });
		});

		$order.on('change', '.fh-cr-more-toggle', function () {
			$order.attr('data-more', this.checked ? '1' : '0');
			remember({ more: this.checked ? 1 : 0 });
		});

		/* ---- The pencil: a price of the owner's own ---- */

		$order.on('click', '.fh-cr-worth-edit', function () {
			var $input = $(this).closest('td').find('.fh-cr-worth-input');
			var open = $input.prop('hidden');
			$input.prop('hidden', !open);
			$(this).attr('aria-expanded', open ? 'true' : 'false');
			if (open) {
				$input.trigger('focus').trigger('select');
			}
		});

		$order.on('input change', '.fh-cr-worth-input', function () {
			var $cell = $(this).closest('td');
			var typed = $.trim(String($(this).val())) !== '';
			$cell.toggleClass('is-typed', typed);
			$cell.find('.fh-cr-shop__price').attr('title', typed ? (t.worthTyped || '') : (t.worthCleared || ''));
		});

		/* ---- Enter and Tab walk down a column ---- */

		var WALK = ['qty_received', 'qty_doa', 'box_no', 'box_pct', 'bags'];

		function fieldOf(el) {
			var m = /\[([a-z_]+)\]$/.exec(String(el.name || ''));
			return m ? m[1] : '';
		}

		function column(field) {
			return $grid.find('tr.fh-cr-line [name$="[' + field + ']"]').filter(function () {
				return this.offsetParent !== null; // Shown: not in a hidden view, mode or row.
			});
		}

		$order.on('keydown', 'tr.fh-cr-line input[type="number"]', function (e) {
			if ((e.key !== 'Enter' && e.key !== 'Tab') || e.altKey || e.ctrlKey || e.metaKey || $order.attr('data-view') !== 'entry') {
				return;
			}
			var field = fieldOf(this);
			if (!field) {
				return;
			}
			var down = WALK.filter(function (name) { return column(name).length > 0; });
			var counts = {};
			down.concat([field]).forEach(function (name) { counts[name] = column(name).length; });
			var target = nextField(field, column(field).index(this), counts, e.shiftKey, e.key, down);
			if (typeof target === 'undefined') {
				return;
			}
			e.preventDefault(); // Also keeps Enter from saving by accident.
			if (target === null) {
				return;
			}
			var $to;
			if (target.same) {
				$to = $(this).closest('tr').find('[name$="[' + target.field + ']"]');
			} else if (target.row) {
				$to = $(this).closest('tr').nextAll('tr.fh-cr-line').first().find('[name$="[' + target.field + ']"]');
				if (!$to.length) {
					$to = $order.find('.fh-cr-line-add'); // The last line: on to "Add line".
				}
			} else {
				$to = column(target.field).eq(target.index);
			}
			if ($to.length) {
				$to.trigger('focus');
				if ($to.is('input')) {
					$to.trigger('select');
				}
			}
		});

		/* ---- Split ---- */

		function fieldEl($row) {
			return $row.find('.fh-cr-split-field');
		}

		function partsOf($row) {
			var parts = splitParts(fieldEl($row).val());
			return Array.isArray(parts) ? parts : [];
		}

		function liveOf($row) {
			return splitLive($row.find('input[name$="[qty_received]"]').val(), $row.find('input[name$="[qty_doa]"]').val());
		}

		function panelOf($row) {
			var $next = $row.next('tr.fh-cr-split-row');
			return $next.length ? $next : null;
		}

		/* The tanks the other lines of the page already use: their plans, else their Tank cell. */
		function takenElsewhere($row) {
			var out = [];
			$grid.find('tr.fh-cr-line').not($row).each(function () {
				var $other = $(this);
				var parts = partsOf($other);
				if (parts.length) {
					parts.forEach(function (part) { out.push(whole(part.tank_id)); });
					return;
				}
				var tank = whole($other.find('select[name$="[tank_id]"]').val());
				if (tank > 0) {
					out.push(tank);
				}
			});
			return out;
		}

		/* The line as its plan stands: the hidden field, the Tank cell, the Split button. */
		function commit($row, parts) {
			var value = splitField(parts);
			fieldEl($row).val(value);
			var list = Array.isArray(parts) ? parts.filter(function (part) { return whole(part.tank_id) > 0; }) : [];
			var split = list.length > 1 || (list.length === 1 && value !== '' && !$row.find('select[name$="[tank_id]"] option[value="' + list[0].tank_id + '"]').length);
			var $said = $row.find('.fh-cr-split-said');
			$row.toggleClass('is-split', split);
			if (list.length) {
				// A split line arrives in the first tank of its plan: the Tank cell follows where it can.
				var $tank = $row.find('select[name$="[tank_id]"]');
				if ($tank.find('option[value="' + list[0].tank_id + '"]').length) {
					$tank.val(String(list[0].tank_id));
				}
			}
			var words = list.map(function (part) {
				return fmt(t.part || '%1$s → %2$s', whole(part.qty), byId[part.tank_id] ? byId[part.tank_id].name : '?');
			}).join(' · ');
			$said.text(split ? (list.length > 1 ? fmt(t.saidTanks || '%d tanks', list.length) : (byId[list[0].tank_id] ? byId[list[0].tank_id].label : '')) : '').attr('title', words);
			$row.find('.fh-cr-split-open').text(split ? (t.edit || 'Edit') : (t.split || 'Split'));
		}

		function tankSelect(parts, i) {
			var $select = $('<select class="fh-cr-input fh-cr-split__tank"></select>');
			$select.append($('<option value=""></option>').text(t.pickTank || '— tank —'));
			var mine = whole(parts[i].tank_id);
			var others = parts.filter(function (part, j) { return j !== i; }).map(function (part) { return whole(part.tank_id); });
			tanks.forEach(function (tank) {
				var id = whole(tank.id);
				var $option = $('<option></option>').val(String(id)).text(tank.label + (tank.empty ? '' : ' · ' + (t.hasFish || 'has fish')));
				if (others.indexOf(id) >= 0) {
					$option.prop('disabled', true); // A tank cannot be picked twice for one line.
				}
				$select.append($option);
			});
			if (mine > 0 && !byId[mine]) {
				$select.append($('<option></option>').val(String(mine)).text(t.goneTank || '(no longer a tank for arrivals)'));
			}
			$select.val(mine > 0 ? String(mine) : '');
			return $select;
		}

		function drawCount($row, $panel, parts) {
			var live = liveOf($row);
			var count = splitCount(parts, live, { placed: t.placed || '%1$d of %2$d placed', placedOnly: t.placedOnly || '%1$d placed' });
			var errors = splitErrors(parts.filter(function (part) { return whole(part.qty) > 0 || whole(part.tank_id) > 0; }), live, byId, {
				noQty: t.noQty || '', noTank: t.noTank || '', area: t.area || '', twice: t.twice || '%s', live: t.live || ''
			});
			var rest = live !== null && splitPlaced(parts) < live ? ' — ' + fmt(t.rest || '%d stay in the first tank', live - splitPlaced(parts)) : '';
			$panel.find('.fh-cr-split__count').text(count.text + rest).removeClass('is-full is-over').addClass(count.state ? 'is-' + count.state : '');
			$panel.find('.fh-cr-split__errors').text(errors.join(' · '));
		}

		function draw($row, parts, why) {
			var $panel = panelOf($row);
			if (!$panel) {
				$panel = $('<tr class="fh-cr-split-row"><td></td></tr>');
				$panel.find('td').attr('colspan', cols);
				$row.after($panel);
			}
			if (typeof why === 'string') {
				$panel.data('why', why);
			}
			var $td = $panel.find('td').empty();
			var $box = $('<div class="fh-cr-split"></div>').appendTo($td);
			$('<span class="fh-cr-split__title"></span>').text(fmt(t.title || 'Split %s over tanks:', $.trim($row.find('.fh-cr-species').val()) || (t.thisLine || 'this line'))).appendTo($box);
			var $parts = $('<span class="fh-cr-split__parts"></span>').appendTo($box);
			parts.forEach(function (part, i) {
				var $part = $('<span class="fh-cr-split__part"></span>').attr('data-part', i);
				$('<input type="number" class="fh-cr-input fh-cr-split__qty" min="1" max="9999" step="1">').val(whole(part.qty) > 0 ? String(whole(part.qty)) : '').attr('aria-label', t.howMany || 'How many').appendTo($part);
				$part.append(document.createTextNode(' → '));
				tankSelect(parts, i).attr('aria-label', t.whichTank || 'Which tank').appendTo($part);
				if (i === 0) {
					$('<span class="fh-cr-sub fh-cr-sub--inline"></span>').text(t.firstTank || 'first tank').appendTo($part);
				}
				$('<button type="button" class="button-link fh-cr-split__remove">&times;</button>').attr('title', t.removePart || 'Remove').appendTo($part);
				$parts.append($part);
			});
			$('<button type="button" class="button button-small fh-cr-split__add"></button>').text(t.addTank || '+ tank').appendTo($box);
			$('<button type="button" class="button button-small fh-cr-split__ones"></button>').text(t.ones || '1 per tank').attr('title', t.onesTitle || '').appendTo($box);
			$('<button type="button" class="button button-small fh-cr-split__none"></button>').text(t.keep || 'Keep together').attr('title', t.keepTitle || '').appendTo($box);
			$('<span class="fh-cr-split__count" aria-live="polite"></span>').appendTo($box);
			$('<span class="fh-cr-split__errors fh-cr-warn" aria-live="polite"></span>').appendTo($box);
			if ($panel.data('why')) {
				$('<span class="fh-cr-split__why"></span>').text($panel.data('why')).appendTo($box);
			}
			$row.find('.fh-cr-split-open').attr('aria-expanded', 'true');
			drawCount($row, $panel, parts);
			return $panel;
		}

		/* The parts as the panel's rows stand (a row may still lack its count or its tank). */
		function read($panel) {
			return $panel.find('.fh-cr-split__part').map(function () {
				return { qty: whole($(this).find('.fh-cr-split__qty').val()), tank_id: whole($(this).find('.fh-cr-split__tank').val()) };
			}).get();
		}

		function close($row) {
			var $panel = panelOf($row);
			if ($panel) {
				$panel.remove();
			}
			$row.find('.fh-cr-split-open').attr('aria-expanded', 'false');
		}

		function layOnes($row) {
			var live = liveOf($row);
			if (live === null) {
				return null;
			}
			var first = whole($row.find('select[name$="[tank_id]"]').val());
			return onePerTank(live, tanks, $order.find('[name="building_id"]').val(), takenElsewhere($row), first);
		}

		function onesNote(parts, live) {
			if (live === null) {
				return t.onesNoCount || 'Type the received count first.';
			}
			if (parts.length < Math.min(live, MAX_PARTS)) {
				return fmt(t.onesShort || 'Only %1$d empty Spa tanks: the other %2$d stay in the first tank.', parts.length, live - parts.length);
			}
			return '';
		}

		$order.on('click', '.fh-cr-split-open', function () {
			var $row = $(this).closest('tr.fh-cr-line');
			if (panelOf($row)) {
				close($row); // The plan stays in the line's field; the Tank cell says what it is.
				return;
			}
			$row.removeData('auto');
			var parts = partsOf($row);
			if (!parts.length) {
				var live = liveOf($row);
				parts = [{ qty: 0, tank_id: whole($row.find('select[name$="[tank_id]"]').val()) }, { qty: 0, tank_id: 0 }];
				if (live !== null && live > 1) {
					parts[0].qty = live - 1;
					parts[1].qty = 1;
				}
			}
			draw($row, parts, '');
		});

		$order.on('click', '.fh-cr-split__add', function () {
			var $panel = $(this).closest('tr.fh-cr-split-row');
			var $row = $panel.prev('tr.fh-cr-line');
			var parts = read($panel);
			if (parts.length >= MAX_PARTS) {
				return;
			}
			$row.removeData('auto');
			parts.push({ qty: 0, tank_id: 0 });
			draw($row, parts);
			commit($row, parts);
			panelOf($row).find('.fh-cr-split__part').last().find('.fh-cr-split__qty').trigger('focus');
		});

		$order.on('click', '.fh-cr-split__remove', function () {
			var $panel = $(this).closest('tr.fh-cr-split-row');
			var $row = $panel.prev('tr.fh-cr-line');
			var parts = read($panel);
			parts.splice(parseInt($(this).closest('.fh-cr-split__part').attr('data-part'), 10), 1);
			$row.removeData('auto');
			draw($row, parts);
			commit($row, parts);
		});

		$order.on('click', '.fh-cr-split__ones', function () {
			var $panel = $(this).closest('tr.fh-cr-split-row');
			var $row = $panel.prev('tr.fh-cr-line');
			var parts = layOnes($row);
			$row.removeData('auto');
			if (parts === null) {
				$panel.find('.fh-cr-split__errors').text(onesNote([], null));
				return;
			}
			draw($row, parts);
			commit($row, parts);
			var note = onesNote(parts, liveOf($row));
			if (note) {
				panelOf($row).find('.fh-cr-split__errors').text(note);
			}
		});

		$order.on('click', '.fh-cr-split__none', function () {
			var $row = $(this).closest('tr.fh-cr-split-row').prev('tr.fh-cr-line');
			// An offered split that is declined is not offered again on this line.
			commit($row, $row.attr('data-split-offer') ? 'none' : null);
			$row.removeData('auto');
			close($row);
		});

		$order.on('input change', '.fh-cr-split__qty, .fh-cr-split__tank', function (e) {
			var $panel = $(this).closest('tr.fh-cr-split-row');
			var $row = $panel.prev('tr.fh-cr-line');
			var parts = read($panel);
			$row.removeData('auto');
			commit($row, parts);
			if (e.type === 'change' && $(this).is('select')) {
				draw($row, parts); // The tank is taken: the other rows no longer offer it.
			} else {
				drawCount($row, $panel, parts);
			}
		});

		// Enter in a split row goes on to the next part; it never saves the order.
		$order.on('keydown', '.fh-cr-split__qty', function (e) {
			if (e.key !== 'Enter') {
				return;
			}
			e.preventDefault();
			$(this).closest('.fh-cr-split__part').next('.fh-cr-split__part').find('.fh-cr-split__qty').trigger('focus').trigger('select');
		});

		// The counts move: the running "placed" follows, and an offered "1 per tank" lays itself out again.
		$order.on('input change', 'tr.fh-cr-line input[name$="[qty_received]"], tr.fh-cr-line input[name$="[qty_doa]"]', function () {
			var $row = $(this).closest('tr.fh-cr-line');
			var $panel = panelOf($row);
			if (!$panel) {
				return;
			}
			if ($row.data('auto')) {
				var parts = layOnes($row);
				if (parts !== null) {
					draw($row, parts);
					commit($row, parts);
					var note = onesNote(parts, liveOf($row));
					if (note) {
						panelOf($row).find('.fh-cr-split__errors').text(note);
					}
				}
				return;
			}
			drawCount($row, $panel, read($panel));
		});

		// The Tank cell picked by hand: a line that is not split follows it (a plan of one part is only
		// its tank); an open panel takes it as its first tank.
		$order.on('change', 'tr.fh-cr-line select[name$="[tank_id]"]', function () {
			var $row = $(this).closest('tr.fh-cr-line');
			var $panel = panelOf($row);
			if ($panel) {
				var parts = read($panel);
				if (parts.length) {
					parts[0].tank_id = whole(this.value);
					draw($row, parts);
					if (fieldEl($row).val() !== '' && fieldEl($row).val() !== 'none') {
						commit($row, parts);
					}
				}
				return;
			}
			if (partsOf($row).length <= 1 && fieldEl($row).val() !== 'none') {
				fieldEl($row).val('');
			}
		});

		/*
		 * A fish that was split last time: its line offers the split, opened.
		 * Only in the Entry view — a plan the owner has not had in front of
		 * them is never written into the field.
		 */
		function offerSplits() {
			offered = true;
			$grid.find('tr.fh-cr-line[data-split-offer]').each(function () {
				var $row = $(this);
				if (fieldEl($row).val() !== '' || panelOf($row) || !fieldEl($row).length) {
					return;
				}
				var ones = $row.attr('data-split-offer') === 'ones';
				var parts = ones ? layOnes($row) : null;
				$row.data('auto', ones);
				if (parts === null || !parts.length) {
					draw($row, [], ones ? (t.offerOnesLater || '') : (t.offerSome || ''));
					return;
				}
				draw($row, parts, t.offerOnes || '');
				commit($row, parts);
				var note = onesNote(parts, liveOf($row));
				if (note) {
					panelOf($row).find('.fh-cr-split__errors').text(note);
				}
			});
		}

		// The plans that came with the page: the Tank cell says what each is.
		$grid.find('tr.fh-cr-line').each(function () {
			var parts = partsOf($(this));
			if (parts.length) {
				commit($(this), parts);
			}
		});
		if ($order.attr('data-view') === 'entry') {
			offerSplits();
		}
	});
})(typeof window !== 'undefined' ? window : globalThis, typeof jQuery !== 'undefined' ? jQuery : null);
