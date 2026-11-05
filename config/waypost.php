<?php

declare(strict_types=1);

return [

    /*
    | HTTP API. Every endpoint lives under this prefix; the middleware runs before the package's own
    | middleware (authentication, throttling, idempotency, schema validation). Errors of every request under
    | the prefix, also of an unknown endpoint, are answered in the API's error format.
    */
    'route' => [
        'prefix' => env('WAYPOST_ROUTE_PREFIX', 'api/waypost/v1'),
        'middleware' => ['api'],
    ],

    /*
    | Name of the package's auth guard (its own token table). The package adds it to auth.guards; if the
    | host application has a guard of this name already, the application stops with an error: pick
    | another name here instead of losing the host's guard.
    */
    'guard' => env('WAYPOST_GUARD', 'waypost'),

    /*
    | Rate limits of the API, in requests per minute: "api" per token owner (user or device), "login" per
    | e-mail address and IP address, "login_ip" per IP address (whatever the address tried; behind a proxy
    | the host's TrustProxies decides the IP), "pin" per token owner on the requests that give a PIN (each of them
    | hashes the PIN again for every user of the sites, a second or two of CPU). Above the limit: 429
    | rate_limited with a Retry-After header.
    */
    'rate_limits' => [
        'api' => (int) env('WAYPOST_RATE_LIMIT', 240),
        'login' => (int) env('WAYPOST_LOGIN_RATE_LIMIT', 5),
        'login_ip' => (int) env('WAYPOST_LOGIN_IP_RATE_LIMIT', 30),
        'pin' => (int) env('WAYPOST_PIN_RATE_LIMIT', 20),
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
    | Milliseconds a changed record waits before GET /sync/pull sends it (Pull::SETTLE_MS by default): the
    | transaction that wrote it may not have committed yet. It must exceed the longest transaction and the
    | clock difference of the application servers, or a change may be missed for good.
    */
    'pull' => [
        'settle_ms' => (int) env('WAYPOST_PULL_SETTLE_MS', 5000),
    ],

    /*
    | Signed URLs of the private files (they need no token): a photo's link in a report lives until the data
    | retention deletes the photo; the link of a stored report file in the report archive (GET /reports) lives
    | this many minutes.
    */
    'signed_urls' => [
        'report_ttl_min' => (int) env('WAYPOST_REPORT_URL_TTL_MIN', 60),
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
