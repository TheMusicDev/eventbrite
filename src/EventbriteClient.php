<?php

declare(strict_types=1);

namespace TheMusicDev\Eventbrite;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Eventbrite API v3 client.
 *
 * Framework agnostic: bring your own PSR-18 HTTP client, PSR-17 request and
 * stream factories, optionally a PSR-16 cache (GET responses) and PSR-3 logger.
 * Auth: a private token or an OAuth access token — plain string.
 *
 * Docs: https://www.eventbrite.com/platform/api (base URL, bearer token,
 * JSON in/out). All methods return decoded JSON arrays; non-2xx responses
 * throw EventbriteApiException with the status code and error body. Listing
 * endpoints that accept `continuation` are exposed as generators.
 *
 * Endpoints verified against the docs (2026-11-04):
 * - GET /v3/users/me/                      — Retrieve Information About Your User Account
 * - GET /v3/users/me/organizations/        — List Your Organizations
 * - GET /v3/organizations/{id}/events/     — List Events by Organization (status, time_filter, ...)
 * - GET /v3/events/{id}/                   — Retrieve an Event
 * - GET /v3/events/{id}/attendees/         — List Attendees by Event
 * - GET /v3/events/{id}/orders/            — List Orders by Event
 * - GET /v3/events/{id}/ticket_classes/    — List Ticket Classes by Event
 * - GET/POST /v3/organizations/{id}/webhooks/ — list and register webhooks
 *
 * The public event search endpoint has been removed upstream — do not add one.
 */
class EventbriteClient
{
    /** @var string */
    public const BASE_URL = 'https://www.eventbriteapi.com/v3/';

    /** @var int Seconds GET responses are cached when a cache is provided. */
    public const CACHE_TTL = 600;

    private string $token;

    private ClientInterface $http;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    private ?CacheInterface $cache;

    private ?LoggerInterface $logger;

    private int $retries;

    private int $cacheTtl;

    /**
     * @param \Psr\Http\Client\ClientInterface $http PSR-18 HTTP client (Guzzle, Symfony, ...).
     * @param \Psr\Http\Message\RequestFactoryInterface $requestFactory PSR-17.
     * @param \Psr\Http\Message\StreamFactoryInterface $streamFactory PSR-17 (POST bodies).
     * @param string|null $token Private token or OAuth access token. Never log it. Null refuses to construct.
     * @param \Psr\SimpleCache\CacheInterface|null $cache When given, GET responses cache for $cacheTtl seconds.
     * @param \Psr\Log\LoggerInterface|null $logger Logs 429 backoff retries; tokens are never logged.
     * @param int $retries Number of 429 retries with exponential backoff before giving up.
     * @param int $cacheTtl Seconds to cache GET responses; 0 disables caching.
     * @throws \RuntimeException When no token is provided.
     */
    public function __construct(
        ClientInterface $http,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ?string $token = null,
        ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null,
        int $retries = 3,
        int $cacheTtl = self::CACHE_TTL,
    ) {
        if ($token === null || $token === '') {
            throw new \RuntimeException(
                'No Eventbrite access token. Pass one explicitly (private token from '
                . 'https://www.eventbrite.com/account-settings/apps or an OAuth access token '
                . 'from EventbriteOAuth::exchangeCode()).',
            );
        }
        $this->token = $token;
        $this->http = $http;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->retries = $retries;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * GET /v3/users/me/
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->get('users/me/');
    }

    /**
     * GET /v3/users/me/organizations/
     *
     * @return array<string, mixed> An `organizations` list plus `pagination`.
     */
    public function organizations(): array
    {
        return $this->get('users/me/organizations/');
    }

    /**
     * GET /v3/organizations/{id}/events/, following pagination.
     *
     * @param string $orgId Organization ID.
     * @param array<string, mixed> $params Optional query params, e.g. ['status' => 'live', 'time_filter' => 'all'].
     * @return \Generator<int, array<string, mixed>> Event objects.
     */
    public function organizationEvents(string $orgId, array $params = []): \Generator
    {
        return $this->paginate('organizations/' . $orgId . '/events/', 'events', $params);
    }

