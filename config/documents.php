<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document Storage
    |--------------------------------------------------------------------------
    |
    | Documents are stored on a private disk. The disk name is recorded on each
    | version so a future disk migration does not invalidate existing rows.
    |
    */

    'disk' => env('DOCUMENT_STORAGE_DISK', 'local'),

    'max_size_kb' => (int) env('DOCUMENT_MAX_SIZE_KB', 51200),

    'allowed_extensions' => [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'jpg',
        'jpeg',
        'png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Download Requests
    |--------------------------------------------------------------------------
    |
    | Approvals are valid until `expires_at`. Pending requests older than the
    | lapse period are treated as lapsed at read time; no scheduled job changes
    | their stored status.
    |
    */

    'download' => [
        'default_approval_days' => (int) env('DOCUMENT_DOWNLOAD_APPROVAL_DAYS', 7),
        'max_approval_days' => (int) env('DOCUMENT_DOWNLOAD_MAX_APPROVAL_DAYS', 30),
        'pending_lapse_days' => (int) env('DOCUMENT_DOWNLOAD_PENDING_LAPSE_DAYS', 14),
    ],

];
