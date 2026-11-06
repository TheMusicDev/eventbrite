# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`themusicdev/eventbrite`: a framework-agnostic Eventbrite API v3 client library for PHP 8.2+. It exists because the official SDK has had no release since 2019. Runtime dependencies are PSR interfaces only. Guzzle's PSR-7 package is a dev dependency for tests, and `src/` must not use it.

## Commands

```bash
composer check                  # cs + stan + test, stops at first failure
composer cs                     # php-cs-fixer dry run
composer cs-fix                 # apply style fixes
composer stan                   # PHPStan level max + strict rules, src and tests
composer test                   # Pest
composer test-coverage          # Pest with coverage, needs pcov or xdebug

vendor/bin/pest tests/EventbriteOAuthTest.php       # one file
vendor/bin/pest --filter='follows pagination'       # one test, by description
```

CI (`.github/workflows/ci.yml`) differs from `composer check` in three ways:

- It runs on PHP 8.2, 8.3 and 8.4, so code must stay 8.2-compatible even if the local PHP is newer.
- It runs `composer update`, not `install`, so it resolves different dependencies per PHP version than the local lock file.
- On 8.4 it also runs `composer-require-checker` from a downloaded PHAR, which fails if `src/` uses a symbol from a package not declared in `require`.

## Architecture

Everything is in three classes in `src/`. `EventbriteOAuth` is independent of `EventbriteClient`: it builds its own requests, has no retry, and shares only `EventbriteApiException`.

### Client request path

Public methods go through `get()`, `getUncached()`, `post()`, `fetchUrl()` or `getRaw()`, then:

1. `sendWithRetry()` adds bearer auth and retries 429s with exponential backoff. It never throws on status.
2. `decode()` throws `EventbriteApiException` on non-2xx and otherwise returns the JSON as an array.

Status errors are raised only in `decode()`, so `getRaw()`, which skips it, returns non-2xx responses rather than throwing.

### Rules that span methods

- **Caching:** only `get()` reads or writes the cache, which includes every page of `paginate()`. Keys hash the token, URL and params. Writes do not invalidate anything, so data that must be fresh has to use `getUncached()` or `fetchUrl()`. The webhooks listing is deliberately never cached because it is the source of truth for what is enabled, and `fetchUrl()` exists so webhook deliveries never read a pre-change snapshot.
- **URL guard:** `assertApiUrl()` refuses anything outside `BASE_URL`. New methods that send a caller-influenced URL or path must call it.

### Eventbrite API constraints

- The public event search endpoint was removed upstream. Do not add one.
- The webhook create body (`endpoint_url` at root level, `actions` as a comma-separated string) was verified live and is not detailed in the reference docs. Responses can return `actions` as a string or an array, so read it through `normalizeActions()`.
- `event_id` on webhook create limits the hook to one event, so `ensureWebhooks()` ignores hooks with a non-empty `event_id`. Verified live on 2026-11-06: the listing returns the event id on scoped hooks and `null` on org-wide ones. Not tested: whether deliveries are actually limited to that event.
- The docs say a user with no organizations can use their user id as the organization id, but organization endpoints returned 404 for it on such an account (2026-11-05), and that user id differed from the organization id once one was created in the dashboard. The API has no create-organization endpoint.
- The `EventbriteClient` class docblock lists endpoints verified against the API docs, with a date. Add new endpoints there.

## Testing

Tests use no mock library. `tests/Support/FakeHttp.php` is one object acting as the PSR-18 client and the PSR-17 factories, and it records requests in `$http->requests`.

- **`FakeHttp` matching:** it matches on method plus URL prefix, and the first registered prefix wins, so register specific URLs before general ones. Responses queued on a key rotate instead of running out, so a key with one response repeats it forever. An unmatched request throws `RuntimeException`.
- **`newClient()` defaults:** the helper in `tests/Support/helpers.php` defaults to `retries: 0` and `cacheTtl: 0`, unlike the production defaults of 3 and 600. Retry and cache tests must opt in.
- **Real sleeps:** backoff calls `sleep()` directly, so a test with `retries: 1` or more takes real time.
- **Helper loading:** helpers are loaded by `require_once` from `tests/bootstrap.php`. Pest's root `helpers.php` auto-include loaded locally but not in CI, so do not move them back.
- **Packaging:** `tests/` is intentionally not `export-ignore`d because `FakeHttp` is the documented test kit.

## Static analysis

The `ignoreErrors` entries in `phpstan.neon` are deliberate and explained in that file's header (the JSON boundary in `src`, Pest and PSR-16 typing in `tests`). Fix new errors rather than widening them.
