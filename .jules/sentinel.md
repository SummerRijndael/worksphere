# Sentinel's Journal

## 2025-10-24 - [CRITICAL] Dev Routes Exposure Risk
**Vulnerability:** Dangerous development routes (e.g., `loginAs`) were protected solely by checking `app()->environment('local')` at route registration time. This is vulnerable to **Route Caching** (if local routes are cached and deployed to production) or accidental removal of the conditional block.
**Learning:** Configuration-based checks at route registration level are insufficient. Runtime authorization checks are required to ensure security logic persists even if routes are cached.
**Prevention:** Always wrap sensitive development-only logic in Authorization Gates or Policies (`can` middleware) that enforce strict runtime checks.