    /**
     * GET /v3/events/{id}/
     *
     * @return array<string, mixed>
     */
    public function event(string $id): array
    {
        return $this->get('events/' . $id . '/');
    }

    /**
     * GET /v3/events/{id}/attendees/, following pagination.
     *
     * @param string $id Event ID.
     * @return \Generator<int, array<string, mixed>> Attendee objects.
     */
    public function eventAttendees(string $id): \Generator
    {
        return $this->paginate('events/' . $id . '/attendees/', 'attendees');
    }

    /**
     * GET /v3/events/{id}/orders/, following pagination.
     *
     * @param string $id Event ID.
     * @return \Generator<int, array<string, mixed>> Order objects.
     */
    public function eventOrders(string $id): \Generator
    {
        return $this->paginate('events/' . $id . '/orders/', 'orders');
    }

    /**
     * GET /v3/events/{id}/ticket_classes/
     *
     * @return array<string, mixed> A `ticket_classes` list plus `pagination`.
     */
    public function ticketClasses(string $id): array
    {
        return $this->get('events/' . $id . '/ticket_classes/');
    }

    /**
     * GET /v3/organizations/{id}/webhooks/ — webhooks registered for the org.
     *
     * Never cached even when a cache is provided: this is the source of truth
     * for what is enabled, and a stale list lies in both toggle reconciliation
     * and any UI state.
     *
     * @return array<string, mixed> A `webhooks` list plus `pagination`.
     */
    public function webhooks(string $orgId): array
    {
        return $this->getUncached('organizations/' . $orgId . '/webhooks/');
    }

    /**
     * POST /v3/organizations/{id}/webhooks/ — register a webhook.
     *
     * Body shape (endpoint_url root-level, actions as a comma-separated string)
     * verified live: Eventbrite echoes the fields back and returns `actions`
     * as an array. Not detailed in the reference docs.
     *
     * @param string $orgId Organization ID.
     * @param string $endpointUrl The public URL Eventbrite should POST to.
     * @param array<string> $actions Action codes, e.g. ['attendee.updated', 'barcode.checked_in'].
     * @param string|null $eventbriteId Existing webhook id to update (same POST when null).
     * @return array<string, mixed> The created webhook object.
     * @throws \TheMusicDev\Eventbrite\EventbriteApiException For non-2xx responses.
     */
    public function createWebhook(string $orgId, string $endpointUrl, array $actions = ['attendee.updated'], ?string $eventbriteId = null): array
    {
        $body = [
            'endpoint_url' => $endpointUrl,
            'actions' => implode(',', $actions),
        ];
        if ($eventbriteId !== null) {
            $body['event_id'] = $eventbriteId;
        }

        return $this->post('organizations/' . $orgId . '/webhooks/', $body);
    }

