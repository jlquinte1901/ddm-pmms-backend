<?php

namespace App\Enums;

enum DocumentAccessLevel: string
{
    case Open = 'open';
    case Restricted = 'restricted';
    case Confidential = 'confidential';
}
