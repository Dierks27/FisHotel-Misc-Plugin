/**
 * Clean Room admin — the settings save, the protocol editor, the layout
 * editor, the alias list, the box-rates editor, the Stays-tab edits
 * (counts, dates, a move with an optional reason, the fields incl. the
 * check-in date), the Events-tab Undo, the Coming Soon release button,
 * the order editor (lines, species suggestions, Save with the recomputed
 * figures, Check in, Delete, the import-fees suggestion) and the Species
 * tab (inline edit, add, delete, the product link).
 *
 * The server renders every row; this file posts and fills in. The pure
 * helpers (renumber(), suggestImportFees()) are exported for the node
 * test; the rest needs jQuery and the page.
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

	var api = { renumber: renumber, suggestImportFees: suggestImportFees };

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
				// Pre-filled with the group's own date, else the suggestion (check-in + the setting); nothing is saved until OK.
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
			$tr.find('input').each(function () { fields[this.name] = this.value; });
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
