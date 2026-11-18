=== PoWned ===
Contributors: gobrrr
Donate link: https://www.gobrrr.me/donate/
Tags: bots, crawler, proof of work, woocommerce, security
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bots get PoWned: Bitcoin-style proof of work against crawlers. Bots mine a block before they get your expensive pages. No CAPTCHAs, no third parties, no tracking.

== Description ==

Visitors of protected URLs first solve a small proof of work in their browser: a double SHA-256 hash below a target, like Bitcoin mining with a very low difficulty. Humans wait about a second once. Crawlers that rotate through thousands of IPs pay that for every request, because a solved block is bound to the visitor's network.

* Double SHA-256 proof of work with a signed, stateless challenge
* Difficulty adjusts to server load
* Bound to the network (/24, /64), the exact IP, or not at all
* Optional early check before other plugins load
* No external requests, works with a strict Content-Security-Policy
* Protected query parameters and paths with wildcards, exclusions
* Accent color, logo, texts; English and German built in

By default it protects WooCommerce's filter parameters. The admin area and the login are always excluded.

== Frequently Asked Questions ==

= Does it slow down normal visitors? =

Only on protected URLs, once per network and validity period (24 hours by default). With the default difficulty that is well under a second on current phones.

= What about search engines? =

Search engines should not crawl filter URLs anyway (block them in robots.txt). Pages that are not protected are not affected.

= Does it set cookies? =

One strictly necessary cookie, `powned`, with the solved block. It never contains the IP address. Sent with SameSite=Lax, Secure on HTTPS, host-only, valid 24 hours by default.

= Does it work behind Cloudflare or a reverse proxy? =

The gate reads PHP's REMOTE_ADDR. Behind a proxy every visitor shares that address, so the network binding collapses into one key and a single solved block unlocks the site for everyone. Configure the proxy to pass the client IP and make PHP trust it, or set "Bound to: Not bound". Page-cache plugins and CDNs also serve cached pages before the plugin runs - never cache the protected URLs.

= Does it protect the REST API / WooCommerce Store API? =

No. /wp-json/* is excluded by default, so product data stays reachable there without proof of work. The gate protects the HTML shop pages.

= How strong is the proof of work, really? =

18 bits are ~260,000 hashes: about a second in a browser, but milliseconds for a bot running native code. The gate exists to make bulk crawling of filter URLs annoying (every request pays, every network waits), not to stop a targeted attacker with a GPU. Raise base difficulty if your visitors can afford it; blocks stay valid once solved until they expire.

== Frequently Asked Questions ==

(see above)

== Changelog ==

= 0.1.0 =
* First version.
