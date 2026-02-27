## 2026-02-09 - Rate Limiter Key Collisions in Laravel
**Vulnerability:** Different named rate limiters (e.g. `guest`, `login`) were using the same key definition (e.g. `$request->ip()`).
**Learning:** Laravel's `RateLimiter::for` does not automatically prefix the cache key with the limiter name when `by()` is explicitly called with a value. This causes multiple limiters to share the same hit counter for the same IP, leading to premature blocking or shared quotas.
**Prevention:** Always namespace the keys returned by `by()` in `AppServiceProvider`. Example: `by('login:'.$request->ip())`.
## 2024-05-23 - SSRF in Email Configuration
**Vulnerability:** User-controlled input in `EmailAccount` configuration was used directly in `EsmtpTransport` and IMAP client, allowing connection to internal/private IPs (SSRF).
**Learning:** `empty($host)` allows "0" (string) to pass, which resolves to `0.0.0.0` (localhost), bypassing simplistic checks.
**Prevention:** Use strict checks (`$host === null || $host === ''`) and validate resolved IPs against private ranges using `filter_var`.
## 2024-05-24 - IPv4-mapped IPv6 SSRF Bypass
**Vulnerability:** IPv4-mapped IPv6 addresses (e.g. `::ffff:127.0.0.1` or `::ffff:0:0/96`) and NAT64 addresses (`64:ff9b::/96`) bypass PHP's `filter_var(..., FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)` checks, allowing SSRF to localhost or private IPs.
**Learning:** `filter_var` does not correctly handle the private/reserved status of IPv4 addresses embedded within IPv6 representations. It treats them as public IPv6 addresses.
**Prevention:** Explicitly block `::ffff:0:0/96` and `64:ff9b::/96` using `\Symfony\Component\HttpFoundation\IpUtils::checkIp` alongside `filter_var` when validating IPs for SSRF protection.
