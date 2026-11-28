/**
 * FisHotel dates — the page's twin of includes/class-dates.php, and the
 * pop-up calendar that replaces a typed date. One small file, no
 * dependencies (no jQuery, no library); any section can load it:
 * in wp-admin by the script handle "fishotel-dates"
 * (\FisHotel\Misc\Dates::register_script()), on the phone's own pages by
 * inlining it before the page's script.
 *
 * A person reads a date as MM-DD-YY (11-27-26). What is SENT and STORED
 * never changes: every value, payload and attribute stays YYYY-MM-DD.
 *
 *   fhDates.us( '2026-11-27' )             → '11-27-26'
 *   fhDates.usTime( '2026-11-27 09:14' )   → '11-27-26 09:14'
 *   fhDates.stamp( new Date() )            → '11-27-26 09:14' (this device's own time)
 *   fhDates.parse( '11/27/26' )            → { date: '2026-11-27', error: '' }
 *   fhDates.ask( { … } )                   → the pop-up calendar (below)
 *
 * us() and parse() work on the digits alone: a date is a calendar day and
 * is never passed through `new Date()`, where a time zone could move it
 * to the day before. To change how every date reads, change us() here and
 * Dates::DATE / Dates::us() in PHP; both are run against
 * tests/fixtures/us-dates.json.
 *
 * fhDates.ask( options ) — one date, picked on the browser's own calendar
 * (<input type="date">: month/day/year in a US browser, a wheel on a
 * phone). Options:
 *   title    the heading ("Sale date — Blue Test Tang")
 *   help     one sentence, or a list of sentences, under the heading
 *   value    the date it opens with, YYYY-MM-DD ('' = none)
 *   min/max  the earliest / latest date, YYYY-MM-DD (optional)
 *   clear    the words of the "no date" button; leave out when a date
 *            is required. onClear() runs — the page gives it the meaning
 *            an empty answer had.
 *   save / cancel / label   other words (defaults from fhDatesI18n)
 *   opener   the element focus goes back to (default: what had focus)
 *   onSave( 'YYYY-MM-DD' ), onClear(), onCancel()
 * Enter saves, Esc and a tap outside cancel, Tab stays inside. Nothing is
 * saved by this file: it only hands the date back.
 *
 * @package FisHotel\Misc
 */
