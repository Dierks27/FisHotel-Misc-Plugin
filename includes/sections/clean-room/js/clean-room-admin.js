/**
 * Clean Room admin — the settings save, the protocol editor, the layout
 * editor, the alias list, the Stays-tab edits (counts, dates, a move with
 * an optional reason, the fields incl. the check-in date), the Events-tab
 * Undo and the Coming Soon release button.
 *
 * The server renders every row; this file only posts and reloads. The
 * pure helper (stepRow index renumbering) is exported for completeness;
 * the behaviour under test lives in js/front-desk.js.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

/* global jQuery, module */
(function (root, $) {
	'use strict';

	/**
	 * Renumber the steps[n][…] names of a protocol table so the posted
	 * array is dense after rows were removed.
	 *
	 * @param {string[]} names The input names in row order.
	 * @param {number}   row   The row index to stamp.
	 * @return {string[]}
	 */
	function renumber(names, row) {
		return (names || []).map(function (name) {
			return String(name).replace(/^steps\[\d+\]/, 'steps[' + row + ']');
		});
	}

	var api = { renumber: renumber };

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

	$(function () {
		// Settings form.
		$('#fishotel-cleanroom-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('button[type="submit"]').prop('disabled', true);
			$.post(cfg.ajaxUrl || root.ajaxurl, $form.serialize())
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
			$.post(cfg.ajaxUrl || root.ajaxurl, $protocol.serialize())
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

		$protocol.on('click', '.fh-cr-protocol-reset', function () {
			if (!root.confirm(i18n.confirmReset || 'Reset?')) {
				return;
			}
			$.post(cfg.ajaxUrl || root.ajaxurl, { action: 'fishotel_cleanroom_protocol', _wpnonce: cfg.settingsNonce, reset: 1 })
				.done(function (res) {
					if (!res || !res.success) {
						fail(res);
						return;
					}
					notice('fishotel-misc-notice--success', i18n.reloading || 'Saved. Reloading…');
					root.setTimeout(function () { root.location.reload(); }, 700);
				})
				.fail(fail);
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
			$.post(cfg.ajaxUrl || root.ajaxurl, $.param(data))
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
			$.post(cfg.ajaxUrl || root.ajaxurl, { action: 'fishotel_cleanroom_alias_delete', _wpnonce: cfg.settingsNonce, id: $row.data('alias') })
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
			$.post(cfg.ajaxUrl || root.ajaxurl, fields)
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
			} else if (op === 'clear' || op === 'ready') {
				var date = root.prompt(i18n.which || 'Which date (YYYY-MM-DD)? Leave empty to clear.', '');
				if (date === null) {
					return;
				}
				fields.date = $.trim(date);
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
				$.post(cfg.ajaxUrl || root.ajaxurl, { action: 'fishotel_cleanroom_stay', nonce: cfg.nonce, op: 'note', stay_id: $tr.data('stay'), note: note });
			}
		});

		$(document).on('click', '.fh-cr-release', function () {
			var $row = $(this).closest('tr');
			var $status = $('.fh-cr-status').first();
			$.post(cfg.ajaxUrl || root.ajaxurl, { action: 'fishotel_cleanroom_release', nonce: cfg.nonce, stay_id: $row.data('stay') })
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
	});
})(typeof window !== 'undefined' ? window : globalThis, typeof jQuery !== 'undefined' ? jQuery : null);
