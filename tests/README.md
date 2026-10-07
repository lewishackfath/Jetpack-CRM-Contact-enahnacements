# Integration tests

The updater contract suite is standalone, requires only PHP, and blocks all real network access through test doubles:

```sh
php tests/updater.php
```

It checks stable version discovery, compatibility metadata, repository/tag-specific ZIP assets, caching, unavailable/rate-limited GitHub responses, downgrade prevention, escaped release notes and cache refresh permissions. The GitHub release workflow runs this suite and PHP syntax checks before attaching assets.

Use a disposable WordPress installation on localhost with **Jetpack CRM 6.8.5**, MailPoet and this plugin activated. The suite creates real CRM contacts, course records, certificates, WordPress users and MailPoet lists. It leaves fixtures for inspection; never run against production.

In the test site's `wp-config.php`, set `JPCC_INTEGRATION_TESTS` to `true`. Disable WP-Cron, block external HTTP, and add a test-only must-use plugin returning `true` from `pre_wp_mail`. Use a dedicated database. WordPress user ID 1 must be an administrator with the CRM and MailPoet capabilities.

```sh
JPCC_TEST_WP_ROOT=/absolute/path/to/test-wordpress php tests/integration.php
```

The suite exercises real database migrations, the CRM DAL, MailPoet's public API, expiry boundaries, renewal filtering, upload validation, private certificate retrieval, permission checks, optimistic concurrency, transaction rollback, subscription exclusions and batch idempotency.

With that test site's HTTP server running at its configured `home_url`, also run:

```sh
JPCC_TEST_WP_ROOT=/absolute/path/to/test-wordpress php tests/http.php
```

This verifies certificate response bytes/headers, attachment downloads, anonymous access rejection, CSRF rejection, and a valid-nonce request from an unprivileged subscriber. It creates temporary login sessions and destroys them afterward.

For the GitHub updater's WordPress integration (PHP Zip extension required):

```sh
python3 scripts/build.py
JPCC_TEST_WP_ROOT=/absolute/path/to/test-wordpress php tests/update-integration.php
```

This extracts the built plugin into a temporary plugin directory, supplies simulated GitHub responses through WordPress's HTTP filter, and uses the real `wp_update_plugins()`, `plugins_api()` and background `Plugin_Upgrader` paths. It checks version discovery, compatibility metadata, release details, actual ZIP installation, activation, record/certificate preservation, automatic-update preferences, cache invalidation and up-to-date classification. It never upgrades the source checkout or downloads an actual remote package. The temporary plugin copy is removed afterward. The marked test database and WordPress upgrade working directories are still used, so the site must be disposable. This test does not publish a GitHub release or test GitHub-hosted Actions execution.

Also verify in a browser: the CRM contact tab, add/edit/upload form, certificate view/download, January expiry filter, record selection, batched audience progress, and MailPoet recipient selection. Test HTTP access while logged out and with a WordPress subscriber; a valid nonce must never grant access without the CRM capability.
