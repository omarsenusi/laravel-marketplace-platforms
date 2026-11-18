<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Host models and tables
    |--------------------------------------------------------------------------
    |
    | The package owns the marketplace connection rows; the host app owns
    | users and workspaces. Point these at your own models. Relations
    | (connection → workspace, claim → user/workspace) resolve through
    | this config, so no host class is ever referenced directly.
    |
    | Key types must match: the package migrations create
    | `platform_connections.workspace_id` as a uuid foreign key and
    | `workspace_claims.user_id` as an unsigned-bigint foreign key.
    | Override `tables.*` when your table names differ.
    |
    */
    'models' => [
        'user' => env('PLATFORMS_USER_MODEL', 'App\\Models\\User'),
        'workspace' => env('PLATFORMS_WORKSPACE_MODEL', 'App\\Models\\Workspace'),
    ],

    'tables' => [
        'users' => env('PLATFORMS_USERS_TABLE', 'users'),
        'workspaces' => env('PLATFORMS_WORKSPACES_TABLE', 'workspaces'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound webhooks
    |--------------------------------------------------------------------------
    |
    | queue:    queue connection-name jobs are dispatched onto (the job
    |           manages its own retries, so no retry-after tuning needed).
    | throttle: rate limit for the public ingress endpoint, in the
    |           "maxAttempts,decayMinutes" format Laravel's throttle
    |           middleware expects.
    |
    */
    'webhooks' => [
        'queue' => env('PLATFORMS_WEBHOOKS_QUEUE', 'default'),
        'throttle' => env('PLATFORMS_WEBHOOKS_THROTTLE', '600,1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspace claims
    |--------------------------------------------------------------------------
    |
    | ttl_hours: how long a claim link stays valid. max_reminders caps
    | the daily reminder command (sent at days 1/3/7 after first claim).
    |
    */
    'claim' => [
        'ttl_hours' => env('PLATFORMS_CLAIM_TTL_HOURS', 72),
        'max_reminders' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedded launch
    |--------------------------------------------------------------------------
    |
    | frame_ancestors: which origins may iframe the launch response
    | (the Salla / Zid store dashboards). Applied by the package's
    | AllowEmbeddedFrames middleware on the launch route only.
    |
    */
    'embedded' => [
        'frame_ancestors' => env('PLATFORMS_EMBEDDED_FRAME_ANCESTORS', "'self' https://s.salla.sa https://*.salla.sa https://*.salla.dev https://web.zid.sa https://*.zid.sa"),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token refresh
    |--------------------------------------------------------------------------
    |
    | lock_wait_seconds: how long RefreshPlatformToken waits for the
    | per-connection lock before requeueing (lets the holder finish
    | instead of double-refreshing on a stale copy).
    |
    */
    'refresh' => [
        'lock_wait_seconds' => env('PLATFORMS_REFRESH_LOCK_WAIT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-store outbound API gateway
    |--------------------------------------------------------------------------
    |
    | rate_limits: per-driver per-minute budgets for outbound Salla/Zid
    | API calls, split into lanes by StoreApiGateway so bulk readers
    | never starve interactive calls.
    |
    | lanes: share of the per-minute budget each lane may spend.
    | `realtime` is the default (orders, stock, shipments, settlements);
    | `background` is for bulk catalog work and additionally yields when
    | the last observed rate-limit headroom drops below 40%.
    |
    */
    'api' => [
        'rate_limits' => [
            'salla' => 120,
            'zid' => 60,
        ],
        'lanes' => [
            'realtime' => 0.90,
            'background' => 0.30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | brand_name: shown in claim / store-connected mail subjects when
    | set. Falls back to config('app.name').
    |
    */
    'mail' => [
        'brand_name' => env('PLATFORMS_MAIL_BRAND_NAME'),
    ],
];
