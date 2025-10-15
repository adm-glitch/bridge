<?php

namespace App\Exceptions;

use Exception;
use GuzzleHttp\Exception\RequestException;

/**
 * Custom exception for AI Insights errors with enhanced error handling
 */
class AiInsightsException extends Exception
{
    protected array $context;
    protected ?RequestException $originalException;
    protected string $operation;
    protected int $attempts;
    protected ?int $httpStatusCode;
    protected ?string $responseBody;

    public function __construct(
        string $message = "AI Insights operation failed",
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

        if ($originalException && $originalException->hasResponse()) {
            $this->httpStatusCode = $originalException->getResponse()->getStatusCode();
            $this->responseBody = $originalException->getResponse()->getBody()->getContents();
        }
    }

    public function getOperation(): string
    {
        return $this->operation;
    }
    public function getAttempts(): int
    {
        return $this->attempts;
    }
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
    public function getContext(): array
    {
        return $this->context;
    }
    public function getOriginalException(): ?RequestException
    {
        return $this->originalException;
    }

    public function isRetryable(): bool
    {
        if ($this->httpStatusCode >= 400 && $this->httpStatusCode < 500) {
            return $this->httpStatusCode === 429; // rate limit
        }
        return $this->httpStatusCode >= 500 || $this->httpStatusCode === null;
    }

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

    public function getUserMessage(): string
    {
        return match ($this->httpStatusCode) {
            400 => 'Invalid request data provided',
            401 => 'Authentication failed',
            403 => 'Access denied',
            404 => 'Resource not found',
            409 => 'Resource conflict',
            422 => 'Validation failed',
            429 => 'Rate limit exceeded. Please try again later',
            500 => 'Internal server error. Please try again later',
            502 => 'Upstream service unavailable. Please try again later',
            503 => 'Service temporarily unavailable. Please try again later',
            default => 'An unexpected error occurred'
        };
    }

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
