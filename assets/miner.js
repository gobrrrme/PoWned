/*
 * PoWned challenge page: start one miner per CPU core (at most 4), fill the block as mining
 * progresses, store the solved block in a cookie and reload the requested page.
 */
(function () {
	'use strict';
	var el = document.getElementById('powned');
	if (!el || !window.PownedMiner) return;
	var C = el.dataset, bits = parseInt(C.bits, 10), expected = Math.pow(2, bits);
	var $ = function (id) { return document.getElementById(id); };
	var cores = Math.max(1, Math.min(4, navigator.hardwareConcurrency || 2));
	var workers = [], counts = [], best = '', started = Date.now(), finished = false;
	var tiles = Array.prototype.slice.call($('pw-block').querySelectorAll('i')), lit = 0;

	if (/^#[0-9a-f]{3,8}$/i.test(C.brand || '')) document.documentElement.style.setProperty('--pw-brand', C.brand);

	/* Tiles light up in a fixed shuffled order, so the block fills like a mosaic. */
	var order = tiles.map(function (t, i) { return i; });
	for (var s = 7, i = order.length - 1; i > 0; i--) { s = (s * 9301 + 49297) % 233280; var j = Math.floor(s / 233280 * (i + 1)); var tmp = order[i]; order[i] = order[j]; order[j] = tmp; }

	function fill(share) {
		var want = Math.min(tiles.length - 1, Math.floor(share * tiles.length));
		while (lit < want) { tiles[order[lit]].classList.remove('next'); tiles[order[lit]].classList.add('on'); lit++; }
		tiles.forEach(function (t) { t.classList.remove('next'); });
		if (lit < tiles.length) tiles[order[lit]].classList.add('next');
	}
	function mark(h) {
		var z = h.match(/^0*/)[0].length;
		return '<b>' + h.slice(0, z) + '</b>' + h.slice(z);
	}
	function total() { return counts.reduce(function (a, b) { return a + b; }, 0); }
	function progress(nonce) {
		var n = total(), secs = (Date.now() - started) / 1000;
		$('pw-nonce').textContent = nonce.toLocaleString();
		if (secs > 0.05) $('pw-rate').textContent = Math.round(n / secs / 1000).toLocaleString() + ' kH/s';
		if (best) $('pw-hash').innerHTML = mark(best);
		/* Expected share of the work done; mining is random, so this is an estimate. */
		fill(Math.min(0.95, 1 - Math.exp(-n / expected)));
	}
	function fail(msg) {
		var s = $('pw-status');
		s.textContent = msg; s.className = 'pw-status err';
	}
	function solved(nonce, hash) {
		if (finished) return;
		finished = true;
		workers.forEach(function (w) { w.terminate(); });
		best = hash; progress(nonce);
		tiles.forEach(function (t) { t.classList.remove('next'); t.classList.add('on'); });
		$('pw-block').classList.add('found');
		$('pw-status').textContent = C.found;
		if (C.preview === '1') return;
		var attrs = '; Path=/; SameSite=Lax' + (C.secure === '1' ? '; Secure' : '');
		document.cookie = C.cookie + '=' + C.challenge + nonce + '.' + C.mac + '; Max-Age=' + C.ttl + attrs;
		/* One-time marker: the server counts this block once and deletes the marker. */
		document.cookie = C.cookie + '_new=1; Max-Age=300' + attrs;
		/* Cookies blocked: stop instead of mining again on every reload. */
		if (document.cookie.indexOf(C.cookie + '=') === -1) { fail(C.nocookie); return; }
		/* Loop guard: three solved blocks within 30 seconds mean the cookie does not stick. */
		var tries = 1;
		try {
			var prev = JSON.parse(sessionStorage.getItem('powned_reloads') || '{}');
			if (prev.t && Date.now() - prev.t < 30000) tries = (prev.n || 0) + 1;
			sessionStorage.setItem('powned_reloads', JSON.stringify({ n: tries, t: Date.now() }));
		} catch (e) {}
		if (tries > 3) { fail(C.nocookie); return; }
		setTimeout(function () { location.replace(location.href); }, 550);
	}
	function onMessage(i) {
		return function (ev) {
			var d = ev.data;
			counts[i] = d.count;
			if (d.done) { solved(d.nonce, d.hash); return; }
			if (d.best && (!best || d.best < best)) best = d.best;
			progress(d.nonce);
		};
	}

	fill(0);
	for (var k = 0; k < cores; k++) {
		try {
			var w = new Worker(C.worker);
			counts[k] = 0;
			w.onmessage = onMessage(k);
			w.postMessage({ challenge: C.challenge, bits: bits, start: k, step: cores });
			workers.push(w);
		} catch (e) { break; }
	}

	/* No Web Workers: mine on the page in short slices so it stays responsive. */
	if (!workers.length) {
		counts[0] = 0;
		var nonce = 0;
		(function slice() {
			var r = window.PownedMiner.search(C.challenge, bits, nonce, 1, 4096);
			counts[0] += r.tries;
			if (r.found) { solved(r.nonce, r.hash); return; }
			if (!best || r.best < best) best = r.best;
			nonce = r.next; progress(nonce);
			setTimeout(slice, 0);
		})();
	}
})();
