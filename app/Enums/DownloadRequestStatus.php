<?php

namespace App\Enums;

enum DownloadRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Cancelled = 'cancelled';
}
