/**
 * Snippets editor — My snippets: the code editor (WordPress's CodeMirror
 * when the user's profile allows syntax highlighting, else the plain
 * textarea), the scope list following the type, the on/off switch (AJAX,
 * nonce; the server test-runs PHP before it switches on), a confirm before
 * delete / restore, and a warning before leaving with unsaved changes.
 *
 * Text only: every message from the server is set with .text(); the page's
 * markup is rendered and escaped by PHP.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

/* global jQuery, fhSnippetsEditor */
(function ($) {
	'use strict';

	var cfg  = window.fhSnippetsEditor || {};
	var i18n = cfg.i18n || {};

	var MODES = {
		php: { name: 'php', startOpen: true },
		css: 'text/css',
		js: 'text/javascript',
		html: 'text/html'
	};

	/**
	 * Show a message above the table.
	 *
	 * @param {string} kind ok|warn|error.
	 * @param {string} text Message.
	 */
	function say(kind, text) {
		$('.fh-snip-status')
			.removeClass('is-ok is-warn is-error')
			.addClass('is-' + kind)
			.text(text || '');
	}

	/**
	 * Show only the scopes of a type; keep a valid one selected.
	 *
	 * @param {string} type php|css|js|html.
	 */
	function filterScopes(type) {
		var $scope = $('#fh-snip-scope');
		if (!$scope.length) {
			return;
		}
		var keep = false;
		$scope.find('option').each(function () {
			var match = String($(this).data('type')) === type;
			$(this).prop('hidden', !match).prop('disabled', !match);
			if (match && $(this).is(':selected')) {
				keep = true;
			}
		});
		if (!keep) {
			$scope.find('option').filter(function () {
				return String($(this).data('type')) === type;
			}).first().prop('selected', true);
		}
	}

	$(function () {
		var $form = $('#fh-snip-form');
		var $type = $('#fh-snip-type');
		var cm    = null;
		var dirty = false;

		// The code editor. cfg.codeEditor is false when the user disabled
		// syntax highlighting in their profile: the textarea stays plain.
		if ($form.length && cfg.codeEditor && window.wp && window.wp.codeEditor) {
			var settings = $.extend(true, {}, cfg.codeEditor);
			settings.codemirror = $.extend({}, settings.codemirror || {}, {
				mode: MODES[String($type.val())] || MODES.php
			});
			var instance = window.wp.codeEditor.initialize(document.getElementById('fh-snip-code'), settings);
			cm = instance && instance.codemirror ? instance.codemirror : null;
			if (cm) {
				cm.on('change', function () {
					dirty = true;
				});
				if ($form.find('fieldset').prop('disabled')) {
					cm.setOption('readOnly', true);
				}
			}
		}

		if ($type.length) {
			filterScopes(String($type.val()));
			$type.on('change', function () {
				var type = String($(this).val());
				filterScopes(type);
				if (cm) {
					// Linting was set up for the first type; a different
					// mode gets none rather than the wrong one.
					cm.setOption('lint', false);
					cm.setOption('mode', MODES[type] || MODES.php);
				}
			});
		}

		if ($form.length) {
			$form.on('input change', 'input, select, textarea', function () {
				dirty = true;
			});
			$form.on('submit', function () {
				if (cm) {
					cm.save();
				}
				dirty = false;
			});
			$(window).on('beforeunload', function () {
				return dirty ? (i18n.leave || 'You have unsaved changes.') : undefined;
			});
		}

		// Confirm before delete / restore.
		$(document).on('submit', 'form[data-confirm]', function (event) {
			if (!window.confirm(String($(this).data('confirm')))) {
				event.preventDefault();
			}
		});

		// The switch.
		$(document).on('change', '.fh-snip-db-table input[data-db-snippet]', function () {
			var $input = $(this);
			var $row   = $input.closest('.fh-snip-row');
			var id     = String($input.data('db-snippet'));
			var on     = $input.is(':checked');
			var data   = {
				action: cfg.action,
				snippet_id: id,
				enabled: on ? '1' : '0'
			};
			data[cfg.nonceField] = cfg.nonce;

			$row.addClass('is-saving');
			say('ok', i18n.saving || 'Saving…');

			$.post(cfg.ajaxUrl, data)
				.done(function (response) {
					if (response && response.success && response.data) {
						var status = response.data.status || {};
						$input.prop('checked', !!response.data.enabled);
						$row.find('[data-role="status"]')
							.attr('class', 'fh-snip-state fh-snip-state--' + String(status.key || 'off'))
							.text(String(status.label || ''));
						// stale: switched in the table, but the list the site runs
						// could not be updated — the message says so.
						say(response.data.stale ? 'error' : 'ok', String(response.data.message || ''));
						return;
					}
					$input.prop('checked', !on);
					say('error', (response && response.data && response.data.message) ? String(response.data.message) : (i18n.error || 'Error'));
				})
				.fail(function (xhr) {
					var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
					$input.prop('checked', !on);
					say('error', msg ? String(msg) : (i18n.error || 'Error'));
				})
				.always(function () {
					$row.removeClass('is-saving');
				});
		});
	});
})(jQuery);
