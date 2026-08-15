<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
cms_logout();
cms_redirect('login.php');
