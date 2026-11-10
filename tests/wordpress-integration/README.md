# Mailchimp WordPress integration tests

From the repository root:

```sh
bash clf-alumni/tests/run-wordpress-integration.sh
```

Requirements: Bash, curl, tar, PHP 8.0+ with mysqli, curl and OpenSSL,
`proc_open` enabled, and MariaDB (`mariadb-install-db`, `mariadbd`,
`mariadb`, `mariadb-admin`). The Replit Nix configuration includes PHP and
MariaDB. No Composer, PHPUnit, Docker, existing WordPress site, credentials,
or external database is required. The WordPress download needs network access.

The runner downloads pinned WordPress 6.8.3, creates a temporary database on a
private Unix socket with networking disabled, installs WordPress, and loads the
actual CLF Alumni plugin. Everything is removed on exit, including on failure.
It never accepts an existing WordPress installation or database. Do not run the
PHP files directly or against a real site.

All fixtures are synthetic (`example.test` addresses). Mailchimp's HTTP API is
mocked through WordPress's `pre_http_request` filter, which rejects unexpected
URLs/methods. Unmocked outbound HTTP is blocked and email is suppressed. The
dummy API key is deliberately not a usable credential.

Coverage:

- Real `wp_update_user()` email changes revoke consent, preserve the old synced
  identity for retirement, and retry failed retirement without enrolling the new
  address. Ordered HTTP expectations reject requests to unexpected identities.
- Real added/updated/deleted user-meta hooks queue changed names, class years and
  leadership only with consent; unrelated metadata does not queue a sync.
- Profile email changes and checkbox opt-outs interleaved at the mocked HTTP
  boundary preserve newer queued revisions when an older worker succeeds or
  fails. The next worker retires the old subscription without restoring consent.
  These deterministic interleavings run in one process with real WordPress hooks.
- Anonymous REST routing and permission callbacks: missing, incorrect,
  array-valued, body-only, rotated and deleted webhook keys.
- GET URL validation, including when syncing is paused and unconfigured:
  no member changes, API requests, locks or health updates.
- Nested form bodies, encoded `+` email addresses, malformed data and wrong
  audiences; successful POST updates consent and delivery health while preserving
  queued work.
- Mocked Mailchimp 401 (revoked API key), 429 and 500 responses: retryable REST
  errors, unchanged member data, failure counts, released locks and recovery.
- Independent PHP worker/database connections: webhook retries while a worker
  holds the lock, worker cannot sync while webhook holds it, retry after release,
  and webhook refusal to steal an abandoned lock.
- Real loopback HTTP GET/POST requests through `rest_api_loaded()` /
  `WP_REST_Server::serve_request()`, checking JSON responses and persisted effects.
  The server uses an ephemeral port and is stopped at the end.

The larger scenario suite uses `rest_do_request()` and mirrors PHP's `$_POST`
population for CLI requests. The separate wire cases exercise **actual PHP form
parsing**, not a request double. No production handler or WordPress function is
replaced. The original fast test-double suite remains independently runnable:

```sh
php clf-alumni/tests/mailchimp-webhook-test.php
```

Any failed assertion exits nonzero. These tests do not verify external hosting
rewrites, TLS, WAF rules or Mailchimp service availability.
