/*
 * PoWned background miner: keeps one unspent action block ready on pages where an action
 * (order, coupon, login, password reset, form) needs one. The server spends it and deletes the
 * cookie, then the next one is mined. Protected forms that are sent before a block is ready
 * wait and then go out on their own. Configuration in data attributes of this script tag.
 */
(function () {
	'use strict';
	var me = document.currentScript;
	if (!me || !window.fetch) return;
	var C = me.dataset, forms = C.forms;
	var eager = C.eager === '1', touched = false;
	var state = 'idle', waiters = [], known = '', until = 0, failedAt = 0, fails = 0, retried = false, bypass = false;
	/* Not taken from the script tag: pages cached before 0.5 name the browsing cookie there. */
	var NAME = 'powned_action', noCookies = false;

	function cookie() {
		var m = document.cookie.match(new RegExp('(?:^|; )' + NAME + '=([^;]+)'));
		return m ? m[1] : '';
	}
	/* Ready: the block the server confirmed (or we mined) is still there and not about to expire. */
	function ready() {
		var c = cookie();
		return !!c && c === known && until > Date.now() + 60000;
	}
	function settle(next) {
		state = next;
		if (next === 'failed') { failedAt = Date.now(); fails++; } else { fails = 0; }
		var w = waiters; waiters = [];
		w.forEach(function (f) { try { f(); } catch (e) {} });
	}

	/* Workers where possible, otherwise (assets on a CDN) short slices on the page. */
	function solve(c, ok, fail) {
		var workers = [], over = false, cores = Math.max(1, Math.min(2, navigator.hardwareConcurrency || 2));
		var timer = setTimeout(function () { end(fail); }, 120000);
		function end(then, value) {
			if (over) return;
			over = true; clearTimeout(timer);
			workers.forEach(function (w) { w.terminate(); });
			then(value);
		}
		function onPage() {
			workers.forEach(function (w) { w.terminate(); });
			workers = [];
			var n = 0;
			function slice() {
				if (over) return;
				var r = window.PownedMiner.search(c.challenge, c.bits, n, 1, 8192);
				if (r.found) { end(ok, r.nonce); return; }
				n = r.next;
				setTimeout(slice, 0);
			}
			if (window.PownedMiner) { slice(); return; }
			var s = document.createElement('script');
			s.src = C.worker;
			s.onload = function () { if (window.PownedMiner) slice(); else end(fail); };
			s.onerror = function () { end(fail); };
			document.head.appendChild(s);
		}
		try {
			var switched = false;
			for (var k = 0; k < cores; k++) {
				var w = new Worker(C.worker);
				w.onmessage = function (ev) { if (ev.data.done) end(ok, ev.data.nonce); };
				w.onerror = function () { if (!switched && !over) { switched = true; onPage(); } };
				w.postMessage({ challenge: c.challenge, bits: c.bits, start: k, step: cores });
				workers.push(w);
			}
		} catch (e) {
			onPage();
		}
	}

	function ensure(force) {
		if (state === 'mining') return;
		state = 'mining';
		var started = Date.now(), before = cookie();
		var url = C.endpoint + '&action=1' + (force ? '&fresh=1' : '');
		fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
			.then(function (r) { return r.json(); })
			.then(function (c) {
				if (c.valid) {
					known = before; until = Date.now() + c.left * 1000;
					settle('idle');
					return;
				}
				/* Cookies blocked: no point in mining, the block could not be handed over. */
				document.cookie = NAME + '_probe=1; Path=/; SameSite=Lax';
				noCookies = document.cookie.indexOf(NAME + '_probe=') === -1;
				document.cookie = NAME + '_probe=; Path=/; Max-Age=0';
				if (noCookies) { settle('failed'); return; }
				solve(c, function (nonce) {
					known = c.challenge + nonce + '.' + c.mac + '_' + Math.max(2, Date.now() - started);
					until = started + c.ttl * 1000;
					document.cookie = NAME + '=' + known + '; Path=/; SameSite=Lax' + (c.secure ? '; Secure' : '') + '; Max-Age=' + c.ttl;
					settle(cookie() === known ? 'idle' : 'failed');
				}, function () { settle('failed'); });
			})
			/* No block possible (network error, cookies off): let actions through, the server decides. */
			['catch'](function () { settle('failed'); });
	}

	/* Hold protected forms until a block is ready, then send them on. */
	document.addEventListener('submit', function (e) {
		var f = e.target, by = e.submitter || null;
		if (bypass || !forms || !f || !f.matches || !f.matches(forms)) return;
		/* Of the cart form only "apply coupon" needs a block, not "update cart". */
		if (f.classList.contains('woocommerce-cart-form') && !(by && by.name === 'apply_coupon')) return;
		if (e.isTrusted) retried = false;
		touched = true;
		if (ready()) return;
		e.preventDefault(); e.stopImmediatePropagation();
		if (f.hasAttribute('data-powned-wait')) return; /* second click while waiting */
		f.setAttribute('data-powned-wait', '1');
		if (by) by.style.cursor = 'progress';
		waiters.push(function () {
			f.removeAttribute('data-powned-wait');
			if (by) by.style.cursor = '';
			bypass = true;
			try {
				if (f.requestSubmit) f.requestSubmit(by && by.form === f ? by : undefined); else f.submit();
			} finally {
				bypass = false;
			}
		});
		ensure(false);
	}, true);

	/* Checkout said the block is missing (spent, expired, or the network changed): mine a new one and send again, once per click. */
	if (window.jQuery) {
		window.jQuery(document.body).on('checkout_error', function (ev, msg) {
			var html = String(msg || '') + (document.querySelector('.woocommerce-NoticeGroup-checkout') || { innerHTML: '' }).innerHTML;
			if (retried || html.indexOf('powned-retry') === -1) return;
			retried = true;
			waiters.push(function () {
				var f = document.querySelector('form.checkout');
				if (f && state !== 'failed') window.jQuery(f).trigger('submit');
			});
			ensure(true);
		});
	}

	/* Shop pages (express payment buttons live in foreign iframes): always keep a block ready.
	   Pages with only a form: from the moment someone uses the form. Never in hidden tabs. */
	function keep() {
		if (!(eager || touched) || document.hidden || state === 'mining' || ready()) return;
		/* After failures only a few retries, further apart each time; a submit still tries. */
		if (state === 'failed' && (noCookies || fails >= 4 || Date.now() - failedAt < 15000 * fails * fails)) return;
		ensure(false);
	}
	function touch(e) {
		if (touched || !forms || !e.target || !e.target.closest || !e.target.closest(forms)) return;
		touched = true;
		keep();
	}
	document.addEventListener('focusin', touch, true);
	document.addEventListener('pointerdown', touch, true);
	document.addEventListener('visibilitychange', keep);
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', keep); else keep();
	setInterval(keep, 1000);
})();
