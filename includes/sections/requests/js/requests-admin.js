/**
 * Requests admin — the small script of FisHotel Tools → Requests. Every
 * form on the page works without it; this adds:
 *
 *   - "Copy" buttons (a private link, a pay link),
 *   - "are you sure?" on the buttons that close or undo something,
 *   - name suggestions while a fish is typed (a native <datalist>),
 *   - the site-customer search on the Add tab: pick one, and their name,
 *     e-mail and forum handle are filled in.
 *
 * No dependency. The pure helpers are exported so
 * tests/requests-admin.test.js can run them under node.
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
		if (root.document.readyState === 'loading') {
			root.document.addEventListener('DOMContentLoaded', function () {
				api.boot(root, root.fhRequestsAdmin || {});
			});
		} else {
			api.boot(root, root.fhRequestsAdmin || {});
		}
	}
}(typeof window !== 'undefined' ? window : null, function () {
	'use strict';

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
	 * The <option>s of the fish suggestions.
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
	 * A customer in one line: "Pat Example — pat@example.test (reef_pat)".
	 *
	 * @param {Object} customer id, name, email, handle.
	 * @return {string}
	 */
	function customerLabel(customer) {
		var name = customer && customer.name ? String(customer.name) : '';
		var email = customer && customer.email ? String(customer.email) : '';
		var handle = customer && customer.handle ? String(customer.handle) : '';
		var label = name || email;
		if (name && email) {
			label += ' — ' + email;
		}
		if (handle) {
			label += ' (' + handle + ')';
		}
		return label;
	}

	/**
	 * The buttons of the customer search results.
	 *
	 * @param {Array} items [{id, name, email, handle}].
	 * @return {string}
	 */
	function customersHtml(items) {
		var out = '';
		(Array.isArray(items) ? items : []).forEach(function (item) {
			var id = item ? parseInt(item.id, 10) : 0;
			if (!id || id < 1) {
				return;
			}
			out += '<button type="button" data-id="' + id + '" data-name="' + escapeHtml(item.name || '') + '" data-email="' + escapeHtml(item.email || '') + '" data-handle="' + escapeHtml(item.handle || '') + '">' + escapeHtml(customerLabel(item)) + '</button>';
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
	 * An admin-ajax address.
	 *
	 * @param {string} base   admin-ajax.php.
	 * @param {Object} params Query parameters.
	 * @return {string}
	 */
	function ajaxUrl(base, params) {
		var parts = [];
		Object.keys(params).forEach(function (key) {
			parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key] === null || params[key] === undefined ? '' : params[key]));
		});
		return String(base) + (String(base).indexOf('?') === -1 ? '?' : '&') + parts.join('&');
	}

	/**
	 * Wire the page.
	 *
	 * @param {Window} root   The window.
	 * @param {Object} config ajaxUrl, nonce, suggestAction, customersAction, i18n.
	 */
	function boot(root, config) {
		var doc = root.document;
		var i18n = config.i18n || {};
		var wrap = doc.querySelector('.fh-rq-wrap');
		if (!wrap) {
			return;
		}

		function getJson(url) {
			if (!root.fetch) {
				return { then: function () { return this; }, 'catch': function () { return this; } };
			}
			return root.fetch(url, { credentials: 'same-origin' })
				.then(function (response) { return response.ok ? response.json() : null; });
		}

		// Copy buttons.
		wrap.addEventListener('click', function (event) {
			var button = event.target && event.target.closest ? event.target.closest('[data-copy]') : null;
			var text;
			var done;
			if (!button) {
				return;
			}
			text = button.getAttribute('data-copy') || '';
			done = function () {
				var old = button.textContent;
				button.textContent = i18n.copied || 'Copied.';
				root.setTimeout(function () { button.textContent = old; }, 1500);
			};
			if (root.navigator && root.navigator.clipboard && root.navigator.clipboard.writeText) {
				root.navigator.clipboard.writeText(text).then(done, function () { /* The field beside the button can be copied by hand. */ });
			} else {
				var field = button.parentNode ? button.parentNode.querySelector('input') : null;
				if (field) {
					field.focus();
					field.select();
					try {
						if (doc.execCommand('copy')) {
							done();
						}
					} catch (e) { /* The field stays selected, to copy by hand. */ }
				}
			}
		});

		// "Are you sure?" (the button that was clicked is remembered for
		// browsers whose submit event does not name it).
		var lastSubmit = null;
		wrap.addEventListener('click', function (event) {
			var button = event.target && event.target.closest ? event.target.closest('button[type="submit"]') : null;
			if (button) {
				lastSubmit = button;
			}
		});
		wrap.addEventListener('submit', function (event) {
			var button = event.submitter || lastSubmit;
			var ask = button && button.getAttribute && (!button.form || button.form === event.target) ? button.getAttribute('data-confirm') : null;
			lastSubmit = null;
			if (ask && !root.confirm(ask)) {
				event.preventDefault();
			}
		});

		// Fish suggestions.
		var list = doc.getElementById('fh-rq-names');
		var lastFish = '';
		var fishTimer = null;
		if (list && config.ajaxUrl) {
			wrap.addEventListener('input', function (event) {
				var target = event.target;
				var term;
				if (!target || !target.classList || !target.classList.contains('fh-rq-fish')) {
					return;
				}
				term = target.value;
				if (!shouldLookup(term, lastFish)) {
					return;
				}
				root.clearTimeout(fishTimer);
				fishTimer = root.setTimeout(function () {
					lastFish = String(term).trim();
					getJson(ajaxUrl(config.ajaxUrl, { action: config.suggestAction, term: lastFish }))
						.then(function (reply) {
							if (reply && reply.success && reply.data) {
								list.innerHTML = optionsHtml(reply.data.items);
							}
						})['catch'](function () { /* Suggestions are a nicety. */ });
				}, 220);
			});
		}

		// The customer search (Add tab).
		var box = doc.querySelector('.fh-rq-customer');
		if (box && config.ajaxUrl) {
			var search = box.querySelector('.fh-rq-customer__search');
			var results = box.querySelector('.fh-rq-customer__results');
			var picked = box.querySelector('.fh-rq-customer__picked');
			var idField = box.querySelector('.fh-rq-customer__id');
			var form = box.closest('form');
			var lastCustomer = '';
			var customerTimer = null;

			var field = function (name) {
				return form ? form.querySelector('[name="' + name + '"]') : null;
			};
			var unpick = function () {
				idField.value = '';
				picked.textContent = '';
			};

			search.addEventListener('input', function () {
				var term = search.value;
				if (!shouldLookup(term, lastCustomer)) {
					if (String(term).trim().length < 2) {
						results.innerHTML = '';
					}
					return;
				}
				root.clearTimeout(customerTimer);
				customerTimer = root.setTimeout(function () {
					lastCustomer = String(term).trim();
					getJson(ajaxUrl(config.ajaxUrl, { action: config.customersAction, nonce: config.nonce, term: lastCustomer }))
						.then(function (reply) {
							var html = reply && reply.success && reply.data ? customersHtml(reply.data.items) : '';
							results.innerHTML = html || '<span class="fh-rq-sub">' + escapeHtml(i18n.nobody || '') + '</span>';
						})['catch'](function () { results.innerHTML = ''; });
				}, 250);
			});

			results.addEventListener('click', function (event) {
				var button = event.target && event.target.closest ? event.target.closest('button[data-id]') : null;
				var link;
				if (!button) {
					return;
				}
				idField.value = button.getAttribute('data-id');
				['name', 'email', 'handle'].forEach(function (key) {
					var input = field(key);
					if (input) {
						input.value = button.getAttribute('data-' + key) || '';
					}
				});
				results.innerHTML = '';
				search.value = '';
				lastCustomer = '';
				picked.textContent = (i18n.picked || 'Site customer:') + ' ' + button.textContent + ' — ';
				link = doc.createElement('button');
				link.type = 'button';
				link.className = 'button-link';
				link.textContent = i18n.unpick || 'not this customer';
				link.addEventListener('click', unpick);
				picked.appendChild(link);
			});
		}
	}

	return {
		escapeHtml: escapeHtml,
		optionsHtml: optionsHtml,
		customerLabel: customerLabel,
		customersHtml: customersHtml,
		shouldLookup: shouldLookup,
		ajaxUrl: ajaxUrl,
		boot: boot
	};
}));
