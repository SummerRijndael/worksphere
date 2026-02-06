## 2026-02-05 - Production Misconfiguration Defense
**Vulnerability:** `DevController` routes relying solely on `APP_ENV=local` could expose dangerous endpoints (like `loginAs`) if production environment is misconfigured.
**Learning:** `app()->environment()` is trusting a user-supplied string. Checking for the presence of dev-only dependencies (like `Faker`) provides a stronger, code-based verification of "non-production" status.
**Prevention:** Use `class_exists(\Faker\Factory::class)` or similar checks in Gates to lock down dev tools, ensuring they are physically impossible to run in a `--no-dev` production build.