(function (root) {
	'use strict';

	var words = (root && root.fhDatesI18n) || {};
	var EXAMPLE = '2026-11-27';

	function word(key, fallback) {
		return typeof words[key] === 'string' && words[key] !== '' ? words[key] : fallback;
	}

	function fill(text, a, b) {
		return String(text).replace(/%1\$s|%s/, function () { return a; }).replace(/%2\$s/, function () { return b; });
	}

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	/**
	 * How many days a month has (pure, no Date).
	 */
	function daysIn(year, month) {
		if (month === 2) {
			return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0 ? 29 : 28;
		}
		return month === 4 || month === 6 || month === 9 || month === 11 ? 30 : 31;
	}

	function realDay(year, month, day) {
		return year >= 1 && month >= 1 && month <= 12 && day >= 1 && day <= daysIn(year, month);
	}

	/**
	 * Whether a value is a stored date: YYYY-MM-DD and a real day.
	 */
	function isYmd(value) {
		var m = typeof value === 'string' ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(value) : null;
		return !!m && realDay(parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10));
	}

	/**
	 * A stored date as a person reads it: 2026-11-27 → 11-27-26. A
	 * date-time gives its date alone; a year outside 2000–2099 is written
	 * in full; anything that is no stored date comes back as it was.
	 */
	function us(ymd, empty) {
		if (ymd === null || ymd === undefined || ymd === false || String(ymd).replace(/^\s+|\s+$/g, '') === '') {
			return empty === undefined ? '' : String(empty);
		}
		var text = String(ymd).replace(/^\s+|\s+$/g, '');
		var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T][\s\S]*)?$/.exec(text);
		if (!m || !realDay(parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10))) {
			return text;
		}
		var year = parseInt(m[1], 10);
		if (year < 2000 || year > 2099) {
			return m[2] + '-' + m[3] + '-' + m[1];
		}
		return m[2] + '-' + m[3] + '-' + pad(year % 100);
	}

	/**
	 * A stored date-time as a person reads it, its time part kept:
	 * 2026-11-27 09:14 → 11-27-26 09:14.
	 */
	function usTime(value, empty) {
		if (value === null || value === undefined || value === false || String(value).replace(/^\s+|\s+$/g, '') === '') {
			return empty === undefined ? '' : String(empty);
		}
		var text = String(value).replace(/^\s+|\s+$/g, '');
		var m = /^(\d{4}-\d{2}-\d{2})(?:[ T]([\s\S]*))?$/.exec(text);
		if (!m || !isYmd(m[1])) {
			return text;
		}
		var rest = (m[2] || '').replace(/^\s+|\s+$/g, '');
		return us(m[1]) + (rest !== '' ? ' ' + rest : '');
	}

	/**
	 * A moment in this device's own time: 11-27-26 09:14. (The one place
	 * a Date object is read — by its local parts, never toISOString(),
	 * which is UTC and can be the day after.)
	 */
	function stamp(date, seconds) {
		if (!date || typeof date.getFullYear !== 'function' || isNaN(date.getTime())) {
			return '';
		}
		return us(localYmd(date)) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + (seconds ? ':' + pad(date.getSeconds()) : '');
	}

	/**
	 * The calendar day of a moment on this device, stored form (YYYY-MM-DD).
	 */
	function localYmd(date) {
		if (!date || typeof date.getFullYear !== 'function' || isNaN(date.getTime())) {
			return '';
		}
		var year = String(date.getFullYear());
		while (year.length < 4) {
			year = '0' + year;
		}
		return year + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
	}

	/**
	 * A date a person typed, read: month first — MM-DD-YY, MM/DD/YY,
	 * MM-DD-YYYY, M/D/YY (dots too) — or the stored YYYY-MM-DD. A year of
	 * two digits is 20YY. A date that does not exist is refused with one
	 * plain sentence, never moved to another day.
	 *
	 * @return {{date: string, error: string}} date is YYYY-MM-DD or ''.
	 */
	function parse(typed) {
		var out = { date: '', error: '' };
		var text = typeof typed === 'string' || typeof typed === 'number' ? String(typed).replace(/^\s+|\s+$/g, '') : '';
		if (text === '') {
			return out;
		}
		var year;
		var month;
		var day;
		var m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(text);
		if (m) {
			year = parseInt(m[1], 10);
			month = parseInt(m[2], 10);
			day = parseInt(m[3], 10);
		} else {
			m = /^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2}|\d{4})$/.exec(text);
			if (!m) {
				out.error = fill(word('notDate', '"%1$s" is not a date — type the month, the day and the year, like %2$s.'), text, us(EXAMPLE));
				return out;
			}
			month = parseInt(m[1], 10);
			day = parseInt(m[2], 10);
			year = m[3].length === 2 ? 2000 + parseInt(m[3], 10) : parseInt(m[3], 10);
		}
		if (!realDay(year, month, day)) {
			out.error = fill(word('noDay', '%s is not a day on the calendar.'), text);
			return out;
		}
		var y = String(year);
		while (y.length < 4) {
			y = '0' + y;
		}
		out.date = y + '-' + pad(month) + '-' + pad(day);
		return out;
	}

	function toYmd(typed) {
		var read = parse(typed);
		return read.date !== '' ? read.date : null;
	}

	/**
	 * Why a picked date is refused ('' = it is fine). Pure.
	 *
	 * @param {string} raw   What the field holds: YYYY-MM-DD from a calendar, or typed text where a browser has no calendar.
	 * @param {Object} rules { min, max, clear } — clear is the words of the "no date" button ('' = a date is required).
	 * @return {{date: string, error: string}}
	 */
	function check(raw, rules) {
		rules = rules || {};
		var read = parse(raw);
		if (read.error !== '') {
			return read;
		}
		if (read.date === '') {
			read.error = rules.clear ? fill(word('pickOr', 'Pick a date first, or choose "%s".'), rules.clear) : word('pick', 'Pick a date first.');
			return read;
		}
		var early = isYmd(rules.min) && read.date < rules.min;
		var late = isYmd(rules.max) && read.date > rules.max;
		if (early || late) {
			if (isYmd(rules.min) && isYmd(rules.max)) {
				read.error = fill(word('range', 'Pick a date from %1$s to %2$s.'), us(rules.min), us(rules.max));
			} else if (early) {
				read.error = fill(word('tooEarly', 'Pick a date on or after %s.'), us(rules.min));
			} else {
				read.error = fill(word('tooLate', 'Pick a date on or before %s.'), us(rules.max));
			}
			read.date = '';
		}
		return read;
	}

	/* ---------------------------------------------------------------- */
	/*  The pop-up calendar                                              */
	/* ---------------------------------------------------------------- */

	var STYLE_ID = 'fh-dates-style';
	var counter = 0;
	var open = null;

	var CSS = [
		'.fh-dates-layer{position:fixed;top:0;right:0;bottom:0;left:0;z-index:160000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.6);box-sizing:border-box;overflow:auto}',
		'.fh-dates-box{box-sizing:border-box;width:100%;max-width:420px;margin:auto;padding:20px;border-radius:8px;border:1px solid rgba(255,255,255,.18);background:#2d2d2d;color:#e0e0e0;box-shadow:0 8px 32px rgba(0,0,0,.6);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",sans-serif;color-scheme:dark;text-align:left}',
		'.fh-dates-box *{box-sizing:border-box}',
		'.fh-dates-title{margin:0 0 8px;padding:0;font-size:17px;line-height:1.3;font-weight:600;color:#fff}',
		'.fh-dates-help{margin:0 0 6px;font-size:14px;color:#c8c8c8}',
		'.fh-dates-field{display:block;margin:14px 0 0}',
		'.fh-dates-field span{display:block;margin:0 0 4px;font-size:13px;font-weight:600;color:#e0e0e0}',
		'.fh-dates-box input.fh-dates-input{display:block;width:100%;max-width:none;min-height:44px;margin:0;padding:8px 10px;font-size:16px;line-height:1.3;border-radius:6px;border:1px solid #6a6a6a;background:#1e1e1e;color:#fff;color-scheme:dark}',
		'.fh-dates-box input.fh-dates-input:focus{outline:2px solid #0eb5e8;outline-offset:1px;border-color:#0eb5e8;box-shadow:none}',
		'.fh-dates-error{margin:8px 0 0;font-size:14px;color:#ff8a8a}',
		'.fh-dates-error[hidden]{display:none}',
		'.fh-dates-date{white-space:nowrap}',
		'.fh-dates-buttons{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0 0}',
		'.fh-dates-box button.fh-dates-btn{flex:1 1 auto;min-height:44px;margin:0;padding:8px 14px;font-size:14px;line-height:1.3;font-weight:600;border-radius:6px;border:1px solid #6a6a6a;background:#3a3a3a;color:#fff;cursor:pointer;white-space:normal;text-align:center;text-shadow:none;box-shadow:none}',
		'.fh-dates-box button.fh-dates-btn:hover{background:#474747;color:#fff;border-color:#8a8a8a}',
		'.fh-dates-box button.fh-dates-btn:focus{outline:2px solid #0eb5e8;outline-offset:2px;box-shadow:none}',
		'.fh-dates-box button.fh-dates-save{background:#00789f;border-color:#00789f;color:#fff}',
		'.fh-dates-box button.fh-dates-save:hover{background:#006a8e;border-color:#006a8e}',
		'.fh-dates-box button.fh-dates-clear{flex-basis:100%;order:3}',
		'.fh-dates-box button.fh-dates-cancel{background:transparent}',
		'@media (max-width:480px){.fh-dates-layer{align-items:flex-end;padding:0}.fh-dates-box{max-width:none;border-radius:12px 12px 0 0;padding:18px 16px calc(18px + env(safe-area-inset-bottom,0px))}}'
	].join('\n');

	/**
	 * Put words into a node, each date in them (11-27-26) in a span that
	 * does not break, so a sentence never wraps in the middle of a date.
	 */
	function write(doc, node, text) {
		String(text).split(/(\d{2}-\d{2}-\d{2,4})/).forEach(function (part, i) {
			if (part === '') {
				return;
			}
			if (i % 2 === 1) {
				var date = doc.createElement('span');
				date.setAttribute('class', 'fh-dates-date');
				date.appendChild(doc.createTextNode(part));
				node.appendChild(date);
			} else {
				node.appendChild(doc.createTextNode(part));
			}
		});
	}

	function make(doc, tag, attrs, text) {
		var node = doc.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			node.setAttribute(key, attrs[key]);
		});
		if (text !== undefined && text !== null) {
			write(doc, node, text);
		}
		return node;
	}

	function addStyle(doc) {
		if (doc.getElementById(STYLE_ID)) {
			return;
		}
		var style = make(doc, 'style', { id: STYLE_ID }, CSS);
		(doc.head || doc.documentElement).appendChild(style);
	}

	/**
	 * Open the pop-up calendar. Returns { close(), input, box } — or null
	 * where there is no document.
	 */
	function ask(options) {
		var opts = options || {};
		var doc = opts.document || (root && root.document);
		if (!doc || !doc.body) {
			return null;
		}
		if (open) {
			open.close(false);
		}
		addStyle(doc);

		var id = 'fh-dates-' + (++counter);
		var opener = opts.opener || doc.activeElement || null;
		var clearWords = typeof opts.clear === 'string' ? opts.clear : '';
		var rules = { min: opts.min || '', max: opts.max || '', clear: clearWords };
		var done = false;

		var layer = make(doc, 'div', { class: 'fh-dates-layer' });
		var box = make(doc, 'div', { class: 'fh-dates-box', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': id + '-title' });
		box.appendChild(make(doc, 'h2', { class: 'fh-dates-title', id: id + '-title' }, opts.title || word('date', 'Date')));

		var helps = [].concat(opts.help || []).filter(function (line) { return typeof line === 'string' && line !== ''; });
		if (helps.length) {
			var helpBox = make(doc, 'div', { id: id + '-help' });
			helps.forEach(function (line) {
				helpBox.appendChild(make(doc, 'p', { class: 'fh-dates-help' }, line));
			});
			box.appendChild(helpBox);
			box.setAttribute('aria-describedby', id + '-help');
		}

		var field = make(doc, 'label', { class: 'fh-dates-field', for: id + '-input' });
		field.appendChild(make(doc, 'span', {}, opts.label || word('date', 'Date')));
		var input = make(doc, 'input', { type: 'date', class: 'fh-dates-input', id: id + '-input', 'aria-describedby': id + '-error' });
		if (isYmd(rules.min)) {
			input.setAttribute('min', rules.min);
		}
		if (isYmd(rules.max)) {
			input.setAttribute('max', rules.max);
		}
		input.value = isYmd(opts.value) ? opts.value : '';
		field.appendChild(input);
		box.appendChild(field);

		var error = make(doc, 'p', { class: 'fh-dates-error', id: id + '-error', role: 'alert', hidden: 'hidden' });
		box.appendChild(error);

		var buttons = make(doc, 'div', { class: 'fh-dates-buttons' });
		var saveBtn = make(doc, 'button', { type: 'button', class: 'fh-dates-btn fh-dates-save' }, opts.save || word('save', 'Save'));
		var cancelBtn = make(doc, 'button', { type: 'button', class: 'fh-dates-btn fh-dates-cancel' }, opts.cancel || word('cancel', 'Cancel'));
		var clearBtn = clearWords ? make(doc, 'button', { type: 'button', class: 'fh-dates-btn fh-dates-clear' }, clearWords) : null;
		buttons.appendChild(saveBtn);
		buttons.appendChild(cancelBtn);
		if (clearBtn) {
			buttons.appendChild(clearBtn);
		}
		box.appendChild(buttons);
		layer.appendChild(box);

		function say(message) {
			while (error.firstChild) {
				error.removeChild(error.firstChild);
			}
			if (message) {
				write(doc, error, message);
				error.removeAttribute('hidden');
				input.setAttribute('aria-invalid', 'true');
			} else {
				error.setAttribute('hidden', 'hidden');
				input.removeAttribute('aria-invalid');
			}
		}

		function close(refocus) {
			if (done) {
				return;
			}
			done = true;
			doc.removeEventListener('keydown', onKey, true);
			if (layer.parentNode) {
				layer.parentNode.removeChild(layer);
			}
			if (open && open.layer === layer) {
				open = null;
			}
			if (refocus !== false && opener && typeof opener.focus === 'function') {
				try {
					opener.focus();
				} catch (e) { /* the opener is gone: nothing to go back to */ }
			}
		}

		function cancel() {
			close();
			if (typeof opts.onCancel === 'function') {
				opts.onCancel();
			}
		}

		function save() {
			// A calendar the browser could not read (half a date typed) holds '' and says so in validity.badInput.
			var raw = input.value;
			if (raw === '' && input.validity && input.validity.badInput) {
				say(word('pick', 'Pick a date first.'));
				input.focus();
				return;
			}
			var read = check(raw, rules);
			if (read.error !== '') {
				say(read.error);
				input.focus();
				return;
			}
			close();
			if (typeof opts.onSave === 'function') {
				opts.onSave(read.date);
			}
		}

		function clear() {
			close();
			if (typeof opts.onClear === 'function') {
				opts.onClear();
			}
		}

		function onKey(event) {
			var key = event.key || '';
			if (key === 'Escape' || key === 'Esc') {
				event.preventDefault();
				event.stopPropagation();
				cancel();
				return;
			}
			if (key !== 'Tab') {
				return;
			}
			// Tab stays inside the box.
			var stops = [input, saveBtn, cancelBtn].concat(clearBtn ? [clearBtn] : []);
			var at = stops.indexOf(doc.activeElement);
			if (at === -1) {
				event.preventDefault();
				input.focus();
			} else if (event.shiftKey && at === 0) {
				event.preventDefault();
				stops[stops.length - 1].focus();
			} else if (!event.shiftKey && at === stops.length - 1) {
				event.preventDefault();
				stops[0].focus();
			}
		}

		input.addEventListener('keydown', function (event) {
			if ((event.key || '') === 'Enter') {
				event.preventDefault();
				save();
			}
		});
		input.addEventListener('input', function () { say(''); });
		saveBtn.addEventListener('click', save);
		cancelBtn.addEventListener('click', cancel);
		if (clearBtn) {
			clearBtn.addEventListener('click', clear);
		}
		layer.addEventListener('mousedown', function (event) {
			if (event.target === layer) {
				event.preventDefault();
				cancel();
			}
		});
		doc.addEventListener('keydown', onKey, true);

		doc.body.appendChild(layer);
		input.focus();

		open = { close: close, layer: layer, box: box, input: input };
		return open;
	}

	var api = {
		us: us,
		usTime: usTime,
		stamp: stamp,
		localYmd: localYmd,
		parse: parse,
		toYmd: toYmd,
		isYmd: isYmd,
		check: check,
		ask: ask,
		EXAMPLE: EXAMPLE
	};

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
	if (root) {
		root.fhDates = api;
	}
})(typeof window !== 'undefined' ? window : globalThis);
