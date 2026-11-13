/**
 * Gift Cards admin: ask before an import or switching to Live, and pick
 * "Send it on" when a delivery date is typed. Pure helpers are tested in
 * node (tests/gift-cards-admin.test.js).
 */
(function (root) {
	'use strict';

	/**
	 * The question to ask before a form is sent ('' = none).
	 *
	 * @param {object} form    { confirm, confirmLive }
	 * @param {string} mode    The chosen mode ('' when the form has none).
	 * @param {string} current The stored mode.
	 */
	function question(form, mode, current) {
		if (form.confirm) {
			return form.confirm;
		}
		if (form.confirmLive && mode === 'live' && current !== 'live') {
			return form.confirmLive;
		}
		return '';
	}

	var api = { question: question };
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
		return;
	}
	var doc = root.document;
	if (!doc) {
		return;
	}

	doc.addEventListener('submit', function (e) {
		var f = e.target;
		if (!f || !f.closest || !f.closest('.fh-gc-wrap')) {
			return;
		}
		var chosen = f.querySelector('input[name="mode"]:checked');
		var stored = f.querySelector('input[name="mode"][checked]');
		var q = question(
			{ confirm: f.getAttribute('data-fh-confirm') || '', confirmLive: f.getAttribute('data-fh-confirm-live') || '' },
			chosen ? chosen.value : '',
			stored ? stored.value : ''
		);
		if (q && !root.confirm(q)) {
			e.preventDefault();
		}
	});

	doc.addEventListener('input', function (e) {
		var t = e.target;
		if (t && t.name === 'deliver_on' && t.value) {
			var radio = t.closest('label') && t.closest('label').querySelector('input[name="delivery"]');
			if (radio) {
				radio.checked = true;
			}
		}
	});
}(typeof window !== 'undefined' ? window : this));
