## 2026-02-09 - Rate Limiter Key Collisions in Laravel
**Vulnerability:** Different named rate limiters (e.g. `guest`, `login`) were using the same key definition (e.g. `$request->ip()`).
**Learning:** Laravel's `RateLimiter::for` does not automatically prefix the cache key with the limiter name when `by()` is explicitly called with a value. This causes multiple limiters to share the same hit counter for the same IP, leading to premature blocking or shared quotas.
**Prevention:** Always namespace the keys returned by `by()` in `AppServiceProvider`. Example: `by('login:'.$request->ip())`.
## 2024-05-23 - SSRF in Email Configuration
**Vulnerability:** User-controlled input in `EmailAccount` configuration was used directly in `EsmtpTransport` and IMAP client, allowing connection to internal/private IPs (SSRF).
**Learning:** `empty($host)` allows "0" (string) to pass, which resolves to `0.0.0.0` (localhost), bypassing simplistic checks.
**Prevention:** Use strict checks (`$host === null || $host === ''`) and validate resolved IPs against private ranges using `filter_var`.
## 2026-05-21 - IPv4-Mapped IPv6 Bypass in PHP filter_var
**Vulnerability:** `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)` treats IPv4-mapped IPv6 addresses (e.g., `::ffff:127.0.0.1`) as valid, public IPv6 addresses, bypassing SSRF protection for localhost/private networks.
**Learning:** Standard PHP filters are insufficient for dual-stack SSRF protection. The mapping mechanism allows an attacker to disguise a private IPv4 address as a "public" IPv6 address.
**Prevention:** Use `Symfony\Component\HttpFoundation\IpUtils::checkIp()` with a comprehensive blocklist that explicitly includes `::ffff:0:0/96` alongside standard private ranges.
