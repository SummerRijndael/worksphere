## 2026-02-09 - Rate Limiter Key Collisions in Laravel
**Vulnerability:** Different named rate limiters (e.g. `guest`, `login`) were using the same key definition (e.g. `$request->ip()`).
**Learning:** Laravel's `RateLimiter::for` does not automatically prefix the cache key with the limiter name when `by()` is explicitly called with a value. This causes multiple limiters to share the same hit counter for the same IP, leading to premature blocking or shared quotas.
**Prevention:** Always namespace the keys returned by `by()` in `AppServiceProvider`. Example: `by('login:'.$request->ip())`.
## 2024-05-23 - SSRF in Email Configuration
**Vulnerability:** User-controlled input in `EmailAccount` configuration was used directly in `EsmtpTransport` and IMAP client, allowing connection to internal/private IPs (SSRF).
**Learning:** `empty($host)` allows "0" (string) to pass, which resolves to `0.0.0.0` (localhost), bypassing simplistic checks.
**Prevention:** Use strict checks (`$host === null || $host === ''`) and validate resolved IPs against private ranges using `filter_var`.
## 2025-10-27 - SSRF Bypass via IPv4-mapped IPv6
**Vulnerability:** Detected that `filter_var` with `FILTER_FLAG_NO_PRIV_RANGE` fails to block IPv4-mapped IPv6 addresses like `::ffff:127.0.0.1`, allowing bypass of private IP restrictions.
**Learning:** Standard PHP IP validation functions treat IPv4-mapped IPv6 addresses as valid public IPv6 addresses unless explicitly checked.
**Prevention:** Always check for `::ffff:0:0/96` range when validating IPs or use a robust library like `spatie/network` if available. In pure PHP, check binary prefix or string prefix and extract/validate the IPv4 part.
