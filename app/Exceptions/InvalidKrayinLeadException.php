<?php

namespace App\Exceptions;

use Exception;

/**
 * Exception thrown when an invalid Krayin lead ID is provided.
 * 
 * @package App\Exceptions
 * @author Bridge Service
 * @version 2.1
 */
class InvalidKrayinLeadException extends Exception
{
    /**
     * Create a new exception instance.
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = "Invalid Krayin lead ID", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
