<?php
/**
 * Copy to config.local.php and fill MySQL + admin credentials.
 * config.local.php is gitignored — never commit real passwords.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'your_database_name',
    'db_user' => 'your_database_user',
    'db_pass' => 'your_database_password',
    'db_charset' => 'utf8mb4',

    // Optional: startech_sms DB (old_serials.score) for seller credit wallet.
    // Leave blank / omit to disable live score lookup.
    'sms_db_host' => 'localhost',
    'sms_db_name' => 'startech_sms',
    'sms_db_user' => 'startech_sms',
    'sms_db_pass' => '',
    'sms_db_charset' => 'utf8mb4',

    // Used only by install.php when creating the first admin
    'admin_username' => 'admin',
    'admin_password' => 'change-me-strong-password',

    // Optional secret for GET /api/mechanic-km-cron.php?key=...
    // If omitted, a key is generated and stored in site_settings.
    'km_cron_key' => '',

    // Skip the 09:00–21:00 customer-club SMS window (localhost is always exempt).
    'sms_test_mode' => false,
];
