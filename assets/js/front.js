// Cancel subscription confirmation dialog.
document.addEventListener('submit', function (event) {
	if (!event.target.matches('.megurio-cancel-subscription-form')) {
		return;
	}
	if (!window.confirm('この定期購入をキャンセルします。よろしいですか？')) {
		event.preventDefault();
	}
});

// Express Checkout (Apple Pay / Google Pay) notice injection.
//
// WCPay  : div.wcpay-express-checkout-wrapper
//            → always rendered in HTML when WCPay thinks express checkout may show;
//              no display:none on the wrapper itself.
//
// Stripe : #wc-stripe-express-checkout-element
//            → starts with style="display:none", Stripe JS removes it when
//              Apple Pay / Google Pay is confirmed available.
//
// Strategy:
//   • WCPay  — inject on DOMContentLoaded (wrapper is already in DOM).
//   • Stripe — MutationObserver watches for style attribute change on the element.
//   • Both   — also poll every 300 ms for 3 s as a safety net (handles React
//               or block-checkout renders that arrive after DOMContentLoaded).
(function () {
	'use strict';

	if (!window.megurio_params || !megurio_params.is_subscription_context) {
		return;
	}

	var noticeText  = megurio_params.express_notice_text;
	var NOTICE_CLASS = 'megurio-express-checkout-notice';

	// ── Selectors ──────────────────────────────────────────────────────────
	// WCPay: wrapper is always visible when rendered.
	var WCPAY_WRAPPERS = [
		'.wcpay-express-checkout-wrapper', // WCPay ECE (current)
		'#wcpay-payment-request-wrapper',  // WCPay classic PR button (older)
	];

	// Stripe: element starts hidden; becomes visible when Apple/Google Pay confirmed.
	var STRIPE_ELEMENTS = [
		'#wc-stripe-express-checkout-element', // Stripe ECE
		'#wc-stripe-payment-request-wrapper',  // Stripe classic PR button
	];
	// ───────────────────────────────────────────────────────────────────────

	function createNotice() {
		var p = document.createElement('p');
		p.className = NOTICE_CLASS;
		p.textContent = noticeText;
		return p;
	}

	function appendNotice(el) {
		if (!el || el.querySelector('.' + NOTICE_CLASS)) return;
		el.appendChild(createNotice());
	}

	function isVisible(el) {
		if (!el) return false;
		var cs = window.getComputedStyle(el);
		return cs.display !== 'none' && cs.visibility !== 'hidden';
	}

	// Inject into WCPay wrappers (always visible when present).
	function injectWCPay() {
		WCPAY_WRAPPERS.forEach(function (sel) {
			var el = document.querySelector(sel);
			if (el) appendNotice(el);
		});
	}

	// Inject into Stripe elements only when they become visible.
	function injectStripeIfVisible() {
		STRIPE_ELEMENTS.forEach(function (sel) {
			var el = document.querySelector(sel);
			if (el && isVisible(el)) appendNotice(el);
		});
	}

	// ── MutationObserver for Stripe (display:none → visible) ───────────────
	var observer = new MutationObserver(function (mutations) {
		mutations.forEach(function (mutation) {
			var target = mutation.target;
			// Check if the mutated element itself is a Stripe express element.
			STRIPE_ELEMENTS.forEach(function (sel) {
				if (target.matches && target.matches(sel) && isVisible(target)) {
					appendNotice(target);
				}
			});
		});
	});

	// ── Polling fallback (handles late React / block-checkout renders) ──────
	var pollCount = 0;
	var MAX_POLLS = 10; // 10 × 300 ms = 3 s
	var pollTimer = setInterval(function () {
		injectWCPay();
		injectStripeIfVisible();
		pollCount++;
		if (pollCount >= MAX_POLLS) clearInterval(pollTimer);
	}, 300);

	// ── Boot on DOMContentLoaded ────────────────────────────────────────────
	document.addEventListener('DOMContentLoaded', function () {
		// WCPay: inject immediately (wrapper already in DOM).
		injectWCPay();

		// Stripe: inject if already visible, then watch for visibility change.
		injectStripeIfVisible();
		STRIPE_ELEMENTS.forEach(function (sel) {
			var el = document.querySelector(sel);
			if (el) {
				observer.observe(el, {
					attributes: true,
					attributeFilter: ['style', 'class'],
				});
			}
		});

		// Also observe body for dynamically added express checkout elements.
		observer.observe(document.body, {
			childList: true,
			subtree: false,
		});
	});
}());
