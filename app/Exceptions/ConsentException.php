<?php

namespace App\Exceptions;

use Exception;

/**
 * Custom exception for Consent Service errors with LGPD compliance
 * 
 * @package App\Exceptions
 * @author Bridge Service
 * @version 2.1
 */
class ConsentException extends Exception
{
    protected array $context;
    protected string $operation;
    protected ?int $contactId;
    protected ?string $consentType;
    protected string $errorCode;

    /**
     * Create a new ConsentException instance
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param array $context Additional context for logging
     * @param string|null $operation The operation that failed
     * @param int|null $contactId The contact ID involved
     * @param string|null $consentType The consent type involved
     * @param string $errorCode The specific error code
     */
    public function __construct(
        string $message = "Consent operation failed",
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = [],
        ?string $operation = null,
        ?int $contactId = null,
        ?string $consentType = null,
        string $errorCode = 'CONSENT_ERROR'
    ) {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
        $this->operation = $operation ?? 'unknown';
        $this->contactId = $contactId;
        $this->consentType = $consentType;
        $this->errorCode = $errorCode;
    }

    /**
     * Get the operation that failed
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Get the contact ID involved
     */
    public function getContactId(): ?int
    {
        return $this->contactId;
    }

    /**
     * Get the consent type involved
     */
    public function getConsentType(): ?string
    {
        return $this->consentType;
    }

    /**
     * Get the error code
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get additional context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Check if this is a retryable error
     */
    public function isRetryable(): bool
    {
        // Most consent operations are not retryable due to legal implications
        return in_array($this->errorCode, [
            'CONSENT_SERVICE_UNAVAILABLE',
            'CONSENT_DATABASE_ERROR',
            'CONSENT_CACHE_ERROR',
        ]);
    }

    /**
     * Get user-friendly error message
     */
    public function getUserMessage(): string
    {
        return match ($this->errorCode) {
            'CONSENT_NOT_FOUND' => 'Consent record not found',
            'CONSENT_ALREADY_EXISTS' => 'Consent already exists for this contact',
            'CONSENT_INVALID_TYPE' => 'Invalid consent type provided',
            'CONSENT_EXPIRED' => 'Consent has expired',
            'CONSENT_WITHDRAWN' => 'Consent has been withdrawn',
            'CONSENT_SERVICE_UNAVAILABLE' => 'Consent service is temporarily unavailable',
            'CONSENT_DATABASE_ERROR' => 'Database error occurred while processing consent',
            'CONSENT_CACHE_ERROR' => 'Cache error occurred while processing consent',
            'CONSENT_VALIDATION_ERROR' => 'Consent validation failed',
            'CONSENT_AUDIT_ERROR' => 'Failed to create audit log for consent operation',
            'CONSENT_PERMISSION_DENIED' => 'Insufficient permissions to perform consent operation',
            'CONSENT_RATE_LIMIT_EXCEEDED' => 'Too many consent operations. Please try again later',
            default => 'An unexpected error occurred with consent processing'
        };
    }

    /**
     * Get LGPD compliance status
     */
    public function getLgpdComplianceStatus(): string
    {
        return match ($this->errorCode) {
            'CONSENT_NOT_FOUND' => 'compliant', // No consent found is compliant
            'CONSENT_ALREADY_EXISTS' => 'compliant', // Existing consent is compliant
            'CONSENT_WITHDRAWN' => 'compliant', // Withdrawn consent is compliant
            'CONSENT_EXPIRED' => 'non_compliant', // Expired consent is non-compliant
            'CONSENT_VALIDATION_ERROR' => 'non_compliant', // Validation errors are non-compliant
            'CONSENT_AUDIT_ERROR' => 'non_compliant', // Audit failures are non-compliant
            default => 'unknown'
        };
    }

    /**
     * Check if this error requires immediate attention
     */
    public function requiresImmediateAttention(): bool
    {
        return in_array($this->errorCode, [
            'CONSENT_AUDIT_ERROR',
            'CONSENT_DATABASE_ERROR',
            'CONSENT_VALIDATION_ERROR',
        ]);
    }

    /**
     * Convert to array for logging
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'operation' => $this->operation,
            'contact_id' => $this->contactId,
            'consent_type' => $this->consentType,
            'error_code' => $this->errorCode,
            'is_retryable' => $this->isRetryable(),
            'lgpd_compliance_status' => $this->getLgpdComplianceStatus(),
            'requires_immediate_attention' => $this->requiresImmediateAttention(),
            'context' => $this->context,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }

    /**
     * Create a consent not found exception
     */
    public static function notFound(int $contactId, string $consentType): self
    {
        return new self(
            "Consent not found for contact {$contactId} and type {$consentType}",
            404,
            null,
            ['contact_id' => $contactId, 'consent_type' => $consentType],
            'getConsent',
            $contactId,
            $consentType,
            'CONSENT_NOT_FOUND'
        );
    }

    /**
     * Create a consent already exists exception
     */
    public static function alreadyExists(int $contactId, string $consentType): self
    {
        return new self(
            "Consent already exists for contact {$contactId} and type {$consentType}",
            409,
            null,
            ['contact_id' => $contactId, 'consent_type' => $consentType],
            'createConsent',
            $contactId,
            $consentType,
            'CONSENT_ALREADY_EXISTS'
        );
    }

    /**
     * Create a consent validation error exception
     */
    public static function validationError(string $message, array $errors = []): self
    {
        return new self(
            "Consent validation failed: {$message}",
            422,
            null,
            ['validation_errors' => $errors],
            'validateConsent',
            null,
            null,
            'CONSENT_VALIDATION_ERROR'
        );
    }

    /**
     * Create a consent audit error exception
     */
    public static function auditError(string $operation, int $contactId): self
    {
        return new self(
            "Failed to create audit log for consent operation: {$operation}",
            500,
            null,
            ['operation' => $operation, 'contact_id' => $contactId],
            'auditConsent',
            $contactId,
            null,
            'CONSENT_AUDIT_ERROR'
        );
    }
}
