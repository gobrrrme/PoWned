/*
 * PoWned background miner: solves a block without a challenge page, for pages where an action
 * (order, express payment, coupon, login, form) needs one. Forms that need the block wait
 * until it is solved and are then sent on automatically. Configuration in data attributes of
 * this script tag.
 */
(function () {
	'use strict';
	var me = document.currentScript;
	if (!me || !window.fetch) return;
	var C = me.dataset, state = 'idle', waiters = [], retried = false;
	var forms = C.forms || 'form';

	function have() {
		var m = document.cookie.match(new RegExp('(?:^|; )' + C.cookie + '=1\\.(\\d{10})\\.[0-9a-f]{12}\\.(\\d{1,2})\\.'));
		return !!m && m[1] * 1000 > Date.now() + 120000 && +m[2] >= +C.min;
	}
	function finish() {
		var w = waiters; waiters = [];
		w.forEach(function (f) { try { f(); } catch (e) {} });
	}
	function mine(force) {
		if (state === 'mining' || (!force && have())) { if (state !== 'mining') { state = 'done'; finish(); } return; }
		state = 'mining';
		var started = Date.now(), workers = [];
		fetch(C.endpoint, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
			.then(function (r) { return r.json(); })
			.then(function (c) {
				var cores = Math.max(1, Math.min(2, navigator.hardwareConcurrency || 2)), solved = false;
				for (var k = 0; k < cores; k++) {
					var w = new Worker(C.worker);
					w.onmessage = function (ev) {
						if (!ev.data.done || solved) return;
						solved = true;
						workers.forEach(function (x) { x.terminate(); });
						var attrs = '; Path=/; SameSite=Lax' + (c.secure ? '; Secure' : '');
						document.cookie = C.cookie + '=' + c.challenge + ev.data.nonce + '.' + c.mac + '; Max-Age=' + c.ttl + attrs;
						document.cookie = C.cookie + '_new=' + Math.max(2, Date.now() - started) + '; Max-Age=300' + attrs;
						state = 'done'; finish();
					};
					w.postMessage({ challenge: c.challenge, bits: c.bits, start: k, step: cores });
					workers.push(w);
				}
			})
			/* No block possible (no workers, network error): let actions through, the server decides. */
			['catch'](function () { state = 'failed'; finish(); });
	}

	/* Hold protected forms until the block is solved, then send them on. */
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (!f || !f.matches || !f.matches(forms) || state === 'done' || state === 'failed' || have()) return;
		e.preventDefault(); e.stopImmediatePropagation();
		var by = e.submitter || null;
		if (by) by.classList.add('powned-wait');
		waiters.push(function () {
			if (by) by.classList.remove('powned-wait');
			if (f.requestSubmit) f.requestSubmit(by && by.form === f ? by : undefined); else f.submit();
		});
		mine(false);
	}, true);

	/* Checkout said the block is missing (expired, or the network changed): mine a new one and place the order again, once. */
	if (window.jQuery) {
		window.jQuery(document.body).on('checkout_error', function (ev, msg) {
			var html = String(msg || '') + (document.querySelector('.woocommerce-NoticeGroup-checkout') || { innerHTML: '' }).innerHTML;
			if (retried || html.indexOf('powned-retry') === -1) return;
			retried = true;
			waiters.push(function () {
				var f = document.querySelector('form.checkout');
				if (f) window.jQuery(f).trigger('submit');
			});
			state = 'idle'; mine(true);
		});
	}

	/* Shop pages (express payment buttons live in foreign iframes): mine right away. Pages with
	   only a form: mine when someone starts using the form. */
	if (C.eager === '1') {
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { mine(false); });
		else mine(false);
	} else {
		var lazy = function (e) {
			if (state === 'idle' && e.target && e.target.closest && e.target.closest(forms)) mine(false);
		};
		document.addEventListener('focusin', lazy, true);
		document.addEventListener('pointerdown', lazy, true);
	}
})();
