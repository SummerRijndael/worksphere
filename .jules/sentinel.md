## 2026-02-09 - Rate Limiter Key Collisions in Laravel
**Vulnerability:** Different named rate limiters (e.g. `guest`, `login`) were using the same key definition (e.g. `$request->ip()`).
**Learning:** Laravel's `RateLimiter::for` does not automatically prefix the cache key with the limiter name when `by()` is explicitly called with a value. This causes multiple limiters to share the same hit counter for the same IP, leading to premature blocking or shared quotas.
**Prevention:** Always namespace the keys returned by `by()` in `AppServiceProvider`. Example: `by('login:'.$request->ip())`.
## 2024-05-23 - SSRF in Email Configuration
**Vulnerability:** User-controlled input in `EmailAccount` configuration was used directly in `EsmtpTransport` and IMAP client, allowing connection to internal/private IPs (SSRF).
**Learning:** `empty($host)` allows "0" (string) to pass, which resolves to `0.0.0.0` (localhost), bypassing simplistic checks.
**Prevention:** Use strict checks (`$host === null || $host === ''`) and validate resolved IPs against private ranges using `filter_var`.

## 2026-02-24 - [SSRF] IPv4-mapped IPv6 Bypass
**Vulnerability:** The `SecureOpenGraph` service was vulnerable to SSRF via IPv4-mapped IPv6 addresses (e.g., `::ffff:127.0.0.1`), which bypassed the IPv4-only blocklist.
**Learning:** Standard PHP/Symfony IP validation libraries might treat mapped addresses as valid IPv6 and not check them against IPv4 blocklists unless explicitly handled or the mapped range `::ffff:0:0/96` is blocked.
**Prevention:** Always block `::ffff:0:0/96` when implementing IP blocklists if the application handles IPv6, or normalize IPs to their canonical form before checking.
