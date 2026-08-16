<?php
/**
 * Local XAMPP credentials — do not commit / survives deploy:xampp.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'st-new-web',
    'db_user' => 'root',
    'db_pass' => '',
    'db_charset' => 'utf8mb4',

    // Local SMS/warranty DB (same MariaDB instance; user is XAMPP root)
    'sms_db_host' => 'localhost',
    'sms_db_name' => 'startech_sms',
    'sms_db_user' => 'root',
    'sms_db_pass' => '',
    'sms_db_charset' => 'utf8mb4',

    'admin_username' => 'admin',
    'admin_password' => 'admin123',

    'km_cron_key' => '',
    'sms_test_mode' => true,
];
