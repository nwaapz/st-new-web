<?php
declare(strict_types=1);

/**
 * Flash Deals / Special Offers.
 *
 * Promotional purchases are accepted only on the online order path
 * (orders_create_from_normalized with sms_channel = 0). SMS ingest and any
 * other deferred channel are rejected with FLASH_DEAL_ONLINE_REQUIRED.
 * Monetary values are integer toman. The server clock (UTC) is authoritative.
 */

require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/jalali.php';

class FlashDealException extends RuntimeException
{
    public string $errorCode;

    /** @var array<string, mixed> */
    public array $payload;

    public int $httpStatus;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $errorCode, string $message, int $httpStatus = 409, array $payload = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->payload = $payload;
    }
}

function flash_deals_emit_api_error(FlashDealException $e): void
{
    if (!function_exists('api_json')) {
        throw $e;
    }
    api_json(array_merge([
        'ok' => false,
        'error' => $e->getMessage(),
        'code' => $e->errorCode,
        'error_code' => $e->errorCode,
    ], $e->payload), $e->httpStatus);
}

/** @return list<string> */
function flash_deals_statuses(): array
{
    return ['draft', 'scheduled', 'active', 'paused', 'ended', 'archived'];
}

/** @return array<string, string> */
function flash_deals_status_labels(): array
{
    return [
        'draft' => 'پیش‌نویس',
        'scheduled' => 'زمان‌بندی‌شده',
        'active' => 'فعال',
        'paused' => 'متوقف',
        'ended' => 'پایان‌یافته',
        'archived' => 'بایگانی',
    ];
}

