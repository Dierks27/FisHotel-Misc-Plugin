/**
 * Clean Room dose maths — the sum behind the Dose sheet and the two
 * Calculators (the phone's and the Board's), in the page so nothing waits
 * for the server:
 *
 *   per gallon   gallons × the rate            100 gal × 25 mg = 2.5 g
 *   to a level   gallons × (target − now) × the rate per 1.0
 *                                              100 gal × 0.24 ppm × 0.692 = 16.6 ml
 *   typed        no sum
 *
 * plan() is the server's Dose::plan(), written a second time on purpose;
 * tests/fixtures/dose-maths.json is read by this file's test and by the
 * PHP one, so the two cannot drift apart. The rounding is spelled out with
 * Math.floor() so both sides agree to the last digit: one decimal from 1
 * up, two below 1; a weight reads in grams from 1 g up and in milligrams
 * below.
 *
 * No DOM, no jQuery, no config: the Front Desk page inlines this file
 * before front-desk.js, the admin enqueues it before medications-admin.js,
 * node requires it.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

/* global module */
(function (root) {
	'use strict';

	var MASS = { mg: 1, g: 1000 };
	var FACTORS = [1, 10, 100, 1000, 10000];
	var MAX_LEVEL = 999;

	/* The words of the sum (English; the page passes the translated ones). */
	var STRINGS = {
		add: 'Add %1$s %2$s',
		nothing: 'Nothing to add',
		atOrAbove: '%1$s is at or above %2$s',
		typed: 'No sum for %s — type the amount.',
		needsNow: 'Type the reading now to get the amount.',
		needsTarget: 'Type the level to bring it to.',
		needsChanged: 'How much of the water was changed?',
		tooMuch: 'That is more than this water holds (%s).',
		noGallons: 'The gallons of this water are not known — type the amount.',
		perGallon: '%s per gallon',
		perGallons: '%1$s per %2$s gal',
		gal: '%s gal',
		ofWater: '%1$s of %2$s',
		topup: 'top-up after a %s water change',
		topupPlain: 'top-up after a water change',
		measure: '%1$s %2$s (one %3$s = %4$s)'
	};

	/**
	 * printf-lite: %s / %d, positional (%1$s) or sequential.
	 *
	 * @param {string} template Template.
	 * @return {string}
	 */
	function fmt(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(template || '').replace(/%(\d+\$)?[ds]/g, function (match, pos) {
			var index = pos ? parseInt(pos, 10) - 1 : i++;
			return typeof args[index] === 'undefined' ? '' : args[index];
		});
	}

	function words(strings) {
		var out = {};
		Object.keys(STRINGS).forEach(function (key) {
			out[key] = strings && strings[key] ? String(strings[key]) : STRINGS[key];
		});
		return out;
	}

	/**
	 * A number from anything typed, or null (the server's is_numeric()).
	 *
	 * @param {*} value Raw value.
	 * @return {number|null}
	 */
	function numeric(value) {
		if (typeof value === 'number') {
			return isFinite(value) ? value : null;
		}
		if (typeof value !== 'string' || !/^\s*[+-]?(\d+\.?\d*|\.\d+)\s*$/.test(value)) {
			return null;
		}
		return parseFloat(value);
	}

	/**
	 * An amount in another unit, or null when the two do not convert (only
	 * mg ↔ g do).
	 *
	 * @param {number} amount Amount.
	 * @param {string} from   Its unit.
	 * @param {string} to     The unit wanted.
	 * @return {number|null}
	 */
	function convert(amount, from, to) {
		if (from === to) {
			return Number(amount);
		}
		if (Object.prototype.hasOwnProperty.call(MASS, from) && Object.prototype.hasOwnProperty.call(MASS, to)) {
			return Number(amount) * MASS[from] / MASS[to];
		}
		return null;
	}

	function compatible(a, b) {
		return convert(1, a, b) !== null;
	}

	/**
	 * Round half up to so many decimals, with floor() and a hair added —
	 * Dose::round_to(), digit for digit.
	 *
	 * @param {number} value    A value of 0 or more.
	 * @param {number} decimals 0–4.
	 * @return {number}
	 */
	function roundTo(value, decimals) {
		var factor = FACTORS[Math.max(0, Math.min(4, parseInt(decimals, 10) || 0))];
		return Math.floor(Number(value) * factor + 0.5 + 1e-9) / factor;
	}

	/**
	 * An amount as it is shown and logged: one decimal from 1 up, two below
	 * 1 (up to four before a small amount would read as 0).
	 *
	 * @param {number} value Amount.
	 * @return {number}
	 */
	function roundAmount(value) {
		value = Number(value);
		if (!(value > 0)) {
			return 0;
		}
		var decimals = value >= 1 ? 1 : 2;
		var rounded = roundTo(value, decimals);
		while (rounded <= 0 && decimals < 4) {
			decimals++;
			rounded = roundTo(value, decimals);
		}
		return rounded;
	}

	/**
	 * An amount in the sensible unit, rounded: a weight in grams from 1 g
	 * up and in milligrams below; every other unit as it is.
	 *
	 * @param {number} amount Amount.
	 * @param {string} unit   Its unit.
	 * @return {{amount: number, unit: string}}
	 */
	function display(amount, unit) {
		amount = Number(amount);
		unit = String(unit);
		if (Object.prototype.hasOwnProperty.call(MASS, unit)) {
			var mg = amount * MASS[unit];
			var rounded = roundAmount(mg);
			if (rounded >= 1000) {
				return { amount: roundAmount(mg / 1000), unit: 'g' };
			}
			return { amount: rounded, unit: 'mg' };
		}
		return { amount: roundAmount(amount), unit: unit };
	}

	/**
	 * A number with exactly so many decimals ("2.40").
	 *
	 * @param {number} value    Value.
	 * @param {number} decimals 0–4.
	 * @return {string}
	 */
	function fixed(value, decimals) {
		decimals = Math.max(0, Math.min(4, parseInt(decimals, 10) || 0));
		value = Number(value);
		var factor = FACTORS[decimals];
		var n = Math.floor(Math.abs(value) * factor + 0.5 + 1e-9);
		var whole = Math.floor(n / factor);
		var text = String(whole);
		if (decimals > 0) {
			var frac = String(n - whole * factor);
			while (frac.length < decimals) {
				frac = '0' + frac;
			}
			text += '.' + frac;
		}
		return (value < 0 && n > 0 ? '-' : '') + text;
	}

	/**
	 * A number without needless zeros: 2.5, 16.6, 250, 0.692.
	 *
	 * @param {number} value Value.
	 * @param {number} max   Most decimals (default 2).
	 * @return {string}
	 */
	function num(value, max) {
		var text = fixed(value, typeof max === 'undefined' ? 2 : max);
		if (text.indexOf('.') !== -1) {
			text = text.replace(/0+$/, '').replace(/\.$/, '');
		}
		return text;
	}

	/**
	 * A level as it is written: two decimals, three when the third counts.
	 *
	 * @param {number} value Level.
	 * @return {string}
	 */
	function levelText(value) {
		var text = fixed(value, 3);
		return text.charAt(text.length - 1) === '0' ? text.substring(0, text.length - 1) : text;
	}

	function amountText(amount, unit) {
		return unit === 'each' ? num(amount, 4) + ' ×' : num(amount, 4) + ' ' + unit;
	}

	/**
	 * A rate's amount in words, in the sensible unit and unrounded: "25 mg"
	 * (also from 0.025 g), "1 ml", "0.5" for pieces.
	 *
	 * @param {number} rate Rate.
	 * @param {string} unit Its unit.
	 * @return {string}
	 */
	function rateAmount(rate, unit) {
		rate = Number(rate);
		unit = String(unit);
		if (Object.prototype.hasOwnProperty.call(MASS, unit)) {
			var mg = rate * MASS[unit];
			if (mg + 1e-9 >= 1000) {
				rate = mg / 1000;
				unit = 'g';
			} else {
				rate = mg;
				unit = 'mg';
			}
		}
		return unit === 'each' ? num(rate, 4) : num(rate, 4) + ' ' + unit;
	}

	/**
	 * "25 mg per gallon" / "1 ml per 10 gal".
	 *
	 * @param {number} rate    Rate.
	 * @param {string} unit    Its unit.
	 * @param {number} per     The gallons the rate is for.
	 * @param {Object} strings The words (optional).
	 * @return {string}
	 */
	function rateText(rate, unit, per, strings) {
		var s = words(strings);
		if (Math.abs(Number(per) - 1) < 1e-9) {
			return fmt(s.perGallon, rateAmount(rate, unit));
		}
		return fmt(s.perGallons, rateAmount(rate, unit), num(per, 2));
	}

	function gallonsText(gallons, strings) {
		return fmt(words(strings).gal, num(gallons, 2));
	}

	/**
	 * A measure's name for a count: scoop → scoops, pinch → pinches.
	 *
	 * @param {string} label Singular name.
	 * @param {number} count Count.
	 * @return {string}
	 */
	function plural(label, count) {
		label = String(label || '');
		if (Math.abs(Number(count) - 1) < 1e-9 || label === '') {
			return label;
		}
		if (/(s|x|z|ch|sh)$/i.test(label)) {
			return label + 'es';
		}
		if (/[^aeiou]y$/i.test(label)) {
			return label.substring(0, label.length - 1) + 'ies';
		}
		return label + 's';
	}

	/**
	 * A level from anything typed: a number from 0 to 999, else null.
	 *
	 * @param {*} value Raw value.
	 * @return {number|null}
	 */
	function level(value) {
		var n = numeric(value);
		if (n === null || n < 0 || n > MAX_LEVEL) {
			return null;
		}
		return roundTo(n, 3);
	}

	function finish(out, medication, shown, s) {
		if (!(shown.amount > 0)) {
			out.state = 'nothing';
			out.line = s.nothing;
			return out;
		}
		out.state = 'ok';
		out.amount = shown.amount;
		out.unit = shown.unit;
		out.line = fmt(s.add, amountText(shown.amount, shown.unit), String(medication.label || ''));

		var measure = medication.measure && typeof medication.measure === 'object' ? medication.measure : null;
		if (measure && Number(measure.amount) > 0) {
			var into = convert(shown.amount, shown.unit, String(measure.unit));
			if (into !== null) {
				var count = roundAmount(into / Number(measure.amount));
				out.measure = fmt(s.measure, num(count, 4), plural(String(measure.label), count), String(measure.label), amountText(Number(measure.amount), String(measure.unit)));
			}
		}
		return out;
	}

	/**
	 * The sum for one medication and one water — Dose::plan().
	 *
	 * state: ok | nothing | typed | no_gallons | needs_now | needs_target |
	 * needs_changed | too_much. In every state but ok the amount is null
	 * and the line says what is missing.
	 *
	 * @param {Object} medication key, label, unit, mode, rate, per, levelUnit (or level_unit), measure.
	 * @param {Object} args       gallons; rate, rateLabel (per gallon); now, target (to a level);
	 *                            topup, changed, changedUnit ('pct' | 'gal').
	 * @param {Object} strings    The words (optional; English when missing).
	 * @return {{state: string, amount: number|null, unit: string, line: string, working: string, note: string, measure: string}}
	 */
	function plan(medication, args, strings) {
		medication = medication || {};
		args = args || {};
		var s = words(strings);
		var label = String(medication.label || '');
		var unit = String(medication.unit || '');
		var mode = String(medication.mode || 'typed');
		var per = Number(medication.per) > 0 ? Number(medication.per) : 1;
		var g = numeric(args.gallons);
		var gallons = g !== null && g > 0 ? g : 0;
		var topup = !!args.topup;
		var c = numeric(args.changed);
		var changed = c !== null && c > 0 ? c : null;
		var byGal = String(args.changedUnit || args.changed_unit || 'pct') === 'gal';
		var share = changed === null ? '' : (byGal ? gallonsText(changed, s) : num(changed, 2) + ' %');
		var remark = !topup ? '' : (share === '' ? s.topupPlain : fmt(s.topup, share));

		var out = { state: 'typed', amount: null, unit: unit, line: '', working: '', note: remark, measure: '' };
		var rate;
		var shown;

		if (mode === 'to_level') {
			var now = level(args.now);
			var target = level(args.target);
			var lu = String(medication.levelUnit || medication.level_unit || '');
			var suffix = lu !== '' ? ' ' + lu : '';
			if (now === null || target === null) {
				out.state = now === null ? 'needs_now' : 'needs_target';
				out.line = now === null ? s.needsNow : s.needsTarget;
				return out;
			}
			out.note = levelText(now) + ' → ' + levelText(target) + suffix + (gallons > 0 ? ', ' + gallonsText(gallons, s) : '') + (remark !== '' ? ' · ' + remark : '');
			if (gallons <= 0) {
				out.state = 'no_gallons';
				out.line = s.noGallons;
				return out;
			}
			var delta = target - now;
			if (delta <= 1e-7) {
				out.state = 'nothing';
				out.line = s.nothing;
				out.working = fmt(s.atOrAbove, levelText(now) + suffix, levelText(target) + suffix);
				return out;
			}
			rate = Number(medication.rate) || 0;
			var factor = Math.abs(per - 1) < 1e-9 ? num(rate, 4) : rateText(rate, unit, per, s);
			shown = display(gallons * delta * rate / per, unit);

			out.working = gallonsText(gallons, s) + ' × ' + levelText(delta) + suffix + ' × ' + factor;
			return finish(out, medication, shown, s);
		}

		if (mode !== 'per_gallon') {
			out.line = fmt(s.typed, label);
			return out;
		}

		var r = numeric(args.rate);
		rate = r !== null && r > 0 ? r : (Number(medication.rate) || 0);
		var rateLabel = String(args.rateLabel || args.rate_label || '').replace(/^\s+|\s+$/g, '');
		var rateWords = rateText(rate, unit, per, s);
		var used = gallons;
		var of = '';

		if (topup) {
			if (changed === null) {
				out.state = 'needs_changed';
				out.line = s.needsChanged;
				return out;
			}
			if (byGal) {
				if (gallons > 0 && changed > gallons + 1e-9) {
					out.state = 'too_much';
					out.line = fmt(s.tooMuch, gallonsText(gallons, s));
					out.note = s.topupPlain;
					return out;
				}
				used = changed;
			} else {
				if (changed > 100 + 1e-9) {
					out.state = 'too_much';
					out.line = fmt(s.tooMuch, '100 %');
					out.note = s.topupPlain;
					return out;
				}
				if (gallons <= 0) {
					out.state = 'no_gallons';
					out.line = s.noGallons;
					return out;
				}
				used = gallons * changed / 100;
				of = ' (' + fmt(s.ofWater, share, gallonsText(gallons, s)) + ')';
			}
		} else if (gallons <= 0) {
			out.state = 'no_gallons';
			out.line = s.noGallons;
			return out;
		}

		shown = display(used * rate / per, unit);

		out.working = gallonsText(used, s) + ' × ' + (Math.abs(per - 1) < 1e-9 ? rateAmount(rate, unit) : rateWords) + of;
		out.note = (rateLabel !== '' ? rateLabel + ': ' : '') + rateWords + ', ' + gallonsText(used, s) + (remark !== '' ? ' · ' + remark : '');

		return finish(out, medication, shown, s);
	}

	var api = {
		STRINGS: STRINGS,
		fmt: fmt,
		numeric: numeric,
		convert: convert,
		compatible: compatible,
		roundTo: roundTo,
		roundAmount: roundAmount,
		display: display,
		fixed: fixed,
		num: num,
		levelText: levelText,
		amountText: amountText,
		rateAmount: rateAmount,
		rateText: rateText,
		gallonsText: gallonsText,
		plural: plural,
		level: level,
		plan: plan
	};

	root.fhDoseMath = api;

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
})(typeof window !== 'undefined' ? window : globalThis);
