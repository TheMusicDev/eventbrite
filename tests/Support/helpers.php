<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use TheMusicDev\Eventbrite\EventbriteClient;
use TheMusicDev\Eventbrite\Tests\Support\FakeHttp;

/**
 * One FakeHttp acting as PSR-18 client + PSR-17 request/stream factories.
 */
function fakeHttp(): FakeHttp
{
    return new FakeHttp();
}

/**
 * Default-token convenience constructor for tests.
 */
function newClient(
    FakeHttp $http,
    string $token = 'test-token',
    ?CacheInterface $cache = null,
    int $cacheTtl = 0,
    int $retries = 0,
): EventbriteClient {
    return new EventbriteClient($http, $http, $http, $token, $cache, null, $retries, $cacheTtl);
}

/**
 * In-memory PSR-16 cache; only get() and set() matter to the client.
 */
function arrayCache(): CacheInterface
{
    return new class () implements CacheInterface {
        /** @var array<string, mixed> */
        private array $items = [];

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
    };
}
