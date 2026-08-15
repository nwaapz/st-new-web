<?php
declare(strict_types=1);

/**
 * Shared boilerplate for mechanic-*.php endpoints — require a logged-in
 * site user who already completed mechanic signup, scoped to their mechanic_id.
 * Include after _common.php + _auth.php.
 */
require_once dirname(__DIR__) . '/cms/lib/mechanics.php';
require_once dirname(__DIR__) . '/cms/lib/mechanic-catalog.php';

/**
 * @return array{id:int, workshop_name:string, owner_name:string, city:string, phone:string, user_id:int, status:string, status_note:?string}
 */
function mechanic_api_require(PDO $pdo): array
{
    site_auth_ensure_schema($pdo);
    mechanics_ensure_schema($pdo);

    $user = site_auth_current_user($pdo);
    if ($user === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    $mechanic = mechanics_find_by_user($pdo, (int) $user['id']);
    if ($mechanic === null) {
        api_error('ابتدا باید ثبت‌نام تعمیرگاه را تکمیل کنید', 403);
    }

    $status = mechanics_normalize_status((string) ($mechanic['status'] ?? 'active'));
    if ($status !== 'active') {
        api_error(mechanics_status_block_message($status, $mechanic['status_note'] ?? null), 403);
    }

    $mechanic['user_id'] = (int) $user['id'];
    return $mechanic;
}

function mechanic_api_request_json(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}
