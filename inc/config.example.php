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
    ],
];
