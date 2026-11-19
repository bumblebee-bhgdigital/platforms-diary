<?php

namespace Platform\Diary\Exceptions;

use RuntimeException;

/** Fachlicher Fehler im Diary-Service (Validierung, ungültiger Übergang). */
class DiaryException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'VALIDATION_ERROR')
    {
        parent::__construct($message);
    }
}
