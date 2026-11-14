/**
 * Requests — the request form's small script (the private link's page
 * and the [fishotel_request_form] shortcode). The form works without it:
 * this only adds "Add another fish" and the name suggestions while a
 * fish is typed (a native <datalist>, filled from admin-ajax).
 *
 * No dependency, no external request. The pure helpers are exported so
 * tests/requests-form.test.js can run them under node.
 *
 * @package FisHotel\Misc\Sections\Requests
 */
(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	}
	if (root && root.document) {
		api.boot(root, root.fhRequestsForm || {});
	}
}(typeof window !== 'undefined' ? window : null, function () {
	'use strict';

	/**
	 * A row template with its index filled in.
	 *
	 * @param {string} html  The template's HTML (rows[__i__][…]).
	 * @param {number} index The new row's index.
	 * @return {string}
	 */
	function rowHtml(html, index) {
		return String(html).split('__i__').join(String(index));
	}

	/**
	 * Whether another row may be added.
	 *
	 * @param {number} count Rows on the page.
	 * @param {number} max   The most a sheet takes.
	 * @return {boolean}
	 */
	function canAdd(count, max) {
		return count < (max > 0 ? max : 10);
	}

	/**
	 * Text made safe for HTML.
	 *
	 * @param {*} text Anything.
	 * @return {string}
	 */
	function escapeHtml(text) {
		return String(text === null || text === undefined ? '' : text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	/**
	 * The <option>s of the suggestions: the name is the value, the
	 * scientific name its label; no name twice.
	 *
	 * @param {Array} items [{name, sci}].
	 * @return {string}
	 */
	function optionsHtml(items) {
		var seen = {};
		var out = '';
		(Array.isArray(items) ? items : []).forEach(function (item) {
			var name = item && typeof item.name === 'string' ? item.name.trim() : '';
			var key = name.toLowerCase();
			if (!name || seen[key]) {
				return;
			}
			seen[key] = true;
			out += '<option value="' + escapeHtml(name) + '"' + (item.sci ? ' label="' + escapeHtml(item.sci) + '"' : '') + '></option>';
		});
		return out;
	}

	/**
	 * Whether what was typed is worth a lookup.
	 *
	 * @param {string} term What was typed.
	 * @param {string} last The last term looked up.
	 * @return {boolean}
	 */
	function shouldLookup(term, last) {
		var clean = String(term || '').trim();
		return clean.length >= 2 && clean.length <= 60 && clean !== last;
	}

	/**
	 * The lookup's address.
	 *
	 * @param {Object} config ajaxUrl, action.
	 * @param {string} term   What was typed.
	 * @param {string} token  The private link's token ('' on the public form).
	 * @return {string}
	 */
	function lookupUrl(config, term, token) {
		var url = String(config.ajaxUrl || '');
		return url + (url.indexOf('?') === -1 ? '?' : '&') +
			'action=' + encodeURIComponent(config.action || '') +
			'&term=' + encodeURIComponent(String(term).trim()) +
			'&token=' + encodeURIComponent(token || '');
	}

	/**
	 * Wire the forms on the page.
	 *
	 * @param {Window} root   The window.
	 * @param {Object} config ajaxUrl, action, maxRows.
	 */
	function boot(root, config) {
		var doc = root.document;
		var forms = doc.querySelectorAll('form.fh-rq-form');

		Array.prototype.forEach.call(forms, function (form) {
			var rows = form.querySelector('.fh-rq-rows');
			var template = form.querySelector('.fh-rq-row-template');
			var add = form.querySelector('.fh-rq-add');
			var list = form.querySelector('datalist');
			var token = form.getAttribute('data-token') || '';
			var last = '';
			var timer = null;

			if (rows && template && add) {
				add.hidden = false;
				add.addEventListener('click', function () {
					var count = rows.querySelectorAll('.fh-rq-row').length;
					var next = parseInt(rows.getAttribute('data-next'), 10) || count;
					var holder;
					var number;
					var fresh;
					if (!canAdd(count, config.maxRows)) {
						add.hidden = true;
						return;
					}
					holder = doc.createElement('div');
					holder.innerHTML = rowHtml(template.innerHTML, next);
					number = holder.querySelector('.fh-rq-row__n');
					if (number) {
						number.textContent = String(count + 1);
					}
					while (holder.firstElementChild) {
						rows.appendChild(holder.firstElementChild);
					}
					rows.setAttribute('data-next', String(next + 1));
					if (!canAdd(count + 1, config.maxRows)) {
						add.hidden = true;
					}
					fresh = rows.querySelector('.fh-rq-row:last-child .fh-rq-fish');
					if (fresh) {
						fresh.focus();
					}
				});
			}

			if (list && root.fetch && config.ajaxUrl) {
				form.addEventListener('input', function (event) {
					var target = event.target;
					if (!target || !target.classList || !target.classList.contains('fh-rq-fish')) {
						return;
					}
					var term = target.value;
					if (!shouldLookup(term, last)) {
						return;
					}
					root.clearTimeout(timer);
					timer = root.setTimeout(function () {
						last = String(term).trim();
						root.fetch(lookupUrl(config, term, token), { credentials: 'same-origin' })
							.then(function (response) { return response.ok ? response.json() : null; })
							.then(function (reply) {
								if (reply && reply.success && reply.data) {
									list.innerHTML = optionsHtml(reply.data.items);
								}
							})
							.catch(function () { /* Suggestions are a nicety: the form works without them. */ });
					}, 220);
				});
			}
		});
	}

	return {
		rowHtml: rowHtml,
		canAdd: canAdd,
		escapeHtml: escapeHtml,
		optionsHtml: optionsHtml,
		shouldLookup: shouldLookup,
		lookupUrl: lookupUrl,
		boot: boot
	};
}));
