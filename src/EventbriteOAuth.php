<?php

declare(strict_types=1);

namespace TheMusicDev\Eventbrite;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Eventbrite OAuth 2 authorization-code flow
 * (https://www.eventbrite.com/platform/docs/authentication).
 *
 * - newState() / verifyState(): a signed, stateless CSRF value — the callback
 *   verifies the signature without needing any session.
 * - authorizeUrl(): the URL to redirect the browser to.
 * - exchangeCode(): swaps the ?code= for a bearer access token.
 * - saveToken() is the host app's job (token storage is your persistence).
 *
 * Never log the client secret or the access token.
 */
class EventbriteOAuth
{
    private string $clientId;

    private string $clientSecret;

    private string $redirectUri;

    private string $salt;

    private ClientInterface $http;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    /**
     * @param \Psr\Http\Client\ClientInterface $http PSR-18 client.
     * @param \Psr\Http\Message\RequestFactoryInterface $requestFactory PSR-17 factory.
     * @param \Psr\Http\Message\StreamFactoryInterface $streamFactory PSR-17 factory (form body).
     * @param string $clientId The Eventbrite app key.
     * @param string $clientSecret The Eventbrite client secret.
     * @param string $redirectUri The exact redirect URI registered in the app settings.
     * @param string $salt An application secret for signing the state (CSRF); any stable random string.
     * @throws \RuntimeException When credentials are missing.
     */
    public function __construct(
        ClientInterface $http,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $salt,
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->salt = $salt;
        $this->http = $http;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;

        if ($this->clientId === '' || $this->clientSecret === '' || $this->redirectUri === '' || $this->salt === '') {
            throw new \RuntimeException(
                'Eventbrite OAuth requires clientId, clientSecret, redirectUri and a salt '
                . '(create an app at https://www.eventbrite.com/account-settings/apps).',
            );
        }
    }

    /**
     * A signed, stateless CSRF value: nonce-HMAC. Immutable across redirects;
     * no session required on any side.
     *
     * @return string
     */
    public function newState(): string
    {
        $nonce = bin2hex(random_bytes(12));

        return $nonce . '-' . hash_hmac('sha256', 'eventbrite-oauth|' . $nonce, $this->salt);
    }

    /**
     * @param string $state The state value Eventbrite returned with the redirect.
     * @return bool True when the value was minted by newState() and is untampered.
     */
    public function verifyState(string $state): bool
    {
        [$nonce, $mac] = explode('-', $state, 2) + [null, null];

        return is_string($nonce) && is_string($mac)
            && hash_equals(hash_hmac('sha256', 'eventbrite-oauth|' . $nonce, $this->salt), $mac);
    }

    /**
     * @param string $state State value from newState().
     * @return string The authorization URL to redirect to.
     */
    public function authorizeUrl(string $state): string
    {
        return 'https://www.eventbrite.com/oauth/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * Exchange the callback's ?code= for an access token.
     *
     * @param string $code The code Eventbrite appended to the redirect URI.
     * @return array<string, mixed> The decoded oauth/token response (access_token, ...).
     * @throws \TheMusicDev\Eventbrite\EventbriteApiException For non-2xx responses.
     */
    public function exchangeCode(string $code): array
    {
        $url = 'https://www.eventbrite.com/oauth/token';
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream(
                http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri,
                ])
            ));
        $response = $this->http->sendRequest($request);
        $body = $response->getBody()->getContents();
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new EventbriteApiException($response->getStatusCode(), $body, $url);
        }
        $json = json_decode($body, true);

        return is_array($json) ? $json : [];
    }
}
