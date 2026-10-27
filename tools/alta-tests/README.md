# Isolated Alta fixtures

Place this directory at `tools/alta-tests` in the application repository. Run from the repository root:

```sh
php vendor/phpunit/phpunit/phpunit -c tools/alta-tests/phpunit.xml --do-not-cache-result
```

The suite builds its own application container and SQLite `:memory:` schema. It does not load the application bootstrap, `.env`, source configuration files, setup hooks, production database, or production queues. HTTP requests are mocked, stray requests are blocked, and queued imports are faked. Temporary exports and raster assets are written only beneath this test directory's ignored `storage` folder and removed after each case.

Coverage includes exact product identity, current and previous retail prices, descriptions and images, authoritative B2B quantities, unavailable-source preservation, stock-only synchronization, owner-only browser ingestion, private asset validation, queued submission replay, and completion status.

For a separate source checkout, set `IAPI_SOURCE_ROOT` to that checkout. If its dependencies are not installed, `ALTA_TEST_VENDOR` may point to an existing compatible vendor directory; the source checkout's own vendor directory always takes precedence. These variables are read only by this test bootstrap.

Validated with PHP 8.3.30, Laravel 13.37.0, and PHPUnit 12.5.12. No dependency installation or lock changes are part of the test runner.
