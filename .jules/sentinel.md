## 2026-02-09 - Rate Limiter Key Collisions in Laravel
**Vulnerability:** Different named rate limiters (e.g. `guest`, `login`) were using the same key definition (e.g. `$request->ip()`).
**Learning:** Laravel's `RateLimiter::for` does not automatically prefix the cache key with the limiter name when `by()` is explicitly called with a value. This causes multiple limiters to share the same hit counter for the same IP, leading to premature blocking or shared quotas.
**Prevention:** Always namespace the keys returned by `by()` in `AppServiceProvider`. Example: `by('login:'.$request->ip())`.
## 2024-05-23 - SSRF in Email Configuration
**Vulnerability:** User-controlled input in `EmailAccount` configuration was used directly in `EsmtpTransport` and IMAP client, allowing connection to internal/private IPs (SSRF).
**Learning:** `empty($host)` allows "0" (string) to pass, which resolves to `0.0.0.0` (localhost), bypassing simplistic checks.
**Prevention:** Use strict checks (`$host === null || $host === ''`) and validate resolved IPs against private ranges using `filter_var`.

## 2026-10-24 - SSRF DNS Rebinding in SecureOpenGraph
**Vulnerability:** The `SecureOpenGraph` service validated resolved IPs but then connected to the original hostname without pinning the resolution, allowing a TOCTOU (Time-of-Check Time-of-Use) DNS rebinding attack.
**Learning:** Checking an IP and then connecting to a hostname is insufficient if the attacker controls DNS. They can return a safe IP for the check and a private IP for the connection.
**Prevention:** Use `CURLOPT_RESOLVE` to force cURL to use the exact IP address that was validated, regardless of subsequent DNS resolution. Also, always use `IpUtils::checkIp` (or similar robust library) instead of manual CIDR checks to correctly handle IPv6 and edge cases.
