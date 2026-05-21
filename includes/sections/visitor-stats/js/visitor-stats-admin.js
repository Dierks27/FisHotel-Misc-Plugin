(function ($) {
	'use strict';

	var data = window.fishotelStatsAdmin;
	if (!data || !data.ajaxUrl) {
		return;
	}

	var $targets = $('.fh-stats-online-now');
	if (!$targets.length) {
		return;
	}

	function poll() {
		$.post(data.ajaxUrl, {
			action: 'fishotel_stats_online_now',
			_wpnonce: data.nonce
		}).done(function (res) {
			if (res && res.success && typeof res.data.count !== 'undefined') {
				$targets.text(res.data.count);
			}
		});
	}

	setInterval(poll, data.pollInterval || 10000);
})(jQuery);
