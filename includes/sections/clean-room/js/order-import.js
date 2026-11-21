/**
 * Clean Room — "Import lines" on the order page.
 *
 * The page half of the line importer: the box that takes pasted rows or a
 * file, the two moments ("This is what I ORDERED" / "This is what
 * ARRIVED"), the preview the server sends back (Admin_Order_Import), what
 * the owner chooses in it — the columns, the ticks, the fish of a row, a
 * pairing — and the confirm. Also on the order page, for the day there is
 * no file: "Received: all as ordered" (fills the EMPTY received fields
 * only; Enter and Tab down the received column are js/order-entry.js's).
 *
 * Nothing the server sends is ever set as HTML: every text goes in as
 * text. Every request carries the order form as it stands on the page;
 * the server answers with a token, and a confirm against an order that
 * changed meanwhile is refused and drawn again.
 *
 * The pure helpers are exported for the node test and the page code is
 * skipped when there is no jQuery.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */
(function (root, $) {
	'use strict';

	/* ------------------------------------------------------------------ */
	/*  Pure helpers                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * "Received: all as ordered": every EMPTY received field takes its
	 * ordered count; a field that holds anything — a 0 too — is left
	 * alone, and so is a line without an ordered count.
	 *
	 * @param {Object[]} lines {ordered, received} as the inputs hold them.
	 * @return {string[]} The received values to show.
	 */
	function receivedAsOrdered(lines) {
		return (lines || []).map(function (line) {
			var received = line.received === null || typeof line.received === 'undefined' ? '' : String(line.received).trim();
			var ordered = line.ordered === null || typeof line.ordered === 'undefined' ? '' : String(line.ordered).trim();
			if (received !== '' || !/^\d+$/.test(ordered)) {
				return received;
			}
			return String(parseInt(ordered, 10));
		});
	}

	/**
	 * The foot of the ordered preview as the ticks stand: the lines that
	 * would be added and the fish on them. A duplicate counts only when
	 * it is set to "add anyway".
	 *
	 * @param {Object[]} rows {on, ordered, dup, dup_choice}.
	 * @return {{lines: number, fish: number}}
	 */
	function orderedFoot(rows) {
		var out = { lines: 0, fish: 0 };
		(rows || []).forEach(function (row) {
			if (!row.on || (row.dup && row.dup_choice !== 'add')) {
				return;
			}
			out.lines++;
			out.fish += parseInt(row.ordered, 10) || 0;
		});
		return out;
	}

	/**
	 * A count with its thousands separators ("1,188").
	 *
	 * @param {number} n A whole number.
	 * @return {string}
	 */
	function thousands(n) {
		return String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	/**
	 * sprintf for "%1$s" and "%s" placeholders.
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
	 * What the page tells the server about the owner's choices.
	 *
	 * @param {Object} state The importer's state.
	 * @return {Object}
	 */
	function payloadOf(state) {
		var out = {
			mode: state.mode,
			hand: state.hand ? 1 : 0,
			arrived_too: state.too ? 1 : 0,
			same_fish: state.sameFish || 'sum',
			use_file_price: state.useFilePrice ? 1 : 0,
			fees: state.fees ? 1 : 0,
			fees_replace: state.feesReplace ? 1 : 0,
			remember: state.remember ? 1 : 0,
			rows: state.rows || {},
			lines: state.lines || {},
			sheet_name: state.sheet || ''
		};
		if (state.hand) {
			out.map = state.map || {};
			out.header_row = state.headerRow === null || typeof state.headerRow === 'undefined' ? '' : state.headerRow;
		}
		if (state.useLayout) {
			out.use_layout = state.useLayout;
		}
		if (state.sections) {
			out.sections = state.sections;
		}
		if (state.token) {
			out.token = state.token;
		}
		return out;
	}

	/**
	 * The words of the ordered foot: "1 line · 3 fish", "2 lines · 9 fish".
	 *
	 * @param {{lines: number, fish: number}} foot orderedFoot().
	 * @param {Object} t The page's words (foot, footOne).
	 * @return {string}
	 */
	function footText(foot, t) {
		return fmt(foot.lines === 1 && t.footOne ? t.footOne : t.foot, thousands(foot.lines), thousands(foot.fish));
	}

	/**
	 * Where the preview's keyboard focus goes back to after it is drawn
	 * again: the control with the same key (what it is + the row or line it
	 * belongs to), wherever the row now stands.
	 *
	 * @param {Object[]} controls {key} of the controls of the new preview, in order.
	 * @param {string} key The key of the control that had the focus ('' = none).
	 * @return {number} Its index, or -1.
	 */
	function focusIndex(controls, key) {
		if (!key) {
			return -1;
		}
		for (var i = 0; i < (controls || []).length; i++) {
			if (controls[i] && controls[i].key === key) {
				return i;
			}
		}
		return -1;
	}

	var helpers = {
		receivedAsOrdered: receivedAsOrdered,
		orderedFoot: orderedFoot,
		footText: footText,
		focusIndex: focusIndex,
		thousands: thousands,
		fmt: fmt,
		payloadOf: payloadOf
	};
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = helpers;
	}
	if (!$) {
		return;
	}

	/* ------------------------------------------------------------------ */
	/*  The page                                                           */
	/* ------------------------------------------------------------------ */

	$(function () {
		var cfg = root.fhCleanRoomImport || {};
		var admin = root.fhCleanRoomAdmin || {};
		var t = cfg.i18n || {};

		// Settings → Saved import layouts: Forget.
		$(root.document).on('click', '.fh-cr-layout-forget', function () {
			var $row = $(this).closest('tr');
			if (!root.confirm(t.confirmLayout || 'Forget this layout?')) {
				return;
			}
			$.post(admin.ajaxUrl, { action: cfg.layoutAction || 'fishotel_cleanroom_import_layout', _wpnonce: admin.settingsNonce, id: $row.attr('data-layout') })
				.done(function (res) {
					if (res && res.success) {
						$row.remove();
						return;
					}
					root.alert(res && res.data && res.data.message ? res.data.message : (t.error || 'Something went wrong. Please try again.'));
				})
				.fail(function () {
					root.alert(t.error || 'Something went wrong. Please try again.');
				});
		});

		var $order = $('#fh-cr-order');
		if (!$order.length) {
			return;
		}
		var $grid = $('#fh-cr-lines tbody');

		/* ---- Received: all as ordered (Enter and Tab down the column: js/order-entry.js) ---- */

		function receivedInputs() {
			return $grid.find('tr.fh-cr-line input[name$="[qty_received]"]');
		}

		$order.on('click', '.fh-cr-received-all', function () {
			var $inputs = receivedInputs();
			var lines = $inputs.map(function () {
				return { ordered: $(this).closest('tr').find('input[name$="[qty_ordered]"]').val(), received: $(this).val() };
			}).get();
			var filled = 0;
			receivedAsOrdered(lines).forEach(function (value, i) {
				if (String($inputs.eq(i).val()) !== value) {
					$inputs.eq(i).val(value).trigger('input');
					filled++;
				}
			});
			$order.find('.fh-cr-received-all__said').text(fmt(filled === 1 ? t.filledOne : t.filledMany, filled));
		});

		/* ---- The importer ---- */

		var $panel = $('#fh-cr-import');
		if (!$panel.length) {
			return;
		}
		var $text = $panel.find('.fh-cr-import__text');
		var $file = $panel.find('.fh-cr-import__file');
		var $said = $panel.find('.fh-cr-import__status');
		var $preview = $panel.find('.fh-cr-import__preview');
		var $confirm = $panel.find('.fh-cr-import__confirm');
		var state = null;
		var busy = false;
		var again = false; // Something was chosen while a request was out: draw once more when it is back.

		function fresh(mode) {
			return {
				mode: mode,
				grid: null,
				truncated: false,
				sheet: '',
				hand: false,
				map: null,
				headerRow: null,
				useLayout: '',
				sections: null,
				too: $panel.find('.fh-cr-import__too').prop('checked'),
				sameFish: 'sum',
				useFilePrice: false,
				fees: false,
				feesReplace: false,
				remember: true,
				rows: {},
				lines: {},
				token: '',
				columnsOpen: false,
				preview: null
			};
		}

		function currentMode() {
			return $panel.find('.fh-cr-import__mode.is-on').attr('data-mode') || 'ordered';
		}

		function setMode(mode) {
			$panel.find('.fh-cr-import__mode').each(function () {
				var on = $(this).attr('data-mode') === mode;
				$(this).toggleClass('is-on', on).attr('aria-pressed', on ? 'true' : 'false');
			});
			$panel.find('.fh-cr-import__too-wrap').prop('hidden', mode !== 'ordered');
			$panel.find('[data-for-mode]').each(function () {
				$(this).prop('hidden', $(this).attr('data-for-mode') !== mode);
			});
		}

		function say(text, bad) {
			$said.toggleClass('is-error', !!bad).text(text || '');
		}

		/** The order form as it stands, its lines as one field. */
		function formData() {
			var data = $order.serializeArray();
			return admin.helpers && typeof admin.helpers.packLines === 'function' ? admin.helpers.packLines(data) : data;
		}

		function send(op, extra, file) {
			var body = new root.FormData();
			formData().forEach(function (field) {
				body.append(field.name, field.value);
			});
			body.append('action', cfg.action || 'fishotel_cleanroom_order_import');
			body.append('nonce', admin.nonce || '');
			body.append('op', op);
			body.append('import', JSON.stringify(extra));
			if (file) {
				body.append('file', file);
			}
			return $.ajax({ url: admin.ajaxUrl, method: 'POST', data: body, processData: false, contentType: false, dataType: 'json' });
		}

		function failed(xhr) {
			var res = xhr && xhr.responseJSON ? xhr.responseJSON : null;
			if (res && res.data && res.data.preview) {
				take(res.data.preview);
			}
			say(res && res.data && res.data.message ? res.data.message : (t.error || 'Something went wrong. Please try again.'), true);
		}

		function take(p) {
			if (p.grid) {
				state.grid = p.grid;
			}
			state.truncated = !!p.truncated;
			state.sheet = p.sheet || state.sheet;
			state.token = p.token;
			state.preview = p;
			if (p.mode !== state.mode) {
				state.mode = p.mode;
				setMode(p.mode);
			}
			// Drawing again must not throw the owner out of the row they are correcting: the control
			// that had the focus gets it back, and stays where it stood on the screen.
			var was = held();
			render(p);
			giveBack(was);
		}

		function held() {
			var active = root.document.activeElement;
			var out = { y: root.pageYOffset || 0, key: '', top: 0 };
			if (active && $preview[0] && $.contains($preview[0], active) && active.getAttribute('data-fk')) {
				out.key = active.getAttribute('data-fk');
				out.top = active.getBoundingClientRect().top;
			}
			return out;
		}

		function giveBack(was) {
			var $controls = $preview.find('[data-fk]');
			var at = focusIndex($controls.map(function () { return { key: this.getAttribute('data-fk') }; }).get(), was.key);
			if (at < 0) {
				if (typeof root.scrollTo === 'function' && Math.abs((root.pageYOffset || 0) - was.y) > 1) {
					root.scrollTo(root.pageXOffset || 0, was.y);
				}
				return;
			}
			var target = $controls[at];
			try {
				target.focus({ preventScroll: true });
			} catch (e) {
				target.focus();
			}
			if (typeof root.scrollTo === 'function') {
				root.scrollTo(root.pageXOffset || 0, (root.pageYOffset || 0) + target.getBoundingClientRect().top - was.top);
			}
		}

		/** Read a new paste or file: everything chosen before is forgotten. */
		function readNew(sheet) {
			if (busy) {
				return;
			}
			var file = $file[0] && $file[0].files && $file[0].files[0] ? $file[0].files[0] : null;
			var text = String($text.val() || '');
			if (!file && !$.trim(text)) {
				say(t.nothing, true);
				return;
			}
			state = fresh(currentMode());
			var extra = payloadOf(state);
			if (file) {
				if (sheet) {
					extra.sheet = sheet;
				}
			} else {
				extra.text = text;
			}
			run('preview', extra, file);
		}

		/** Draw the preview again with what the owner chose. */
		function refresh() {
			if (!state || !state.grid) {
				return;
			}
			if (busy) {
				again = true;
				return;
			}
			var extra = payloadOf(state);
			extra.grid = state.grid;
			extra.truncated = state.truncated ? 1 : 0;
			run('preview', extra, null);
		}

		function run(op, extra, file) {
			busy = true;
			say(t.reading || '…');
			$confirm.prop('disabled', true);
			send(op, extra, file)
				.done(function (res) {
					if (!res || !res.success) {
						failed({ responseJSON: res });
						return;
					}
					say('');
					take(res.data);
				})
				.fail(failed)
				.always(function () {
					busy = false;
					if (again) {
						again = false;
						refresh();
					}
				});
		}

		/* ---- Drawing ---- */

		function el(tag, cls, text) {
			var $e = $(root.document.createElement(tag));
			if (cls) {
				$e.addClass(cls);
			}
			if (typeof text !== 'undefined' && text !== null) {
				$e.text(String(text));
			}
			return $e;
		}

		function rowSaid(n) {
			if (!state.rows[n]) {
				state.rows[n] = {};
			}
			return state.rows[n];
		}

		function num(value) {
			return value === null || typeof value === 'undefined' ? '' : String(value);
		}

		/** The dropdown of a row's fish: the candidates, as typed, unknown. */
		function fishSelect(row) {
			var $select = el('select', 'fh-cr-input fh-cr-import__pick').attr('data-fk', 'pick:' + row.n);
			var chosen = false;
			(row.match.candidates || []).forEach(function (candidate, i) {
				var $o = el('option', '', candidate.name).val('c:' + i);
				if (row.kind === 'fish' && !chosen && String(candidate.name).toLowerCase() === String(row.name).toLowerCase()) {
					$o.prop('selected', true);
					chosen = true;
				}
				$select.append($o);
			});
			if (row.kind === 'fish' && !chosen) {
				$select.prepend(el('option', '', row.name).val('keep').prop('selected', true));
			}
			$select.append(el('option', '', t.asTyped).val('typed').prop('selected', row.kind === 'typed'));
			$select.append(el('option', '', t.unknown).val('unknown').prop('selected', row.kind === 'unknown'));
			$select.on('change', function () {
				var value = String($(this).val());
				var said = rowSaid(row.n);
				if (value === 'typed' || value === 'unknown') {
					said.pick = { kind: value };
				} else if (value === 'keep') {
					said.pick = { kind: 'fish', name: row.name, product_id: row.product_id || 0 };
				} else {
					var candidate = row.match.candidates[parseInt(value.slice(2), 10)];
					said.pick = { kind: 'fish', name: candidate.name, product_id: candidate.product_id || 0 };
				}
				if (state.mode === 'arrived') {
					refresh();
				} else {
					$(this).closest('tr').find('.fh-cr-import__match').removeClass('is-flag').text(t.picked);
				}
			});
			return $select;
		}

		function matchCell(row) {
			var $cell = el('td', 'fh-cr-import__fish');
			$cell.append(el('span', 'fh-cr-import__typed', row.typed !== '' ? row.typed : '—'));
			if (row.check) {
				$cell.append(' ').append(el('span', 'fh-cr-warn fh-cr-import__check', t.check).attr('title', t.checkTitle));
			}
			if (row.fee_word) {
				$cell.append(' ').append(el('span', 'fh-cr-warn fh-cr-import__check', t.feeWord).attr('title', t.feeWordTitle));
			}
			$cell.append(el('span', 'fh-cr-sub fh-cr-import__match' + (row.match.flag ? ' is-flag' : ''), row.match.text + (row.kind === 'fish' && !row.match.flag ? ': ' + row.name : '')));
			$cell.append(fishSelect(row));
			if (row.size && row.size.note) {
				$cell.append(el('span', 'fh-cr-sub', row.size.note));
			}
			if (row.label) {
				$cell.append(el('span', 'fh-cr-sub', fmt(t.label, row.label)));
			}
			return $cell;
		}

		function numberCell(row, role, value) {
			var bad = (row.flags || []).indexOf(role) !== -1;
			var $cell = el('td', 'fh-cr-num' + (bad ? ' is-bad' : ''), bad ? '?' : num(value));
			if (bad) {
				$cell.attr('title', t.badNumber);
			}
			return $cell;
		}

		function extrasText(row) {
			var parts = [];
			if (row.code) { parts.push(fmt(t.code, row.code)); }
			if (row.sci) { parts.push(row.sci); }
			if (row.per_box !== null) { parts.push(fmt(t.perBox, row.per_box)); }
			if (row.box !== null) { parts.push(fmt(t.box, row.box)); }
			if (row.pct !== null) { parts.push(fmt(t.pct, row.pct)); }
			if (row.doa !== null && row.doa > 0) { parts.push(fmt(t.doa, row.doa)); }
			if (row.origin) { parts.push(row.origin); }
			['box', 'pct', 'doa', 'per_box', 'tank'].forEach(function (role) {
				if ((row.flags || []).indexOf(role) !== -1) {
					parts.push(fmt(t.badCell, (cfg.roles || {})[role] || role));
				}
			});
			return parts.join(' · ');
		}

		function tick(row) {
			var $box = $('<input type="checkbox">').prop('checked', !!row.on).attr('data-fk', 'tick:' + row.n);
			$box.on('change', function () {
				var said = rowSaid(row.n);
				said.on = this.checked ? 1 : 0;
				row.on = this.checked;
				if (this.checked) {
					delete said.junk;
				}
				if (state.mode === 'arrived') {
					refresh();
					return;
				}
				$(this).closest('tr').toggleClass('is-off', !this.checked).find('.fh-cr-import__junk-wrap').prop('hidden', this.checked);
				drawFoot();
			});
			return $box;
		}

		function junkBox(row) {
			var $wrap = el('label', 'fh-cr-sub fh-cr-import__junk-wrap').prop('hidden', !!row.on);
			var $box = $('<input type="checkbox">').prop('checked', !!row.junk).on('change', function () {
				rowSaid(row.n).junk = this.checked ? 1 : 0;
			});
			return $wrap.append($box).append(' ' + t.junk);
		}

		function drawFoot() {
			var p = state.preview;
			if (!p || p.mode !== 'ordered') {
				return;
			}
			var foot = orderedFoot(p.rows);
			$preview.find('.fh-cr-import__foot-text').text(footText(foot, t));
			$confirm.prop('disabled', !p.columns.complete || foot.lines < 1).text(fmt(foot.lines === 1 ? t.addOne : t.addMany, thousands(foot.lines)));
		}

		function drawColumns(p) {
			var open = p.columns.open || state.columnsOpen;
			var $wrap = el('div', 'fh-cr-import__columns').prop('hidden', !open);
			var width = p.columns.width || 0;
			var map = p.columns.map || {};

			var $row = el('p', 'fh-cr-import__header-row');
			$row.append(el('span', '', t.headerOn + ' '));
			var $pick = el('select', 'fh-cr-input').attr('data-fk', 'head');
			$pick.append(el('option', '', t.noHeader).val(''));
			for (var r = 0; r < Math.min(30, (state.grid || []).length); r++) {
				$pick.append(el('option', '', fmt(t.rowN, r + 1) + ' — ' + (state.grid[r] || []).filter(function (c) { return c !== ''; }).slice(0, 4).join(' · ').slice(0, 60)).val(String(r)));
			}
			$pick.val(p.columns.row === null ? '' : String(p.columns.row));
			$row.append($pick);
			$wrap.append($row);

			var $table = el('table', 'widefat fh-cr-table fh-cr-import__sample');
			var $head = el('tr');
			var selects = [];
			function gather() {
				var chosen = {};
				selects.forEach(function ($s, i) {
					if ($s.val()) {
						chosen[i] = $s.val();
					}
				});
				state.hand = true;
				state.useLayout = '';
				state.map = chosen;
				state.headerRow = $pick.val() === '' ? null : parseInt($pick.val(), 10);
				state.columnsOpen = true;
				state.rows = {};
				state.lines = {};
				refresh();
			}
			for (var c = 0; c < width; c++) {
				var $select = el('select', 'fh-cr-input fh-cr-import__role').attr('aria-label', fmt(t.columnN, c + 1)).attr('data-fk', 'role:' + c);
				$select.append(el('option', '', t.ignore).val(''));
				Object.keys(cfg.roles || {}).forEach(function (role) {
					$select.append(el('option', '', cfg.roles[role]).val(role));
				});
				$select.val(map[c] || '');
				$select.on('change', function () {
					// One column per role: taking a role frees it elsewhere.
					var mine = this;
					var role = $(this).val();
					selects.forEach(function ($s) {
						if ($s[0] !== mine && role && $s.val() === role) {
							$s.val('');
						}
					});
					gather();
				});
				selects.push($select);
				$head.append(el('th').append(el('span', 'fh-cr-sub', t.thisColumn)).append($select));
			}
			$pick.on('change', gather);
			$table.append(el('thead').append($head));
			var $body = el('tbody');
			if (p.columns.headers && p.columns.headers.length) {
				var $h = el('tr', 'fh-cr-import__sample-head');
				for (var h = 0; h < width; h++) {
					$h.append(el('td', '', p.columns.headers[h] || ''));
				}
				$body.append($h);
			}
			(p.columns.sample || []).forEach(function (cells) {
				var $tr = el('tr');
				for (var i = 0; i < width; i++) {
					$tr.append(el('td', '', cells[i] || ''));
				}
				$body.append($tr);
			});
			$table.append($body);
			$wrap.append(el('div', 'fh-cr-grid-wrap').append($table));
			return $wrap;
		}

		function drawLayout(p) {
			var $wrap = el('div', 'fh-cr-import__layout');
			var $line = el('p', 'fh-cr-import__layout-line');
			if (p.layout.line) {
				$line.append(el('span', '', p.layout.line + ' · '));
			} else if (p.columns.source === 'headers') {
				$line.append(el('span', '', t.byHeaders + ' · '));
			} else if (p.columns.source === 'guess') {
				$line.append(el('span', '', t.byGuess + ' · '));
			} else if (p.columns.source === 'hand') {
				$line.append(el('span', '', t.byHand + ' · '));
			}
			$line.append(el('button', 'button-link fh-cr-import__change', t.change).attr('type', 'button').on('click', function () {
				state.columnsOpen = !state.columnsOpen;
				$preview.find('.fh-cr-import__columns').prop('hidden', !(p.columns.open || state.columnsOpen));
			}));
			$wrap.append($line);
			if (p.layout.partial) {
				$wrap.append(el('p', 'fh-cr-warn', p.layout.partial));
			}
			if (p.layout.offer) {
				var $offer = el('p', 'fh-cr-import__offer');
				$offer.append(el('span', '', p.layout.offer.text + ' '));
				var $set = null;
				$offer.append(el('button', 'button button-small', t.useLayout).attr('type', 'button').on('click', function () {
					if ($set && $set.prop('checked')) {
						$order.find('[name="supplier"]').val(p.layout.offer.supplier).trigger('change');
						if (p.layout.offer.origin && !$.trim($order.find('[name="origin"]').val())) {
							$order.find('[name="origin"]').val(p.layout.offer.origin).trigger('change');
						}
					}
					state.useLayout = p.layout.offer.id;
					state.hand = false;
					state.rows = {};
					state.lines = {};
					refresh();
				}));
				if (p.layout.offer.set) {
					$set = $('<input type="checkbox" checked>');
					$offer.append(' ').append(el('label', '').append($set).append(' ' + p.layout.offer.set));
				}
				$wrap.append($offer);
			}
			if (p.sheets && p.sheets.length > 1) {
				var $sheet = el('p', 'fh-cr-import__sheet');
				$sheet.append(el('span', '', t.sheet + ' '));
				var $s = el('select', 'fh-cr-input');
				p.sheets.forEach(function (name) {
					$s.append(el('option', '', name).val(name));
				});
				$s.val(p.sheet);
				$s.on('change', function () { readNew($(this).val()); });
				$wrap.append($sheet.append($s));
			}
			return $wrap;
		}

		function drawOrdered(p) {
			var $table = el('table', 'widefat fh-cr-table fh-cr-import__rows');
			var $head = el('tr');
			['', t.thFish, t.thSize, t.thOrdered, t.thReceived, t.thPrice, t.thMore, t.thDup].forEach(function (title) {
				$head.append(el('th', '', title));
			});
			$table.append(el('thead').append($head));
			var $body = el('tbody');
			p.rows.forEach(function (row) {
				var $tr = el('tr', (row.on ? '' : 'is-off') + (row.match.flag || row.check || row.fee_word || (row.flags || []).length ? ' is-flagged' : ''));
				$tr.append(el('td', 'fh-cr-import__tick').append(tick(row)).append(junkBox(row)));
				$tr.append(matchCell(row));
				$tr.append(el('td', '', row.size.label || ''));
				$tr.append(numberCell(row, row.flags.indexOf('qty') !== -1 ? 'qty' : 'ordered', row.ordered));
				$tr.append(numberCell(row, 'received', row.received));
				$tr.append(numberCell(row, 'price', row.price));
				$tr.append(el('td', 'fh-cr-sub', extrasText(row)));
				var $dup = el('td', 'fh-cr-import__dup');
				if (row.dup) {
					$dup.append(el('span', 'fh-cr-warn', fmt(t.dup, row.dup)));
					var $choice = el('select', 'fh-cr-input').attr('data-fk', 'dup:' + row.n);
					$choice.append(el('option', '', t.dupSkip).val('skip'));
					$choice.append(el('option', '', t.dupAdd).val('add'));
					$choice.val(row.dup_choice || 'skip').on('change', function () {
						rowSaid(row.n).dup = $(this).val();
						row.dup_choice = $(this).val();
						drawFoot();
					});
					$dup.append($choice);
				}
				$tr.append($dup);
				$body.append($tr);
			});
			$table.append($body);
			return el('div', 'fh-cr-grid-wrap').append($table);
		}

		/** A pairing dropdown, filled when it is first opened (an order has up to 400 lines). */
		function pairSelect(row, a, current) {
			var $select = el('select', 'fh-cr-input fh-cr-import__pair').attr('data-fk', 'pair:' + row.n);
			var said = (state.rows[row.n] || {}).pair || '';
			$select.append(el('option', '', current).val(said));
			var filled = false;
			function fill() {
				if (filled) {
					return;
				}
				filled = true;
				$select.empty();
				$select.append(el('option', '', t.pairAuto).val(''));
				$select.append(el('option', '', t.pairNew).val('new'));
				a.lines.forEach(function (line) {
					$select.append(el('option', '', fmt(t.pairLine, line.text)).val('line:' + line.key));
				});
				a.lines.forEach(function (line) {
					$select.append(el('option', '', fmt(t.pairInstead, line.text)).val('instead:' + line.key));
				});
				$select.val(said);
			}
			$select.on('focus mousedown keydown', fill);
			$select.on('change', function () {
				rowSaid(row.n).pair = $(this).val();
				refresh();
			});
			return $select;
		}

		function drawArrived(p) {
			var a = p.arrived;
			var $wrap = el('div', 'fh-cr-import__arrived');
			var rowOf = {};
			p.rows.forEach(function (row) { rowOf[row.n] = row; });

			if (a.sections.length > 1) {
				var $sections = el('p', 'fh-cr-import__sections');
				$sections.append(el('strong', '', t.sections + ' '));
				a.sections.forEach(function (section) {
					var $box = $('<input type="checkbox">').prop('checked', !!section.on).attr('data-fk', 'section:' + section.name).on('change', function () {
						var chosen = [];
						$sections.find('input').each(function (i) {
							if (this.checked) {
								chosen.push(a.sections[i].name);
							}
						});
						state.sections = chosen.length === a.sections.length ? null : chosen;
						refresh();
					});
					$sections.append(el('label', 'fh-cr-import__section').append($box).append(' ' + fmt(t.sectionN, section.name, section.count))).append(' ');
				});
				$wrap.append($sections);
			}

			var $same = el('p', 'fh-cr-import__same');
			$same.append(el('span', '', t.sameFish + ' '));
			var $sameSel = el('select', 'fh-cr-input').attr('data-fk', 'same');
			$sameSel.append(el('option', '', t.sameSum).val('sum'));
			$sameSel.append(el('option', '', t.sameSeparate).val('separate'));
			$sameSel.val(a.same_fish).on('change', function () {
				state.sameFish = $(this).val();
				refresh();
			});
			$wrap.append($same.append($sameSel));

			a.groups.forEach(function (group) {
				if (!group.entries.length) {
					return;
				}
				var $group = el('div', 'fh-cr-import__group fh-cr-import__group--' + group.key);
				$group.append(el('h3', '', group.title + ' (' + group.entries.length + ')'));
				if (group.hint) {
					$group.append(el('p', 'fh-cr-sub', group.hint));
				}
				var $list = el('table', 'widefat fh-cr-table');
				var $body = el('tbody');
				group.entries.forEach(function (entry) {
					var $tr = el('tr');
					if (group.key === 'extra') {
						var row = rowOf[entry.row];
						$tr.append(el('td', 'fh-cr-import__tick').append(tick(row)));
						$tr.append(matchCell(row));
						$tr.append(el('td', '', row.size.label || ''));
						$tr.append(el('td', 'fh-cr-num', fmt(t.arrivedN, entry.arrived)));
						$tr.append(el('td', 'fh-cr-sub', extrasText(row) + (row.price !== null ? ' · ' + fmt(t.priceN, row.price) : '')));
						var $pair = el('td').append(pairSelect(row, a, entry.instead ? t.pairInsteadSet : t.pairNew));
						if (entry.note) {
							$pair.append(el('span', 'fh-cr-warn fh-cr-import__could', entry.note));
						}
						$tr.append($pair);
						$body.append($tr);
						return;
					}
					$tr.append(el('td', 'fh-cr-import__line').append(el('strong', '', entry.text)).append(el('span', 'fh-cr-sub', entry.notes.join(' · '))));
					var detail = entry.detail;
					if (group.key === 'missing' && entry.keep) {
						detail = fmt(t.kept, entry.was === null ? 0 : entry.was);
					}
					$tr.append(el('td', entry.over ? 'fh-cr-import__over' : '', detail));
					var $rows = el('td');
					if (group.key === 'missing') {
						var $what = el('select', 'fh-cr-input').attr('data-fk', 'line:' + entry.line);
						$what.append(el('option', '', t.lineZero).val('zero'));
						$what.append(el('option', '', t.lineKeep).val('keep'));
						$what.val(entry.choice).on('change', function () {
							state.lines[entry.line] = $(this).val();
							refresh();
						});
						$rows.append($what);
					}
					entry.rows.forEach(function (n) {
						var r = rowOf[n];
						if (!r) {
							return;
						}
						var $one = el('div', 'fh-cr-import__paired');
						$one.append(tick(r)).append(' ');
						$one.append(el('span', '', (r.typed || '—') + ' · ' + fmt(t.arrivedN, r.received === null ? 0 : r.received) + (r.box !== null ? ' · ' + fmt(t.box, r.box) : '')));
						if (r.check) {
							$one.append(' ').append(el('span', 'fh-cr-warn', t.check).attr('title', t.checkTitle));
						}
						if ((r.flags || []).length) {
							$one.append(' ').append(el('span', 'fh-cr-warn', t.badRow).attr('title', t.badNumber));
						}
						if (group.key !== 'stay') {
							$one.append(' ').append(pairSelect(r, a, t.pairThis));
						}
						$rows.append($one);
					});
					$tr.append($rows);
					$body.append($tr);
				});
				$group.append($list.append($body));
				$wrap.append($group);
			});

			// Rows the owner unticked: still listed, so a tick can come back.
			var off = p.rows.filter(function (row) { return !row.on; });
			if (off.length) {
				var $off = el('div', 'fh-cr-import__group fh-cr-import__group--off');
				$off.append(el('h3', '', fmt(t.unticked, off.length)));
				off.forEach(function (row) {
					$off.append(el('div', 'fh-cr-import__paired').append(tick(row)).append(' ').append(el('span', '', (row.typed || '—') + ' · ' + fmt(t.arrivedN, row.received === null ? 0 : row.received))));
				});
				$wrap.append($off);
			}

			if (a.differs) {
				var $price = $('<input type="checkbox">').prop('checked', !!state.useFilePrice).on('change', function () {
					state.useFilePrice = this.checked;
				});
				$wrap.append(el('p', 'fh-cr-import__price').append(el('label', '').append($price).append(' ' + t.useFilePrice)));
			}

			if (a.fees.count > 0) {
				var $fees = el('div', 'fh-cr-import__fees');
				$fees.append(el('p', '').append(el('strong', '', a.fees.text)));
				var $details = el('details', '');
				$details.append(el('summary', '', t.feeLines));
				var $ul = el('ul', '');
				a.fees.items.forEach(function (item) { $ul.append(el('li', '', item)); });
				$fees.append($details.append($ul));
				a.fees.notes.forEach(function (note) { $fees.append(el('p', 'fh-cr-sub', note)); });
				var $put = $('<input type="checkbox">').prop('checked', !!state.fees).on('change', function () {
					state.fees = this.checked;
					$fees.find('.fh-cr-import__fees-replace').prop('hidden', !(this.checked && a.fees.typed));
				});
				$fees.append(el('p', '').append(el('label', '').append($put).append(' ' + t.putFees)));
				var $replace = $('<input type="checkbox">').prop('checked', !!state.feesReplace).on('change', function () {
					state.feesReplace = this.checked;
				});
				$fees.append(el('p', 'fh-cr-import__fees-replace').prop('hidden', !(state.fees && a.fees.typed)).append(el('label', '').append($replace).append(' ' + t.replaceFees)));
				$wrap.append($fees);
			}
			if (a.sum) {
				$wrap.append(el('p', 'fh-cr-import__sum' + (a.sum_ok ? '' : ' fh-cr-warn'), a.sum));
			}
			return $wrap;
		}

		function render(p) {
			$preview.empty();
			$preview.append(drawLayout(p));
			$preview.append(drawColumns(p));
			(p.notes || []).forEach(function (note) {
				$preview.append(el('p', 'fh-cr-warn', note));
			});
			if (p.summary) {
				$preview.append(el('p', 'fh-cr-import__summary', p.summary));
			}
			if (p['switch']) {
				// A sheet of received counts under "This is what I ORDERED": the other button, one press away.
				var $switch = el('p', 'fh-cr-warn fh-cr-import__switch');
				$switch.append(el('span', '', p['switch'].text + ' '));
				$switch.append(el('button', 'button button-primary', p['switch'].button).attr('type', 'button').on('click', function () {
					$panel.find('.fh-cr-import__mode[data-mode="' + (p['switch'].mode === 'arrived' ? 'arrived' : 'ordered') + '"]').trigger('click');
				}));
				$preview.append($switch);
			}
			if (p.left_out && p.left_out.count > 0) {
				var $left = el('details', 'fh-cr-import__left');
				$left.append(el('summary', '', p.left_out.text + ': ' + p.left_out.items.slice(0, 3).join('; ') + (p.left_out.items.length > 3 ? ' …' : '')));
				var $ul = el('ul', '');
				p.left_out.items.forEach(function (item) { $ul.append(el('li', '', item)); });
				$preview.append($left.append($ul));
			}
			if (p.columns.complete) {
				$preview.append(p.mode === 'arrived' && p.arrived ? drawArrived(p) : drawOrdered(p));
				$preview.append(el('p', 'fh-cr-import__foot-text', p.foot));
			}
			var $rememberWrap = $panel.find('.fh-cr-import__remember-wrap');
			if (p.layout.remember) {
				$rememberWrap.prop('hidden', false).find('span').text(p.layout.remember);
				$rememberWrap.find('input').prop('checked', state.remember);
			} else {
				$rememberWrap.prop('hidden', true);
			}
			$panel.find('.fh-cr-import__actions').prop('hidden', false);
			if (p.mode === 'ordered') {
				drawFoot();
				$confirm.prop('disabled', !p.can_confirm || $confirm.prop('disabled'));
			} else {
				$confirm.prop('disabled', !p.can_confirm).text(t.writeArrived);
			}
		}

		/* ---- Events ---- */

		function open(show) {
			$panel.prop('hidden', !show);
			$order.find('.fh-cr-import-open').attr('aria-expanded', show ? 'true' : 'false');
			if (show) {
				setMode(currentMode());
				$text.trigger('focus');
				if ($panel[0].scrollIntoView) {
					$panel[0].scrollIntoView({ block: 'nearest' });
				}
			}
		}

		$order.on('click', '.fh-cr-import-open', function () {
			open($panel.prop('hidden'));
		});
		$panel.on('click', '.fh-cr-import__mode', function () {
			setMode($(this).attr('data-mode'));
			if (state && state.grid) {
				state.mode = currentMode();
				state.hand = false;
				state.map = null;
				state.useLayout = '';
				state.rows = {};
				state.lines = {};
				state.sections = null;
				refresh();
			}
		});
		// A saved layout belongs to a supplier: when the order's supplier or origin changes, look again.
		$order.on('change', '[name="supplier"], [name="origin"]', function () {
			if (state && state.grid && !state.hand) {
				state.useLayout = '';
				refresh();
			}
		});
		$panel.on('click', '.fh-cr-import__read', function () { readNew(''); });
		$panel.on('change', '.fh-cr-import__file', function () {
			if (this.files && this.files.length) {
				readNew('');
			}
		});
		$panel.on('change', '.fh-cr-import__too', function () {
			if (state && state.grid) {
				state.too = this.checked;
				refresh();
			}
		});
		$panel.on('change', '.fh-cr-import__remember', function () {
			if (state) {
				state.remember = this.checked;
			}
		});
		$panel.on('click', '.fh-cr-import__cancel', function () {
			state = null;
			$preview.empty();
			$panel.find('.fh-cr-import__actions').prop('hidden', true);
			$text.val('');
			$file.val('');
			say('');
			open(false);
		});
		// Enter in the panel's fields is never the order's Save.
		$panel.on('keydown', 'input, select', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
			}
		});

		$panel.on('click', '.fh-cr-import__copy', function () {
			var area = $panel.find('.fh-cr-import__instructions')[0];
			var $done = $panel.find('.fh-cr-import__copied');
			function copied() { $done.text(t.copied); }
			if (root.navigator && root.navigator.clipboard && root.navigator.clipboard.writeText) {
				root.navigator.clipboard.writeText(area.value).then(copied, function () {
					area.select();
					root.document.execCommand('copy');
					copied();
				});
				return;
			}
			area.select();
			root.document.execCommand('copy');
			copied();
		});

		$confirm.on('click', function () {
			if (!state || !state.grid || busy) {
				return;
			}
			busy = true;
			$confirm.prop('disabled', true);
			say(t.saving || '…');
			var extra = payloadOf(state);
			extra.grid = state.grid;
			send('confirm', extra, null)
				.done(function (res) {
					if (!res || !res.success) {
						failed({ responseJSON: res });
						return;
					}
					say(res.data.message || '');
					root.location.href = res.data.redirect || root.location.href;
				})
				.fail(failed)
				.always(function () { busy = false; });
		});

		if ($panel.attr('data-open') === '1') {
			open(true);
		}
	});
})(typeof window !== 'undefined' ? window : globalThis, typeof jQuery !== 'undefined' ? jQuery : null);
