<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Protects append-only records at the application layer.
 *
 * Database-level grants are the final guarantee; this guard makes an
 * accidental Eloquent update or delete fail loudly during development and in
 * tests instead of silently rewriting history.
 */
trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(function (): never {
            throw new LogicException('Append-only records cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Append-only records cannot be deleted.');
        });
    }
}
