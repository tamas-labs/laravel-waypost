<?php

declare(strict_types=1);

return [

    /*
    | HTTP API. Every endpoint lives under this prefix; the middleware runs before the package's own
    | middleware (authentication, schema validation, idempotency).
    */
    'route' => [
        'prefix' => env('WAYPOST_ROUTE_PREFIX', 'api/waypost/v1'),
        'middleware' => ['api'],
    ],

    /*
    | Prefix of every table the package creates. The package never touches the host application's
    | own tables. Change it only before the first migration.
    */
    'table_prefix' => env('WAYPOST_TABLE_PREFIX', 'waypost_'),

    /*
    | Filesystem disk for photos and PDF reports. It must be private: files are served only through
    | signed, expiring URLs.
    */
    'storage' => [
        'disk' => env('WAYPOST_DISK', 'local'),
    ],

    /*
    | Queue for the package's jobs (chain processing, reports, alerts). A null connection means the
    | application's default queue connection.
    */
    'queue' => [
        'connection' => env('WAYPOST_QUEUE_CONNECTION'),
        'queue' => env('WAYPOST_QUEUE', 'default'),
    ],

    /*
    | Sender of the report and alert e-mails. A null address means the application's mail.from.
    */
    'mail' => [
        'from' => [
            'address' => env('WAYPOST_MAIL_FROM_ADDRESS'),
            'name' => env('WAYPOST_MAIL_FROM_NAME', 'Waypost'),
        ],
    ],

];