    /**
     * Normalize a webhook's `actions` field: the API may return it as a
     * comma-separated string or an array of codes.
     *
     * @param mixed $actions Raw `actions` field from a webhook object.
     * @return array<string> Action codes.
     */
    public static function normalizeActions(mixed $actions): array
    {
        $out = [];
        $parts = is_array($actions) ? $actions : [$actions];
        foreach ($parts as $part) {
            $split = preg_split('/\s*,\s*/', is_string($part) ? $part : '');
            if (!is_array($split)) {
                continue;
            }
            foreach ($split as $code) {
                if ($code !== '') {
                    $out[$code] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * Make sure an org has one webhook per given action code pointing at
     * $endpointUrl; creates the missing registrations. Listing is authoritative
     * from Eventbrite, so this survives any local state loss.
     *
     * @param string $orgId Organization ID.
     * @param string $endpointUrl Webhook target URL.
     * @param array<string> $actions Action codes, e.g. ['attendee.updated'].
     * @return array{existing: int, created: int} Counts.
     */
    public function ensureWebhooks(string $orgId, string $endpointUrl, array $actions = ['attendee.updated']): array
    {
        $registered = [];
        $listing = $this->webhooks($orgId);
        if (is_array($listing['webhooks'] ?? null)) {
            foreach ($listing['webhooks'] as $hook) {
                if (!is_array($hook) || ($hook['endpoint_url'] ?? null) !== $endpointUrl) {
                    continue;
                }
                foreach (self::normalizeActions($hook['actions'] ?? null) as $action) {
                    $registered[$action] = true;
                }
            }
        }
        $created = 0;
        foreach ($actions as $action) {
            if (!isset($registered[$action])) {
                $this->createWebhook($orgId, $endpointUrl, [$action]);
                $created++;
            }
        }

        return ['existing' => count($registered), 'created' => $created];
    }

    /**
     * DELETE /v3/webhooks/{id}/ — permanent; kills the webhook and its history.
     *
     * @return array<string, mixed>
     * @throws \TheMusicDev\Eventbrite\EventbriteApiException For non-2xx responses.
     */
    public function deleteWebhook(string $webhookId): array
    {
        $url = self::BASE_URL . 'webhooks/' . $webhookId . '/';

        return $this->decode($this->sendWithRetry('DELETE', $url, []), $url);
    }

    /**
     * Delete every webhook of $orgId whose endpoint_url is $endpointUrl.
     * Counterpart of ensureWebhooks().
     *
     * @return int Number of webhooks deleted.
     */
    public function disableWebhooks(string $orgId, string $endpointUrl): int
    {
        $deleted = 0;
        $listing = $this->webhooks($orgId);
        if (is_array($listing['webhooks'] ?? null)) {
            foreach ($listing['webhooks'] as $hook) {
                if (
                    !is_array($hook)
                    || ($hook['endpoint_url'] ?? null) !== $endpointUrl
                    || !is_string($hook['id'] ?? null)
                ) {
                    continue;
                }
                $this->deleteWebhook($hook['id']);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Fetch an absolute Eventbrite URL bypassing the cache entirely — used by
     * webhook deliveries, where the cache would serve the pre-change snapshot
     * (a ticket list from before the sale).
     *
     * @param string $url Absolute URL, normally the webhook payload's api_url.
     * @return array<string, mixed> The decoded JSON response.
     * @throws \TheMusicDev\Eventbrite\EventbriteApiException For non-2xx responses.
     */
    public function fetchUrl(string $url): array
    {
        $this->assertApiUrl($url);

        return $this->decode($this->sendWithRetry('GET', $url, []), $url);
    }

    /**
     * GET $path (relative to BASE_URL), cached for self::CACHE_TTL seconds
     * when the package was given a PSR-16 cache and ttl is not 0.
     *
     * @param string $path Endpoint path, e.g. `users/me/`.
     * @param array<string, mixed> $params Query parameters.
     * @return array<string, mixed> The decoded JSON response.
     * @throws \TheMusicDev\Eventbrite\EventbriteApiException For non-2xx responses.
     */
    public function get(string $path, array $params = []): array
    {
        $url = self::BASE_URL . ltrim($path, '/');
        $cacheKey = 'eventbrite_get_' . md5(sha1($this->token) . '|' . $url . serialize($params));

        if ($this->cache instanceof CacheInterface && $this->cacheTtl > 0) {
            $cached = $this->cache->get($cacheKey, null);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $this->assertApiUrl($url);
        $data = $this->decode($this->sendWithRetry('GET', $url, $params), $url);

        if ($this->cache instanceof CacheInterface && $this->cacheTtl > 0) {
            $this->cache->set($cacheKey, $data, $this->cacheTtl);
        }

        return $data;
    }

    /**
     * GET bypassing the cache in both directions (no read, no write).
     *
     * @param string $path Endpoint path relative to BASE_URL.
     * @param array<string, mixed> $params Query parameters.
     * @return array<string, mixed>
     * @throws \TheMusicDev\Eventbrite\EventbriteApiException For non-2xx responses.
     */
    public function getUncached(string $path, array $params = []): array
    {
        $url = self::BASE_URL . ltrim($path, '/');
        $this->assertApiUrl($url);

        return $this->decode($this->sendWithRetry('GET', $url, $params), $url);
    }

    /**
     * Raw HTTP GET — no decoding, no cache: headers, status and body, for
     * debugging/scratchpad uses. Non-2xx still throws.
     *
     * @param string $url Full URL (anything outside the API base throws).
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \TheMusicDev\Eventbrite\EventbriteApiException For non-2xx responses.
     */
    public function getRaw(string $url): ResponseInterface
    {
        $this->assertApiUrl($url);

        return $this->sendWithRetry('GET', $url, []);
    }

    /**
     * POST JSON against $path (relative to BASE_URL). Writes are never cached.
     *
     * @param string $path Endpoint path, e.g. `organizations/123/webhooks/`.
     * @param array<string, mixed> $data JSON body.
     * @return array<string, mixed> The decoded JSON response.
     * @throws \TheMusicDev\Eventbrite\EventbriteApiException For non-2xx responses.
     */
    public function post(string $path, array $data): array
    {
        $url = self::BASE_URL . ltrim($path, '/');
        $this->assertApiUrl($url);

        $response = $this->sendWithRetry('POST', $url, [], $data);

        return $this->decode($response, $url);
    }

    /**
     * Follow Eventbrite pagination: yields every item of every page, sending
     * `continuation` until pagination.has_more_items is false or there is no
     * continuation token left. $key is the response's list field ('events',
     * 'attendees', 'orders', ...).
     *
     * @param array<string, mixed> $params Query params for the first request.
     * @return \Generator<int, array<string, mixed>>
     */
    public function paginate(string $path, string $key, array $params = []): \Generator
    {
        while (true) {
            $data = $this->get($path, $params);
            if (is_array($data[$key] ?? null)) {
                yield from $data[$key];
            }
            $page = $data['pagination'] ?? null;
            $hasMore = is_array($page) && ($page['has_more_items'] ?? null) === true;
            $continuation = is_array($page) ? ($page['continuation'] ?? null) : null;
            if (!$hasMore || !is_string($continuation)) {
                return;
            }
            $params = ['continuation' => $continuation];
        }
    }

    /**
     * Encode a request body, turning an encoding failure into an exception
     * instead of a false empty body.
     *
     * @param array<string, mixed> $data
     */
    private function jsonBody(array $data): string
    {
        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);

            return $encoded;
        } catch (\JsonException $e) {
            throw new \RuntimeException('Could not encode request body: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Refuse any URL outside the Eventbrite API (paths interpolate here;
     * untrusted input must not leave the API's URL space).
     */
    private function assertApiUrl(string $url): void
    {
        if (!str_starts_with($url, self::BASE_URL)) {
            throw new \RuntimeException('Refusing to send a request outside ' . self::BASE_URL);
        }
    }

    /**
     * Send with the shared bearer auth and 429 backoff policy.
     *
     * @param array<string, mixed> $params Query parameters (GET) — appended to $url.
     * @param array<string, mixed>|null $jsonBody JSON body (POST); with a body, $appendedQuery is ignored.
     */
    private function sendWithRetry(string $method, string $url, array $params, ?array $jsonBody = null): ResponseInterface
    {
        $target = $jsonBody === null && $params !== []
            ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986)
            : $url;
        $headers = ['Authorization' => 'Bearer ' . $this->token];
        $attempt = 0;
        while (true) {
            if ($jsonBody !== null) {
                $request = $this->requestFactory->createRequest($method, $target)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streamFactory->createStream($this->jsonBody($jsonBody)));
            } else {
                $request = $this->requestFactory->createRequest($method, $target);
            }
            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            $response = $this->http->sendRequest($request);
            if ($response->getStatusCode() === 429 && $attempt < $this->retries) {
                // ponytail: fixed exponential backoff (1s, 2s, 4s); honor a
                // retry-after header if Eventbrite starts sending one.
                $this->logger?->warning('Eventbrite rate limited; backing off', ['attempt' => $attempt + 1]);
                sleep(2 ** $attempt);
                $attempt++;
                continue;
            }
            break;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, string $url): array
    {
        $body = $response->getBody()->getContents();
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new EventbriteApiException($response->getStatusCode(), $body, $url);
        }
        $json = json_decode($body, true);

        return is_array($json) ? $json : [];
    }
}
