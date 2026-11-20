# Bots get PoWned

**PoWned** is a WordPress plugin that makes crawlers pay for what they take. Before a bot gets your expensive pages, it has to mine a block: double SHA-256 below a target, exactly like Bitcoin, just with a difficulty a phone solves in a blink. Humans wait about a second, once. Bots that hammer you from 10,000 rotating IPs pay that on every single request.

GG WP

![Challenge page while mining](screenshots/challenge-dark.png)

## Why this exists

Our WooCommerce shop got farmed by a residential-proxy crawler. Tens of thousands of filter combinations (`?filter_pa_color=…&filter_pa_size=…`), about 10,000 IPs per half hour, one request each. Pages went from 0.3 to 25 seconds.

What did not work:

- **IP bans and rate limits.** Every IP shows up once. Nothing to ban.
- **User-agent filters.** It claims to be Chrome. Sure it is.
- **Hiding the links behind JavaScript.** It read our JavaScript and came back.

What works: make every request cost CPU time. Proof of work. The thing Bitcoin has been doing since 2009.

## Loadout

- **Real proof of work.** `SHA256(SHA256(challenge + nonce))` with a required number of leading zero bits. Same principle as a Bitcoin miner, same algorithm.
- **Difficulty adjustment.** Server busy? Difficulty goes up automatically: one extra bit at a set load per CPU core, one more each time the load doubles. Blocks already solved stay valid.
- **Bound to the network.** A solved block works for one /24 (IPv4) or /64 (IPv6) for 24 hours. Rotate your IP, mine again. Exact IP or no binding also available.
- **Stateless.** The challenge is signed (HMAC). The server stores nothing per visitor. The cookie holds challenge, nonce and signature plus a keyed hash of the network, never the IP.
- **Cheap to serve.** Optional early loader in `mu-plugins` checks before other plugins even load. The challenge page is a few kilobytes and answers in about 40 ms.
- **Multi-core mining.** One Web Worker per CPU core (up to 4), with a fallback for browsers without workers.
- **No third parties.** No CAPTCHAs, no external scripts, no fonts from elsewhere, no tracking. Runs under a strict Content-Security-Policy (no inline scripts or styles; needs `worker-src 'self'`).
- **Block counter.** The settings page shows how many blocks have been mined on your site. Every solved challenge counts once, and the visitor sees which block they are mining: *Block #1,337*.
- **Checkout protection (optional).** Bots placing unpaid orders through the Store API or the checkout form, holding your stock for an hour each? Switch it on and opening the checkout needs a solved block (most customers already have one from browsing), and orders without one get turned away: classic checkout and Store API, drafts and batch requests included. The thank-you page and payment webhooks stay untouched.
- **30-day stats.** Blocks mined, average mining time, challenges shown, requests let through and the solve rate, with a small chart per day. Only daily totals are stored, nothing per visitor. Challenge counts ride on your object cache (Redis, Memcached) and are rolled up hourly, so a bot flood never turns into database writes.
- **Your look.** Accent color, logo (defaults to your site icon), own texts, light and dark mode. English and German built in, language by path prefix for multilingual sites.

## Installation

1. Download this repository as ZIP (*Code → Download ZIP*) or grab a release.
2. In WordPress: *Plugins → Add New → Upload Plugin*, pick the ZIP, activate.
3. Open *Settings → PoWned*. Check the protected parameters, set your accent color, hit *Preview the challenge page*.

The defaults protect WooCommerce filters out of the box (`filter_*`, `query_type_*`, `min_price`, `max_price`, `rating_filter`, `instock`, `on_sale`). The admin area and the login are always excluded, so you cannot lock yourself out.

Keep search engines away from filter URLs in your robots.txt (`Disallow: /*?*filter_`). They would only see the challenge page there anyway.

## Screenshots

| Mining (dark) | Mining (light, mobile) |
|---|---|
| ![Dark](screenshots/challenge-dark.png) | ![Light mobile](screenshots/challenge-light-mobile.png) |

Settings: [screenshots/settings.png](screenshots/settings.png)

## How it works

1. A request hits a protected parameter or path without a valid `powned` cookie.
2. The server sends the mining page with a signed challenge: `1.<expiry>.<client>.<bits>.<salt>.`
3. The browser grinds nonces until `SHA256(SHA256(challenge + nonce))` starts with enough zero bits, stores `challenge + nonce + "." + signature` in the cookie and reloads.
4. The server checks signature, expiry, network and the hash. Two SHA-256 runs and one HMAC, done.

Tuning: 18 bits is about 260,000 hashes on average, well under a second on current phones. Each extra bit doubles the work. The settings page shows the expected time for every level.

## Requirements

WordPress 6.2+, PHP 7.4+. The load-based difficulty reads `/proc/cpuinfo` and `sys_getloadavg()` (Linux). Without them, the base difficulty applies.

## Privacy

The cookie is strictly necessary for the security of the site and contains no personal data in clear text. Put a line in your privacy policy, for example:

> When you use the shop filters, your browser solves a small computing task and stores the result for 24 hours in the cookie `powned` (plus, for a few seconds, the marker `powned_new`, which counts the solved block once). It protects the site against automated mass requests and contains no personal data.

## Roadmap

- **PoW for forms.** Contact and comment forms mine a block in the background on submit, no puzzle for humans. Every spam message costs the bot CPU time.

## License

GPL-2.0-or-later. Fork it, run it, PoWn some bots.

---

Built by [GoBrrr](https://www.gobrrr.me), your Bitcoin hardware shop from Austria. If PoWned saved your server, [buy us a coffee in sats](https://www.gobrrr.me/donate/).

> **Heads-up:** PoWned was vibecoded and has not seen a lot of real-world testing yet. Please [report any issues](https://github.com/gobrrrme/PoWned/issues) you run into, and feel free to contribute. Pull requests welcome.
