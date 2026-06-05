<?php

return [
    'app' => [
        'env' => 'production',
        'base_url' => 'https://ops.midwestmanagedit.com',
    ],

    'database' => [
        'host' => 'localhost',
        'name' => 'CHANGE_ME',
        'user' => 'CHANGE_ME',
        'pass' => 'CHANGE_ME',
    ],

    'mail' => [
        'provider' => 'graph',
        'tenant_id' => 'CHANGE_ME',
        'client_id' => 'CHANGE_ME',
        'client_secret' => 'CHANGE_ME',
        'from_billing' => 'billing@midwestmanagedit.com',
        'from_noreply' => 'noreply@midwestmanagedit.com',
        'from_support' => 'support@midwestmanagedit.com',
    ],

    'stripe' => [
        'publishable_key' => 'CHANGE_ME',
        'secret_key' => 'CHANGE_ME',
        'webhook_secret' => 'CHANGE_ME',
    ],

    'boldsign' => [
        'api_key' => 'CHANGE_ME',
    ],

    'onedrive' => [
        'tenant_id' => 'CHANGE_ME',
        'client_id' => 'CHANGE_ME',
        'client_secret' => 'CHANGE_ME',
        'drive_id' => 'CHANGE_ME',
    ],

    'syncro' => [
        'api_key' => 'CHANGE_ME',
        'subdomain' => 'CHANGE_ME',
        // Staging/testing only. Default false keeps POST/PUT/PATCH/DELETE blocked in OPS staging;
        // when true, only POST/PUT/PATCH are allowed and DELETE remains blocked.
        'allow_staging_writes' => false,
        'policy_assignments' => [
            // Configure real Syncro policy IDs only; null keeps assignment PENDING_MANUAL.
            'manage.deploy.workstations' => null,
            'manage.deploy.servers' => null,
            'manage.production.workstations' => null,
            'manage.production.servers' => null,
            'protect.deploy.workstations' => null,
            'protect.deploy.servers' => null,
            'protect.production.workstations' => null,
            'protect.production.servers' => null,
            'govern.deploy.workstations' => null,
            'govern.deploy.servers' => null,
            'govern.production.workstations' => null,
            'govern.production.servers' => null,
        ],
    ],
];
