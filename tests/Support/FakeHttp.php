<?php

declare(strict_types=1);

namespace TheMusicDev\Eventbrite\Tests\Support;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Hand-rolled PSR-17 factories + recording PSR-18 client. No mock library.
 *
 * Responses are registered per method + URL prefix (or exact); requests the
 * production code sends are recorded (method, url, headers, body) so tests
 * assert the auth header and payload through `$stub->requests`.
 *
 * Matches are consumed in registration order and the matching entry re-queues
 * at the end of its key's list, so repeated requests to one URL rotate
 * through all registered responses for that URL.
 */
final class FakeHttp implements ClientInterface, RequestFactoryInterface, StreamFactoryInterface
{
    /** @var array<string, list<ResponseInterface>> Keyed by "METHOD /url-prefix". */
    public array $responses = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    private string $missingMessage = '';

    public function queue(string $method, string $urlPrefix, Response $response): void
    {
        $this->responses[$method . ' ' . $urlPrefix][] = $response;
    }

    /**
     * @param array<string, mixed> $data Body payload; encoded pretty for debuggability.
     */
    public static function jsonResponse(int $status, array $data): Response
    {
        return new Response($status, ['content-type' => 'application/json'], Utils::streamFor(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $target = (string)$request->getUri();
        $this->requests[] = [
            'method' => $method,
            'url' => $target,
            'headers' => $this->flat($request),
            'body' => (string)$request->getBody(),
        ];

        $key = $method . ' ';
        foreach ($this->responses as $pattern => $list) {
            if (!str_starts_with($pattern, $key)) {
                continue;
            }
            $prefix = substr($pattern, strlen($key));
            if (str_starts_with($target, $prefix)) {
                $queued = $list[0];
                // rotate: consumed response re-queues, so repeated requests
                // to one URL walk through every registered response in order.
                $this->responses[$pattern] = array_merge(array_slice($list, 1), [$queued]);
                // fresh body stream per delivery: rotation may hand the same
                // instance back later, and a consumed stream reads empty.
                $headers = [];
                foreach ($queued->getHeaders() as $name => $values) {
                    $headers[$name] = $values[0];
                }

                return new Response($queued->getStatusCode(), $headers, (string)$queued->getBody());
            }
        }

        $this->missingMessage = sprintf('No queued response for %s to %s', $method, $target);
        throw new \RuntimeException($this->missingMessage);
    }

    /**
     * @param array<string, mixed> $headers
     * @param mixed $body Anything Guzzle's Request accepts (string|StreamInterface).
     */
    public function createRequest(string $method, $uri, array $headers = [], $body = null, string $protocolVersion = '1.1'): RequestInterface
    {
        return new Request($method, $uri, $headers, $body, $protocolVersion);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return Utils::streamFor($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return Utils::streamFor(fopen($filename, $mode));
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return Utils::streamFor($resource);
    }

    public function missingMessage(): string
    {
        return $this->missingMessage;
    }

    /**
     * @return array<string, string> First value of each header, lower-cased.
     */
    private function flat(RequestInterface $request): array
    {
        $out = [];
        foreach ($request->getHeaders() as $name => $values) {
            $out[strtolower($name)] = $values[0] ?? '';
        }

        return $out;
    }
}
