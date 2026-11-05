<?php

declare(strict_types=1);

namespace TheMusicDev\Eventbrite\Tests;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\SimpleCache\CacheInterface;
use TheMusicDev\Eventbrite\EventbriteApiException;
use TheMusicDev\Eventbrite\EventbriteClient;
use TheMusicDev\Eventbrite\Tests\Support\FakeHttp;

/**
 * Client behaviour: bearer auth, JSON decode, typed exceptions, pagination,
 * 429 backoff, cache, webhooks.
 */
it('refuses to construct without a token', function (): void {
    newClient(fakeHttp(), '');
})->throws(\RuntimeException::class, 'No Eventbrite access token');

it('sends the bearer token and decodes json', function (): void {
    $http = fakeHttp();
    $http->queue('GET', EventbriteClient::BASE_URL . 'users/me/', FakeHttp::jsonResponse(200, ['id' => '123', 'name' => 'Test User']));

    $client = newClient($http, 'test-token', cache: null, cacheTtl: 0);

    expect($client->me())->toBe(['id' => '123', 'name' => 'Test User']);
    $sent = $http->requests[0];
    expect($sent['headers']['authorization'])->toBe('Bearer test-token');
    expect($sent['url'])->toBe(EventbriteClient::BASE_URL . 'users/me/');
});

it('throws a typed exception with status and body for non-2xx', function (int $status, string $body, string $contains): void {
    $http = fakeHttp();
    $http->queue('GET', EventbriteClient::BASE_URL . 'events/999/', new Response(
        $status,
        ['content-type' => 'application/json'],
        Utils::streamFor($body)
    ));
    $client = newClient($http, cache: null, cacheTtl: 0);

    expect(fn () => $client->event('999'))->toThrow(static function (EventbriteApiException $e) use ($status, $body, $contains): void {
        expect($e->getStatusCode())->toBe($status);
        expect($e->getErrorBody())->toBe($body);
        expect($e->getMessage())->toContain($contains);
        expect($e->getMessage())->not->toContain('test-token');
    });
})->with([
    [401, '{"error": "INVALID_AUTH", "error_description": "The OAuth token you provided was invalid."}', 'The OAuth token you provided was invalid'],
    [404, '{"status_code": 404, "error_description": "The event you requested does not exist"}', 'does not exist'],
    [500, 'Internal Server Error', 'Internal Server Error'],
]);

it('follows pagination via continuation until no more', function (): void {
    $http = fakeHttp();
    $events = EventbriteClient::BASE_URL . 'organizations/444/events/';
    $http->queue('GET', $events, FakeHttp::jsonResponse(200, [
        'pagination' => ['object_count' => 3, 'page_number' => 1, 'page_size' => 2, 'has_more_items' => true, 'continuation' => 'c2'],
        'events' => [['id' => 'e1'], ['id' => 'e2']],
    ]));
    $http->queue('GET', $events, FakeHttp::jsonResponse(200, [
        'pagination' => ['object_count' => 3, 'page_number' => 2, 'page_size' => 2, 'has_more_items' => false],
        'events' => [['id' => 'e3']],
    ]));
    $client = newClient($http, cache: null, cacheTtl: 0);

    $ids = [];
    foreach ($client->organizationEvents('444') as $event) {
        $ids[] = $event['id'];
    }

    expect($ids)->toBe(['e1', 'e2', 'e3']);
    expect($http->requests)->toHaveCount(2);
    expect($http->requests[1]['url'])->toContain('continuation=c2');
});

it('passes query params through to the url', function (): void {
    $http = fakeHttp();
    $http->queue('GET', EventbriteClient::BASE_URL . 'organizations/444/events/?status=live', FakeHttp::jsonResponse(200, ['events' => []]));
    $client = newClient($http, cache: null, cacheTtl: 0);

    $events = iterator_to_array($client->organizationEvents('444', ['status' => 'live']), false);
    expect($events)->toBe([]);
});

