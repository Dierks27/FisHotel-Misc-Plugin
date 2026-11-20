/**
 * The order page measured in a real browser — NOT run by the suite (the
 * suite has no browser): tests/order-view-test.php writes static copies
 * of the page with --harness=<dir>, each of which loads this file, and a
 * headless Chrome prints what it found (tests/README.md has the command).
 *
 * For Entry, Numbers and Entry with "More columns", at the window's own
 * width: whether the table fits its wrapper and the page fits the
 * window (no sideways scrolling), the width every shown column got,
 * which fields cut their own text off — and that the form posts exactly
 * the same fields with the same values in every view (FormData reads
 * the form as a Save does: what a stylesheet hides is still a field).
 *
 * The result is written, as JSON, into <pre id="fh-result">.
 */
(function () {
	'use strict';

	function run() {
		var form = document.getElementById('fh-cr-order');
		var wrap = document.querySelector('.fh-cr-grid-wrap');
		var table = document.getElementById('fh-cr-lines');
		var out = { window: window.innerWidth, errors: window.fhErrors || [], views: {} };

		function posted() {
			var list = [];
			new FormData(form).forEach(function (value, name) {
				list.push(name + '=' + value);
			});
			return list;
		}

		function shown(el) {
			return el.offsetParent !== null;
		}

		function measure(view, more) {
			form.setAttribute('data-view', view);
			form.setAttribute('data-more', more ? '1' : '0');
			var cut = [];
			Array.prototype.forEach.call(table.querySelectorAll('tbody tr.fh-cr-line input, tbody tr.fh-cr-line select'), function (el) {
				// A number box must show its whole number (a long name in a text box may scroll).
				if (shown(el) && el.type === 'number' && el.scrollWidth > el.clientWidth + 1) {
					cut.push(el.name + ' ' + el.scrollWidth + '>' + el.clientWidth);
				}
			});
			var fields = posted();
			return {
				table: Math.round(table.getBoundingClientRect().width),
				wrap: wrap.clientWidth,
				fits: wrap.scrollWidth <= wrap.clientWidth,
				page_fits: document.documentElement.scrollWidth <= window.innerWidth,
				columns: Array.prototype.filter.call(table.querySelectorAll('thead th'), shown).map(function (th) {
					return (th.textContent.replace(/\s+/g, ' ').replace(/^ | $/g, '') || '·') + ' ' + Math.round(th.getBoundingClientRect().width);
				}),
				row_height: table.querySelector('tbody tr.fh-cr-line') ? Math.round(table.querySelector('tbody tr.fh-cr-line').getBoundingClientRect().height) : 0,
				cut_off: cut.length,
				cut_first: cut.slice(0, 4),
				fields: fields.length,
				posted: fields.join('&')
			};
		}

		var was = { view: form.getAttribute('data-view'), more: form.getAttribute('data-more') };
		var entry = measure('entry', false);
		var numbers = measure('numbers', false);
		var more = measure('entry', true);
		out.same_fields = entry.posted === numbers.posted && entry.posted === more.posted;
		out.split_rows = table.querySelectorAll('tbody tr.fh-cr-split-row').length;
		out.split_fields = Array.prototype.map.call(table.querySelectorAll('tbody input.fh-cr-split-field'), function (el) { return el.value; }).filter(function (value) { return value !== ''; });
		[entry, numbers, more].forEach(function (view) { delete view.posted; });
		out.views = { entry: entry, numbers: numbers, entry_more: more };
		// A head that cuts its own word off ("Recei…") is a column too narrow for its name.
		form.setAttribute('data-view', 'entry');
		form.setAttribute('data-more', '0');
		out.heads_cut = Array.prototype.filter.call(table.querySelectorAll('thead th'), function (th) {
			return shown(th) && th.scrollWidth > th.clientWidth + 1;
		}).map(function (th) { return th.textContent.replace(/\s+/g, ' ').replace(/^ | $/g, ''); });
		form.setAttribute('data-view', was.view);
		form.setAttribute('data-more', was.more);

		var pre = document.createElement('pre');
		pre.id = 'fh-result';
		pre.textContent = JSON.stringify(out);
		// One long line must not widen the page it measured: the box around it wraps it.
		var box = document.createElement('div');
		box.style.cssText = 'width:600px;overflow:hidden;font-size:10px';
		box.appendChild(pre);
		document.body.appendChild(box);
	}

	window.addEventListener('load', function () {
		window.setTimeout(run, 50);
	});
})();
