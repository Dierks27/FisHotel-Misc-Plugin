/**
 * Announcer admin — tabs, repeaters, conditional fields.
 *
 * @package FisHotel\Misc\Sections\Announcer
 */

/* global jQuery */
(function ($) {
	'use strict';

	/* ============================================================== */
	/*  Tabs                                                           */
	/* ============================================================== */
	$(document).on('click', '.ancr-tab', function () {
		var tab = $(this).data('tab');

		$('.ancr-tab').removeClass('ancr-tab--active');
		$(this).addClass('ancr-tab--active');

		$('.ancr-panel').hide();
		$('.ancr-panel[data-panel="' + tab + '"]').show();
	});

	/* ============================================================== */
	/*  Color Pickers                                                  */
	/* ============================================================== */
	function initColorPickers(context) {
		$(context || document).find('.ancr-color-picker').each(function () {
			if (!$(this).closest('.wp-picker-container').length) {
				$(this).wpColorPicker();
			}
		});
	}

	$(document).ready(function () {
		initColorPickers();
	});

	/* ============================================================== */
	/*  Conditional Fields (display trigger)                           */
	/* ============================================================== */
	function updateConditionals() {
		var trigger = $('#ancr-display-trigger').val();
		$('.ancr-conditional').removeClass('ancr-visible');
		$('.ancr-conditional[data-show-when="' + trigger + '"]').addClass('ancr-visible');
	}

	$(document).on('change', '#ancr-display-trigger', updateConditionals);
	$(document).ready(updateConditionals);

	/* Schedule toggle */
	function updateScheduleVisibility() {
		var checked = $('#ancr-schedule-toggle').is(':checked');
		$('#ancr-schedule-fields').toggle(checked);
	}

	$(document).on('change', '#ancr-schedule-toggle', updateScheduleVisibility);
	$(document).ready(updateScheduleVisibility);

	/* Location type toggle */
	function updateLocationVisibility() {
		var type = $('#ancr-location-type').val();
		$('#ancr-location-rules-wrap').toggle(type !== 'all');
	}

	$(document).on('change', '#ancr-location-type', updateLocationVisibility);
	$(document).ready(updateLocationVisibility);

	/* ============================================================== */
	/*  CTA Button Repeater                                            */
	/* ============================================================== */
	var ctaIndex = $('#ancr-cta-list .ancr-cta-item').length;

	$(document).on('click', '#ancr-add-cta', function () {
		var i = ctaIndex++;
		var html = '<div class="ancr-cta-item" data-index="' + i + '">' +
			'<div class="ancr-cta-header">' +
				'<strong>Button <span class="ancr-cta-num">' + (i + 1) + '</span></strong>' +
				'<button type="button" class="ancr-cta-remove" title="Remove">&times;</button>' +
			'</div>' +
			'<div class="ancr-cta-body">' +
				'<div class="ancr-field-row">' +
					'<div class="ancr-field"><label class="ancr-label">Text</label>' +
						'<input type="text" name="_announcer_cta_buttons[' + i + '][text]" value=""></div>' +
					'<div class="ancr-field"><label class="ancr-label">Action</label>' +
						'<select name="_announcer_cta_buttons[' + i + '][action]">' +
							'<option value="link">Open link</option>' +
							'<option value="close">Close announcement</option>' +
							'<option value="link_close">Open link &amp; close</option>' +
						'</select></div>' +
				'</div>' +
				'<div class="ancr-field-row">' +
					'<div class="ancr-field"><label class="ancr-label">URL</label>' +
						'<input type="url" name="_announcer_cta_buttons[' + i + '][url]" value=""></div>' +
					'<div class="ancr-field"><label class="ancr-label">Target</label>' +
						'<select name="_announcer_cta_buttons[' + i + '][target]">' +
							'<option value="_self">Same window</option>' +
							'<option value="_blank">New tab</option>' +
						'</select></div>' +
				'</div>' +
				'<div class="ancr-field-row">' +
					'<div class="ancr-field"><label class="ancr-label">Button bg</label>' +
						'<input type="text" name="_announcer_cta_buttons[' + i + '][bg_color]" value="#ffffff" class="ancr-color-picker"></div>' +
					'<div class="ancr-field"><label class="ancr-label">Button text</label>' +
						'<input type="text" name="_announcer_cta_buttons[' + i + '][text_color]" value="#000000" class="ancr-color-picker"></div>' +
				'</div>' +
				'<div class="ancr-field-row">' +
					'<div class="ancr-field"><label class="ancr-label">Animation</label>' +
						'<select name="_announcer_cta_buttons[' + i + '][animation]">' +
							'<option value="none">None</option>' +
							'<option value="bounce">Bounce</option>' +
							'<option value="flash">Flash</option>' +
							'<option value="pulse">Pulse</option>' +
							'<option value="shake">Shake</option>' +
							'<option value="swing">Swing</option>' +
							'<option value="tada">Tada</option>' +
							'<option value="wobble">Wobble</option>' +
							'<option value="jello">Jello</option>' +
							'<option value="heartbeat">Heartbeat</option>' +
							'<option value="rubberband">Rubber Band</option>' +
						'</select></div>' +
					'<div class="ancr-field"><label class="ancr-label">Assign to message #</label>' +
						'<input type="number" name="_announcer_cta_buttons[' + i + '][message_index]" value="0" min="0" step="1">' +
						'<span class="ancr-hint">0 = all messages</span></div>' +
				'</div>' +
			'</div>' +
		'</div>';

		$('#ancr-cta-list').append(html);
		initColorPickers($('#ancr-cta-list .ancr-cta-item').last());
	});

	$(document).on('click', '.ancr-cta-remove', function () {
		$(this).closest('.ancr-cta-item').remove();
	});

	/* ============================================================== */
	/*  Location Rules Repeater                                        */
	/* ============================================================== */
	var locationIndex = $('#ancr-location-rules-list .ancr-rule-item').length;

	$(document).on('click', '#ancr-add-location-rule', function () {
		var i = locationIndex++;
		var html = '<div class="ancr-rule-item">' +
			'<select name="_announcer_location_rules[' + i + '][type]">' +
				'<option value="front_page">Front page</option>' +
				'<option value="blog">Blog / Posts page</option>' +
				'<option value="page">Page (ID/slug)</option>' +
				'<option value="post">Post (ID/slug)</option>' +
				'<option value="category">Category (slug)</option>' +
				'<option value="post_type">Post type</option>' +
				'<option value="archive">Archive pages</option>' +
				'<option value="search">Search results</option>' +
				'<option value="404">404 page</option>' +
				'<option value="url_contains">URL contains</option>' +
			'</select>' +
			'<input type="text" name="_announcer_location_rules[' + i + '][value]" value="" placeholder="Value (if applicable)">' +
			'<button type="button" class="ancr-rule-remove" title="Remove">&times;</button>' +
		'</div>';

		$('#ancr-location-rules-list').append(html);
	});

	$(document).on('click', '#ancr-location-rules-list .ancr-rule-remove', function () {
		$(this).closest('.ancr-rule-item').remove();
	});

	/* ============================================================== */
	/*  Visitor Conditions Repeater                                    */
	/* ============================================================== */
	var visitorIndex = $('#ancr-visitor-list .ancr-rule-item').length;

	$(document).on('click', '#ancr-add-visitor-condition', function () {
		var i = visitorIndex++;
		var html = '<div class="ancr-rule-item">' +
			'<select name="_announcer_visitor_conditions[' + i + '][type]">' +
				'<option value="device">Device type</option>' +
				'<option value="browser">Browser</option>' +
				'<option value="os">Operating system</option>' +
				'<option value="logged_in">Logged in</option>' +
				'<option value="user_role">User role</option>' +
				'<option value="referrer">Referrer contains</option>' +
				'<option value="language">Browser language</option>' +
			'</select>' +
			'<select name="_announcer_visitor_conditions[' + i + '][operator]">' +
				'<option value="is">is</option>' +
				'<option value="is_not">is not</option>' +
			'</select>' +
			'<input type="text" name="_announcer_visitor_conditions[' + i + '][value]" value="" placeholder="e.g. mobile, chrome, yes">' +
			'<button type="button" class="ancr-rule-remove" title="Remove">&times;</button>' +
		'</div>';

		$('#ancr-visitor-list').append(html);
	});

	$(document).on('click', '#ancr-visitor-list .ancr-rule-remove', function () {
		$(this).closest('.ancr-rule-item').remove();
	});

})(jQuery);
