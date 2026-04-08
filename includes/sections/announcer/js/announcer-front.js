/**
 * Announcer frontend — display triggers, close, cookies, ticker, countdown.
 *
 * @package FisHotel\Misc\Sections\Announcer
 */

(function () {
	'use strict';

	/* ============================================================== */
	/*  Cookie Helpers                                                 */
	/* ============================================================== */
	function setCookie(name, value, days) {
		var expires = '';
		if (days > 0) {
			var d = new Date();
			d.setTime(d.getTime() + days * 86400000);
			expires = ';expires=' + d.toUTCString();
		}
		document.cookie = name + '=' + value + expires + ';path=/;SameSite=Lax';
	}

	function getCookie(name) {
		var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
		return match ? match[2] : null;
	}

	/* ============================================================== */
	/*  Show Bar                                                       */
	/* ============================================================== */
	function showBar(bar) {
		var anim = bar.dataset.ancrShowAnim || 'none';
		bar.classList.remove('ancr-hidden');
		bar.style.display = '';

		if (anim && anim !== 'none') {
			bar.classList.add('ancr-anim-show-' + anim);
		}
	}

	/* ============================================================== */
	/*  Close Bar                                                      */
	/* ============================================================== */
	function closeBar(bar) {
		var anim       = bar.dataset.ancrCloseAnim || 'none';
		var cookieDays = parseInt(bar.dataset.ancrCookieDays, 10) || 0;
		var barId      = bar.dataset.ancrId;

		setCookie('ancr_closed_' + barId, '1', cookieDays);

		if (anim && anim !== 'none') {
			bar.classList.add('ancr-anim-close-' + anim);
			bar.addEventListener('animationend', function handler() {
				bar.removeEventListener('animationend', handler);
				bar.style.display = 'none';
			});
		} else {
			bar.style.display = 'none';
		}
	}

	/* ============================================================== */
	/*  Ticker / Slider                                                */
	/* ============================================================== */
	function initTicker(bar) {
		var isMulti    = bar.dataset.ancrMulti === '1';
		var multiType  = bar.dataset.ancrMultiType;
		var autoPlay   = bar.dataset.ancrMultiAuto === '1';
		var speed      = (parseInt(bar.dataset.ancrMultiSpeed, 10) || 5) * 1000;
		var isScroll   = bar.dataset.ancrTickerScroll === '1';

		if (!isMulti || multiType !== 'ticker') return;

		var messages = bar.querySelectorAll('.ancr-message');
		if (messages.length < 2) return;

		if (isScroll) {
			messages.forEach(function (m) { m.classList.add('ancr-message--active'); });
			var totalWidth = 0;
			messages.forEach(function (m) { totalWidth += m.scrollWidth; });
			bar.style.setProperty('--ancr-marquee-speed', Math.max(totalWidth / 60, 10) + 's');
			return;
		}

		var current = 0;

		function goTo(index) {
			messages[current].classList.remove('ancr-message--active');
			current = (index + messages.length) % messages.length;
			messages[current].classList.add('ancr-message--active');
		}

		var prevBtn = bar.querySelector('.ancr-nav-prev');
		var nextBtn = bar.querySelector('.ancr-nav-next');

		if (prevBtn) prevBtn.addEventListener('click', function () { goTo(current - 1); });
		if (nextBtn) nextBtn.addEventListener('click', function () { goTo(current + 1); });

		if (autoPlay) {
			setInterval(function () { goTo(current + 1); }, speed);
		}
	}

	/* ============================================================== */
	/*  Countdown Timer                                                */
	/* ============================================================== */
	function initCountdown(bar) {
		if (bar.dataset.ancrCountdown !== '1') return;

		var timerEl  = bar.querySelector('.ancr-cd-timer');
		if (!timerEl) return;

		var target   = new Date(timerEl.dataset.ancrCdTarget).getTime();
		var complete = bar.dataset.ancrCdComplete || 'hide';

		function update() {
			var now  = Date.now();
			var diff = Math.max(0, target - now);

			if (diff <= 0) {
				if (complete === 'hide') {
					bar.style.display = 'none';
				} else if (complete === 'keep') {
					var cdWrap = bar.querySelector('.ancr-countdown');
					if (cdWrap) cdWrap.style.display = 'none';
				}
				return;
			}

			var d = Math.floor(diff / 86400000);
			var h = Math.floor((diff % 86400000) / 3600000);
			var m = Math.floor((diff % 3600000) / 60000);
			var s = Math.floor((diff % 60000) / 1000);

			var parts = timerEl.querySelectorAll('.ancr-cd-part');
			if (parts[0]) parts[0].textContent = String(d).padStart(2, '0');
			if (parts[1]) parts[1].textContent = String(h).padStart(2, '0');
			if (parts[2]) parts[2].textContent = String(m).padStart(2, '0');
			if (parts[3]) parts[3].textContent = String(s).padStart(2, '0');

			requestAnimationFrame(function () {
				setTimeout(update, 1000);
			});
		}

		update();
	}

	/* ============================================================== */
	/*  CTA Button Actions                                             */
	/* ============================================================== */
	function initCTAActions(bar) {
		bar.querySelectorAll('.ancr-cta-btn').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				var action = btn.dataset.ancrAction;

				if (action === 'close') {
					e.preventDefault();
					closeBar(bar);
				} else if (action === 'link_close') {
					setTimeout(function () { closeBar(bar); }, 100);
				}
			});
		});
	}

	/* ============================================================== */
	/*  Init All Bars                                                  */
	/* ============================================================== */
	function initBars() {
		var bars = document.querySelectorAll('.ancr-bar');

		bars.forEach(function (bar) {
			var barId   = bar.dataset.ancrId;
			var trigger = bar.dataset.ancrTrigger || 'immediate';

			// Check if previously closed (cookie).
			if (getCookie('ancr_closed_' + barId)) {
				bar.style.display = 'none';
				return;
			}

			// Display trigger.
			if (trigger === 'immediate') {
				showBar(bar);
			} else if (trigger === 'delay') {
				var delay = (parseInt(bar.dataset.ancrDelay, 10) || 0) * 1000;
				setTimeout(function () { showBar(bar); }, delay);
			} else if (trigger === 'scroll') {
				var pct = parseInt(bar.dataset.ancrScroll, 10) || 50;
				var shown = false;

				function onScroll() {
					if (shown) return;
					var scrolled = (window.scrollY / (document.documentElement.scrollHeight - window.innerHeight)) * 100;
					if (scrolled >= pct) {
						shown = true;
						showBar(bar);
						window.removeEventListener('scroll', onScroll);
					}
				}

				window.addEventListener('scroll', onScroll, { passive: true });
			}

			// Close button.
			var closeBtn = bar.querySelector('.ancr-close');
			if (closeBtn) {
				closeBtn.addEventListener('click', function () { closeBar(bar); });
			}

			// Ticker / slider.
			initTicker(bar);

			// Countdown.
			initCountdown(bar);

			// CTA actions.
			initCTAActions(bar);
		});
	}

	/* ============================================================== */
	/*  Boot                                                           */
	/* ============================================================== */
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initBars);
	} else {
		initBars();
	}

})();