it('retries a 429 then succeeds', function (): void {
    $http = fakeHttp();
    $http->queue('GET', EventbriteClient::BASE_URL . 'users/me/', FakeHttp::jsonResponse(429, ['error' => 'HTTP_TOO_MANY_REQUESTS']));
    $http->queue('GET', EventbriteClient::BASE_URL . 'users/me/', FakeHttp::jsonResponse(200, ['id' => '123']));
    $client = newClient($http, 'test-token', retries: 2, cacheTtl: 0);

    expect($client->me())->toBe(['id' => '123']);
    expect($http->requests)->toHaveCount(2);
});

it('throws after exhausting 429 retries', function (): void {
    $http = fakeHttp();
    $http->queue('GET', EventbriteClient::BASE_URL . 'users/me/', FakeHttp::jsonResponse(429, ['error' => 'HTTP_TOO_MANY_REQUESTS']));
    $client = newClient($http, 'test-token', retries: 0, cacheTtl: 0);

    expect(fn () => $client->me())->toThrow(EventbriteApiException::class);
});

it('caches GET responses when a cache is present', function (): void {
    $http = fakeHttp();
    $http->queue('GET', EventbriteClient::BASE_URL . 'users/me/', FakeHttp::jsonResponse(200, ['id' => 'cache-me']));
    $cache = new class () implements CacheInterface {
        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }

        public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
        {
            $this->items[$key] = $value;

            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->items[$key]);

            return true;
        }

        public function clear(): bool
        {
            $this->items = [];

            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return [];
        }

        /** @param iterable<string, mixed> $values */
        public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return true;
        }

        public function has(string $key): bool
        {
            return isset($this->items[$key]);
        }

        /** @var array<string, mixed> */
        public array $items = [];
    };
    $client = newClient($http, 'tok', cache: $cache, cacheTtl: 600);

    $first = $client->me();
    $second = $client->me(); // served from cache: only one recorded request

    expect($first)->toBe($second);
    expect($http->requests)->toHaveCount(1);
    expect($cache->items)->toHaveCount(1);
});

it('never caches the webhooks listing', function (): void {
    $http = fakeHttp();
    $hooksUrl = EventbriteClient::BASE_URL . 'organizations/444/webhooks/';
    $http->queue('GET', $hooksUrl, FakeHttp::jsonResponse(200, ['webhooks' => [['id' => 'h1', 'endpoint_url' => 'https://x.dev/webhook', 'actions' => ['attendee.updated']]]]));
    $cache = new class () implements CacheInterface {
        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }

        public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
        {
            $this->items[$key] = $value;

            return true;
        }

        public function delete(string $key): bool
        {
            return true;
        }

        public function clear(): bool
        {
            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return [];
        }

        /** @param iterable<string, mixed> $values */
        public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return true;
        }

        public function has(string $key): bool
        {
            return false;
        }

        /** @var array<string, mixed> */
        public array $items = [];
    };
    $client = newClient($http, 'tok', cache: $cache, cacheTtl: 600);

    $client->webhooks('444');
    $second = $client->webhooks('444');

    expect($http->requests)->toHaveCount(2, 'webhooks listing must hit the wire twice');
    expect($second['webhooks'][0]['id'])->toBe('h1');
    expect($cache->items)->toBe([], 'webhooks listing must not write cache');
});

