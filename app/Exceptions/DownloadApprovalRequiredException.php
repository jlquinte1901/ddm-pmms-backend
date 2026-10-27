<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The document requires an approved, unexpired download request.
 */
final class DownloadApprovalRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An approved download request is required for this document.');
    }
}
