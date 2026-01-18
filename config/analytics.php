<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Anonymize IP Addresses
    |--------------------------------------------------------------------------
    |
    | If true, IP addresses will be anonymized (hashed/masked) before storage.
    | Recommended for GDPR compliance.
    |
    */
    'anonymize_ips' => env('ANALYTICS_ANONYMIZE_IPS', false),

    /*
    |--------------------------------------------------------------------------
    | Data Retention (Days)
    |--------------------------------------------------------------------------
    |
    | How many days to keep page view data before automatically pruning it.
    | Set to 0 to potential disable pruning (though logic would need adjustment).
    |
    */
    'retention_days' => env('ANALYTICS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Ignore Admins
    |--------------------------------------------------------------------------
    |
    | If true, page views from users with the 'admin' or 'super_admin' roles
    | will not be tracked.
    |
    */
    'ignore_admins' => env('ANALYTICS_IGNORE_ADMINS', true),

    /*
    |--------------------------------------------------------------------------
    | Ignored Roles
    |--------------------------------------------------------------------------
    |
    | Specific roles to ignore, regardless of the ignore_admins setting.
    |
    */
    'ignore_roles' => [
        'super_admin',
        // 'admin', // Uncomment to explicitly ignore admin role if ignore_admins is false
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored IPs
    |--------------------------------------------------------------------------
    |
    | List of IP addresses or CIDR ranges to ignore.
    | Useful for excluding internal office networks.
    |
    */
    'ignore_ips' => [
        '127.0.0.1',
        // '192.168.0.0/16',
    ],
];
