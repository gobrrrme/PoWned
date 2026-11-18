/*
 * PoWned miner core: double SHA-256 (as in Bitcoin) over "<challenge><nonce>", searching
 * for a hash with at least `bits` leading zero bits. Plain JavaScript on 32-bit integers.
 *
 * Loaded twice: as a Web Worker (one per CPU core), and as a normal script on the challenge
 * page as fallback for browsers without Worker support.
 */
(function (root) {
	'use strict';
	var K = new Int32Array([
		0x428a2f98, 0x71374491, -0x4a3f0431, -0x164a245b, 0x3956c25b, 0x59f111f1, -0x6dc07d5c, -0x54e3a12b,
		-0x27f85568, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, -0x7f214e02, -0x6423f959, -0x3e640e8c,
		-0x1b64963f, -0x1041b87a, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
		-0x67c1aeae, -0x57ce3993, -0x4ffcd838, -0x40a68039, -0x391ff40d, -0x2a586eb9, 0x06ca6351, 0x14292967,
		0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, -0x7e3d36d2, -0x6d8dd37b,
		-0x5d40175f, -0x57e599b5, -0x3db47490, -0x3893ae5d, -0x2e6d17e7, -0x2966f9dc, -0x0bf1ca7b, 0x106aa070,
		0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
		0x748f82ee, 0x78a5636f, -0x7b3787ec, -0x7338fdf8, -0x6f410006, -0x5baf9315, -0x41065c09, -0x398e870e
	]);
	var IV = [0x6a09e667, -0x4498517b, 0x3c6ef372, -0x5ab00ac6, 0x510e527f, -0x64fa9774, 0x1f83d9ab, 0x5be0cd19];
	var W = new Int32Array(64);

	/* One SHA-256 compression of a 16-word block, starting from the initial hash values. */
	function compress(block, out) {
		var a = IV[0], b = IV[1], c = IV[2], d = IV[3], e = IV[4], f = IV[5], g = IV[6], h = IV[7], i, t1, t2, x, y;
		for (i = 0; i < 16; i++) W[i] = block[i];
		for (i = 16; i < 64; i++) {
			x = W[i - 15]; y = W[i - 2];
			W[i] = ((((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3)) + W[i - 16] +
				(((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10)) + W[i - 7]) | 0;
		}
		for (i = 0; i < 64; i++) {
			t1 = (h + (((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7))) + ((e & f) ^ (~e & g)) + K[i] + W[i]) | 0;
			t2 = ((((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10))) + ((a & b) ^ (a & c) ^ (b & c))) | 0;
			h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
		}
		out[0] = (a + IV[0]) | 0; out[1] = (b + IV[1]) | 0; out[2] = (c + IV[2]) | 0; out[3] = (d + IV[3]) | 0;
		out[4] = (e + IV[4]) | 0; out[5] = (f + IV[5]) | 0; out[6] = (g + IV[6]) | 0; out[7] = (h + IV[7]) | 0;
	}

	function zeroBits(h) {
		for (var i = 0, n = 0; i < 8; i++) {
			if (h[i] === 0) { n += 32; continue; }
			return n + Math.clz32(h[i]);
		}
		return n;
	}

	function hex(h) {
		var s = '';
		for (var i = 0; i < 8; i++) s += ('0000000' + (h[i] >>> 0).toString(16)).slice(-8);
		return s;
	}

	/*
	 * Try `max` nonces: start, start+step, start+2*step, ... The ASCII input (challenge and
	 * decimal nonce, at most 55 bytes) fits one block, so each try costs two compressions.
	 */
	function search(prefix, bits, start, step, max) {
		var m1 = new Int32Array(16), m2 = new Int32Array(16), h1 = new Int32Array(8), h2 = new Int32Array(8), P = new Int32Array(16);
		var nonce = start, best = -1, bestHex = '', s, len, i, z, n, pl = prefix.length, pos;
		/* The challenge part of the block never changes: encode it once. */
		for (i = 0; i < pl; i++) P[i >> 2] |= prefix.charCodeAt(i) << (24 - (i & 3) * 8);
		m2[8] = 0x80000000 | 0; m2[15] = 256;
		for (n = 0; n < max; n++) {
			s = '' + nonce; len = pl + s.length;
			m1.set(P);
			for (i = 0; i < s.length; i++) { pos = pl + i; m1[pos >> 2] |= s.charCodeAt(i) << (24 - (pos & 3) * 8); }
			m1[len >> 2] |= 0x80 << (24 - (len & 3) * 8);
			m1[15] = len * 8;
			compress(m1, h1);
			for (i = 0; i < 8; i++) m2[i] = h1[i];
			compress(m2, h2);
			z = zeroBits(h2);
			if (z > best) { best = z; bestHex = hex(h2); }
			if (z >= bits) return { found: true, nonce: nonce, hash: hex(h2), tries: n + 1, best: bestHex };
			nonce += step;
		}
		return { found: false, next: nonce, tries: max, best: bestHex };
	}

	root.PownedMiner = { search: search };

	/* Inside a Web Worker: mine until found, report progress every 16384 tries. */
	if (typeof WorkerGlobalScope !== 'undefined' && root instanceof WorkerGlobalScope) {
		root.onmessage = function (ev) {
			var p = ev.data, nonce = p.start, total = 0, r;
			for (;;) {
				r = search(p.challenge, p.bits, nonce, p.step, 16384);
				total += r.tries;
				if (r.found) { root.postMessage({ done: true, nonce: r.nonce, hash: r.hash, count: total }); return; }
				nonce = r.next;
				root.postMessage({ done: false, nonce: nonce, count: total, best: r.best });
			}
		};
	}
})(self);
