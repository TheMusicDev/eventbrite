<?php

declare(strict_types=1);

use TheMusicDev\Eventbrite\EventbriteApiException;
use TheMusicDev\Eventbrite\EventbriteOAuth;
use TheMusicDev\Eventbrite\Tests\Support\FakeHttp;

/**
 * OAuth flow: signed state (stateless CSRF), authorize URL, code exchange.
 */
it('mints and verifies the signed state, rejecting tampering', function (): void {
    $oauth = new EventbriteOAuth(fakeHttp(), fakeHttp(), fakeHttp(), 'key', 'secret', 'https://app.test/cb', 'app-salt');

    $state = $oauth->newState();
    expect($state)->toMatch('/^[0-9a-f]{24}-[0-9a-f]{64}$/');
    expect($oauth->verifyState($state))->toBeTrue();
    expect($oauth->verifyState(substr($state, 0, 24) . '-0' . substr($state, 26)))->toBeFalse();
    expect($oauth->verifyState('deadbeefdeadbeefdeadbeef-fake'))->toBeFalse();
    expect($oauth->verifyState('no-mac-at-all'))->toBeFalse();
});

it('refuses to construct without full credentials', function (): void {
    new EventbriteOAuth(fakeHttp(), fakeHttp(), fakeHttp(), 'key', '', 'https://app.test/cb', 'salt');
})->throws(RuntimeException::class, 'OAuth requires');

it('builds the authorize url with all parameters', function (): void {
    $oauth = new EventbriteOAuth(fakeHttp(), fakeHttp(), fakeHttp(), 'key', 'secret', 'https://app.test/cb', 'salt');

    $target = $oauth->authorizeUrl($oauth->newState());
    parse_str(parse_url($target, PHP_URL_QUERY) ?? '', $query);
    expect($query['response_type'])->toBe('code');
    expect($query['client_id'])->toBe('key');
    expect($query['redirect_uri'])->toBe('https://app.test/cb');
    expect($oauth->verifyState($query['state']))->toBeTrue();
});

it('exchanges the code with the required form fields', function (): void {
    $http = fakeHttp();
    $http->queue('POST', 'https://www.eventbrite.com/oauth/token', FakeHttp::jsonResponse(200, ['access_token' => 'oauth-tok', 'token_type' => 'bearer']));
    $oauth = new EventbriteOAuth($http, $http, $http, 'key', 'secret', 'https://app.test/cb', 'salt');

    expect($oauth->exchangeCode('the-code'))->toBe(['access_token' => 'oauth-tok', 'token_type' => 'bearer']);

    $sent = $http->requests[0];
    parse_str($sent['body'], $body);
    expect($sent['headers']['content-type'])->toBe('application/x-www-form-urlencoded');
    expect($body['grant_type'])->toBe('authorization_code');
    expect($body['client_id'])->toBe('key');
    expect($body['client_secret'])->toBe('secret');
    expect($body['code'])->toBe('the-code');
    expect($body['redirect_uri'])->toBe('https://app.test/cb');
});

it('surfaces an erroring token exchange as a typed exception', function (): void {
    $http = fakeHttp();
    $http->queue('POST', 'https://www.eventbrite.com/oauth/token', FakeHttp::jsonResponse(400, ['error' => 'invalid_grant', 'error_description' => 'bad code']));
    $oauth = new EventbriteOAuth($http, $http, $http, 'key', 'secret', 'https://app.test/cb', 'salt');

    expect(fn () => $oauth->exchangeCode('the-code'))->toThrow(function (EventbriteApiException $e): void {
        expect($e->getStatusCode())->toBe(400);
        expect($e->getMessage())->toContain('bad code');
    });
});
