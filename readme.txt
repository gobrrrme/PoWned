=== PoWned ===
Contributors: gobrrr
Donate link: https://www.gobrrr.me/donate/
Tags: bots, crawler, proof of work, woocommerce, security
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bots get PoWned: Bitcoin-style proof of work against crawlers. Bots mine a block before they get your expensive pages. No CAPTCHAs, no third parties, no tracking.

== Description ==

Visitors of protected URLs first solve a small proof of work in their browser: a double SHA-256 hash below a target, like Bitcoin mining with a very low difficulty. Humans wait about a second once. Crawlers that rotate through thousands of IPs pay that for every request, because a solved block is bound to the visitor's network.

* Double SHA-256 proof of work with a signed, stateless challenge
* Difficulty adjusts to server load
* Bound to the network (/24, /64), the exact IP, or not at all
* Optional early check before other plugins load
* Protected actions: checkout and express payment buttons, coupon codes, login forms, Contact Form 7 and comments need a solved block, mined invisibly in the background
* Stealth mode: a small spinner instead of the mining page
* Full lockdown for emergencies: manual with automatic switch-off, or automatic under load; payment webhooks, APIs, feeds and verified search engines stay open
* No external requests, works with a strict Content-Security-Policy
* Protected query parameters and paths with wildcards, exclusions
* Accent color, logo, texts; English and German built in

By default it protects WooCommerce's filter parameters. The admin area and the login are always excluded.

== Frequently Asked Questions ==

= Does it slow down normal visitors? =

Only on protected URLs, once per network and validity period (24 hours by default). With the default difficulty that is well under a second on current phones.

= What about search engines? =

Search engines should not crawl filter URLs anyway (block them in robots.txt). Pages that are not protected are not affected. During a full lockdown, challenge pages are sent as 503 with Retry-After, and real search engine crawlers (verified by reverse DNS) can be let through.

= Will it break my payment provider? =

No list of gateways is needed. PoWned only checks actions a person performs in the browser. Webhooks and callbacks of any provider arrive through ?wc-api=, the REST API or Ajax and are never checked.

= Does it set cookies? =

One strictly necessary cookie, `powned`, with the solved block: HttpOnly, SameSite=Lax, Secure on HTTPS, valid 24 hours by default. Right after solving, the block sits briefly in `powned_new` until the server takes it over. Neither contains the IP address.

= Does it work behind Cloudflare or a reverse proxy? =

PoWned reads PHP's REMOTE_ADDR. Behind a proxy every visitor shares that address, so one solved block unlocks the site for everyone. Pass the real client address with the powned_client_ip filter, or set "Bound to" to "Not bound". The settings page warns when it detects a proxy. Page caches and CDNs serve cached pages before PoWned runs: never cache the protected URLs.

= Does it protect the REST API? =

The Store API checkout and coupon routes, when those protected actions are on. Everything else under /wp-json/ is excluded by default, so product data stays reachable there without proof of work.

= How strong is the proof of work, really? =

18 bits are about 260,000 hashes: around a second in a browser, but milliseconds for a bot running native code. PoWned makes bulk requests expensive (every request pays, every network mines), it does not stop a targeted attacker with a GPU. Raise the base difficulty if your visitors can afford it.

== Changelog ==

= 0.4.3 =
* Challenge page CSP: plugin assets served from another origin (CDN, static domain) and a logo from another origin over http are allowed automatically, so the challenge stays solvable there. New filter powned_csp. Thanks to mptfire for pointing it out.

= 0.4.2 =
Contributions by mptfire, thank you:
* "Go back" link on the challenge page: http(s) links to this site only.
* Strict Content-Security-Policy header on the challenge page.
* Early loader also works when the plugin is network-activated (multisite); it is rewritten automatically after updates.
* Warnings on the settings page when a page cache or a reverse proxy can defeat the gate, dismissible per user.
* With a persistent object cache, mined blocks are counted in the cache and written to the database once an hour: no database writes per block.
* SECURITY.md and more FAQ.

= 0.4.1 =
* Neutral file names for scripts and styles (pw-core.js, pw-ui.js, pw-bg.js, pw.css): ad blockers with cryptominer lists blocked miner.js, so visitors with them could not get past the challenge page.
* The solved block is now an HttpOnly cookie, taken over by the server from the freshly mined one.
* Filter powned_client_ip for sites behind their own reverse proxy.
* "Rotate secret" button.
* Security notes in the README.

= 0.4.0 =
* Background mining: on pages with protected actions the browser solves a block without a challenge page; forms wait until it is ready and are then sent on automatically.
* Protected actions: express payment buttons (fixes orders from the cart page with checkout protection on), coupon codes, login forms (without a block the password is not checked), Contact Form 7 and comments.
* A block that expires during checkout is renewed and the order placed again automatically.
* Stealth mode: the challenge page shows only a small spinner, after 0.6 seconds.
* Full lockdown: log only, manual with automatic switch-off, or automatic under load. 503 with Retry-After, verified search engine crawlers and listed user agents pass, webhooks and APIs are never touched. Admin notice while active, confirmation when switching on.
* Own item in the admin menu.

= 0.3.0 =
* Optional checkout protection: opening the checkout needs a solved block, and orders are only accepted with one, in the classic checkout and through the Store API (block checkout, including drafts and batch requests). Stops bots that place unpaid orders and hold stock. The thank-you page and payment webhooks are not affected.

= 0.2.0 =
* 30-day statistics on the settings page: blocks mined, average mining time, challenges shown, requests let through, solve rate, daily charts.
* The browser reports the mining time with the one-time block marker.
* Difficulty selector covers the full allowed range (8 to 28 bits).

= 0.1.0 =
* First version.
