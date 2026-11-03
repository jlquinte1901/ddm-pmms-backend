<?php

namespace App\Enums;

enum DocumentAccessLevel: string
{
    case Restricted = 'restricted';
    case Confidential = 'confidential';
}
