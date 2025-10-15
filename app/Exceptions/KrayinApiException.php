<?php

namespace App\Exceptions;

use Exception;
use GuzzleHttp\Exception\RequestException;

/**
 * Custom exception for Krayin API errors with enhanced error handling
 * 
 * @package App\Exceptions
 * @author Bridge Service
 * @version 2.1
 */
class KrayinApiException extends Exception
{
    protected array $context;
    protected ?RequestException $originalException;
    protected string $operation;
    protected int $attempts;
    protected ?int $httpStatusCode;
    protected ?string $responseBody;

    /**
     * Create a new KrayinApiException instance
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param array $context Additional context for logging
     * @param string|null $operation The API operation that failed
     * @param int $attempts Number of retry attempts made
     * @param RequestException|null $originalException The original Guzzle exception
     */
    public function __construct(
        string $message = "Krayin API request failed",
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = [],
        ?string $operation = null,
        int $attempts = 0,
        ?RequestException $originalException = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
        $this->operation = $operation ?? 'unknown';
        $this->attempts = $attempts;
        $this->originalException = $originalException;

        // Extract HTTP status code and response body from original exception
        if ($originalException && $originalException->hasResponse()) {
            $this->httpStatusCode = $originalException->getResponse()->getStatusCode();
            $this->responseBody = $originalException->getResponse()->getBody()->getContents();
        }
    }

    /**
     * Get the operation that failed
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Get the number of attempts made
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Get the HTTP status code from the original response
     */
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    /**
     * Get the response body from the original response
     */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    /**
     * Get additional context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get the original Guzzle exception
     */
    public function getOriginalException(): ?RequestException
    {
        return $this->originalException;
    }

    /**
     * Check if this is a retryable error
     */
    public function isRetryable(): bool
    {
        // Don't retry client errors (4xx) except for rate limiting
        if ($this->httpStatusCode >= 400 && $this->httpStatusCode < 500) {
            return $this->httpStatusCode === 429; // Rate limiting
        }

        // Retry server errors (5xx) and network issues
        return $this->httpStatusCode >= 500 || $this->httpStatusCode === null;
    }

    /**
     * Get error code based on HTTP status
     */
    public function getErrorCode(): string
    {
        return match ($this->httpStatusCode) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            429 => 'RATE_LIMIT_EXCEEDED',
            500 => 'INTERNAL_SERVER_ERROR',
            502 => 'BAD_GATEWAY',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'UNKNOWN_ERROR'
        };
    }

    /**
     * Get user-friendly error message
     */
    public function getUserMessage(): string
    {
        return match ($this->httpStatusCode) {
            400 => 'Invalid request data provided',
            401 => 'Authentication failed. Please check your API credentials',
            403 => 'Access denied. Insufficient permissions',
            404 => 'The requested resource was not found',
            409 => 'Resource conflict. The resource may already exist',
            422 => 'Validation failed. Please check your input data',
            429 => 'Rate limit exceeded. Please try again later',
            500 => 'Internal server error. Please try again later',
            502 => 'Service temporarily unavailable. Please try again later',
            503 => 'Service temporarily unavailable. Please try again later',
            default => 'An unexpected error occurred'
        };
    }

    /**
     * Convert to array for logging
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'operation' => $this->operation,
            'attempts' => $this->attempts,
            'http_status_code' => $this->httpStatusCode,
            'error_code' => $this->getErrorCode(),
            'is_retryable' => $this->isRetryable(),
            'context' => $this->context,
            'response_body' => $this->responseBody,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }
}
