## 2026-02-09 - Rate Limiter Key Collisions in Laravel
**Vulnerability:** Different named rate limiters (e.g. `guest`, `login`) were using the same key definition (e.g. `$request->ip()`).
**Learning:** Laravel's `RateLimiter::for` does not automatically prefix the cache key with the limiter name when `by()` is explicitly called with a value. This causes multiple limiters to share the same hit counter for the same IP, leading to premature blocking or shared quotas.
**Prevention:** Always namespace the keys returned by `by()` in `AppServiceProvider`. Example: `by('login:'.$request->ip())`.
## 2024-05-23 - SSRF in Email Configuration
**Vulnerability:** User-controlled input in `EmailAccount` configuration was used directly in `EsmtpTransport` and IMAP client, allowing connection to internal/private IPs (SSRF).
**Learning:** `empty($host)` allows "0" (string) to pass, which resolves to `0.0.0.0` (localhost), bypassing simplistic checks.
**Prevention:** Use strict checks (`$host === null || $host === ''`) and validate resolved IPs against private ranges using `filter_var`.

## 2026-02-24 - SSRF Bypass via IPv4-mapped IPv6 Addresses
**Vulnerability:** `SecureOpenGraph` used `IpUtils::checkIp` with a blocklist that missed `::ffff:0:0/96`. This allowed attackers to bypass IPv4 blocklists (e.g., `127.0.0.1`) by using the IPv6 mapped format (e.g., `::ffff:127.0.0.1`).
**Learning:** `Symfony\Component\HttpFoundation\IpUtils` does not automatically map IPv4-mapped IPv6 addresses to IPv4 when checking against IPv4 ranges. You must explicitly block the mapped range or convert the IP before checking.
**Prevention:** Always include `::ffff:0:0/96` in IP blocklists when handling dual-stack resolution, or normalize IPs to v4/v6 before validation.
