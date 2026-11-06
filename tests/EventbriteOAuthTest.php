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

    $state = $oauth->newState('session-a');
    expect($state)->toMatch('/^[0-9a-f]{24}\.\d+-[0-9a-f]{64}$/');
    expect($oauth->verifyState($state, 'session-a'))->toBeTrue();
    // Flip the MAC's first char; hardcoding one value is a no-op when the MAC already starts with it.
    $macAt = strlen($state) - 64;
    expect($oauth->verifyState(substr_replace($state, $state[$macAt] === '0' ? '1' : '0', $macAt, 1), 'session-a'))->toBeFalse();
    expect($oauth->verifyState('deadbeefdeadbeefdeadbeef-fake', 'session-a'))->toBeFalse();
    expect($oauth->verifyState('no-mac-at-all', 'session-a'))->toBeFalse();
});

it('rejects a state minted for another visitor', function (): void {
    $oauth = new EventbriteOAuth(fakeHttp(), fakeHttp(), fakeHttp(), 'key', 'secret', 'https://app.test/cb', 'app-salt');

    expect($oauth->verifyState($oauth->newState('attacker-session'), 'victim-session'))->toBeFalse();
});

it('expires a state after the ttl and signs its timestamp', function (): void {
    $oauth = new EventbriteOAuth(fakeHttp(), fakeHttp(), fakeHttp(), 'key', 'secret', 'https://app.test/cb', 'app-salt');
    $signedAt = static function (int $issuedAt): string {
        $payload = str_repeat('a', 24) . '.' . $issuedAt;

        return $payload . '-' . hash_hmac('sha256', 'eventbrite-oauth|' . $payload . '|session-a', 'app-salt');
    };

    expect($oauth->verifyState($signedAt(time() - EventbriteOAuth::STATE_TTL + 60), 'session-a'))->toBeTrue();
    expect($oauth->verifyState($signedAt(time() - EventbriteOAuth::STATE_TTL - 60), 'session-a'))->toBeFalse();
    expect($oauth->verifyState($oauth->newState('session-a'), 'session-a', maxAge: -1))->toBeFalse();

    $refreshed = preg_replace('/\.\d+-/', '.' . (time() + 1000) . '-', $oauth->newState('session-a'));
    expect($oauth->verifyState($refreshed, 'session-a'))->toBeFalse();
});

it('rejects a state in the unbound 0.1 format', function (): void {
    $oauth = new EventbriteOAuth(fakeHttp(), fakeHttp(), fakeHttp(), 'key', 'secret', 'https://app.test/cb', 'app-salt');
    $nonce = str_repeat('a', 24);

    expect($oauth->verifyState($nonce . '-' . hash_hmac('sha256', 'eventbrite-oauth|' . $nonce, 'app-salt'), 'session-a'))->toBeFalse();
});

it('refuses an empty visitor, which is what session_id() returns before session_start()', function (): void {
    $oauth = new EventbriteOAuth(fakeHttp(), fakeHttp(), fakeHttp(), 'key', 'secret', 'https://app.test/cb', 'app-salt');

    expect($oauth->verifyState($oauth->newState('session-a'), ''))->toBeFalse();
    expect(fn () => $oauth->newState(''))->toThrow(InvalidArgumentException::class, 'visitor');
});

it('refuses to construct without full credentials', function (): void {
    new EventbriteOAuth(fakeHttp(), fakeHttp(), fakeHttp(), 'key', '', 'https://app.test/cb', 'salt');
})->throws(RuntimeException::class, 'OAuth requires');

it('builds the authorize url with all parameters', function (): void {
    $oauth = new EventbriteOAuth(fakeHttp(), fakeHttp(), fakeHttp(), 'key', 'secret', 'https://app.test/cb', 'salt');

    $target = $oauth->authorizeUrl($oauth->newState('session-a'));
    parse_str(parse_url($target, PHP_URL_QUERY) ?? '', $query);
    expect($query['response_type'])->toBe('code');
    expect($query['client_id'])->toBe('key');
    expect($query['redirect_uri'])->toBe('https://app.test/cb');
    expect($oauth->verifyState($query['state'], 'session-a'))->toBeTrue();
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
