## 2026-02-09 - Rate Limiter Key Collisions in Laravel
**Vulnerability:** Different named rate limiters (e.g. `guest`, `login`) were using the same key definition (e.g. `$request->ip()`).
**Learning:** Laravel's `RateLimiter::for` does not automatically prefix the cache key with the limiter name when `by()` is explicitly called with a value. This causes multiple limiters to share the same hit counter for the same IP, leading to premature blocking or shared quotas.
**Prevention:** Always namespace the keys returned by `by()` in `AppServiceProvider`. Example: `by('login:'.$request->ip())`.
## 2024-05-23 - SSRF in Email Configuration
**Vulnerability:** User-controlled input in `EmailAccount` configuration was used directly in `EsmtpTransport` and IMAP client, allowing connection to internal/private IPs (SSRF).
**Learning:** `empty($host)` allows "0" (string) to pass, which resolves to `0.0.0.0` (localhost), bypassing simplistic checks.
**Prevention:** Use strict checks (`$host === null || $host === ''`) and validate resolved IPs against private ranges using `filter_var`.

## 2026-02-10 - SSRF Bypass via IPv4-mapped IPv6
**Vulnerability:** `SecureOpenGraph` service explicitly listed blocked private IP ranges but missed the IPv4-mapped IPv6 range `::ffff:0:0/96`, allowing bypass of SSRF protections using addresses like `::ffff:127.0.0.1`.
**Learning:** When validating IP addresses in a dual-stack environment, always include IPv4-mapped IPv6 ranges in blocklists, as they can resolve to local IPv4 addresses.
**Prevention:** Add `::ffff:0:0/96` to all IP blocklists or use a library that handles this normalization automatically.