function flash_deals_now_utc(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function flash_deals_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS flash_deals (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          title VARCHAR(191) NOT NULL,
          description TEXT NULL,
          banner_image VARCHAR(512) NULL,
          starts_at DATETIME NOT NULL,
          ends_at DATETIME NOT NULL,
          status ENUM('draft','scheduled','active','paused','ended','archived') NOT NULL DEFAULT 'draft',
          display_priority INT NOT NULL DEFAULT 0,
          version INT UNSIGNED NOT NULL DEFAULT 1,
          created_by INT UNSIGNED NULL,
          updated_by INT UNSIGNED NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_flash_deals_status_window (status, starts_at, ends_at),
          KEY idx_flash_deals_priority (display_priority, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS flash_deal_products (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          flash_deal_id INT UNSIGNED NOT NULL,
          product_id INT UNSIGNED NOT NULL,
          regular_unit_price BIGINT NOT NULL,
          promo_unit_price BIGINT NOT NULL,
          discount_amount BIGINT NOT NULL,
          discount_percent DECIMAL(5,2) NOT NULL,
          per_order_qty_limit INT UNSIGNED NULL,
          per_customer_qty_limit INT UNSIGNED NULL,
          campaign_qty_limit INT UNSIGNED NULL,
          campaign_qty_sold INT UNSIGNED NOT NULL DEFAULT 0,
          eligibility_status ENUM('eligible','ineligible') NOT NULL DEFAULT 'eligible',
          display_priority INT NOT NULL DEFAULT 0,
          PRIMARY KEY (id),
          UNIQUE KEY uq_flash_deal_product (flash_deal_id, product_id),
          KEY idx_fdp_product (product_id),
          CONSTRAINT fk_fdp_deal FOREIGN KEY (flash_deal_id) REFERENCES flash_deals (id) ON DELETE CASCADE,
          CONSTRAINT fk_fdp_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $itemCols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM order_items')->fetchAll() ?: [] as $row) {
        $itemCols[(string) ($row['Field'] ?? '')] = true;
    }
    $adds = [
        'flash_deal_id' => 'ALTER TABLE order_items ADD COLUMN flash_deal_id INT UNSIGNED NULL',
        'flash_deal_title' => 'ALTER TABLE order_items ADD COLUMN flash_deal_title VARCHAR(191) NULL',
        'flash_deal_version' => 'ALTER TABLE order_items ADD COLUMN flash_deal_version INT UNSIGNED NULL',
        'regular_unit_price' => 'ALTER TABLE order_items ADD COLUMN regular_unit_price BIGINT NULL',
        'promo_unit_price' => 'ALTER TABLE order_items ADD COLUMN promo_unit_price BIGINT NULL',
        'discount_amount' => 'ALTER TABLE order_items ADD COLUMN discount_amount BIGINT NULL',
        'line_total' => 'ALTER TABLE order_items ADD COLUMN line_total BIGINT NULL',
    ];
    foreach ($adds as $col => $sql) {
        if (!isset($itemCols[$col])) {
            $pdo->exec($sql);
        }
    }
    $idx = $pdo->query("SHOW INDEX FROM order_items WHERE Key_name = 'idx_order_items_flash_deal'")->fetchAll();
    if ($idx === []) {
        $pdo->exec('ALTER TABLE order_items ADD KEY idx_order_items_flash_deal (flash_deal_id)');
    }

    $ready = true;
}

function flash_deals_parse_toman($raw): int
{
    if (is_int($raw)) {
        $amount = $raw;
    } elseif (is_float($raw)) {
        throw new InvalidArgumentException('قیمت باید عدد صحیح تومان باشد');
    } else {
        $amount = invoices_parse_toman_amount(trim((string) $raw));
        if ($amount === null) {
            throw new InvalidArgumentException('قیمت ویژه الزامی است');
        }
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('قیمت ویژه باید بزرگ‌تر از صفر باشد');
    }
    if ($amount > 999999999999) {
        throw new InvalidArgumentException('مبلغ تومان بیش از حد بزرگ است');
    }

    return $amount;
}

/**
 * @return array{amount:int,percent:string}
 */
function flash_deals_discount(int $regular, int $promo): array
{
    if ($regular <= 0) {
        throw new InvalidArgumentException('قیمت عادی محصول قابل محاسبه نیست');
    }
    if ($promo <= 0 || $promo >= $regular) {
        throw new InvalidArgumentException('قیمت ویژه باید کمتر از قیمت عادی باشد');
    }
    $amount = $regular - $promo;
    $hundredths = intdiv($amount * 10000 + intdiv($regular, 2), $regular);

    return [
        'amount' => $amount,
        'percent' => number_format($hundredths / 100, 2, '.', ''),
    ];
}

function flash_deals_input_to_utc(string $date, string $time): string
{
    $date = trim(cms_from_persian_digits($date));
    $time = trim(cms_from_persian_digits($time));
    if ($date === '' || $time === '') {
        throw new InvalidArgumentException('تاریخ و ساعت شروع و پایان الزامی است');
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $tm)) {
        throw new InvalidArgumentException('ساعت نامعتبر است');
    }
    $hour = (int) $tm[1];
    $minute = (int) $tm[2];
    if ($hour > 23 || $minute > 59) {
        throw new InvalidArgumentException('ساعت نامعتبر است');
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $g) && (int) $g[1] > 1700) {
        $gy = (int) $g[1];
        $gm = (int) $g[2];
        $gd = (int) $g[3];
    } else {
        $jalali = cms_jalali_parse_date($date);
        if ($jalali === null) {
            throw new InvalidArgumentException('تاریخ نامعتبر است');
        }
        [$gy, $gm, $gd] = cms_jalali_to_gregorian($jalali[0], $jalali[1], $jalali[2]);
    }

    try {
        $local = new DateTimeImmutable(
            sprintf('%04d-%02d-%02d %02d:%02d:00', $gy, $gm, $gd, $hour, $minute),
            new DateTimeZone('Asia/Tehran')
        );
    } catch (Throwable $e) {
        throw new InvalidArgumentException('تاریخ نامعتبر است');
    }

    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function flash_deals_utc_to_jalali_parts(?string $utc): array
{
    if ($utc === null || trim($utc) === '') {
        return ['date' => '', 'time' => ''];
    }
    $formatted = cms_jalali_format_datetime($utc);
    if (!preg_match('/^(\d{4}\/\d{2}\/\d{2}) - (\d{2}:\d{2})/', $formatted, $m)) {
        return ['date' => '', 'time' => ''];
    }

    return ['date' => $m[1], 'time' => $m[2]];
}

function flash_deals_nullable_limit($raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $text = trim(cms_from_persian_digits((string) $raw));
    if ($text === '' || $text === '0') {
        return null;
    }
    if (!preg_match('/^\d+$/', $text)) {
        throw new InvalidArgumentException('سقف تعداد نامعتبر است');
    }
    $value = (int) $text;
    if ($value < 1 || $value > 999999) {
        throw new InvalidArgumentException('سقف تعداد نامعتبر است');
    }

    return $value;
}

/**
 * @return array{id:?int,username:string}
 */
function flash_deals_actor(PDO $pdo): array
{
    if (!function_exists('admin_audit_current_user')) {
        require_once __DIR__ . '/admin-audit.php';
    }
    $admin = admin_audit_current_user($pdo);

    return $admin ?? ['id' => null, 'username' => ''];
}

/**
 * @param array<string, mixed> $detail
 */
function flash_deals_audit_failure(PDO $pdo, int $id, string $action, string $message): void
{
    if ($id <= 0) {
        return;
    }
    try {
        flash_deals_audit($pdo, 'flash_deal.failed', $id, 'پیشنهاد #' . $id, 'عملیات ناموفق (' . $action . '): ' . mb_substr($message, 0, 180), [
            'action' => $action,
        ]);
    } catch (Throwable $e) {
        error_log('[flash-deals audit] ' . $e->getMessage());
    }
}

function flash_deals_audit(PDO $pdo, string $action, int $id, string $label, string $summary, array $detail = []): void
{
    if (!function_exists('cms_admin_audit')) {
        require_once __DIR__ . '/admin-audit.php';
    }
    cms_admin_audit($pdo, $action, [
        'entity_type' => 'flash_deal',
        'entity_id' => $id,
        'entity_label' => $label,
        'summary' => $summary,
        'detail' => $detail,
    ]);
}

function flash_deals_catalog_price(PDO $pdo, int $productId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, slug, visual_id, price_text, image, shop_display_image, stock_qty, published
         FROM products WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $price = null;
    try {
        $price = invoices_parse_toman_amount(isset($row['price_text']) ? (string) $row['price_text'] : null);
    } catch (Throwable $e) {
        $price = null;
    }
    $row['regular_toman'] = $price;

    return $row;
}

function flash_deals_sync_row(PDO $pdo, array $row, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? flash_deals_now_utc();
    $status = (string) ($row['status'] ?? '');
    if (!in_array($status, ['scheduled', 'active'], true)) {
        return $row;
    }
    $start = new DateTimeImmutable((string) $row['starts_at'], new DateTimeZone('UTC'));
    $end = new DateTimeImmutable((string) $row['ends_at'], new DateTimeZone('UTC'));
    $next = $status;
    if ($now >= $end) {
        $next = 'ended';
    } elseif ($status === 'scheduled' && $now >= $start && $now < $end) {
        $next = 'active';
    }
    if ($next === $status) {
        return $row;
    }
    $pdo->prepare('UPDATE flash_deals SET status = ? WHERE id = ? AND status = ?')
        ->execute([$next, (int) $row['id'], $status]);
    $row['status'] = $next;

    return $row;
}

/**
 * Server-side purchase gate. Paused, ended, archived, draft, and out-of-window
 * campaigns are rejected here even if a client clock or cache still shows them.
 */
function flash_deals_assert_purchasable_status(array $deal, DateTimeImmutable $now): void
{
    $status = (string) ($deal['status'] ?? '');
    if ($status === 'paused') {
        throw new FlashDealException('FLASH_DEAL_PAUSED', 'این پیشنهاد موقتاً متوقف شده است', 409);
    }
    $endRaw = (string) ($deal['ends_at'] ?? '');
    $end = new DateTimeImmutable($endRaw !== '' ? $endRaw : '1970-01-01 00:00:00', new DateTimeZone('UTC'));
    if ($status === 'ended' || $now >= $end) {
        throw new FlashDealException('FLASH_DEAL_EXPIRED', 'مهلت این پیشنهاد تمام شده است', 409);
    }
    if (!flash_deals_is_purchasable($deal, $now)) {
        throw new FlashDealException('FLASH_DEAL_UNAVAILABLE', 'این پیشنهاد در حال حاضر قابل خرید نیست', 409);
    }
}

function flash_deals_is_purchasable(array $deal, ?DateTimeImmutable $now = null): bool
{
    $now = $now ?? flash_deals_now_utc();
    $status = (string) ($deal['status'] ?? '');
    if (!in_array($status, ['active', 'scheduled'], true)) {
        return false;
    }
    $start = new DateTimeImmutable((string) $deal['starts_at'], new DateTimeZone('UTC'));
    $end = new DateTimeImmutable((string) $deal['ends_at'], new DateTimeZone('UTC'));

    return $now >= $start && $now < $end;
}

/**
 * @param array<string, mixed> $deal
 * @param list<array<string, mixed>> $products
 * @return array<string, mixed>
 */
function flash_deals_serialize(array $deal, array $products = []): array
{
    $startParts = flash_deals_utc_to_jalali_parts((string) ($deal['starts_at'] ?? ''));
    $endParts = flash_deals_utc_to_jalali_parts((string) ($deal['ends_at'] ?? ''));
    $start = new DateTimeImmutable((string) $deal['starts_at'], new DateTimeZone('UTC'));
    $end = new DateTimeImmutable((string) $deal['ends_at'], new DateTimeZone('UTC'));

    return [
        'id' => (int) $deal['id'],
        'title' => (string) $deal['title'],
        'description' => isset($deal['description']) && $deal['description'] !== null
            ? (string) $deal['description']
            : '',
        'banner_image' => isset($deal['banner_image']) && trim((string) $deal['banner_image']) !== ''
            ? (string) $deal['banner_image']
            : null,
        'starts_at' => $start->format('Y-m-d\TH:i:s\Z'),
        'ends_at' => $end->format('Y-m-d\TH:i:s\Z'),
        'start_date' => $startParts['date'],
        'start_time' => $startParts['time'],
        'end_date' => $endParts['date'],
        'end_time' => $endParts['time'],
        'status' => (string) $deal['status'],
        'status_label' => flash_deals_status_labels()[(string) $deal['status']] ?? (string) $deal['status'],
        'display_priority' => (int) ($deal['display_priority'] ?? 0),
        'version' => (int) ($deal['version'] ?? 1),
        'product_count' => isset($deal['product_count'])
            ? (int) $deal['product_count']
            : count($products),
        'created_at' => (string) ($deal['created_at'] ?? ''),
        'updated_at' => (string) ($deal['updated_at'] ?? ''),
        'updated_at_jalali' => cms_jalali_format_datetime((string) ($deal['updated_at'] ?? '')),
        'purchasable' => flash_deals_is_purchasable($deal),
        'products' => $products,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function flash_deals_serialize_product(array $row): array
{
    $regular = (int) $row['regular_unit_price'];
    $promo = (int) $row['promo_unit_price'];

    return [
        'id' => (int) $row['id'],
        'product_id' => (int) $row['product_id'],
        'name' => (string) ($row['name'] ?? ''),
        'slug' => (string) ($row['slug'] ?? ''),
        'visual_id' => isset($row['visual_id']) && trim((string) $row['visual_id']) !== ''
            ? (string) $row['visual_id']
            : null,
        'image' => isset($row['image']) && trim((string) $row['image']) !== ''
            ? (string) $row['image']
            : null,
        'regular_unit_price' => $regular,
        'promo_unit_price' => $promo,
        'regular_price_text' => invoices_format_toman($regular),
        'promo_price_text' => invoices_format_toman($promo),
        'discount_amount' => (int) $row['discount_amount'],
        'discount_percent' => (string) $row['discount_percent'],
        'per_order_qty_limit' => isset($row['per_order_qty_limit']) && $row['per_order_qty_limit'] !== null
            ? (int) $row['per_order_qty_limit']
            : null,
        'per_customer_qty_limit' => isset($row['per_customer_qty_limit']) && $row['per_customer_qty_limit'] !== null
            ? (int) $row['per_customer_qty_limit']
            : null,
        'campaign_qty_limit' => isset($row['campaign_qty_limit']) && $row['campaign_qty_limit'] !== null
            ? (int) $row['campaign_qty_limit']
            : null,
        'campaign_qty_sold' => (int) ($row['campaign_qty_sold'] ?? 0),
        'eligibility_status' => (string) ($row['eligibility_status'] ?? 'eligible'),
        'display_priority' => (int) ($row['display_priority'] ?? 0),
        'stock_qty' => isset($row['stock_qty']) ? (int) $row['stock_qty'] : null,
        'published' => isset($row['published']) ? (int) $row['published'] === 1 : null,
    ];
}

function flash_deals_fetch_products(PDO $pdo, int $dealId): array
{
    $stmt = $pdo->prepare(
        'SELECT fdp.*, p.name, p.slug, p.visual_id, p.stock_qty, p.published,
                COALESCE(NULLIF(p.shop_display_image, \'\'), NULLIF(p.image, \'\')) AS image
         FROM flash_deal_products fdp
         INNER JOIN products p ON p.id = fdp.product_id
         WHERE fdp.flash_deal_id = ?
         ORDER BY fdp.display_priority DESC, fdp.id ASC'
    );
    $stmt->execute([$dealId]);
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $out[] = flash_deals_serialize_product($row);
    }

    return $out;
}

function flash_deals_get(PDO $pdo, int $id, bool $sync = true): ?array
{
    flash_deals_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM flash_deals WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ($sync) {
        $row = flash_deals_sync_row($pdo, $row);
    }

    return flash_deals_serialize($row, flash_deals_fetch_products($pdo, $id));
}

/**
 * @param array{q?:string,status?:string,date_from?:string,date_to?:string} $filters
 * @return array{items:list<array<string,mixed>>,total:int,page:int,total_pages:int}
 */
function flash_deals_list(PDO $pdo, array $filters = [], int $page = 1, int $pageSize = 20): array
{
    flash_deals_ensure_schema($pdo);
    $page = max(1, $page);
    $pageSize = min(50, max(1, $pageSize));
    $where = ['1=1'];
    $params = [];
    $status = trim((string) ($filters['status'] ?? ''));
    if ($status === '' || $status === 'open') {
        $where[] = "status <> 'archived'";
    } elseif ($status !== 'all' && in_array($status, flash_deals_statuses(), true)) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = 'title LIKE ?';
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }
    $from = trim((string) ($filters['date_from'] ?? ''));
    $to = trim((string) ($filters['date_to'] ?? ''));
    if ($from !== '') {
        $where[] = 'ends_at >= ?';
        $params[] = flash_deals_input_to_utc($from, '00:00');
    }
    if ($to !== '') {
        $where[] = 'starts_at <= ?';
        $params[] = flash_deals_input_to_utc($to, '23:59');
    }
    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare("SELECT COUNT(*) FROM flash_deals WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $offset = ($page - 1) * $pageSize;
    $stmt = $pdo->prepare(
        "SELECT d.*,
                (SELECT COUNT(*) FROM flash_deal_products fdp WHERE fdp.flash_deal_id = d.id) AS product_count
         FROM flash_deals d
         WHERE {$whereSql}
         ORDER BY d.display_priority DESC, d.updated_at DESC, d.id DESC
         LIMIT {$pageSize} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $row = flash_deals_sync_row($pdo, $row);
        $items[] = flash_deals_serialize($row);
    }
    $pages = max(1, (int) ceil($total / $pageSize));

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'total_pages' => $pages,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function flash_deals_search_products(PDO $pdo, string $q, int $limit = 20): array
{
    $q = trim($q);
    $limit = min(30, max(1, $limit));
    $select = "SELECT id, name, slug, visual_id, price_text, stock_qty, published,
                COALESCE(NULLIF(shop_display_image, ''), NULLIF(image, '')) AS image
         FROM products";
    if ($q === '') {
        $browseLimit = min(20, $limit);
        $stmt = $pdo->prepare(
            $select . " WHERE published = 1 ORDER BY name ASC LIMIT {$browseLimit}"
        );
        $stmt->execute();
    } else {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $stmt = $pdo->prepare(
            $select . " WHERE name LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR visual_id LIKE ? ESCAPE '\\\\'
             ORDER BY published DESC, name ASC
             LIMIT {$limit}"
        );
        $stmt->execute([$like, $like, $like]);
    }
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $regular = null;
        try {
            $regular = invoices_parse_toman_amount(isset($row['price_text']) ? (string) $row['price_text'] : null);
        } catch (Throwable $e) {
            $regular = null;
        }
        $out[] = [
            'product_id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'visual_id' => $row['visual_id'] !== null ? (string) $row['visual_id'] : null,
            'image' => $row['image'] !== null ? (string) $row['image'] : null,
            'regular_unit_price' => $regular,
            'regular_price_text' => $regular !== null ? invoices_format_toman($regular) : null,
            'stock_qty' => (int) $row['stock_qty'],
            'published' => (int) $row['published'] === 1,
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function flash_deals_save(PDO $pdo, array $body): array
{
    flash_deals_ensure_schema($pdo);
    $id = (int) ($body['id'] ?? 0);
    $title = trim((string) ($body['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('عنوان الزامی است');
    }
    if (mb_strlen($title) > 191) {
        throw new InvalidArgumentException('عنوان بیش از حد طولانی است');
    }
    $description = trim((string) ($body['description'] ?? ''));
    $banner = trim((string) ($body['banner_image'] ?? ''));
    if (mb_strlen($banner) > 512) {
        throw new InvalidArgumentException('مسیر تصویر نامعتبر است');
    }
    $intent = trim((string) ($body['intent'] ?? 'draft'));
    if (!in_array($intent, ['draft', 'schedule', 'publish'], true)) {
        throw new InvalidArgumentException('وضعیت ذخیره نامعتبر است');
    }
    $priority = (int) ($body['display_priority'] ?? 0);
    if ($priority < -100000 || $priority > 100000) {
        throw new InvalidArgumentException('اولویت نمایش نامعتبر است');
    }

    $startsAt = flash_deals_input_to_utc(
        (string) ($body['start_date'] ?? ''),
        (string) ($body['start_time'] ?? '')
    );
    $endsAt = flash_deals_input_to_utc(
        (string) ($body['end_date'] ?? ''),
        (string) ($body['end_time'] ?? '')
    );
    if ($endsAt <= $startsAt) {
        throw new InvalidArgumentException('زمان پایان باید بعد از زمان شروع باشد');
    }

    $rawProducts = $body['products'] ?? [];
    if (!is_array($rawProducts)) {
        throw new InvalidArgumentException('فهرست محصولات نامعتبر است');
    }
    $prepared = [];
    $seen = [];
    foreach ($rawProducts as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $productId = (int) ($raw['product_id'] ?? 0);
        if ($productId <= 0 || isset($seen[$productId])) {
            throw new InvalidArgumentException('محصول تکراری یا نامعتبر است');
        }
        $seen[$productId] = true;
        $catalog = flash_deals_catalog_price($pdo, $productId);
        if ($catalog === null) {
            throw new InvalidArgumentException('محصول یافت نشد');
        }
        $regular = $catalog['regular_toman'];
        if ($regular === null || $regular <= 0) {
            throw new InvalidArgumentException('قیمت عادی «' . $catalog['name'] . '» قابل محاسبه نیست');
        }
        if ($intent === 'draft') {
            $promo = 0;
            try {
                if (($raw['promo_unit_price'] ?? '') !== '' && ($raw['promo_unit_price'] ?? null) !== null) {
                    $promo = flash_deals_parse_toman($raw['promo_unit_price']);
                }
            } catch (InvalidArgumentException $e) {
                $promo = 0;
            }
            if ($promo > 0 && $promo < $regular) {
                $discount = flash_deals_discount($regular, $promo);
            } else {
                $discount = ['amount' => 0, 'percent' => '0.00'];
            }
        } else {
            $promo = flash_deals_parse_toman($raw['promo_unit_price'] ?? null);
            $discount = flash_deals_discount($regular, $promo);
        }
        $eligible = (int) $catalog['published'] === 1 && (int) $catalog['stock_qty'] > 0;
        $prepared[] = [
            'product_id' => $productId,
            'name' => (string) $catalog['name'],
            'regular_unit_price' => $regular,
            'promo_unit_price' => $promo,
            'discount_amount' => $discount['amount'],
            'discount_percent' => $discount['percent'],
            'per_order_qty_limit' => flash_deals_nullable_limit($raw['per_order_qty_limit'] ?? null),
            'per_customer_qty_limit' => flash_deals_nullable_limit($raw['per_customer_qty_limit'] ?? null),
            'campaign_qty_limit' => flash_deals_nullable_limit($raw['campaign_qty_limit'] ?? null),
            'eligibility_status' => $eligible ? 'eligible' : 'ineligible',
            'display_priority' => (int) ($raw['display_priority'] ?? 0),
        ];
    }

    $now = flash_deals_now_utc();
    $startDt = new DateTimeImmutable($startsAt, new DateTimeZone('UTC'));
    $endDt = new DateTimeImmutable($endsAt, new DateTimeZone('UTC'));
    if ($intent !== 'draft' && $prepared === []) {
        throw new InvalidArgumentException('حداقل یک محصول با قیمت ویژه لازم است');
    }
    if ($intent === 'schedule' && $startDt <= $now) {
        throw new InvalidArgumentException('برای زمان‌بندی، شروع باید در آینده باشد');
    }
    if ($intent !== 'draft' && $endDt <= $now) {
        throw new InvalidArgumentException('زمان پایان گذشته است');
    }

    $status = 'draft';
    if ($intent === 'schedule') {
        $status = 'scheduled';
    } elseif ($intent === 'publish') {
        $status = ($startDt > $now) ? 'scheduled' : 'active';
    }

    $actor = flash_deals_actor($pdo);
    $actorId = $actor['id'] !== null ? (int) $actor['id'] : null;
    $warnings = [];
    $before = null;
    $isNew = $id <= 0;

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $lock = $pdo->prepare('SELECT * FROM flash_deals WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $before = $lock->fetch();
            if (!$before) {
                throw new FlashDealException('FLASH_DEAL_NOT_FOUND', 'کمپین یافت نشد', 404);
            }
            $clientVersion = (int) ($body['version'] ?? 0);
            if ($clientVersion !== (int) $before['version']) {
                throw new FlashDealException(
                    'FLASH_DEAL_CONFLICT',
                    'این کمپین توسط مدیر دیگری به‌روز شده است. صفحه را تازه کنید و دوباره ذخیره کنید.',
                    409,
                    ['current_version' => (int) $before['version']]
                );
            }
            if ((string) $before['status'] === 'archived' && $intent === 'draft') {
                $status = 'archived';
            }
            $upd = $pdo->prepare(
                'UPDATE flash_deals
                 SET title = ?, description = ?, banner_image = ?, starts_at = ?, ends_at = ?,
                     status = ?, display_priority = ?, version = version + 1, updated_by = ?
                 WHERE id = ? AND version = ?'
            );
            $upd->execute([
                $title,
                $description !== '' ? $description : null,
                $banner !== '' ? $banner : null,
                $startsAt,
                $endsAt,
                $status,
                $priority,
                $actorId,
                $id,
                $clientVersion,
            ]);
            if ($upd->rowCount() !== 1) {
                throw new FlashDealException(
                    'FLASH_DEAL_CONFLICT',
                    'این کمپین توسط مدیر دیگری به‌روز شده است. صفحه را تازه کنید و دوباره ذخیره کنید.',
                    409
                );
            }
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO flash_deals
                 (title, description, banner_image, starts_at, ends_at, status, display_priority, version, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $ins->execute([
                $title,
                $description !== '' ? $description : null,
                $banner !== '' ? $banner : null,
                $startsAt,
                $endsAt,
                $status,
                $priority,
                $actorId,
                $actorId,
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        $existingSold = [];
        $soldStmt = $pdo->prepare(
            'SELECT product_id, campaign_qty_sold FROM flash_deal_products WHERE flash_deal_id = ?'
        );
        $soldStmt->execute([$id]);
        foreach ($soldStmt->fetchAll() ?: [] as $soldRow) {
            $existingSold[(int) $soldRow['product_id']] = (int) $soldRow['campaign_qty_sold'];
        }
        $orderCount = flash_deals_order_ref_count($pdo, $id);
        if ($before !== null && $orderCount > 0) {
            $warnings[] = 'تغییر قیمت، محصولات یا بازه زمانی فقط روی خریدهای بعدی اثر می‌گذارد. سفارش‌های قبلی با قیمت پذیرفته‌شده باقی می‌مانند.';
        }

        $pdo->prepare('DELETE FROM flash_deal_products WHERE flash_deal_id = ?')->execute([$id]);
        $pins = $pdo->prepare(
            'INSERT INTO flash_deal_products
             (flash_deal_id, product_id, regular_unit_price, promo_unit_price, discount_amount, discount_percent,
              per_order_qty_limit, per_customer_qty_limit, campaign_qty_limit, campaign_qty_sold,
              eligibility_status, display_priority)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($prepared as $product) {
            $sold = $existingSold[$product['product_id']] ?? flash_deals_historical_sold($pdo, $id, $product['product_id']);
            $pins->execute([
                $id,
                $product['product_id'],
                $product['regular_unit_price'],
                $product['promo_unit_price'],
                $product['discount_amount'],
                $product['discount_percent'],
                $product['per_order_qty_limit'],
                $product['per_customer_qty_limit'],
                $product['campaign_qty_limit'],
                $sold,
                $product['eligibility_status'],
                $product['display_priority'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $action = $isNew ? 'flash_deal.create' : 'flash_deal.edit';
    if ($intent === 'schedule') {
        $action = 'flash_deal.schedule';
    } elseif ($intent === 'publish') {
        $action = 'flash_deal.activate';
    }
    flash_deals_audit($pdo, $action, $id, $title, 'ذخیره پیشنهاد ویژه: ' . $title, [
        'intent' => $intent,
        'status' => $status,
        'product_ids' => array_column($prepared, 'product_id'),
        'before_status' => $before['status'] ?? null,
    ]);
    $wasActive = is_array($before) && (string) ($before['status'] ?? '') === 'active';
    if ($status === 'active' && !$wasActive) {
        flash_deals_notify_sales($pdo, $title);
    }

    $saved = flash_deals_get($pdo, $id, false);
    if ($saved === null) {
        throw new RuntimeException('خطا در ذخیره کمپین');
    }
    $saved['warnings'] = $warnings;

    return $saved;
}

function flash_deals_order_ref_count(PDO $pdo, int $dealId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE flash_deal_id = ?');
    $stmt->execute([$dealId]);

    return (int) $stmt->fetchColumn();
}

function flash_deals_historical_sold(PDO $pdo, int $dealId, int $productId): int
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity), 0)
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE oi.flash_deal_id = ? AND oi.product_id = ?
           AND o.status NOT IN ('rejected', 'cancelled')"
    );
    $stmt->execute([$dealId, $productId]);

    return (int) $stmt->fetchColumn();
}

/**
 * @return array<string, mixed>
 */
function flash_deals_action(PDO $pdo, int $id, string $action, int $version): array
{
    flash_deals_ensure_schema($pdo);
    $allowed = ['pause', 'resume', 'end', 'archive', 'duplicate', 'delete'];
    if (!in_array($action, $allowed, true)) {
        throw new InvalidArgumentException('عملیات نامعتبر است');
    }
    if ($action === 'duplicate') {
        return flash_deals_duplicate($pdo, $id);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM flash_deals WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new FlashDealException('FLASH_DEAL_NOT_FOUND', 'کمپین یافت نشد', 404);
        }
        if ($version !== (int) $row['version']) {
            throw new FlashDealException(
                'FLASH_DEAL_CONFLICT',
                'این کمپین توسط مدیر دیگری به‌روز شده است. صفحه را تازه کنید.',
                409,
                ['current_version' => (int) $row['version']]
            );
        }
        $status = (string) $row['status'];
        $now = flash_deals_now_utc();
        $actor = flash_deals_actor($pdo);
        $actorId = $actor['id'] !== null ? (int) $actor['id'] : null;
        $nowSql = $now->format('Y-m-d H:i:s');

        if ($action === 'delete') {
            $refs = flash_deals_order_ref_count($pdo, $id);
            if ($refs > 0) {
                throw new FlashDealException(
                    'FLASH_DEAL_DELETE_BLOCKED',
                    'حذف دائمی ممکن نیست چون سفارش‌هایی به این پیشنهاد وصل هستند. به‌جای حذف، آن را بایگانی کنید تا تاریخچه قیمت و سفارش حفظ شود.',
                    409,
                    ['order_item_count' => $refs]
                );
            }
            $pdo->prepare('DELETE FROM flash_deals WHERE id = ? AND version = ?')->execute([$id, $version]);
            $pdo->commit();
            flash_deals_audit($pdo, 'flash_deal.delete', $id, (string) $row['title'], 'حذف پیشنهاد ویژه: ' . $row['title']);

            return ['deleted' => true, 'id' => $id];
        }

        $next = $status;
        $endsAt = (string) $row['ends_at'];
        if ($action === 'pause') {
            if (!in_array($status, ['active', 'scheduled'], true)) {
                throw new InvalidArgumentException('فقط پیشنهاد فعال یا زمان‌بندی‌شده قابل توقف است');
            }
            $next = 'paused';
        } elseif ($action === 'resume') {
            if ($status !== 'paused') {
                throw new InvalidArgumentException('فقط پیشنهاد متوقف‌شده قابل ادامه است');
            }
            $end = new DateTimeImmutable((string) $row['ends_at'], new DateTimeZone('UTC'));
            $start = new DateTimeImmutable((string) $row['starts_at'], new DateTimeZone('UTC'));
            if ($now >= $end) {
                throw new InvalidArgumentException('بازه پیشنهاد تمام شده و قابل ادامه نیست');
            }
            $products = flash_deals_fetch_products($pdo, $id);
            if ($products === []) {
                throw new InvalidArgumentException('برای ادامه، حداقل یک محصول لازم است');
            }
            $next = $now < $start ? 'scheduled' : 'active';
        } elseif ($action === 'end') {
            if (in_array($status, ['ended', 'archived'], true)) {
                throw new InvalidArgumentException('این پیشنهاد قبلاً پایان یافته است');
            }
            $next = 'ended';
            if ($endsAt > $nowSql) {
                $endsAt = $nowSql;
            }
        } elseif ($action === 'archive') {
            if ($status === 'archived') {
                throw new InvalidArgumentException('این پیشنهاد قبلاً بایگانی شده است');
            }
            $next = 'archived';
        }

        $upd = $pdo->prepare(
            'UPDATE flash_deals
             SET status = ?, ends_at = ?, version = version + 1, updated_by = ?
             WHERE id = ? AND version = ?'
        );
        $upd->execute([$next, $endsAt, $actorId, $id, $version]);
        if ($upd->rowCount() !== 1) {
            throw new FlashDealException('FLASH_DEAL_CONFLICT', 'این کمپین توسط مدیر دیگری به‌روز شده است.', 409);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $auditAction = [
        'pause' => 'flash_deal.pause',
        'resume' => 'flash_deal.resume',
        'end' => 'flash_deal.end',
        'archive' => 'flash_deal.archive',
    ][$action];
    flash_deals_audit($pdo, $auditAction, $id, (string) $row['title'], 'عملیات پیشنهاد ویژه: ' . $action, [
        'from' => $status,
        'to' => $next,
    ]);
    $saved = flash_deals_get($pdo, $id, false);
    if ($saved === null) {
        throw new RuntimeException('کمپین یافت نشد');
    }

    return $saved;
}

/**
 * @return array<string, mixed>
 */
function flash_deals_duplicate(PDO $pdo, int $id): array
{
    $source = flash_deals_get($pdo, $id, false);
    if ($source === null) {
        throw new FlashDealException('FLASH_DEAL_NOT_FOUND', 'کمپین یافت نشد', 404);
    }
    $tehran = new DateTimeZone('Asia/Tehran');
    $freshStart = (new DateTimeImmutable('tomorrow', $tehran))->setTime(9, 0);
    $freshEnd = $freshStart->modify('+7 days')->setTime(21, 0);
    $freshStartParts = flash_deals_utc_to_jalali_parts(
        $freshStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
    );
    $freshEndParts = flash_deals_utc_to_jalali_parts(
        $freshEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
    );
    $body = [
        'id' => 0,
        'title' => mb_substr($source['title'] . ' (کپی)', 0, 191),
        'description' => $source['description'],
        'banner_image' => (string) ($source['banner_image'] ?? ''),
        'start_date' => $freshStartParts['date'],
        'start_time' => $freshStartParts['time'],
        'end_date' => $freshEndParts['date'],
        'end_time' => $freshEndParts['time'],
        'display_priority' => $source['display_priority'],
        'intent' => 'draft',
        'products' => [],
    ];
    foreach ($source['products'] as $product) {
        $body['products'][] = [
            'product_id' => $product['product_id'],
            'promo_unit_price' => $product['promo_unit_price'],
            'per_order_qty_limit' => $product['per_order_qty_limit'],
            'per_customer_qty_limit' => $product['per_customer_qty_limit'],
            'campaign_qty_limit' => $product['campaign_qty_limit'],
            'display_priority' => $product['display_priority'],
        ];
    }
    $copy = flash_deals_save($pdo, $body);
    flash_deals_audit(
        $pdo,
        'flash_deal.duplicate',
        (int) $copy['id'],
        (string) $copy['title'],
        'کپی پیشنهاد ویژه از #' . $id,
        ['source_id' => $id]
    );

    return $copy;
}

/**
 * Offers for the sales app. Draft, paused, archived, and ended deals are omitted.
 * Scheduled deals that have not started yet are included and marked not purchasable.
 *
 * @return array{server_time:string,offers:list<array<string,mixed>>}
 */
function flash_deals_sales_offers(PDO $pdo): array
{
    flash_deals_ensure_schema($pdo);
    $now = flash_deals_now_utc();
    $stmt = $pdo->query(
        "SELECT d.*
         FROM flash_deals d
         WHERE d.status IN ('active', 'scheduled')
         ORDER BY d.display_priority DESC, d.id DESC"
    );
    $offers = [];
    foreach ($stmt->fetchAll() ?: [] as $deal) {
        $deal = flash_deals_sync_row($pdo, $deal, $now);
        $status = (string) ($deal['status'] ?? '');
        if (!in_array($status, ['active', 'scheduled'], true)) {
            continue;
        }
        $start = new DateTimeImmutable((string) $deal['starts_at'], new DateTimeZone('UTC'));
        $end = new DateTimeImmutable((string) $deal['ends_at'], new DateTimeZone('UTC'));
        if ($now >= $end) {
            continue;
        }
        $inWindow = $now >= $start && $now < $end;
        $campaign = flash_deals_serialize($deal);
        foreach (flash_deals_fetch_products($pdo, (int) $deal['id']) as $product) {
            $promo = (int) ($product['promo_unit_price'] ?? 0);
            $regular = (int) ($product['regular_unit_price'] ?? 0);
            if ($promo <= 0 || $regular <= 0 || $promo >= $regular) {
                continue;
            }
            $remaining = null;
            $remainingOk = true;
            if ($product['campaign_qty_limit'] !== null) {
                $remaining = max(0, (int) $product['campaign_qty_limit'] - (int) $product['campaign_qty_sold']);
                $remainingOk = $remaining > 0;
            }
            $inStock = $product['stock_qty'] === null || (int) $product['stock_qty'] > 0;
            $published = $product['published'] === true;
            $offers[] = [
                'flash_deal_id' => $campaign['id'],
                'flash_deal_version' => $campaign['version'],
                'campaign_title' => $campaign['title'],
                'campaign_description' => $campaign['description'],
                'banner_image' => $campaign['banner_image'],
                'starts_at' => $campaign['starts_at'],
                'starts_at_jalali' => cms_jalali_format_datetime((string) $deal['starts_at']),
                'ends_at' => $campaign['ends_at'],
                'ends_at_jalali' => cms_jalali_format_datetime((string) $deal['ends_at']),
                'purchasable' => $inWindow && $published && $inStock && $remainingOk,
                'product_id' => $product['product_id'],
                'name' => $product['name'],
                'slug' => $product['slug'],
                'visual_id' => $product['visual_id'],
                'image' => $product['image'],
                'regular_unit_price' => $product['regular_unit_price'],
                'promo_unit_price' => $product['promo_unit_price'],
                'regular_price_text' => $product['regular_price_text'],
                'promo_price_text' => $product['promo_price_text'],
                'discount_amount' => $product['discount_amount'],
                'discount_percent' => $product['discount_percent'],
                'per_order_qty_limit' => $product['per_order_qty_limit'],
                'per_customer_qty_limit' => $product['per_customer_qty_limit'],
                'campaign_qty_remaining' => $remaining,
            ];
        }
    }

    return [
        'server_time' => $now->format('Y-m-d\TH:i:s\Z'),
        'offers' => $offers,
    ];
}

/**
 * @param array<string, mixed> $item
 */
function flash_deals_item_id(array $item): int
{
    return isset($item['flash_deal_id']) ? (int) $item['flash_deal_id'] : 0;
}

/**
 * @param array<string, array<string, mixed>> $normalized
 */
function flash_deals_cart_has_promo(array $normalized): bool
{
    foreach ($normalized as $item) {
        if (is_array($item) && flash_deals_item_id($item) > 0) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array<string, mixed>> $rawItems
 */
function flash_deals_raw_has_promo(array $rawItems): bool
{
    foreach ($rawItems as $raw) {
        if (is_array($raw) && (int) ($raw['flash_deal_id'] ?? 0) > 0) {
            return true;
        }
    }

    return false;
}

function flash_deals_customer_purchased(PDO $pdo, int $dealId, int $productId, ?int $salesUserId, int $siteUserId): int
{
    if ($salesUserId !== null && $salesUserId > 0) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(oi.quantity), 0)
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             WHERE oi.flash_deal_id = ? AND oi.product_id = ?
               AND o.sales_user_id = ?
               AND o.status NOT IN ('rejected', 'cancelled')"
        );
        $stmt->execute([$dealId, $productId, $salesUserId]);

        return (int) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity), 0)
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE oi.flash_deal_id = ? AND oi.product_id = ?
           AND o.user_id = ?
           AND o.status NOT IN ('rejected', 'cancelled')"
    );
    $stmt->execute([$dealId, $productId, $siteUserId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Validate promotional lines and replace client prices with server prices.
 * Does not increment sold counters.
 *
 * @param array<string, array<string, mixed>> $normalized
 * @param array{sms_channel?:bool,sales_user_id?:?int,site_user_id?:int} $ctx
 * @return array<string, array<string, mixed>>
 */
function flash_deals_finalize_cart(PDO $pdo, array $normalized, array $ctx, ?DateTimeImmutable $now = null): array
{
    if (!flash_deals_cart_has_promo($normalized)) {
        return $normalized;
    }
    flash_deals_ensure_schema($pdo);
    if (!empty($ctx['sms_channel'])) {
        throw new FlashDealException(
            'FLASH_DEAL_ONLINE_REQUIRED',
            'این پیشنهاد فقط به‌صورت آنلاین قابل خرید است. برای ثبت سفارش با قیمت ویژه، به اینترنت متصل شوید.',
            409
        );
    }

    $now = $now ?? flash_deals_now_utc();
    $salesUserId = isset($ctx['sales_user_id']) ? (int) $ctx['sales_user_id'] : 0;
    $siteUserId = (int) ($ctx['site_user_id'] ?? 0);

    foreach ($normalized as $key => $item) {
        $dealId = flash_deals_item_id($item);
        if ($dealId <= 0) {
            continue;
        }
        $productId = isset($item['product_id']) ? (int) $item['product_id'] : 0;
        if ($productId <= 0) {
            throw new FlashDealException(
                'FLASH_DEAL_UNAVAILABLE',
                'محصول این پیشنهاد در دسترس نیست',
                409
            );
        }
        $dealStmt = $pdo->prepare('SELECT * FROM flash_deals WHERE id = ? LIMIT 1');
        $dealStmt->execute([$dealId]);
        $deal = $dealStmt->fetch();
        if (!$deal) {
            throw new FlashDealException('FLASH_DEAL_UNAVAILABLE', 'پیشنهاد ویژه یافت نشد یا دیگر قابل خرید نیست', 409);
        }
        $deal = flash_deals_sync_row($pdo, $deal, $now);
        flash_deals_assert_purchasable_status($deal, $now);
        $prodStmt = $pdo->prepare(
            'SELECT fdp.*, p.published, p.stock_qty
             FROM flash_deal_products fdp
             INNER JOIN products p ON p.id = fdp.product_id
             WHERE fdp.flash_deal_id = ? AND fdp.product_id = ?
             LIMIT 1'
        );
        $prodStmt->execute([$dealId, $productId]);
        $line = $prodStmt->fetch();
        if (!$line || (int) $line['published'] !== 1 || (int) $line['stock_qty'] <= 0
            || (string) ($line['eligibility_status'] ?? 'eligible') !== 'eligible') {
            throw new FlashDealException('FLASH_DEAL_UNAVAILABLE', 'محصول این پیشنهاد در دسترس نیست', 409);
        }
        $serverPromo = (int) $line['promo_unit_price'];
        $expected = isset($item['expected_promo_toman']) ? (int) $item['expected_promo_toman'] : 0;
        if ($expected <= 0) {
            throw new FlashDealException(
                'FLASH_DEAL_INVALID_PRICE',
                'قیمت ویژه باید دوباره تأیید شود',
                409
            );
        }
        if ($expected !== $serverPromo) {
            throw new FlashDealException(
                'FLASH_DEAL_OFFER_CHANGED',
                'قیمت این پیشنهاد تغییر کرده است. قیمت جدید را بررسی و دوباره تأیید کنید.',
                409,
                [
                    'product_id' => $productId,
                    'flash_deal_id' => $dealId,
                    'server_promo_toman' => $serverPromo,
                    'regular_unit_price' => (int) $line['regular_unit_price'],
                    'promo_price_text' => invoices_format_toman($serverPromo),
                    'regular_price_text' => invoices_format_toman((int) $line['regular_unit_price']),
                ]
            );
        }
        $qty = (int) $item['quantity'];
        if ($line['per_order_qty_limit'] !== null && $qty > (int) $line['per_order_qty_limit']) {
            throw new FlashDealException('FLASH_DEAL_LIMIT_EXCEEDED', 'تعداد درخواستی از سقف هر سفارش بیشتر است', 409);
        }
        $already = flash_deals_customer_purchased($pdo, $dealId, $productId, $salesUserId > 0 ? $salesUserId : null, $siteUserId);
        if ($line['per_customer_qty_limit'] !== null && ($already + $qty) > (int) $line['per_customer_qty_limit']) {
            throw new FlashDealException('FLASH_DEAL_LIMIT_EXCEEDED', 'سقف خرید این پیشنهاد برای شما تکمیل شده است', 409);
        }
        if ($line['campaign_qty_limit'] !== null) {
            $left = (int) $line['campaign_qty_limit'] - (int) $line['campaign_qty_sold'];
            if ($qty > $left) {
                throw new FlashDealException('FLASH_DEAL_LIMIT_EXCEEDED', 'موجودی این پیشنهاد ویژه تمام شده است', 409);
            }
        }
        $unit = (string) ($item['unit_type'] ?? 'piece');
        $pack = isset($item['pack_size']) ? (int) $item['pack_size'] : 0;
        $lineTotal = ($unit === 'pack' && $pack > 0) ? $serverPromo * $pack * $qty : $serverPromo * $qty;
        $item['price_text'] = invoices_format_toman($serverPromo);
        $item['flash_deal_title'] = (string) $deal['title'];
        $item['flash_deal_version'] = (int) $deal['version'];
        $item['regular_unit_price'] = (int) $line['regular_unit_price'];
        $item['promo_unit_price'] = $serverPromo;
        $item['discount_amount'] = (int) $line['discount_amount'];
        $item['line_total'] = $lineTotal;
        $normalized[$key] = $item;
    }

    return $normalized;
}

/**
 * Lock campaign product rows and increment sold quantity. Call inside the order transaction.
 *
 * @param array<string, array<string, mixed>> $normalized
 */
function flash_deals_consume_limits(PDO $pdo, array $normalized, ?int $salesUserId, int $siteUserId): void
{
    if (!flash_deals_cart_has_promo($normalized)) {
        return;
    }
    $now = flash_deals_now_utc();
    foreach ($normalized as $item) {
        $dealId = flash_deals_item_id($item);
        if ($dealId <= 0) {
            continue;
        }
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['quantity'] ?? 0);
        $dealStmt = $pdo->prepare('SELECT * FROM flash_deals WHERE id = ? FOR UPDATE');
        $dealStmt->execute([$dealId]);
        $deal = $dealStmt->fetch();
        if (!$deal) {
            throw new FlashDealException('FLASH_DEAL_UNAVAILABLE', 'پیشنهاد ویژه یافت نشد یا دیگر قابل خرید نیست', 409);
        }
        $deal = flash_deals_sync_row($pdo, $deal, $now);
        flash_deals_assert_purchasable_status($deal, $now);
        $lineStmt = $pdo->prepare(
            'SELECT * FROM flash_deal_products WHERE flash_deal_id = ? AND product_id = ? FOR UPDATE'
        );
        $lineStmt->execute([$dealId, $productId]);
        $line = $lineStmt->fetch();
        if (!$line || (string) ($line['eligibility_status'] ?? 'eligible') !== 'eligible') {
            throw new FlashDealException('FLASH_DEAL_UNAVAILABLE', 'محصول این پیشنهاد در دسترس نیست', 409);
        }
        if ((int) $line['promo_unit_price'] !== (int) ($item['promo_unit_price'] ?? 0)) {
            throw new FlashDealException(
                'FLASH_DEAL_OFFER_CHANGED',
                'قیمت این پیشنهاد تغییر کرده است. قیمت جدید را بررسی و دوباره تأیید کنید.',
                409,
                [
                    'product_id' => $productId,
                    'flash_deal_id' => $dealId,
                    'server_promo_toman' => (int) $line['promo_unit_price'],
                ]
            );
        }
        if ($line['campaign_qty_limit'] !== null && ((int) $line['campaign_qty_sold'] + $qty) > (int) $line['campaign_qty_limit']) {
            throw new FlashDealException('FLASH_DEAL_LIMIT_EXCEEDED', 'موجودی این پیشنهاد ویژه تمام شده است', 409);
        }
        $already = flash_deals_customer_purchased($pdo, $dealId, $productId, $salesUserId, $siteUserId);
        if ($line['per_customer_qty_limit'] !== null && ($already + $qty) > (int) $line['per_customer_qty_limit']) {
            throw new FlashDealException('FLASH_DEAL_LIMIT_EXCEEDED', 'سقف خرید این پیشنهاد برای شما تکمیل شده است', 409);
        }
        $pdo->prepare(
            'UPDATE flash_deal_products SET campaign_qty_sold = campaign_qty_sold + ? WHERE id = ?'
        )->execute([$qty, (int) $line['id']]);
    }
}

/**
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */
function flash_deals_order_item_payload(array $item): array
{
    $id = isset($item['flash_deal_id']) && $item['flash_deal_id'] !== null
        ? (int) $item['flash_deal_id']
        : 0;
    if ($id <= 0) {
        return [
            'is_flash_deal' => false,
            'flash_deal_id' => null,
            'flash_deal_title' => null,
            'flash_deal_version' => null,
            'regular_unit_price' => null,
            'promo_unit_price' => null,
            'discount_amount' => null,
            'line_total' => null,
        ];
    }

    return [
        'is_flash_deal' => true,
        'flash_deal_id' => $id,
        'flash_deal_title' => isset($item['flash_deal_title']) ? (string) $item['flash_deal_title'] : null,
        'flash_deal_version' => isset($item['flash_deal_version']) && $item['flash_deal_version'] !== null
            ? (int) $item['flash_deal_version']
            : null,
        'regular_unit_price' => isset($item['regular_unit_price']) && $item['regular_unit_price'] !== null
            ? (int) $item['regular_unit_price']
            : null,
        'promo_unit_price' => isset($item['promo_unit_price']) && $item['promo_unit_price'] !== null
            ? (int) $item['promo_unit_price']
            : null,
        'discount_amount' => isset($item['discount_amount']) && $item['discount_amount'] !== null
            ? (int) $item['discount_amount']
            : null,
        'line_total' => isset($item['line_total']) && $item['line_total'] !== null
            ? (int) $item['line_total']
            : null,
        'flash_deal_label' => 'پیشنهاد ویژه',
    ];
}

function flash_deals_notify_sales(PDO $pdo, string $title): void
{
    try {
        if (!function_exists('sales_push_ensure_schema')) {
            $lib = __DIR__ . '/sales-push.php';
            if (!is_readable($lib)) {
                return;
            }
            require_once $lib;
        }
        if (!function_exists('admin_push_send_to_token') || !function_exists('sales_push_ensure_schema')) {
            return;
        }
        sales_push_ensure_schema($pdo);
        $tokens = $pdo->query('SELECT DISTINCT fcm_token FROM sales_push_tokens')->fetchAll() ?: [];
        foreach ($tokens as $row) {
            $token = trim((string) ($row['fcm_token'] ?? ''));
            if ($token === '') {
                continue;
            }
            admin_push_send_to_token($token, 'پیشنهاد ویژه', $title, ['type' => 'flash_deal']);
        }
    } catch (Throwable $e) {
        error_log('[flash_deals_notify] ' . $e->getMessage());
    }
}