it('ensureWebhooks is idempotent', function (): void {
    $http = fakeHttp();
    $url = 'https://silica.test/webhook';
    $hooksUrl = EventbriteClient::BASE_URL . 'organizations/444/webhooks/';
    // first call: only attendee.updated registered on our endpoint
    $http->queue('GET', $hooksUrl, FakeHttp::jsonResponse(200, ['webhooks' => [
        ['id' => 'h1', 'endpoint_url' => $url, 'actions' => 'attendee.updated'],
    ]]));
    $http->queue('GET', $hooksUrl, FakeHttp::jsonResponse(200, ['webhooks' => [
        ['id' => 'h1', 'endpoint_url' => $url, 'actions' => 'attendee.updated'],
        ['id' => 'h2', 'endpoint_url' => 'https://elsewhere.test/hook', 'actions' => 'order.placed'],
        ['id' => 'h3', 'endpoint_url' => $url, 'actions' => 'order.placed'],
    ]]));
    $http->queue('POST', $hooksUrl, FakeHttp::jsonResponse(200, ['id' => 'h3']));
    $client = newClient($http, cache: null, cacheTtl: 0);

    $first = $client->ensureWebhooks('444', $url, ['attendee.updated', 'order.placed']);
    expect($first)->toBe(['existing' => 1, 'created' => 1]);

    $second = $client->ensureWebhooks('444', $url, ['attendee.updated', 'order.placed']);
    expect($second)->toBe(['existing' => 2, 'created' => 0]);
});

it('normalizeActions accepts string, comma-string and array shapes', function (): void {
    expect(EventbriteClient::normalizeActions('attendee.updated'))->toBe(['attendee.updated']);
    expect(EventbriteClient::normalizeActions('attendee.updated, order.placed'))->toBe(['attendee.updated', 'order.placed']);
    expect(EventbriteClient::normalizeActions(['attendee.updated', 'order.placed']))->toBe(['attendee.updated', 'order.placed']);
    expect(EventbriteClient::normalizeActions(['attendee.updated,order.placed']))->toBe(['attendee.updated', 'order.placed']);
    expect(EventbriteClient::normalizeActions(null))->toBe([]);
});

it('createWebhook posts urlencoded action string and drops null ids', function (): void {
    $http = fakeHttp();
    $hooksUrl = EventbriteClient::BASE_URL . 'organizations/444/webhooks/';
    $http->queue('POST', $hooksUrl, FakeHttp::jsonResponse(200, ['id' => 'n1']));
    $client = newClient($http, cache: null, cacheTtl: 0);

    $client->createWebhook('444', 'https://x.dev/hook', ['attendee.updated', 'order.placed']);

    $sent = $http->requests[0];
    expect($sent['url'])->toBe($hooksUrl);
    expect($sent['headers']['content-type'])->toBe('application/json');
    expect(json_decode($sent['body'], true))->toBe(['endpoint_url' => 'https://x.dev/hook', 'actions' => 'attendee.updated,order.placed']);
});

it('deleteWebhook sends a DELETE with bearer auth', function (): void {
    $http = fakeHttp();
    $http->queue('DELETE', EventbriteClient::BASE_URL . 'webhooks/h9/', FakeHttp::jsonResponse(200, []));
    $client = newClient($http, 'special-token', cache: null, cacheTtl: 0);

    $client->deleteWebhook('h9');

    expect($http->requests[0]['method'])->toBe('DELETE');
    expect($http->requests[0]['headers']['authorization'])->toBe('Bearer special-token');
    expect($http->requests[0]['url'])->toBe(EventbriteClient::BASE_URL . 'webhooks/h9/');
});

it('getRaw returns the raw PSR-7 response within the api space', function (): void {
    $http = fakeHttp();
    $http->queue('GET', EventbriteClient::BASE_URL . 'users/me/', FakeHttp::jsonResponse(200, ['id' => 'raw']));
    $client = newClient($http, cache: null, cacheTtl: 0);

    $response = $client->getRaw(EventbriteClient::BASE_URL . 'users/me/');
    expect($response->getStatusCode())->toBe(200);
    expect((string)$response->getBody())->toContain('"id"');
});

it('refuses URLs outside the api space, with no network use', function (): void {
    $http = fakeHttp();
    $client = newClient($http, cache: null, cacheTtl: 0);

    try {
        $client->getRaw('https://evil.example.com/v3/users/me/');
        expect(true)->toBeFalse('expected the getRaw call to be refused');
    } catch (\RuntimeException $exception) {
        expect($exception->getMessage())->toContain('Refusing');
    }
    expect($http->requests)->toBe([]);
});
