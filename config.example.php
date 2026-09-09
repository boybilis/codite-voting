<?php
return [
    'app_name' => 'Assembly',
    'base_url' => 'https://your-domain.com', // Include a subfolder if used; no trailing slash.
    'environment' => 'production',
    'app_key' => 'REPLACE_WITH_64_RANDOM_HEX_CHARACTERS',
    'setup_key' => 'REPLACE_WITH_A_LONG_RANDOM_SECRET',
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'assembly', 'user' => 'root', 'password' => ''],
    'mail' => [
        'transport' => 'smtp', // "log" is allowed only in the local environment.
        'host' => 'smtp.hostinger.com', 'port' => 465, 'encryption' => 'ssl',
        'username' => 'elections@your-domain.com', 'password' => '',
        'from_email' => 'elections@your-domain.com', 'from_name' => 'Assembly Elections',
    ],
];
