/**
 * Packing admin — the saves of the Packing page (bags, the size → bag map,
 * boxes, how many fit), "Add a row", the "Fill empty ones from the map"
 * tool (preview, then a run that asks for one batch after another), the
 * Packing box on the order screen (confirm / change / clear / refresh), and
 * the "Livestock — packed in a bag" checkbox of the product editor, which
 * shows and hides the Bag size dropdowns.
 *
 * The server draws every piece of HTML and writes every sentence; this
 * file posts forms and swaps the answers in. The pure helpers (fmt,
 * rowFromTemplate, fillStep, confirmPayload, afterSave, unsavedElsewhere,
 * livestockFromCategories, drawnAsAfterClick) are exported for
 * tests/packing-admin.test.js.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

/* global jQuery, module */
(function (root, $) {
	'use strict';

	/**
	 * A tiny sprintf: %d / %s in order, or %1$d / %2$s by position.
	 *
	 * @param {string} template Template.
	 * @return {string}
	 */
	function fmt(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var next = 0;
		return String(template === null || typeof template === 'undefined' ? '' : template).replace(/%(\d+\$)?[ds]/g, function (match, pos) {
			var index = pos ? parseInt(pos, 10) - 1 : next++;
			return typeof args[index] === 'undefined' ? '' : String(args[index]);
		});
	}

	/**
	 * A new table row from its template: every __i__ becomes the row's
	 * number, so its fields post under an index no other row has.
	 *
	 * @param {string} html  The template.
	 * @param {number} index The row's number.
	 * @return {string}
	 */
	function rowFromTemplate(html, index) {
		var n = parseInt(index, 10);
		if (isNaN(n) || n < 0) {
			n = 0;
		}
		return String(html || '').replace(/__i__/g, String(n));
	}

	/**
	 * One step of the fill run: fold the server's answer for a batch into
	 * the running state. The run goes on while the server says there is
	 * more and the cursor moved; an answer that is not a success, or a
	 * cursor that did not move, stops it (so it can never loop for ever).
	 *
	 * @param {Object} state { set, failed, after, steps }.
	 * @param {Object} reply The AJAX reply ({ success, data: { set, failed, last_id, done } }).
	 * @return {Object} { set, failed, after, steps, done, stopped }.
	 */
	function fillStep(state, reply) {
		var out = {
			set: (state && state.set) || 0,
			failed: (state && state.failed) || 0,
			after: (state && state.after) || 0,
			steps: ((state && state.steps) || 0) + 1,
			done: false,
			stopped: false
		};
		var data = reply && reply.success && reply.data ? reply.data : null;

		if (!data) {
			out.stopped = true;
			return out;
		}

		out.set += parseInt(data.set, 10) || 0;
		out.failed += parseInt(data.failed, 10) || 0;

		var last = parseInt(data.last_id, 10) || 0;
		if (data.done) {
			out.done = true;
		} else if (last <= out.after) {
			out.stopped = true;
		}
		out.after = Math.max(out.after, last);

		// A safety net: no store has this many batches of variations.
		if (!out.done && out.steps >= 2000) {
			out.stopped = true;
		}

		return out;
	}

	/**
	 * What a button of the Packing box posts.
	 *
	 * @param {string} op       view | confirm | clear.
	 * @param {string} choice   "suggested" or "chosen" (confirm only).
	 * @param {string} selected The key of the box picked in the list.
	 * @param {*}      count    How many of it.
	 * @param {*}      shown    The suggestion as the button showed it: the server
	 *                          refuses to confirm one that is different by now.
	 * @return {Object|null} The fields, or null when a box has to be picked first.
	 */
	function confirmPayload(op, choice, selected, count, shown) {
		op = String(op || '');
		if (op === 'view' || op === 'clear') {
			return { op: op };
		}
		if (op !== 'confirm') {
			return null;
		}
		if (choice !== 'chosen') {
			return { op: 'confirm', choice: 'suggested', shown: String(shown === null || typeof shown === 'undefined' ? '' : shown) };
		}
		selected = String(selected === null || typeof selected === 'undefined' ? '' : selected);
		if (selected === '') {
			return null;
		}
		var n = parseInt(count, 10);
		return { op: 'confirm', choice: selected, count: isNaN(n) || n < 1 ? 1 : n };
	}

	/**
	 * What follows a form's save. A saved form that reloads the page keeps
	 * its button disabled until the page is there again: a second click in
	 * between would post the same rows against a list that has changed.
	 *
	 * @param {Object}  reply   The AJAX reply.
	 * @param {boolean} reloads Whether the form reloads the page once saved.
	 * @return {Object} { ok, reload, enable }.
	 */
	function afterSave(reply, reloads) {
		var ok = !!(reply && reply.success);
		return { ok: ok, reload: ok && !!reloads, enable: !(ok && !!reloads) };
	}

	/**
	 * Whether saving a form now would throw away what was typed into
	 * another: it reloads the page while another form has unsaved changes.
	 *
	 * @param {Array}  forms   [{ dirty: boolean }] — every form of the page.
	 * @param {number} index   The form being saved.
	 * @param {boolean} reloads Whether it reloads the page once saved.
	 * @return {boolean}
	 */
	function unsavedElsewhere(forms, index, reloads) {
		if (!reloads || !forms || !forms.length) {
			return false;
		}
		for (var i = 0; i < forms.length; i++) {
			if (i !== index && forms[i] && forms[i].dirty) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What the "Livestock — packed in a bag" checkbox of the product editor
	 * should say after the product's categories changed. It follows them
	 * only while the product has no stored choice and the owner has not
	 * touched the box on this page: then it is ticked when the product is in
	 * one of the fish categories (or carries a Bag size from before the
	 * checkbox existed), as the server will read it once the product is
	 * saved.
	 *
	 * @param {Object} box        { follow: no stored choice, touched: changed by hand, legacy: has a Bag size already }.
	 * @param {Array}  fishIds    The term ids of the fish categories.
	 * @param {Array}  checkedIds The term ids ticked in the category list.
	 * @return {boolean|null} Ticked or not; null = leave the box alone.
	 */
	function livestockFromCategories(box, fishIds, checkedIds) {
		if (!box || !box.follow || box.touched) {
			return null;
		}
		if (box.legacy) {
			return true;
		}

		var fish = {};
		var i;
		for (i = 0; fishIds && i < fishIds.length; i++) {
			if (String(fishIds[i]).replace(/\s+/g, '') !== '') {
				fish[String(fishIds[i]).replace(/\s+/g, '')] = true;
			}
		}
		for (i = 0; checkedIds && i < checkedIds.length; i++) {
			if (fish[String(checkedIds[i]).replace(/\s+/g, '')] === true) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What the form says the "Livestock — packed in a bag" checkbox was
	 * drawn as, once the owner has clicked it. The server stores a choice
	 * when the box is posted differently from how it was drawn; a box that
	 * was clicked is a choice whatever it ends up as — clicked off and on
	 * again included — so the form then says the opposite of what is posted.
	 *
	 * @param {boolean} checked Whether the box is ticked now.
	 * @return {string} 'yes' or 'no'.
	 */
	function drawnAsAfterClick(checked) {
		return checked ? 'no' : 'yes';
	}

	var api = {
		fmt: fmt,
		rowFromTemplate: rowFromTemplate,
		fillStep: fillStep,
		confirmPayload: confirmPayload,
		afterSave: afterSave,
		unsavedElsewhere: unsavedElsewhere,
		livestockFromCategories: livestockFromCategories,
		drawnAsAfterClick: drawnAsAfterClick
	};

	root.fhPacking = root.fhPacking || {};
	root.fhPacking.helpers = api;

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}

	if (!$) {
		return;
	}

	/* ---------------------------------------------------------------- */
	/*  DOM wiring                                                       */
	/* ---------------------------------------------------------------- */

	var cfg = root.fhPacking || {};
	var i18n = cfg.i18n || {};
	var ajaxUrl = cfg.ajaxUrl || root.ajaxurl || '';

	function setStatus($el, msg, state) {
		$el.removeClass('is-ok is-error').text(msg || '');
		if (state) {
			$el.addClass(state);
		}
	}

	function messageOf(xhrOrReply, fallback) {
		var reply = xhrOrReply && xhrOrReply.responseJSON ? xhrOrReply.responseJSON : xhrOrReply;
		if (reply && reply.data && reply.data.message) {
			return String(reply.data.message);
		}
		return fallback || i18n.error || 'Something went wrong. Please try again.';
	}

	$(function () {

		/* ---- The page: every form saves by AJAX ------------------- */

		// What was typed into a form and not saved yet.
		$(document).on('input change', '.fh-pack-form', function () {
			$(this).data('fhDirty', true);
		});

		$(document).on('submit', '.fh-pack-form', function (e) {
			e.preventDefault();

			var $form = $(this);
			var $btn = $form.find('button[type="submit"]');
			var $status = $form.find('.fh-pack-status').last();
			var reloads = !!$form.attr('data-reload');
			var $forms = $('.fh-pack-form');
			var states = $forms.map(function () {
				return { dirty: !!$(this).data('fhDirty') };
			}).get();

			// This save reloads the page: what is typed into another form would be gone.
			if (unsavedElsewhere(states, $forms.index($form), reloads)) {
				setStatus($status, i18n.unsaved || 'Nothing was saved: another form on this page has changes that are not saved, and saving this one reloads the page. Save that one first (or reload the page to drop them).', 'is-error');
				return;
			}

			$btn.prop('disabled', true);
			setStatus($status, i18n.saving || 'Saving…', '');

			$.post(ajaxUrl, $form.serialize())
				.done(function (reply) {
					var next = afterSave(reply, reloads);
					if (next.ok) {
						$form.data('fhDirty', false);
						setStatus($status, messageOf(reply, ''), 'is-ok');
					} else {
						setStatus($status, messageOf(reply), 'is-error');
					}
					$btn.prop('disabled', !next.enable);
					if (next.reload) {
						// The list changed (new numbers, new columns): draw the page again.
						// The button stays disabled until then.
						root.setTimeout(function () {
							root.location.reload();
						}, 900);
					}
				})
				.fail(function (xhr) {
					setStatus($status, messageOf(xhr), 'is-error');
					$btn.prop('disabled', false);
				});
		});

		$(document).on('click', '.fh-pack-add-row', function () {
			var $form = $(this).closest('form');
			var $table = $form.find('table.fh-pack-table').first();
			var template = $form.find('.fh-pack-row-template').first().html();
			var next = parseInt($table.attr('data-next'), 10) || $table.find('tbody tr').length;

			if (!template) {
				return;
			}
			$table.find('tbody').append(rowFromTemplate(template, next));
			$table.attr('data-next', next + 1);
			$table.find('tbody tr').last().find('input[type="text"]').first().trigger('focus');
		});

		/* ---- Fill empty ones from the map -------------------------- */

		$(document).on('click', '.fh-pack-fill-preview', function () {
			var $tool = $(this).closest('.fh-pack-fill');
			var $status = $tool.find('.fh-pack-status');
			var $list = $tool.find('.fh-pack-fill-result');
			var $run = $tool.find('.fh-pack-fill-run');
			var $btn = $(this);

			$btn.prop('disabled', true);
			$run.prop('disabled', true);
			setStatus($status, i18n.counting || 'Counting…', '');

			$.post(ajaxUrl, { action: 'fishotel_packing_fill_preview', _wpnonce: $tool.attr('data-nonce') })
				.done(function (reply) {
					if (!reply || !reply.success) {
						setStatus($status, messageOf(reply), 'is-error');
						return;
					}
					setStatus($status, '', '');
					$list.empty();
					$.each(reply.data.lines || [], function (i, line) {
						$('<li></li>').text(line).appendTo($list);
					});
					$run.prop('disabled', !(parseInt(reply.data.would_set, 10) > 0));
				})
				.fail(function (xhr) {
					setStatus($status, messageOf(xhr), 'is-error');
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
		});

		$(document).on('click', '.fh-pack-fill-run', function () {
			var $tool = $(this).closest('.fh-pack-fill');
			var $status = $tool.find('.fh-pack-status');
			var $buttons = $tool.find('button');
			var state = { set: 0, failed: 0, after: 0, steps: 0 };

			$buttons.prop('disabled', true);
			setStatus($status, i18n.working || 'Working…', '');

			function finish(text, cls) {
				setStatus($status, text, cls);
				$tool.find('.fh-pack-fill-preview').prop('disabled', false);
			}

			function step() {
				$.post(ajaxUrl, { action: 'fishotel_packing_fill_run', _wpnonce: $tool.attr('data-nonce'), after: state.after })
					.done(function (reply) {
						state = fillStep(state, reply);
						if (state.done) {
							finish(fmt(i18n.done || 'Done: %1$d set (%2$d failed).', state.set, state.failed), state.failed ? 'is-error' : 'is-ok');
							return;
						}
						if (state.stopped) {
							finish(reply && !reply.success ? messageOf(reply) : (i18n.stopped || 'Stopped.'), 'is-error');
							return;
						}
						setStatus($status, fmt(i18n.progress || '%d set so far…', state.set), '');
						step();
					})
					.fail(function (xhr) {
						finish(messageOf(xhr, i18n.stopped), 'is-error');
					});
			}

			step();
		});

		/* ---- The product editor: Livestock — packed in a bag ------- */

		// The product-data box carries .fh-no-bag while the checkbox is off; the
		// stylesheet hides every Bag size dropdown under it, so the variation
		// panels WooCommerce loads later are covered without being touched here.
		var $packs = $('#fh_packs_in_bag');
		if ($packs.length) {
			var packsTouched = false;
			var showBagFields = function () {
				$('#woocommerce-product-data').toggleClass('fh-no-bag', !$packs.prop('checked'));
			};

			$packs.on('change', function () {
				packsTouched = true;
				// The owner's click is a choice: the save stores it, whatever it ends up as.
				$('#fh_packs_in_bag_shown').val(drawnAsAfterClick($packs.prop('checked')));
				showBagFields();
			});

			// A product with no stored choice follows its categories, as the server
			// will once it is saved — so a new fish shows its Bag size right away.
			$(document).on('change', '#product_catdiv input[type="checkbox"]', function () {
				root.setTimeout(function () {
					var next = livestockFromCategories(
						{ follow: $packs.attr('data-fh-follow') === '1', touched: packsTouched, legacy: $packs.attr('data-fh-legacy') === '1' },
						String($packs.attr('data-fh-fish') || '').split(','),
						$('#product_catchecklist input[type="checkbox"]:checked').map(function () {
							return this.value;
						}).get()
					);
					if (next === null) {
						return;
					}
					$packs.prop('checked', next);
					// Still the default, not a choice: the save keeps following it.
					$('#fh_packs_in_bag_shown').val(next ? 'yes' : 'no');
					showBagFields();
				}, 0);
			});

			showBagFields();
		}

		/* ---- The Packing box on the order screen ------------------- */

		// The box sits inside the order's own form: Enter in its fields must
		// choose the box, never save the whole order.
		$(document).on('keydown', '.fh-pack-box .fh-pack-count, .fh-pack-box .fh-pack-choice', function (e) {
			if (e.key === 'Enter' || e.keyCode === 13) {
				e.preventDefault();
				$(this).closest('.fh-pack-box').find('.fh-pack-confirm[data-choice="chosen"]').trigger('click');
			}
		});

		$(document).on('click', '.fh-pack-box .fh-pack-confirm', function (e) {
			e.preventDefault();

			var $btn = $(this);
			var $box = $btn.closest('.fh-pack-box');
			var $status = $box.find('.fh-pack-status');
			var payload = confirmPayload($btn.attr('data-op'), $btn.attr('data-choice'), $box.find('.fh-pack-choice').val(), $box.find('.fh-pack-count').val(), $btn.attr('data-shown'));

			if (!payload) {
				$box.find('.fh-pack-choice').trigger('focus');
				return;
			}

			payload.action = 'fishotel_packing_confirm';
			payload._wpnonce = $box.attr('data-nonce');
			payload.order_id = $box.attr('data-order-id');

			$box.find('button').prop('disabled', true);
			setStatus($status, i18n.saving || 'Saving…', '');

			$.post(ajaxUrl, payload)
				.done(function (reply) {
					if (reply && reply.success && reply.data && reply.data.html) {
						$box.replaceWith(reply.data.html);
						return;
					}
					$box.find('button').prop('disabled', false);
					setStatus($status, messageOf(reply), 'is-error');
				})
				.fail(function (xhr) {
					var data = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
					if (data && data.html) {
						// Refused because the box on screen was out of date: here it is as it stands now.
						$box.replaceWith(data.html);
						return;
					}
					$box.find('button').prop('disabled', false);
					setStatus($status, messageOf(xhr), 'is-error');
				});
		});
	});
})(typeof window !== 'undefined' ? window : globalThis, typeof jQuery !== 'undefined' ? jQuery : null);
