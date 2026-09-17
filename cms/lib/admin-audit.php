<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';

function admin_audit_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_audit_log (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          admin_user_id INT UNSIGNED NULL,
          admin_username VARCHAR(64) NOT NULL DEFAULT '',
          action VARCHAR(64) NOT NULL,
          entity_type VARCHAR(32) NULL,
          entity_id INT UNSIGNED NULL,
          entity_label VARCHAR(191) NULL,
          summary VARCHAR(512) NOT NULL,
          detail_json TEXT NULL,
          source VARCHAR(16) NOT NULL DEFAULT 'cms',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_admin_audit_created (created_at),
          KEY idx_admin_audit_admin (admin_user_id),
          KEY idx_admin_audit_action (action),
          KEY idx_admin_audit_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

/** @return array<string, string> */
function admin_audit_action_labels(): array
{
    return [
        'order.accept' => 'تأیید انبار سفارش',
        'order.reject' => 'رد سفارش',
        'order.cancel' => 'لغو سفارش',
        'order.mark_paid' => 'تأیید پرداخت',
        'order.mark_shipped' => 'ارسال مرسوله',
        'order.mark_not_received' => 'عدم دریافت مرسوله',
        'order.mark_returned' => 'برگشت به مبدأ',
        'order.mark_lost' => 'مفقود شدن مرسوله',
        'order.mark_received' => 'تأیید دریافت',
        'order.warn_payment' => 'هشدار نقص مدارک',
        'order.save_prices' => 'ذخیره قیمت سفارش',
        'order.issue_pre_invoice' => 'صدور پیش‌فاکتور',
        'order.issue_final_invoice' => 'صدور فاکتور نهایی',
        'order.cheque.add' => 'ثبت چک',
        'order.cheque.result' => 'نتیجه چک',
        'order.cheque.delete' => 'حذف چک',
        'product.save' => 'ذخیره محصول',
        'product.delete' => 'حذف محصول',
        'category.save' => 'ذخیره دسته',
        'category.delete' => 'حذف دسته',
        'factory.save' => 'ذخیره کارخانه',
        'factory.delete' => 'حذف کارخانه',
        'car_model.save' => 'ذخیره مدل خودرو',
        'car_model.delete' => 'حذف مدل خودرو',
        'product_series.save' => 'ذخیره سری محصول',
        'product_series.delete' => 'حذف سری محصول',
        'price_import.apply' => 'اعمال ورود قیمت',
        'price_import.clear' => 'پاک کردن ورود قیمت',
        'media.upload' => 'آپلود رسانه',
        'media.delete' => 'حذف رسانه',
        'sales_user.save' => 'ذخیره کاربر فروش',
        'sales_user.delete' => 'حذف کاربر فروش',
        'message.reply' => 'پاسخ پیام مشتری',
        'branch_message.reply' => 'پاسخ پیام نماینده',
        'branch_ticket.reply' => 'پاسخ تیکت',
        'branch_ticket.close' => 'بستن تیکت',
        'branch_ticket.reopen' => 'باز کردن تیکت',
        'admin_user.save' => 'ذخیره مدیر CMS',
        'admin_user.delete' => 'حذف مدیر CMS',
        'settings.save' => 'ذخیره تنظیمات',
        'content.save' => 'ذخیره محتوا',
        'product_review.moderate' => 'بررسی نظر محصول',
    ];
}

function admin_audit_action_label(string $action): string
{
    $labels = admin_audit_action_labels();

    return $labels[$action] ?? $action;
}

/** @return list<string> */
function admin_audit_categories(): array
{
    return ['order', 'catalog', 'media', 'comms', 'settings', 'admin'];
}

function admin_audit_category_for_action(string $action): string
{
    if (str_starts_with($action, 'order.')) {
        return 'order';
    }
    if (str_starts_with($action, 'product.') || str_starts_with($action, 'category.')
        || str_starts_with($action, 'factory.') || str_starts_with($action, 'car_model.')
        || str_starts_with($action, 'product_series.') || str_starts_with($action, 'price_import.')
        || str_starts_with($action, 'product_review.')) {
        return 'catalog';
    }
    if (str_starts_with($action, 'media.')) {
        return 'media';
    }
    if (str_starts_with($action, 'message.') || str_starts_with($action, 'branch_')) {
        return 'comms';
    }
    if (str_starts_with($action, 'admin_user.')) {
        return 'admin';
    }

    return 'settings';
}

/**
 * @param array<string, mixed> $ctx entity_type, entity_id, entity_label, summary, detail (array)
 */
function cms_admin_audit(PDO $pdo, string $action, array $ctx = []): void
{
    admin_audit_ensure_schema($pdo);

    $admin = cms_current_admin();
    if ($admin === null) {
        return;
    }

    $summary = trim((string) ($ctx['summary'] ?? ''));
    if ($summary === '') {
        $summary = $admin['username'] . ' — ' . admin_audit_action_label($action);
    }

    $detail = $ctx['detail'] ?? null;
    $detailJson = null;
    if (is_array($detail) && $detail !== []) {
        $encoded = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $detailJson = $encoded !== false ? $encoded : null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO admin_audit_log
         (admin_user_id, admin_username, action, entity_type, entity_id, entity_label, summary, detail_json, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $admin['id'],
        $admin['username'],
        $action,
        isset($ctx['entity_type']) ? (string) $ctx['entity_type'] : null,
        isset($ctx['entity_id']) ? (int) $ctx['entity_id'] : null,
        isset($ctx['entity_label']) ? (string) $ctx['entity_label'] : null,
        $summary,
        $detailJson,
        'cms',
    ]);
}

/**
 * @param array{admin?:string,category?:string,q?:string} $filters
 * @return array{items:list<array<string,mixed>>,total:int,page:int,total_pages:int}
 */
function admin_audit_list(PDO $pdo, array $filters = [], int $page = 1, int $pageSize = 40): array
{
    admin_audit_ensure_schema($pdo);
    $page = max(1, $page);
    $where = ['1=1'];
    $params = [];

    $adminFilter = trim((string) ($filters['admin'] ?? ''));
    if ($adminFilter !== '') {
        $where[] = 'admin_username LIKE ?';
        $params[] = '%' . $adminFilter . '%';
    }

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '' && in_array($category, admin_audit_categories(), true)) {
        if ($category === 'order') {
            $where[] = "action LIKE 'order.%'";
        } elseif ($category === 'catalog') {
            $where[] = "(action LIKE 'product.%' OR action LIKE 'category.%' OR action LIKE 'factory.%'
                OR action LIKE 'car_model.%' OR action LIKE 'product_series.%' OR action LIKE 'price_import.%'
                OR action LIKE 'product_review.%')";
        } elseif ($category === 'media') {
            $where[] = "action LIKE 'media.%'";
        } elseif ($category === 'comms') {
            $where[] = "(action LIKE 'message.%' OR action LIKE 'branch_%')";
        } elseif ($category === 'admin') {
            $where[] = "action LIKE 'admin_user.%'";
        } else {
            $where[] = "(action LIKE 'settings.%' OR action LIKE 'content.%')";
        }
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(summary LIKE ? OR entity_label LIKE ? OR action LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_log WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    $stmt = $pdo->prepare(
        "SELECT * FROM admin_audit_log WHERE {$whereSql} ORDER BY id DESC LIMIT {$pageSize} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $items[] = admin_audit_serialize_row($row);
    }

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function admin_audit_serialize_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'admin_user_id' => isset($row['admin_user_id']) ? (int) $row['admin_user_id'] : null,
        'admin_username' => (string) ($row['admin_username'] ?? ''),
        'action' => (string) ($row['action'] ?? ''),
        'action_label' => admin_audit_action_label((string) ($row['action'] ?? '')),
        'entity_type' => isset($row['entity_type']) ? (string) $row['entity_type'] : null,
        'entity_id' => isset($row['entity_id']) ? (int) $row['entity_id'] : null,
        'entity_label' => isset($row['entity_label']) ? (string) $row['entity_label'] : null,
        'summary' => (string) ($row['summary'] ?? ''),
        'source' => (string) ($row['source'] ?? 'cms'),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function admin_audit_entity_href(?string $entityType, ?int $entityId): ?string
{
    if ($entityType === null || $entityId === null || $entityId <= 0) {
        return null;
    }

    switch ($entityType) {
        case 'order':
            return 'orders.php?id=' . $entityId;
        case 'product':
            return 'products.php?edit=' . $entityId;
        case 'category':
            return 'categories.php?edit=' . $entityId;
        case 'factory':
            return 'factories.php?edit=' . $entityId;
        case 'car_model':
            return 'car-models.php?edit=' . $entityId;
        case 'product_series':
            return 'product-series.php?edit=' . $entityId;
        case 'sales_user':
            return 'sales-users.php?edit=' . $entityId;
        case 'admin_user':
            return 'admin-users.php?edit=' . $entityId;
        default:
            return null;
    }
}

function orders_admin_audit(PDO $pdo, array $order, string $action, ?array $detail = null): void
{
    $admin = cms_current_admin();
    $user = $admin['username'] ?? 'admin';
    $code = (string) ($order['public_code'] ?? '—');
    $orderId = (int) ($order['id'] ?? 0);

    switch ($action) {
        case 'accept':
            $verb = 'سفارش ' . $code . ' را تأیید انبار کرد';
            break;
        case 'reject':
            $verb = 'سفارش ' . $code . ' را رد انبار کرد';
            break;
        case 'cancel':
            $verb = 'سفارش ' . $code . ' را لغو کرد';
            break;
        case 'mark_paid':
            $verb = 'پرداخت سفارش ' . $code . ' را تأیید کرد';
            break;
        case 'mark_shipped':
            $verb = 'سفارش ' . $code . ' را ارسال کرد';
            break;
        case 'mark_not_received':
            $verb = 'سفارش ' . $code . ' را «هنوز دریافت نشده» ثبت کرد';
            break;
        case 'mark_returned':
            $verb = 'مرسوله سفارش ' . $code . ' را «برگشت به مبدأ» ثبت کرد';
            break;
        case 'mark_lost':
            $verb = 'مرسوله سفارش ' . $code . ' را «مفقود» ثبت کرد';
            break;
        case 'mark_received':
            $verb = 'دریافت سفارش ' . $code . ' را تأیید کرد';
            break;
        case 'warn_payment':
            $verb = 'برای سفارش ' . $code . ' هشدار نقص مدارک فرستاد';
            break;
        case 'save_prices':
            $verb = 'قیمت‌های سفارش ' . $code . ' را ذخیره کرد';
            break;
        case 'issue_pre_invoice':
            $verb = 'پیش‌فاکتور سفارش ' . $code . ' را صادر کرد';
            break;
        case 'issue_final_invoice':
            $verb = 'فاکتور نهایی سفارش ' . $code . ' را صادر کرد';
            break;
        case 'add_cheque':
            $verb = 'چک جدید برای سفارش ' . $code . ' ثبت کرد';
            break;
        case 'set_cheque_result':
            $verb = 'نتیجه چک سفارش ' . $code . ' را ثبت کرد';
            break;
        case 'delete_cheque':
            $verb = 'چک سفارش ' . $code . ' را حذف کرد';
            break;
        default:
            $verb = 'سفارش ' . $code . ' را به‌روز کرد';
            break;
    }

    switch ($action) {
        case 'add_cheque':
            $auditAction = 'order.cheque.add';
            break;
        case 'set_cheque_result':
            $auditAction = 'order.cheque.result';
            break;
        case 'delete_cheque':
            $auditAction = 'order.cheque.delete';
            break;
        case 'issue_final_invoice':
            $auditAction = 'order.issue_final_invoice';
            break;
        default:
            $auditAction = 'order.' . $action;
            break;
    }

    cms_admin_audit($pdo, $auditAction, [
        'entity_type' => 'order',
        'entity_id' => $orderId,
        'entity_label' => $code,
        'summary' => $user . ' ' . $verb,
        'detail' => $detail,
    ]);
}

function cms_audit_catalog_save(
    PDO $pdo,
    string $action,
    string $entityType,
    int $entityId,
    string $label,
    bool $isUpdate
): void {
    $user = cms_current_username();
    $verb = $isUpdate ? ' را به‌روز کرد' : ' را اضافه کرد';
    cms_admin_audit($pdo, $action, [
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'entity_label' => $label,
        'summary' => $user . ' «' . $label . '»' . $verb,
    ]);
}

function cms_audit_catalog_delete(
    PDO $pdo,
    string $action,
    string $entityType,
    int $entityId,
    string $label
): void {
    $user = cms_current_username();
    cms_admin_audit($pdo, $action, [
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'entity_label' => $label,
        'summary' => $user . ' «' . $label . '» را حذف کرد',
    ]);
}

function cms_audit_content(PDO $pdo, string $pageLabel, string $action = 'content.save'): void
{
    cms_admin_audit($pdo, $action, [
        'entity_type' => 'content',
        'entity_label' => $pageLabel,
        'summary' => cms_current_username() . ' «' . $pageLabel . '» را ذخیره کرد',
    ]);
}

function cms_audit_settings(PDO $pdo, string $label): void
{
    cms_admin_audit($pdo, 'settings.save', [
        'entity_type' => 'settings',
        'entity_label' => $label,
        'summary' => cms_current_username() . ' تنظیمات «' . $label . '» را ذخیره کرد',
    ]);
}

function cms_audit_simple(PDO $pdo, string $action, string $summary, ?string $entityType = null, ?int $entityId = null, ?string $entityLabel = null): void
{
    cms_admin_audit($pdo, $action, array_filter([
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'entity_label' => $entityLabel,
        'summary' => $summary,
    ], static fn ($v) => $v !== null && $v !== ''));
}
