# Security Policy

## Reporting a vulnerability

Please open a **private security advisory** (GitHub: Security → Advisories →
New draft advisory) instead of a public issue. Include the plugin version,
the affected URLs/settings, and a minimal reproduction. You will get a
response within a few days.

## Scope

- Anything that lets a bot or visitor skip the proof-of-work gate
  (challenge forgery, cookie acceptance outside the bound network,
  cache/reverse-proxy bypasses, REST/AJAX shortcuts).
- HMAC/secret handling, client-IP resolution, cookie flags.
- Anything in the challenge page that executes attacker-controlled content.

## Out of scope

- Server-side WordPress/WooCommerce vulnerabilities unrelated to this plugin.
- Reports that only show the difficulty is a speed bump for determined,
  native-code attackers (that is documented behavior; see README notes on
  effectiveness).
