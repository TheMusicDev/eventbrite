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
 * - newState() / verifyState(): a signed CSRF value bound to one visitor, which
 *   expires. Nothing is stored; the callback re-derives it from the same visitor value.
 * - authorizeUrl(): the URL to redirect the browser to.
 * - exchangeCode(): swaps the ?code= for a bearer access token.
 * - saveToken() is the host app's job (token storage is your persistence).
 *
 * Never log the client secret or the access token.
 */
class EventbriteOAuth
{
    /** @var int Seconds a state stays valid: how long the user has to approve on Eventbrite. */
    public const STATE_TTL = 600;

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
     * A signed CSRF value bound to one visitor: nonce, issue time and HMAC.
     * Nothing is stored, but the HMAC covers $visitor, so a state minted for
     * one browser fails in another. That is what stops login CSRF: an attacker's
     * own callback URL cannot be replayed in a victim's browser.
     *
     * @param string $visitor Identifies this browser, e.g. session_id(). Must stay the same until the callback.
     * @return string
     * @throws \InvalidArgumentException When $visitor is empty (session_id() is '' before session_start()).
     */
    public function newState(string $visitor): string
    {
        if ($visitor === '') {
            throw new \InvalidArgumentException(
                'newState() needs a non-empty visitor value, e.g. session_id() after session_start().',
            );
        }
        $payload = bin2hex(random_bytes(12)) . '.' . time();

        return $payload . '-' . $this->signState($payload, $visitor);
    }

    /**
     * @param string $state The state value Eventbrite returned with the redirect.
     * @param string $visitor The same value that was passed to newState().
     * @param int $maxAge Seconds the state stays valid.
     * @return bool True when newState() minted the value for $visitor within $maxAge, untampered. Not single-use.
     */
    public function verifyState(string $state, string $visitor, int $maxAge = self::STATE_TTL): bool
    {
        if ($visitor === '' || preg_match('/^([0-9a-f]{24}\.(\d+))-([0-9a-f]{64})$/D', $state, $parts) !== 1) {
            return false;
        }

        return hash_equals($this->signState($parts[1], $visitor), $parts[3])
            && time() - (int)$parts[2] <= $maxAge;
    }

    private function signState(string $payload, string $visitor): string
    {
        return hash_hmac('sha256', 'eventbrite-oauth|' . $payload . '|' . $visitor, $this->salt);
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
