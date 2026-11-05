<?php

declare(strict_types=1);

namespace TheMusicDev\Eventbrite;

/**
 * Thrown for any Eventbrite HTTP response with a non-2xx status code.
 *
 * Carries the HTTP status and the raw error body (Eventbrite typically returns
 * {"error": "...", "error_description": "..."}).
 */
class EventbriteApiException extends \RuntimeException
{
    private int $statusCode;

    private string $errorBody;

    /**
     * @param int $statusCode The HTTP status code of the response.
     * @param string $errorBody The raw response body.
     * @param string $url The request URL (for the message; the token is never included).
     */
    public function __construct(int $statusCode, string $errorBody, string $url)
    {
        $this->statusCode = $statusCode;
        $this->errorBody = $errorBody;

        $parsed = json_decode($errorBody, true);
        $detail = '';
        if (is_array($parsed)) {
            $error = is_scalar($parsed['error'] ?? null) ? (string)$parsed['error'] : '';
            $description = is_scalar($parsed['error_description'] ?? null) ? (string)$parsed['error_description'] : '';
            $detail = trim($error . ' ' . $description);
        }
        $detail = $detail !== '' ? $detail : $errorBody;

        parent::__construct(
            sprintf('Eventbrite API returned HTTP %d for %s: %s', $statusCode, $url, $detail),
        );
    }

    /**
     * @return int The HTTP status code of the failed response.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return string The raw response body of the failed response.
     */
    public function getErrorBody(): string
    {
        return $this->errorBody;
    }
}
