<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/application-logs.php';
$username = cms_current_username();
$userId = cms_current_admin_id();
cms_logout();
cms_app_log([
    'source_app' => 'web_crm',
    'severity' => 'INFO',
    'category' => 'authentication',
    'event_code' => 'cms_logout',
    'summary' => 'خروج از CRM',
    'user_type' => 'cms',
    'user_id' => $userId > 0 ? $userId : null,
    'user_label' => $username,
]);
cms_redirect('login.php');
