<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The database points at a document version whose file is not available.
 */
final class FileMissingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The stored file is unavailable.');
    }
}
