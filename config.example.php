<?php
return [
    'app_name' => 'Assembly by iBarakoTech',
    'base_url' => require __DIR__ . '/app/site.php', // Production URL; local mode may override this.
    'environment' => 'production',
    'app_key' => 'REPLACE_WITH_64_RANDOM_HEX_CHARACTERS',
    'setup_key' => 'REPLACE_WITH_A_LONG_RANDOM_SECRET',
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'assembly', 'user' => 'root', 'password' => ''],
    'mail' => [
        'transport' => 'smtp', // "log" is allowed only in the local environment.
        'host' => 'smtp.hostinger.com', 'port' => 465, 'encryption' => 'ssl',
        'username' => 'REPLACE_WITH_YOUR_MAILBOX_ADDRESS', 'password' => '',
        'from_email' => 'REPLACE_WITH_YOUR_MAILBOX_ADDRESS', 'from_name' => 'Assembly by iBarakoTech',
    ],
];
