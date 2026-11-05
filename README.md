# themusicdev/eventbrite

> Eventbrite API v3 client — PSR-18 HTTP, PSR-16 cache, framework agnostic MIT code.

A small client for the [Eventbrite API v3](https://www.eventbrite.com/platform/api):
read events, attendees, orders and ticket classes, manage webhooks, and run the
OAuth authorization-code flow — with no framework lock-in.

- **PSR-18** HTTP client in (Guzzle, Symfony HTTP Client, WordPress-anything)
- **PSR-16** cache optional — GET responses cached per token (10 min default)
- **PSR-3** logger optional — warnings on 429 backoff
- **Continuation pagination** exposed as generators
- **Typed exceptions** carrying status code + Eventbrite's error body
- **429 retries** with exponential backoff (1s, 2s, 4s, then throws)
- **Webhook helpers**: list, create, ensure (idempotent reconcile), delete

## Install

```bash
composer require themusicdev/eventbrite
```

Requirements: PHP 8.2+, a PSR-18 client and PSR-17 factories. With Guzzle:

```bash
composer require guzzlehttp/guzzle
```

## Getting an API key

1. Create/Sign in to an Eventbrite account → [account settings → Developer Links](https://www.eventbrite.com/account-settings/apps) → **Developer Portal** → **Get a Free API Key**.
2. That page shows:
   - **API key** — the OAuth `client_id`
   - **Client secret** — for OAuth server-side code exchange
   - **Private token** — a personal bearer token for your own account (handy to start)
3. Full walkthrough: [docs/getting-an-api-key.md](docs/getting-an-api-key.md).

## Quickstart — private token

```php
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use TheMusicDev\Eventbrite\EventbriteClient;

$httpFactory = new HttpFactory(); // PSR-17 request + stream factory

$client = new EventbriteClient(
    new HttpClient(),
    $httpFactory,
    $httpFactory,
    $_ENV['EVENTBRITE_TOKEN'], // never hard-code; see docs/getting-an-api-key.md
);

$me              = $client->me();                          // GET /users/me/
$organizations   = $client->organizations();               // GET /users/me/organizations/
$orgId           = $organizations['organizations'][0]['id'] ?? $me['id'];
$allEvents       = $client->organizationEvents($orgId);    // Generator over pages
foreach ($allEvents as $event) {
    // ...
}
```

### Caching

Pass any PSR-16 cache; GET responses cache **per token** for 600s (configurable
`$cacheTtl`, `0` disables). The webhooks listing is **never cached** (it is the
enabled-state source of truth). With symfony/cache:

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Adapter;

$cache = new Psr16Adapter(new FilesystemAdapter('eventbrite', 600));

$client = new EventbriteClient(new HttpClient(), $httpFactory, $httpFactory,
    $token, $cache);
```

## Query the API

Every response method returns decoded JSON arrays; non-2xx throws
`EventbriteApiException` (`getStatusCode()`, `getErrorBody()`).

```php
try {
    $client->event('123456');
} catch (EventbriteApiException $e) {
    // $e->getStatusCode(); $e->getErrorBody();
}

// one page: GET /organizations/{id}/events/ with any query params
$page = $client->get('organizations/' . $orgId . '/events/', ['time_filter' => 'all']);

// paginated generators (continuation-based)
$attendees = $client->eventAttendees('123456');
$orders    = $client->eventOrders('123456');
foreach ($attendees as $attendee) { /* attendee['profile']['email'], ['affiliate'] ... */ }
```

## Webhooks

```php
// list (never cached)
$hooks = $client->webhooks($orgId)['webhooks'];

// ensure an endpoint has one webhook per action — idempotent, run freely
$client->ensureWebhooks($orgId, 'https://example.com/webhook', [
    'attendee.updated', 'barcode.checked_in', 'order.placed', 'event.published',
]);

// remove the ones pointing at an endpoint
$client->disableWebhooks($orgId, 'https://example.com/webhook');
```

Deliveries POST `{"api_url": ".../v3/orders/123/"}` to your endpoint — fetch the
pointed object with `fetchUrl()` (bypasses the cache, so you never store a
pre-change snapshot) and upsert. Validate the URL: Eventbrite webhooks carry no
signature, so your receiver must refuse anything outside
`https://www.eventbriteapi.com/v3/`. Never return 410 — it auto-unsubscribes
your endpoint.

## OAuth (authorization code flow)

```php
use TheMusicDev\Eventbrite\EventbriteOAuth;

$oauth = new EventbriteOAuth($httpClient, $httpFactory, $httpFactory,
    $clientId, $clientSecret,         // API key page
    'https://example.com/oauth/eventbrite/callback', // registered redirect URI
    $appSecret,                       // any stable app secret, signs the state
);

// 1. redirect the browser to:
$oauth->authorizeUrl($oauth->newState());

// 2. on the callback (?code=...&state=...):
if ($oauth->verifyState($state)) {
    $tokenResponse = $oauth->exchangeCode($code); // ['access_token' => ...]
    // store $tokenResponse['access_token'] and pass it to the client
}
```

The state is a signed nonce-HMAC — no session storage needed anywhere.

## Testing your integration

`Tests\Support\FakeHttp` ships in the dev autoload (`TheMusicDev\Eventbrite\Tests\Support`)
or is trivial to copy: it is a recording, rotating PSR-18 client + PSR-17
factories in one anonymous-class-free object. See `tests/` for usage.

## Docs

- [Getting an API key](docs/getting-an-api-key.md)
- [API v3 reference](https://www.eventbrite.com/platform/api) — the parameter lists this client's methods were verified against

## Quality

- [x] PHPStan (max + strict-rules), PHP-CS-Fixer (PSR-12), Pest tests + coverage on CI (PHP 8.2–8.4)
- [x] `composer-require-checker` guards the PSR-only dependency list

## License

[MIT](LICENSE)