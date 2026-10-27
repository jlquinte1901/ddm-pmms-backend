<?php

namespace App\Enums;

/**
 * A read-time monitoring state. It is never persisted and never changes the
 * project lifecycle status.
 */
enum ProjectIndicator: string
{
    case Normal = 'normal';
    case Critical = 'critical';
    case Overdue = 'overdue';
    case Paused = 'paused';
    case Completed = 'completed';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Critical => 'Critical',
            self::Overdue => 'Overdue',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Terminated => 'Terminated',
        };
    }
}
