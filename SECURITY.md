# Security Policy

## Reporting a vulnerability

Please open a **private security advisory** (Security → Advisories → Report a vulnerability) instead of a public issue. Include the plugin version, the affected URLs and settings, and a minimal reproduction. You will get an answer within a few days.

## Scope

- Anything that lets a bot or visitor skip the proof of work (forged challenges or blocks, blocks accepted outside the bound network, bypasses through caches, proxies, REST or Ajax).
- Secret handling, client address resolution, cookie flags.
- Anything on the challenge page that runs content an attacker controls.
- Protected actions (checkout, coupons, login, forms) and the lockdown letting requests through that they should stop, or blocking payment webhooks.

## Out of scope

- Vulnerabilities in WordPress, WooCommerce or other plugins that are unrelated to PoWned.
- Reports that only show that proof of work is a speed bump for a determined attacker running native code or a GPU. That is the documented design: PoWned makes bulk requests expensive, it does not authenticate anyone.
