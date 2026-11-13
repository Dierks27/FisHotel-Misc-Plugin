/**
 * Clean Room admin — the temporary tanks on the Room Board and the To do
 * tab. Loaded after clean-room-admin.js with window.fhCleanRoomTemp
 * (ajaxUrl, action, nonce, i18n).
 *
 * Every write is one AJAX action (fishotel_cleanroom_temp) carrying an
 * `act` — the same acts the phone posts: setup, setup_edit, setup_steps,
 * takedown, todo_tick. The server answers with the sentence to show; the
 * page then reloads on the room as it is.
 *
 * "Set up a temporary tank": choosing what it is for offers the treatment
 * schedules that carry that word first, and whichever schedule is picked
 * is shown — which steps, which medication, which days — before anything
 * is saved. The pure helpers (offer, previewLines, stepsFromRows) are
 * exported for tests/temp-tanks.test.js and the file skips the DOM
 * wiring when there is no jQuery.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

/* global module */
(function (root, $) {
	'use strict';

	/**
	 * The schedules a "what is it for" offers: the ones carrying that tag
	 * (whatever its case) first, the rest after. A tag no template carries
	 * offers none first.
	 *
	 * @param {Object[]} templates name, tags[].
	 * @param {string}   tag       What it is for.
	 * @return {{matching: string[], others: string[]}}
	 */
	function offer(templates, tag) {
		var wanted = String(tag === null || typeof tag === 'undefined' ? '' : tag).replace(/\s+/g, ' ').replace(/^ +| +$/g, '').toLowerCase();
		var out = { matching: [], others: [] };
		(templates || []).forEach(function (template) {
			var carries = wanted !== '' && (template.tags || []).some(function (candidate) {
				return String(candidate).toLowerCase() === wanted;
			});
			(carries ? out.matching : out.others).push(String(template.name));
		});
		return out;
	}

	/**
	 * What a schedule does, one line per step that is not the feeding:
	 * "Furan-2 — days 2, 4, 6" and, when the step names a medication its
	 * label does not already spell out, " · Metronidazole".
	 *
	 * @param {Object|null} template name, preview[] (label, when, medication).
	 * @return {string[]}
	 */
	function previewLines(template) {
		if (!template || !template.preview) {
			return [];
		}
		return template.preview.map(function (step) {
			var line = String(step.label) + ' — ' + String(step.when);
			var medication = String(step.medication || '');
			if (medication && String(step.label).toLowerCase().indexOf(medication.toLowerCase()) === -1) {
				line += ' · ' + medication;
			}
			return line;
		});
	}

	/**
	 * The rows of a schedule editor as the steps the server saves: rows
	 * without a name are left out.
	 *
	 * @param {Object[]} rows label, kind, day, every, until, note (as typed).
	 * @return {Object[]}
	 */
	function stepsFromRows(rows) {
		var out = [];
		(rows || []).forEach(function (row) {
			var label = String(row.label === null || typeof row.label === 'undefined' ? '' : row.label).replace(/^\s+|\s+$/g, '');
			if (label === '') {
				return;
			}
			out.push({
				label: label,
				kind: String(row.kind || 'other'),
				day: parseInt(row.day, 10) || 1,
				every: parseInt(row.every, 10) || 0,
				until: row.until === '' || row.until === null || typeof row.until === 'undefined' ? '' : (parseInt(row.until, 10) || ''),
				where: 'any',
				note: String(row.note === null || typeof row.note === 'undefined' ? '' : row.note)
			});
		});
		return out;
	}

	var api = {
		offer: offer,
		previewLines: previewLines,
		stepsFromRows: stepsFromRows
	};

	root.fhCleanRoomTempHelpers = api;

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}

	if (!$) {
		return;
	}

	var cfg = root.fhCleanRoomTemp || {};
	var i18n = cfg.i18n || {};

	function say($status, ok, message) {
		$status.removeClass('is-ok is-error').addClass(ok ? 'is-ok' : 'is-error').text(message);
	}

	function post(fields, $status, $buttons) {
		var data = $.extend({ action: cfg.action, nonce: cfg.nonce }, fields);
		if ($buttons) {
			$buttons.prop('disabled', true);
		}
		return $.post(cfg.ajaxUrl || root.ajaxurl, data)
			.done(function (res) {
				if (!res || !res.success) {
					say($status, false, (res && res.data && res.data.message) || i18n.error || 'Something went wrong.');
					if ($buttons) {
						$buttons.prop('disabled', false);
					}
					return;
				}
				say($status, true, ((res.data && res.data.message) || '') + ' ' + (i18n.reloading || 'Reloading…'));
				root.setTimeout(function () { root.location.reload(); }, 700);
			})
			.fail(function (res) {
				say($status, false, (res && res.responseJSON && res.responseJSON.data && res.responseJSON.data.message) || i18n.error || 'Something went wrong.');
				if ($buttons) {
					$buttons.prop('disabled', false);
				}
			});
	}

	$(function () {
		var $form = $('.fh-cr-temp-form');
		var $status = $('.fh-cr-temp-status');
		var templates = [];
		try {
			templates = JSON.parse($form.attr('data-templates') || '[]') || [];
		} catch (e) {
			templates = [];
		}

		function purpose() {
			return $.trim($form.find('.fh-cr-temp-purpose-text').val() || '');
		}

		function templateByName(name) {
			for (var i = 0; i < templates.length; i++) {
				if (templates[i].name === name) {
					return templates[i];
				}
			}
			return null;
		}

		/* The treatment choices for what it is for: the schedules carrying that word first. */
		function rebuildTreatment() {
			var $select = $form.find('.fh-cr-temp-template');
			var before = $select.val();
			var offered = offer(templates, purpose());
			$select.empty();
			function add($host, names) {
				names.forEach(function (name) {
					$host.append($('<option></option>').attr('value', name).text(name));
				});
			}
			if (offered.matching.length) {
				add($('<optgroup></optgroup>').attr('label', i18n.usedFor || 'Used for this').appendTo($select), offered.matching);
				add($('<optgroup></optgroup>').attr('label', i18n.others || 'Other schedules').appendTo($select), offered.others);
				$select.append($('<option></option>').attr('value', '').text(i18n.noTreatment || 'No treatment, just watching'));
				$select.val(offered.matching[0]);
			} else {
				$select.append($('<option></option>').attr('value', '').text(i18n.noTreatment || 'No treatment, just watching'));
				add($select, offered.others);
				$select.val(before && templateByName(before) ? before : '');
			}
			renderPreview(!offered.matching.length && purpose() !== '');
		}

		/* What the chosen schedule does — before anything is saved. */
		function renderPreview(nothingMarked) {
			var $box = $form.find('.fh-cr-temp-preview').empty();
			var template = templateByName($form.find('.fh-cr-temp-template').val());
			if (nothingMarked) {
				$('<p class="fh-cr-sub"></p>').text((i18n.noneForTag || 'No schedule is marked "%s" yet.').replace('%s', purpose())).appendTo($box);
			}
			if (!template) {
				$('<p class="fh-cr-sub"></p>').text(i18n.justFeeding || 'Only the daily feeding, while fish are in it.').appendTo($box);
				return;
			}
			$('<p class="fh-cr-sub"></p>').text((i18n.whatItDoes || 'What it does:') + ' ' + template.name).appendTo($box);
			var $list = $('<ul class="fh-cr-temp-preview__list"></ul>').appendTo($box);
			previewLines(template).forEach(function (line) {
				$('<li></li>').text(line).appendTo($list);
			});
			$('<li class="fh-cr-muted"></li>').text(i18n.feedingToo || 'plus the daily feeding').appendTo($list);
		}

		$(document).on('click', '.fh-cr-temp-open', function () {
			var slot = $(this).data('slot');
			$form.prop('hidden', false);
			if (slot) {
				$form.find('[name="slot"]').val(String(slot));
			}
			rebuildTreatment();
			$form.find('[name="name"]').trigger('focus');
			if ($form.length && $form[0].scrollIntoView) {
				$form[0].scrollIntoView({ block: 'center' });
			}
		});

		$form.on('click', '.fh-cr-temp-cancel', function () {
			$form.prop('hidden', true);
		});

		$form.on('change', '.fh-cr-temp-purpose', function () {
			var value = $(this).val();
			var $text = $form.find('.fh-cr-temp-purpose-text');
			if (value === '__other') {
				$text.val('').prop('hidden', false).trigger('focus');
			} else {
				$text.val(value).prop('hidden', true);
			}
			rebuildTreatment();
		});

		$form.on('input', '.fh-cr-temp-purpose-text', rebuildTreatment);

		$form.on('change', '.fh-cr-temp-template', function () {
			renderPreview(false);
		});

		$form.on('submit', function (e) {
			e.preventDefault();
			post({
				act: 'setup',
				slot: $form.find('[name="slot"]').val() || '',
				name: $form.find('[name="name"]').val() || '',
				purpose: purpose(),
				kind: $form.find('[name="kind"]:checked').val() || '',
				gallons: $form.find('[name="gallons"]').val() || '',
				template: $form.find('[name="template"]').val() || '',
				start_date: $form.find('[name="start_date"]').val() || '',
				note: $form.find('[name="note"]').val() || ''
			}, $status, $form.find('button'));
			return false;
		});

		/* A setup's card: its panels, Edit, its own schedule, Take down. */
		$(document).on('click', '.fh-cr-temp-toggle', function () {
			var $card = $(this).closest('.fh-cr-tank--temp');
			var name = $(this).data('panel');
			$card.find('.fh-cr-temp-panel').each(function () {
				var $panel = $(this);
				$panel.prop('hidden', $panel.data('panel') !== name || !$panel.prop('hidden'));
			});
		});

		/*
		 * Which setup a card's write is about: the one the page was showing
		 * (its id, and for an edit the token of what it was) — never
		 * "whatever stands in that slot now".
		 */
		function setupOf($inside) {
			var $card = $inside.closest('.fh-cr-tank--temp');
			return {
				tank: $card.data('tank'),
				setup_id: String($card.attr('data-setup') || ''),
				seen: String($card.attr('data-seen') || '')
			};
		}

		$(document).on('submit', '.fh-cr-temp-edit', function (e) {
			e.preventDefault();
			var $panel = $(this);
			post($.extend(setupOf($panel), {
				act: 'setup_edit',
				name: $panel.find('[name="name"]').val() || '',
				purpose: $panel.find('[name="purpose"]').val() || '',
				gallons: $panel.find('[name="gallons"]').val() || '',
				start_date: $panel.find('[name="start_date"]').val() || '',
				note: $panel.find('[name="note"]').val() || ''
			}), $status, $panel.find('button'));
			return false;
		});

		$(document).on('click', '.fh-cr-temp-step-remove', function () {
			$(this).closest('tr').remove();
		});

		$(document).on('click', '.fh-cr-temp-step-add', function () {
			var $body = $(this).closest('.fh-cr-temp-steps').find('tbody');
			var $row = $('<tr class="fh-cr-temp-step">' +
				'<td><input type="text" class="fh-cr-input" data-field="label" maxlength="80"></td>' +
				'<td><select class="fh-cr-input" data-field="kind"><option value="feed">feed</option><option value="dose" selected>dose</option><option value="test">test</option><option value="water_change">water change</option><option value="other">other</option></select></td>' +
				'<td><input type="number" class="fh-cr-input fh-cr-input--num" data-field="day" min="1" max="120" value="1"></td>' +
				'<td><input type="number" class="fh-cr-input fh-cr-input--num" data-field="every" min="0" max="60" value="0"></td>' +
				'<td><input type="number" class="fh-cr-input fh-cr-input--num" data-field="until" min="1" max="120"></td>' +
				'<td><input type="text" class="fh-cr-input" data-field="note" maxlength="200"></td>' +
				'<td><button type="button" class="button button-small fh-cr-temp-step-remove">&times;</button></td>' +
				'</tr>');
			$body.append($row);
			$row.find('input').first().trigger('focus');
		});

		$(document).on('submit', '.fh-cr-temp-steps', function (e) {
			e.preventDefault();
			var $panel = $(this);
			var rows = $panel.find('tr.fh-cr-temp-step').map(function () {
				var row = {};
				$(this).find('[data-field]').each(function () {
					row[$(this).data('field')] = $(this).val();
				});
				return row;
			}).get();
			post($.extend(setupOf($panel), {
				act: 'setup_steps',
				op: 'save',
				steps: stepsFromRows(rows)
			}), $status, $panel.find('button'));
			return false;
		});

		$(document).on('click', '.fh-cr-temp-down', function () {
			var $card = $(this).closest('.fh-cr-tank--temp');
			if (!root.confirm((i18n.confirmDown || 'Take %s down?').replace('%s', String($card.data('name') || '')))) {
				return;
			}
			post($.extend(setupOf($card), { act: 'takedown' }), $status, $card.find('button'));
		});

		/* The To do tab: one tick per line — for the whole system, or for one tank. */
		$(document).on('click', '.fh-cr-todo-tick', function () {
			var $button = $(this);
			post({
				act: 'todo_tick',
				scope: $button.data('scope'),
				building: $button.data('building') || 0,
				tick_tank: $button.data('tank') || 0,
				key: String($button.attr('data-key') || ''), // As written: .data() would turn "12" into a number.
				kind: String($button.attr('data-kind') || ''),
				label: String($button.attr('data-label') || '')
			}, $('.fh-cr-todo-status'), $button);
		});
	});
})(typeof window !== 'undefined' ? window : globalThis, typeof jQuery !== 'undefined' ? jQuery : null);
