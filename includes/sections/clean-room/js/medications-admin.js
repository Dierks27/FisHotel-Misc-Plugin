/**
 * Clean Room medications, the admin side — Settings → Medications (the
 * list editor: Edit opens a medication's form under its row, the fields
 * follow how it is dosed, a line says what 100 gallons take, Save / Retire
 * / Bring back post to the fishotel_cleanroom_medications action and
 * reload) and the Board's Calculator (a medication and any number of
 * gallons give the Dose sheet's result and working; nothing is saved).
 *
 * The sum is js/dose-math.js, enqueued before this file. The Calculator's
 * button is put into the Board's toolbar from here — next to "Front
 * Desk" when that toolbar is there, else in a bar of its own under the
 * tabs — so the Board's view needs no line for it.
 *
 * The pure helpers (hundredHint, calcChoices, calcPlan) are exported for
 * tests/medications-admin.test.js; the rest needs jQuery and the page.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

/* global jQuery, module, require */
(function (root, $) {
	'use strict';

	var math = root.fhDoseMath || (typeof require === 'function' ? require('./dose-math.js') : null);

	/**
	 * What 100 gallons take at a rate, as the hint beside the editor's
	 * rate fields: "100 gal take 2.5 g" (per gallon), "100 gal take 69.2 ml
	 * for each 1.0" (to a level); '' while the rate is not a number above 0
	 * or the medication is typed.
	 *
	 * @param {string} mode    per_gallon | to_level | typed.
	 * @param {*}      rate    The amount typed.
	 * @param {string} unit    Its unit.
	 * @param {*}      per     The gallons it is for ('' = 1).
	 * @param {Object} strings {on100, on100Level} (English when missing).
	 * @return {string}
	 */
	function hundredHint(mode, rate, unit, per, strings) {
		var s = strings || {};
		var r = math.numeric(typeof rate === 'number' ? rate : String(rate === null || typeof rate === 'undefined' ? '' : rate));
		var p = per === '' || per === null || typeof per === 'undefined' ? 1 : math.numeric(typeof per === 'number' ? per : String(per));
		if ((mode !== 'per_gallon' && mode !== 'to_level') || r === null || !(r > 0) || p === null || !(p > 0)) {
			return '';
		}
		var shown = math.display(100 * r / p, unit);
		var template = mode === 'to_level' ? (s.on100Level || '100 gal take %s for each 1.0') : (s.on100 || '100 gal take %s');
		return math.fmt(template, math.amountText(shown.amount, shown.unit));
	}

	/**
	 * The rates the Calculator offers for a per-gallon medication: its own,
	 * then its named doses.
	 *
	 * @param {Object} med     Medication.
	 * @param {Object} strings The words of the sum.
	 * @return {Object[]} {rate, name, text}.
	 */
	function calcChoices(med, strings) {
		if (!med || med.mode !== 'per_gallon') {
			return [];
		}
		var out = [{ rate: Number(med.rate), name: '', text: math.rateText(med.rate, med.unit, med.per, strings) }];
		(med.doses || []).forEach(function (dose) {
			out.push({ rate: Number(dose.rate), name: String(dose.label), text: String(dose.label) + ' · ' + math.rateText(dose.rate, med.unit, med.per, strings) });
		});
		return out;
	}

	/**
	 * The Calculator's sum: the Dose sheet's plan for the gallons typed,
	 * with "Type the gallons." where the sheet would say the water's size
	 * is not known; null without a medication.
	 *
	 * @param {Object} med     Medication (or null).
	 * @param {Object} form    gallons, rate, rateLabel, now, target.
	 * @param {Object} strings The words of the sum.
	 * @param {string} ask     "Type the gallons."
	 * @return {Object|null}
	 */
	function calcPlan(med, form, strings, ask) {
		if (!med) {
			return null;
		}
		var plan = math.plan(med, { gallons: form.gallons, rate: form.rate, rateLabel: form.rateLabel, now: form.now, target: form.target }, strings);
		if (plan.state === 'no_gallons') {
			plan.line = ask || 'Type the gallons.';
		}
		return plan;
	}

	var api = { hundredHint: hundredHint, calcChoices: calcChoices, calcPlan: calcPlan };

	root.fhCleanRoomDoseHelpers = api;

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}

	if (!$ || typeof document === 'undefined' || !root.fhCleanRoomDose) {
		return;
	}

	var cfg = root.fhCleanRoomDose;
	var i18n = cfg.i18n || {};

	function t(key, fallback) {
		return i18n[key] ? i18n[key] : fallback;
	}

	/* ---------------------------------------------------------------- */
	/*  Settings → Medications                                           */
	/* ---------------------------------------------------------------- */

	/* Show the fields that belong to the way of dosing, and what 100 gallons take. */
	function syncForm($form) {
		var mode = String($form.find('.fh-cr-med-mode').val() || 'typed');
		$form.find('[data-modes]').each(function () {
			var on = String($(this).data('modes')).split(' ').indexOf(mode) !== -1;
			$(this).prop('hidden', !on);
		});
		$form.find('.fh-cr-med-hint').text(hundredHint(mode, $form.find('[name="rate"]').val(), String($form.find('[name="unit"]').val()), $form.find('[name="per"]').val(), i18n));
	}

	function send($status, fields) {
		$status.removeClass('is-error is-ok').text('…');
		return $.post(cfg.ajaxUrl, fields).done(function (res) {
			if (res && res.success) {
				$status.addClass('is-ok').text((res.data && res.data.message ? res.data.message + ' ' : '') + t('saved', 'Saved. Reloading…'));
				root.location.reload();
			} else {
				$status.addClass('is-error').text(res && res.data && res.data.message ? res.data.message : t('error', 'Something went wrong. Please try again.'));
			}
		}).fail(function (xhr) {
			var data = xhr && xhr.responseJSON && xhr.responseJSON.data;
			$status.addClass('is-error').text(data && data.message ? data.message : t('error', 'Something went wrong. Please try again.'));
		});
	}

	function mountEditor($card) {
		$card.find('.fh-cr-med-form').each(function () { syncForm($(this)); });

		$card.on('change input', '.fh-cr-med-form :input', function () {
			syncForm($(this).closest('.fh-cr-med-form'));
		});

		$card.on('click', '.fh-cr-med-edit', function () {
			var $editor = $(this).closest('tr').next('.fh-cr-med-editor');
			$editor.prop('hidden', !$editor.prop('hidden'));
		});

		$card.on('click', '.fh-cr-med-cancel', function () {
			$(this).closest('.fh-cr-med-editor').prop('hidden', true);
		});

		$card.on('submit', '.fh-cr-med-form', function (e) {
			e.preventDefault();
			var $form = $(this);
			var data = $form.serializeArray();
			data.push({ name: 'action', value: cfg.action });
			data.push({ name: '_wpnonce', value: cfg.nonce });
			data.push({ name: 'op', value: String($form.data('op')) });
			var $button = $form.find('.fh-cr-med-save').prop('disabled', true);
			send($form.find('.fh-cr-med-status'), $.param(data)).always(function () { $button.prop('disabled', false); });
		});

		$card.on('click', '.fh-cr-med-retire, .fh-cr-med-restore', function () {
			var retire = $(this).hasClass('fh-cr-med-retire');
			if (retire && !root.confirm(t('confirmRetire', 'Retire this medication?'))) {
				return;
			}
			send($card.find('.fh-cr-meds-status'), { action: cfg.action, _wpnonce: cfg.nonce, op: retire ? 'retire' : 'restore', key: String($(this).closest('tr').data('key')) });
		});
	}

	/* ---------------------------------------------------------------- */
	/*  The Board's Calculator                                           */
	/* ---------------------------------------------------------------- */

	function mountCalculator($wrap) {
		var meds = cfg.medications || [];
		var form = { gallons: '', rate: null, rateLabel: '', now: '', target: '' };

		var $button = $('<button type="button" class="button fh-cr-calc-open" aria-expanded="false"></button>').text(t('calculator', 'Calculator'));
		var $actions = $wrap.find('.fh-cr-toolbar .fh-cr-actions').first();
		if ($actions.length) {
			$actions.prepend($button);
		} else {
			// The toolbar is not where it was: a bar of its own, so the button is never lost.
			var $bar = $('<div class="fh-cr-toolbar fh-cr-toolbar--calc"></div>').append($('<div class="fh-cr-actions"></div>').append($button));
			var $tabs = $wrap.find('.fh-cr-tabs').first();
			if ($tabs.length) {
				$tabs.after($bar);
			} else {
				$wrap.prepend($bar);
			}
		}

		var $panel = $('<div class="fh-cr-calc" hidden></div>');
		var $med = $('<select class="fh-cr-input" id="fh-cr-calc-med"></select>');
		var $gallons = $('<input type="number" class="fh-cr-input fh-cr-input--num" id="fh-cr-calc-gallons" min="0" step="any" placeholder="?">');
		var $waters = $('<span class="fh-cr-calc__waters"></span>');
		var $rateField = $('<label class="fh-cr-filter" hidden></label>');
		var $rate = $('<select class="fh-cr-input" id="fh-cr-calc-rate"></select>');
		var $nowField = $('<label class="fh-cr-filter" hidden></label>');
		var $now = $('<input type="number" class="fh-cr-input fh-cr-input--num" id="fh-cr-calc-now" min="0" max="999" step="0.01" placeholder="?">');
		var $targetField = $('<label class="fh-cr-filter" hidden></label>');
		var $target = $('<input type="number" class="fh-cr-input fh-cr-input--num" id="fh-cr-calc-target" min="0" max="999" step="0.01" placeholder="?">');
		var $line = $('<p class="fh-cr-calc__line"></p>');
		var $work = $('<p class="fh-cr-calc__work"></p>');
		var $measure = $('<p class="fh-cr-calc__work"></p>');

		function current() {
			var key = String($med.val() || '');
			for (var i = 0; i < meds.length; i++) {
				if (meds[i].key === key) {
					return meds[i];
				}
			}
			return null;
		}

		function update() {
			var plan = calcPlan(current(), form, cfg.strings, t('gallonsAsk', 'Type the gallons.'));
			$panel.find('.fh-cr-calc__result').attr('class', 'fh-cr-calc__result' + (plan ? ' is-' + plan.state : ''));
			$line.text(plan ? plan.line : '');
			$work.text(plan ? plan.working : '').prop('hidden', !plan || !plan.working);
			$measure.text(plan ? plan.measure : '').prop('hidden', !plan || !plan.measure);
		}

		/* Another medication: its rates, or its levels (the copper product starts at the copper target). */
		function pickMed() {
			var med = current();
			var choices = calcChoices(med, cfg.strings);
			$rate.empty();
			choices.forEach(function (choice, index) {
				$rate.append($('<option></option>').attr('value', index).text(choice.text));
			});
			form.rate = choices.length ? choices[0].rate : null;
			form.rateLabel = '';
			form.now = '';
			form.target = med && med.copper && cfg.copperTarget ? String(cfg.copperTarget) : '';
			$now.val(form.now);
			$target.val(form.target);
			$rateField.prop('hidden', choices.length < 2);
			$nowField.prop('hidden', !med || med.mode !== 'to_level');
			$targetField.prop('hidden', !med || med.mode !== 'to_level');
			var unit = med && med.levelUnit ? ' (' + med.levelUnit + ')' : '';
			$nowField.find('span').text(t('now', 'Now') + unit);
			$targetField.find('span').text(t('target', 'Bring it to') + unit);
			update();
		}

		$med.append($('<option value=""></option>').text(t('pick', 'Pick a medication')));
		meds.forEach(function (med) {
			$med.append($('<option></option>').attr('value', med.key).text(med.label));
		});
		(cfg.waters || []).forEach(function (water) {
			$waters.append($('<button type="button" class="button button-small"></button>').text(math.fmt(t('water', '%1$s · %2$s gal'), water.label, math.num(Number(water.gallons), 2))).on('click', function () {
				form.gallons = String(water.gallons);
				$gallons.val(form.gallons);
				update();
			}));
		});

		$panel.append($('<div class="fh-cr-calc__head"></div>').append($('<strong></strong>').text(t('title', 'Dose calculator')), $('<span class="fh-cr-sub"></span>').text(t('hint', 'Nothing is saved.')), $('<button type="button" class="button button-small fh-cr-calc-close"></button>').text(t('close', 'Close'))));
		if (!meds.length) {
			$panel.append($('<p class="fh-cr-sub"></p>').text(t('noMeds', 'No medication is offered — add or bring one back in Settings → Medications.')));
		} else {
			$panel.append($('<div class="fh-cr-calc__fields"></div>').append(
				$('<label class="fh-cr-filter"></label>').append($('<span></span>').text(t('medication', 'Medication')), $med),
				$('<label class="fh-cr-filter"></label>').append($('<span></span>').text(t('gallons', 'Gallons')), $gallons),
				$rateField.append($('<span></span>').text(t('rate', 'How much')), $rate),
				$nowField.append($('<span></span>'), $now),
				$targetField.append($('<span></span>'), $target)
			));
			$panel.append($waters);
			$panel.append($('<div class="fh-cr-calc__result"></div>').append($line, $work, $measure));
		}

		var $toolbar = $wrap.find('.fh-cr-toolbar').first();
		if ($toolbar.length) {
			$toolbar.after($panel);
		} else {
			$wrap.append($panel);
		}

		function toggle(open) {
			$panel.prop('hidden', !open);
			$button.attr('aria-expanded', open ? 'true' : 'false');
			if (open) {
				$med.trigger('focus');
			}
		}

		$button.on('click', function () { toggle($panel.prop('hidden')); });
		$panel.on('click', '.fh-cr-calc-close', function () { toggle(false); });
		$med.on('change', pickMed);
		$gallons.on('input change', function () { form.gallons = String($gallons.val()); update(); });
		$rate.on('change', function () {
			var choice = calcChoices(current(), cfg.strings)[parseInt($rate.val(), 10) || 0];
			form.rate = choice ? choice.rate : null;
			form.rateLabel = choice ? choice.name : '';
			update();
		});
		$now.on('input change', function () { form.now = String($now.val()); update(); });
		$target.on('input change', function () { form.target = String($target.val()); update(); });
		pickMed();
	}

	$(function () {
		var $card = $('#fishotel-cleanroom-medications');
		if ($card.length) {
			mountEditor($card);
		}
		if (cfg.tab === 'board') {
			var $wrap = $('.fh-cr-wrap').first();
			if ($wrap.length) {
				mountCalculator($wrap);
			}
		}
	});
})(typeof window !== 'undefined' ? window : globalThis, typeof jQuery !== 'undefined' ? jQuery : null);
