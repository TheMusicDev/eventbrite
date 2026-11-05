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
