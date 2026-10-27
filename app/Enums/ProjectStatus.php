<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case NotStarted = 'not_started';
    case Ongoing = 'ongoing';
    case Suspended = 'suspended';
    case Postponed = 'postponed';
    case Completed = 'completed';
    case Terminated = 'terminated';

    public function isPaused(): bool
    {
        return in_array($this, [self::Suspended, self::Postponed], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::NotStarted, self::Ongoing], true);
    }
}
