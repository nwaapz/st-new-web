<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cms_require_login();
cms_redirect('customers.php');
