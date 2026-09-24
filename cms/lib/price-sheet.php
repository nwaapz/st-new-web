<?php
declare(strict_types=1);

require_once __DIR__ . '/price-import.php';
require_once __DIR__ . '/admin-audit.php';
require_once __DIR__ . '/product-car-models.php';
require_once __DIR__ . '/product-categories.php';
require_once __DIR__ . '/product-series-categories.php';
require_once __DIR__ . '/product-stock.php';
require_once __DIR__ . '/product-series.php';

function price_sheet_warranty_text(?string $description): string
{
    $raw = trim((string) $description);
    if ($raw === '') {
        return '—';
    }
    if (preg_match('/^گارانتی:\s*(.+)/u', $raw, $match)) {
        $value = trim((string) ($match[1] ?? ''));
        return $value !== '' ? $value : '—';
    }

    return '—';
}

function price_sheet_parse_toman_amount(string $raw): ?int
{
    $text = trim($raw);
    if ($text === '') {
        return null;
    }

    $normalized = '';
    $persianDigits = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];
    $length = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $length; $i++) {
        $ch = mb_substr($text, $i, 1, 'UTF-8');
        if ($ch === '٬' || $ch === '،' || $ch === ',' || $ch === ' ' || $ch === "\u{00A0}") {
            continue;
        }
        $normalized .= $persianDigits[$ch] ?? $ch;
    }

    $normalized = preg_replace('/تومان$/u', '', $normalized);
    $normalized = trim((string) $normalized);
    if ($normalized === '' || preg_match('/ریال/u', $text)) {
        return null;
    }
    if (!preg_match('/^\d+$/', $normalized) || strlen($normalized) > 12) {
        return null;
    }

    return (int) $normalized;
}

function price_sheet_pack_price_text(string $unitPrice, ?int $packSize): string
{
    $unit = price_sheet_parse_toman_amount($unitPrice);
    if ($unit === null || $packSize === null || $packSize <= 0) {
        return '—';
    }

    $amount = $unit * $packSize;
    $grouped = number_format($amount, 0, '', ',');
    $grouped = str_replace(',', '٬', $grouped);

    return cms_to_persian_digits($grouped) . ' تومان';
}

function price_sheet_page_url(bool $fromWarehouse = false): string
{
    return $fromWarehouse ? 'price-sheet.php?from=warehouse' : 'price-sheet.php';
}

function price_sheet_format_toman_amount(int $amount): string
{
    $grouped = number_format(max(0, $amount), 0, '', ',');
    $grouped = str_replace(',', '٬', $grouped);

    return cms_to_persian_digits($grouped) . ' تومان';
}

function price_sheet_price_input_value(string $priceText): string
{
    $amount = price_sheet_parse_toman_amount($priceText);
    if ($amount === null) {
        return preg_replace('/\s*تومان\s*$/u', '', trim($priceText));
    }

    $grouped = number_format(max(0, $amount), 0, '', ',');
    $grouped = str_replace(',', '٬', $grouped);

    return cms_to_persian_digits($grouped);
}

function price_sheet_clean_cell_value(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || $value === '—') {
        return '';
    }

    return $value;
}

/**
 * @param array<string,mixed> $input
 */
function price_sheet_resolve_row_fields(array $input): array
{
    $visualId = price_import_normalize_visual_id((string) ($input['visual_id'] ?? ''));
    $name = trim((string) ($input['name'] ?? ''));
    $warrantyText = price_sheet_clean_cell_value((string) ($input['warranty_text'] ?? ''));
    $priceText = trim((string) ($input['price_text'] ?? ''));
    $packPriceText = trim((string) ($input['pack_price_text'] ?? ''));
    $packRaw = trim((string) ($input['pack_size'] ?? ''));
    $packSize = $packRaw === '' ? null : max(0, (int) $packRaw);
    if ($packSize === 0) {
        $packSize = null;
    }

    if ($priceText === '' && $packPriceText !== '' && $packSize !== null && $packSize > 0) {
        $packAmount = price_sheet_parse_toman_amount($packPriceText);
        if ($packAmount !== null && $packAmount > 0) {
            $unitAmount = (int) floor($packAmount / $packSize);
            if ($unitAmount > 0) {
                $priceText = price_sheet_format_toman_amount($unitAmount);
            }
        }
    }

    $unitAmount = price_sheet_parse_toman_amount($priceText);
    if ($unitAmount !== null && $unitAmount > 0) {
        $priceText = price_sheet_format_toman_amount($unitAmount);
    }

    $stockRaw = trim((string) ($input['stock_qty'] ?? ''));
    $stockQty = null;
    if ($stockRaw !== '') {
        $stockQty = products_normalize_stock_qty($stockRaw, 0);
    }

    return [
        'visual_id' => $visualId,
        'name' => $name,
        'warranty_text' => $warrantyText,
        'price_text' => $priceText,
        'pack_size' => $packSize,
        'stock_qty' => $stockQty,
    ];
}

function price_sheet_sync_product_stock(PDO $pdo, string $visualId, ?int $stockQty): void
{
    price_sheet_sync_row_to_product($pdo, $visualId, ['stock_qty' => $stockQty]);
}

/**
 * @param array{name?:string,warranty_text?:string,stock_qty?:int|null} $fields
 */
function price_sheet_sync_row_to_product(PDO $pdo, string $visualId, array $fields): void
{
    products_ensure_stock_schema($pdo);
    $entities = price_import_find_entities_by_visual_id($pdo, $visualId);
    $product = $entities['product'] ?? null;
    if (!is_array($product) || (int) ($product['id'] ?? 0) <= 0) {
        return;
    }

    $productId = (int) $product['id'];
    $existingName = trim((string) ($product['name'] ?? ''));
    $existingDescription = (string) ($product['description'] ?? '');
    $name = trim((string) ($fields['name'] ?? ''));
    $warrantyText = price_sheet_clean_cell_value((string) ($fields['warranty_text'] ?? ''));
    $newDescription = price_import_description_with_warranty($existingDescription, $warrantyText);
    $stockQty = array_key_exists('stock_qty', $fields) ? $fields['stock_qty'] : null;
    $currentStock = products_stock_qty($pdo, $productId);

    $stmt = $pdo->prepare(
        'UPDATE products SET name = ?, description = ?, stock_qty = ? WHERE id = ?'
    );
    $stmt->execute([
        $name !== '' ? $name : $existingName,
        $newDescription,
        $stockQty !== null ? $stockQty : $currentStock,
        $productId,
    ]);
}

/**
 * @param array{name?:string,warranty_text?:string} $fields
 */
function price_sheet_sync_row_to_series(PDO $pdo, string $visualId, array $fields): void
{
    $entities = price_import_find_entities_by_visual_id($pdo, $visualId);
    $series = $entities['series'] ?? null;
    if (!is_array($series) || (int) ($series['id'] ?? 0) <= 0) {
        return;
    }

    $seriesId = (int) $series['id'];
    $stmt = $pdo->prepare('SELECT name, description FROM product_series WHERE id = ? LIMIT 1');
    $stmt->execute([$seriesId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        return;
    }

    $existingName = trim((string) ($existing['name'] ?? ''));
    $existingDescription = (string) ($existing['description'] ?? '');
    $name = trim((string) ($fields['name'] ?? ''));
    $warrantyText = price_sheet_clean_cell_value((string) ($fields['warranty_text'] ?? ''));
    $newDescription = price_import_description_with_warranty($existingDescription, $warrantyText);

    $update = $pdo->prepare(
        'UPDATE product_series SET name = ?, description = ? WHERE id = ?'
    );
    $update->execute([
        $name !== '' ? $name : $existingName,
        $newDescription,
        $seriesId,
    ]);
}

/**
 * @param list<string> $visualIds
 * @return array<string, array{model_name:string,warranty_text:string,stock_qty:?int,is_series:bool}>
 */
function price_sheet_load_catalog_meta_by_visual_ids(PDO $pdo, array $visualIds): array
{
    if ($visualIds === []) {
        return [];
    }

    products_ensure_stock_schema($pdo);
    cms_ensure_product_car_models_schema($pdo);
    price_sheet_ensure_series_model_column($pdo);

    /** @var array<string, array{model_name:string,warranty_text:string,stock_qty:?int,is_series:bool}> $metaByVisual */
    $metaByVisual = [];
    $placeholders = implode(',', array_fill(0, count($visualIds), '?'));
    $params = array_values($visualIds);

    $productModelSql = cms_product_model_names_sql('p');
    $productStmt = $pdo->prepare(
        "SELECT p.visual_id, p.description, p.stock_qty, {$productModelSql} AS model_name
         FROM products p
         WHERE p.visual_id IN ({$placeholders})"
    );
    $productStmt->execute($params);
    foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $productRow) {
        $visualId = price_import_normalize_visual_id((string) ($productRow['visual_id'] ?? ''));
        if ($visualId === '') {
            continue;
        }
        $productStock = $productRow['stock_qty'];
        $metaByVisual[$visualId] = [
            'model_name' => trim((string) ($productRow['model_name'] ?? '')),
            'warranty_text' => price_sheet_warranty_text((string) ($productRow['description'] ?? '')),
            'stock_qty' => $productStock !== null ? max(0, (int) $productStock) : null,
            'is_series' => false,
        ];
    }

    $seriesStmt = $pdo->prepare(
        "SELECT s.visual_id, s.description,
                (SELECT GROUP_CONCAT(m.name ORDER BY pscm.sort_order ASC, m.sort_order ASC, m.name ASC SEPARATOR ' · ')
                 FROM product_series_car_models pscm
                 JOIN car_models m ON m.id = pscm.car_model_id
                 WHERE pscm.series_id = s.id) AS model_name
         FROM product_series s
         WHERE s.visual_id IN ({$placeholders})"
    );
    $seriesStmt->execute($params);
    foreach ($seriesStmt->fetchAll(PDO::FETCH_ASSOC) as $seriesRow) {
        $visualId = price_import_normalize_visual_id((string) ($seriesRow['visual_id'] ?? ''));
        if ($visualId === '') {
            continue;
        }

        $seriesModel = price_sheet_clean_cell_value((string) ($seriesRow['model_name'] ?? ''));
        $seriesWarranty = price_sheet_warranty_text((string) ($seriesRow['description'] ?? ''));
        $existing = $metaByVisual[$visualId] ?? null;

        if ($existing === null) {
            $metaByVisual[$visualId] = [
                'model_name' => $seriesModel,
                'warranty_text' => $seriesWarranty,
                'stock_qty' => null,
                'is_series' => true,
            ];
            continue;
        }

        $existing['is_series'] = true;
        if ($seriesModel !== '') {
            $existing['model_name'] = $seriesModel;
        }
        if (($existing['warranty_text'] === '' || $existing['warranty_text'] === '—') && $seriesWarranty !== '—') {
            $existing['warranty_text'] = $seriesWarranty;
        }
        $metaByVisual[$visualId] = $existing;
    }

    return $metaByVisual;
}

/**
 * @param array<string,mixed> $sheetRow
 * @param array{model_name:string,warranty_text:string,stock_qty:?int,is_series?:bool}|null $catalogMeta
 */
function price_sheet_apply_catalog_meta_to_row(array &$sheetRow, ?array $catalogMeta): void
{
    if ($catalogMeta === null) {
        return;
    }

    $sheetRow['model_name'] = price_sheet_clean_cell_value((string) ($catalogMeta['model_name'] ?? ''));
    if (($sheetRow['warranty_text'] ?? '') === '') {
        $sheetRow['warranty_text'] = price_sheet_clean_cell_value((string) ($catalogMeta['warranty_text'] ?? ''));
    }
    if (($sheetRow['stock_qty'] ?? null) === null && ($catalogMeta['stock_qty'] ?? null) !== null) {
        $sheetRow['stock_qty'] = $catalogMeta['stock_qty'];
    }
}

function price_sheet_resolve_catalog_category_id(PDO $pdo, string $visualId): ?int
{
    $visualId = price_import_normalize_visual_id($visualId);
    if ($visualId === '') {
        return null;
    }

    cms_ensure_product_categories_schema($pdo);
    cms_series_ensure_categories_schema($pdo);

    $entities = price_import_find_entities_by_visual_id($pdo, $visualId);
    $product = $entities['product'] ?? null;
    if (is_array($product) && (int) ($product['id'] ?? 0) > 0) {
        $categoryIds = cms_product_load_category_ids($pdo, (int) $product['id']);
        if ($categoryIds !== []) {
            return (int) $categoryIds[0];
        }
    }

    $series = $entities['series'] ?? null;
    if (is_array($series) && (int) ($series['id'] ?? 0) > 0) {
        $categoryIds = cms_series_load_category_ids($pdo, (int) $series['id']);
        if ($categoryIds !== []) {
            return (int) $categoryIds[0];
        }
    }

    return null;
}

/**
 * @return array{id:int,category_id:int,category_name:string,visual_id:string}|null
 */
function price_sheet_find_row_by_visual_id(PDO $pdo, string $visualId): ?array
{
    $visualId = price_import_normalize_visual_id($visualId);
    if ($visualId === '') {
        return null;
    }

    price_sheet_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT r.id, r.category_id, c.name AS category_name
         FROM price_sheet_rows r
         JOIN categories c ON c.id = r.category_id
         WHERE r.visual_id = ?
         ORDER BY r.id ASC
         LIMIT 1'
    );
    $stmt->execute([$visualId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'category_id' => (int) ($row['category_id'] ?? 0),
        'category_name' => (string) ($row['category_name'] ?? ''),
        'visual_id' => $visualId,
    ];
}

/**
 * Find an existing draft row by کد کالا, or add one from the shop catalog.
 *
 * @return array{visual_id:string,category_id:int,category_name:string,added:bool}
 */
function price_sheet_find_or_add_by_visual_id(PDO $pdo, string $visualId): array
{
    $visualId = price_import_normalize_visual_id($visualId);
    if ($visualId === '') {
        throw new RuntimeException('کد کالا را وارد کنید');
    }

    $existing = price_sheet_find_row_by_visual_id($pdo, $visualId);
    if ($existing !== null) {
        return [
            'visual_id' => $existing['visual_id'],
            'category_id' => $existing['category_id'],
            'category_name' => $existing['category_name'],
            'added' => false,
        ];
    }

    $resolved = price_import_resolve_by_visual_id($pdo, $visualId);
    if ($resolved === null) {
        throw new RuntimeException('کد «' . $visualId . '» در فروشگاه (محصول یا سری) یافت نشد');
    }

    $categoryId = price_sheet_resolve_catalog_category_id($pdo, $visualId);
    if ($categoryId === null || $categoryId <= 0) {
        throw new RuntimeException('برای کد «' . $visualId . '» دسته‌ای در فروشگاه تعریف نشده است');
    }

    $catStmt = $pdo->prepare('SELECT name FROM categories WHERE id = ? LIMIT 1');
    $catStmt->execute([$categoryId]);
    $categoryName = (string) ($catStmt->fetchColumn() ?: '');

    $catalogMeta = price_sheet_load_catalog_meta_by_visual_ids($pdo, [$visualId])[$visualId] ?? null;
    $modelName = price_sheet_clean_cell_value((string) ($catalogMeta['model_name'] ?? ''));
    $warrantyText = price_sheet_clean_cell_value((string) ($catalogMeta['warranty_text'] ?? ''));
    if ($warrantyText === '—') {
        $warrantyText = '';
    }

    $priceText = trim((string) ($resolved['price_text'] ?? ''));
    if ($priceText === '') {
        throw new RuntimeException('قیمت کد «' . $visualId . '» در فروشگاه خالی است');
    }

    $packSize = $resolved['pack_size'] ?? null;
    if ($packSize !== null) {
        $packSize = max(0, (int) $packSize);
        if ($packSize === 0) {
            $packSize = null;
        }
    }

    $stockQty = null;
    if (is_array($catalogMeta) && array_key_exists('stock_qty', $catalogMeta)) {
        $stockQty = $catalogMeta['stock_qty'];
    }

    $maxSortStmt = $pdo->prepare(
        'SELECT COALESCE(MAX(sort_order), -1) FROM price_sheet_rows WHERE category_id = ?'
    );
    $maxSortStmt->execute([$categoryId]);
    $sortOrder = (int) $maxSortStmt->fetchColumn() + 1;

    price_sheet_persist_draft_row(
        $pdo,
        $categoryId,
        0,
        $categoryId,
        [
            'visual_id' => $visualId,
            'name' => (string) ($resolved['name'] ?? ''),
            'model_name' => $modelName,
            'warranty_text' => $warrantyText,
            'price_text' => $priceText,
            'pack_size' => $packSize,
            'stock_qty' => $stockQty,
        ],
        $sortOrder
    );
    price_sheet_touch_draft_updated($pdo);

    return [
        'visual_id' => $visualId,
        'category_id' => $categoryId,
        'category_name' => $categoryName,
        'added' => true,
    ];
}

/**
 * @param array{visual_id:string,name:string,model_name:string,warranty_text:string,price_text:string,pack_size:?int,stock_qty:?int} $fields
 */
function price_sheet_persist_draft_row(
    PDO $pdo,
    int $frameCategoryId,
    int $rowId,
    int $targetCategoryId,
    array $fields,
    int $sortOrder
): void {
    if ($targetCategoryId <= 0) {
        $targetCategoryId = $frameCategoryId;
    }

    $visualId = $fields['visual_id'];
    $dupStmt = $pdo->prepare(
        'SELECT id FROM price_sheet_rows WHERE category_id = ? AND visual_id = ? LIMIT 1'
    );
    $dupStmt->execute([$targetCategoryId, $visualId]);
    $existingTargetId = (int) ($dupStmt->fetchColumn() ?: 0);

    $updateStmt = $pdo->prepare(
        'UPDATE price_sheet_rows
         SET category_id = ?, visual_id = ?, name = ?, model_name = ?, warranty_text = ?, price_text = ?, pack_size = ?, stock_qty = ?, sort_order = ?
         WHERE id = ?'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO price_sheet_rows (category_id, visual_id, name, model_name, warranty_text, price_text, pack_size, stock_qty, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $deleteByIdStmt = $pdo->prepare('DELETE FROM price_sheet_rows WHERE id = ?');

    $bindUpdate = static function (PDOStatement $stmt, int $keepId) use ($targetCategoryId, $fields, $sortOrder): void {
        $stmt->execute([
            $targetCategoryId,
            $fields['visual_id'],
            $fields['name'],
            $fields['model_name'],
            $fields['warranty_text'],
            $fields['price_text'],
            $fields['pack_size'],
            $fields['stock_qty'],
            $sortOrder,
            $keepId,
        ]);
    };

    if ($existingTargetId > 0) {
        $bindUpdate($updateStmt, $existingTargetId);
        if ($rowId > 0 && $rowId !== $existingTargetId) {
            $deleteByIdStmt->execute([$rowId]);
        }
        return;
    }

    if ($rowId > 0) {
        $bindUpdate($updateStmt, $rowId);
        return;
    }

    $insertStmt->execute([
        $targetCategoryId,
        $fields['visual_id'],
        $fields['name'],
        $fields['model_name'],
        $fields['warranty_text'],
        $fields['price_text'],
        $fields['pack_size'],
        $fields['stock_qty'],
        $sortOrder,
    ]);
}

function price_sheet_move_row_to_category(PDO $pdo, int $rowId, int $targetCategoryId): bool
{
    if ($rowId <= 0 || $targetCategoryId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id, category_id, visual_id, name, model_name, warranty_text, price_text, pack_size, stock_qty, sort_order
         FROM price_sheet_rows
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$rowId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $currentCategoryId = (int) ($row['category_id'] ?? 0);
    if ($currentCategoryId === $targetCategoryId) {
        return false;
    }

    price_sheet_persist_draft_row(
        $pdo,
        $currentCategoryId,
        $rowId,
        $targetCategoryId,
        [
            'visual_id' => price_import_normalize_visual_id((string) ($row['visual_id'] ?? '')),
            'name' => (string) ($row['name'] ?? ''),
            'model_name' => price_sheet_clean_cell_value((string) ($row['model_name'] ?? '')),
            'warranty_text' => price_sheet_clean_cell_value((string) ($row['warranty_text'] ?? '')),
            'price_text' => (string) ($row['price_text'] ?? ''),
            'pack_size' => $row['pack_size'] !== null ? (int) $row['pack_size'] : null,
            'stock_qty' => $row['stock_qty'] !== null ? (int) $row['stock_qty'] : null,
        ],
        (int) ($row['sort_order'] ?? 0)
    );

    return true;
}

/**
 * @return array{moved:int,unchanged:int,unresolved:int}
 */
function price_sheet_sync_categories_from_catalog(PDO $pdo): array
{
    price_sheet_ensure_schema($pdo);
    cms_ensure_product_categories_schema($pdo);
    cms_series_ensure_categories_schema($pdo);

    $rows = $pdo->query(
        'SELECT id, category_id, visual_id
         FROM price_sheet_rows
         ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $moved = 0;
    $unchanged = 0;
    $unresolved = 0;

    foreach ($rows as $row) {
        $rowId = (int) ($row['id'] ?? 0);
        $currentCategoryId = (int) ($row['category_id'] ?? 0);
        $visualId = price_import_normalize_visual_id((string) ($row['visual_id'] ?? ''));
        $targetCategoryId = price_sheet_resolve_catalog_category_id($pdo, $visualId);

        if ($targetCategoryId === null || $targetCategoryId <= 0) {
            $unresolved++;
            continue;
        }

        if ($targetCategoryId === $currentCategoryId) {
            $unchanged++;
            continue;
        }

        if (price_sheet_move_row_to_category($pdo, $rowId, $targetCategoryId)) {
            $moved++;
        }
    }

    if ($moved > 0) {
        price_sheet_touch_draft_updated($pdo);
    }

    return [
        'moved' => $moved,
        'unchanged' => $unchanged,
        'unresolved' => $unresolved,
    ];
}

/**
 * @param array<string,mixed> $row
 */
function price_sheet_apply_row_to_catalog(PDO $pdo, array $row, int $excelRow): array
{
    $visualId = price_import_normalize_visual_id((string) ($row['visual_id'] ?? ''));
    $name = trim((string) ($row['name'] ?? ''));
    $priceText = trim((string) ($row['price_text'] ?? ''));
    $newPackSize = isset($row['pack_size']) && $row['pack_size'] !== null
        ? (int) $row['pack_size']
        : null;
    $warrantyText = price_sheet_clean_cell_value((string) ($row['warranty_text'] ?? ''));
    $stockQty = array_key_exists('stock_qty', $row) && $row['stock_qty'] !== null
        ? (int) $row['stock_qty']
        : null;

    $result = [
        'visual_id' => $visualId,
        'name' => $name,
        'excel_row' => $excelRow,
        'updated' => false,
        'reason' => '',
        'entity' => '',
    ];

    if ($visualId === '') {
        $result['reason'] = 'کد کالا خالی است';
        return $result;
    }
    if ($priceText === '') {
        $result['reason'] = 'قیمت نامعتبر یا خالی است';
        return $result;
    }

    products_ensure_stock_schema($pdo);
    $entities = price_import_find_entities_by_visual_id($pdo, $visualId);
    $product = $entities['product'] ?? null;
    $series = $entities['series'] ?? null;

    if (!$product && !$series) {
        $result['reason'] = 'محصول یا سری کیت با این کد در سایت یافت نشد';
        return $result;
    }

    if ($name === '') {
        if (is_array($series)) {
            $name = (string) ($series['name'] ?? '');
        } elseif (is_array($product)) {
            $name = (string) ($product['name'] ?? '');
        }
    }

    $updatedProduct = false;
    $updatedSeries = false;

    if (is_array($product)) {
        $productId = (int) ($product['id'] ?? 0);
        $existingPrice = trim((string) ($product['price_text'] ?? ''));
        $existingPackSize = price_import_db_pack_size($product);
        $existingName = trim((string) ($product['name'] ?? ''));
        $existingDescription = (string) ($product['description'] ?? '');
        $existingStock = products_stock_qty($pdo, $productId);
        $newDescription = price_import_description_with_warranty($existingDescription, $warrantyText);
        $descriptionChanged = (string) ($newDescription ?? '') !== $existingDescription;
        $nameChanged = $name !== '' && $name !== $existingName;
        $stockChanged = $stockQty !== null && $stockQty !== $existingStock;
        $priceChanged = $existingPrice !== $priceText || $existingPackSize !== $newPackSize;

        if ($priceChanged || $nameChanged || $descriptionChanged || $stockChanged) {
            $stmt = $pdo->prepare(
                'UPDATE products
                 SET price_text = ?, pack_size = ?, name = ?, description = ?, stock_qty = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $priceText,
                $newPackSize,
                $name !== '' ? $name : $existingName,
                $newDescription,
                $stockQty !== null ? $stockQty : $existingStock,
                $productId,
            ]);
            $updatedProduct = true;
        }
    }

    if (is_array($series)) {
        $seriesId = (int) ($series['id'] ?? 0);
        $existingPrice = trim((string) ($series['price_text'] ?? ''));
        $existingPackSize = price_import_db_pack_size($series);
        $seriesRow = $pdo->prepare('SELECT name, description, model_name FROM product_series WHERE id = ? LIMIT 1');
        $seriesRow->execute([$seriesId]);
        $seriesExisting = $seriesRow->fetch(PDO::FETCH_ASSOC) ?: [];
        $existingSeriesName = trim((string) ($seriesExisting['name'] ?? ''));
        $existingSeriesDescription = (string) ($seriesExisting['description'] ?? '');
        $newSeriesDescription = price_import_description_with_warranty($existingSeriesDescription, $warrantyText);
        $seriesNameChanged = $name !== '' && $name !== $existingSeriesName;
        $seriesDescriptionChanged = (string) ($newSeriesDescription ?? '') !== $existingSeriesDescription;
        $seriesPriceChanged = $existingPrice !== $priceText || $existingPackSize !== $newPackSize;

        if ($seriesPriceChanged || $seriesNameChanged || $seriesDescriptionChanged) {
            $stmt = $pdo->prepare(
                'UPDATE product_series SET price_text = ?, pack_size = ?, name = ?, description = ? WHERE id = ?'
            );
            $stmt->execute([
                $priceText,
                $newPackSize,
                $name !== '' ? $name : $existingSeriesName,
                $newSeriesDescription,
                $seriesId,
            ]);
            $updatedSeries = true;
        }
    }

    if (!$updatedProduct && !$updatedSeries) {
        $entityHint = is_array($product) && is_array($series)
            ? 'both'
            : (is_array($series) ? 'series' : 'product');
        $result['reason'] = 'تغییری برای انتشار یافت نشد';
        $result['entity'] = $entityHint;
        return $result;
    }

    $entityParts = [];
    if ($updatedProduct) {
        $entityParts[] = 'product';
    }
    if ($updatedSeries) {
        $entityParts[] = 'series';
    }

    $result['updated'] = true;
    $result['name'] = $name;
    $result['entity'] = count($entityParts) === 2 ? 'both' : $entityParts[0];
    $result['pack_size'] = $newPackSize;

    return $result;
}

/**
 * @param list<array<string,mixed>> $sheetRows
 * @return array{
 *   total_rows:int,
 *   updated:int,
 *   skipped:list<array<string,mixed>>,
 *   updated_rows:list<array<string,mixed>>
 * }
 */
function price_sheet_apply_catalog_from_rows(PDO $pdo, array $sheetRows): array
{
    $updated = 0;
    $skipped = [];
    $updatedRows = [];

    $pdo->beginTransaction();
    try {
        foreach ($sheetRows as $index => $row) {
            $applyResult = price_sheet_apply_row_to_catalog($pdo, $row, $index + 1);
            if (!empty($applyResult['updated'])) {
                $updated++;
                $updatedRows[] = [
                    'visual_id' => (string) ($applyResult['visual_id'] ?? ''),
                    'name' => (string) ($applyResult['name'] ?? ''),
                    'excel_row' => (int) ($applyResult['excel_row'] ?? 0),
                    'entity' => (string) ($applyResult['entity'] ?? ''),
                    'pack_size' => $applyResult['pack_size'] ?? null,
                ];
                continue;
            }

            $skipped[] = [
                'visual_id' => (string) ($applyResult['visual_id'] ?? ''),
                'name' => (string) ($applyResult['name'] ?? ''),
                'excel_row' => (int) ($applyResult['excel_row'] ?? 0),
                'reason' => (string) ($applyResult['reason'] ?? 'نامشخص'),
                'entity' => (string) ($applyResult['entity'] ?? ''),
            ];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'total_rows' => count($sheetRows),
        'updated' => $updated,
        'skipped' => $skipped,
        'updated_rows' => $updatedRows,
    ];
}

function price_sheet_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS price_sheet_rows (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED NOT NULL,
            visual_id VARCHAR(64) NOT NULL,
            name VARCHAR(512) NOT NULL DEFAULT \'\',
            price_text VARCHAR(64) NOT NULL DEFAULT \'\',
            pack_size INT UNSIGNED NULL,
            sort_order INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_price_sheet_category_visual (category_id, visual_id),
            KEY idx_price_sheet_category (category_id),
            KEY idx_price_sheet_visual (visual_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS price_sheet_meta (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            last_published_at VARCHAR(64) NULL,
            last_published_count INT NOT NULL DEFAULT 0,
            draft_updated_at VARCHAR(64) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $count = (int) $pdo->query('SELECT COUNT(*) FROM price_sheet_meta')->fetchColumn();
    if ($count === 0) {
        $pdo->exec(
            'INSERT INTO price_sheet_meta (id, last_published_at, last_published_count, draft_updated_at)
             VALUES (1, NULL, 0, NULL)'
        );
    }

    price_sheet_ensure_extended_columns($pdo);
    price_sheet_ensure_series_model_column($pdo);
}

function price_sheet_ensure_series_model_column(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM product_series')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[(string) ($row['Field'] ?? '')] = true;
    }
    if (!isset($cols['model_name'])) {
        $pdo->exec(
            "ALTER TABLE product_series
             ADD COLUMN model_name VARCHAR(512) NOT NULL DEFAULT '' AFTER name"
        );
    }
}

function price_sheet_ensure_extended_columns(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM price_sheet_rows')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[(string) ($row['Field'] ?? '')] = true;
    }

    if (!isset($cols['model_name'])) {
        $pdo->exec(
            "ALTER TABLE price_sheet_rows
             ADD COLUMN model_name VARCHAR(512) NOT NULL DEFAULT '' AFTER name"
        );
    }
    if (!isset($cols['warranty_text'])) {
        $pdo->exec(
            "ALTER TABLE price_sheet_rows
             ADD COLUMN warranty_text VARCHAR(255) NOT NULL DEFAULT '' AFTER model_name"
        );
    }
    if (!isset($cols['stock_qty'])) {
        $pdo->exec(
            'ALTER TABLE price_sheet_rows
             ADD COLUMN stock_qty INT UNSIGNED NULL AFTER pack_size'
        );
    }
}

/**
 * @return array{last_published_at:?string,last_published_count:int,draft_updated_at:?string}
 */
function price_sheet_load_meta(PDO $pdo): array
{
    price_sheet_ensure_schema($pdo);
    $stmt = $pdo->query('SELECT last_published_at, last_published_count, draft_updated_at FROM price_sheet_meta WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'last_published_at' => null,
            'last_published_count' => 0,
            'draft_updated_at' => null,
        ];
    }

    return [
        'last_published_at' => $row['last_published_at'] !== null ? (string) $row['last_published_at'] : null,
        'last_published_count' => (int) ($row['last_published_count'] ?? 0),
        'draft_updated_at' => $row['draft_updated_at'] !== null ? (string) $row['draft_updated_at'] : null,
    ];
}

function price_sheet_touch_draft_updated(PDO $pdo): void
{
    price_sheet_ensure_schema($pdo);
    $stmt = $pdo->prepare('UPDATE price_sheet_meta SET draft_updated_at = ? WHERE id = 1');
    $stmt->execute([date('c')]);
}

function price_sheet_record_publish_meta(PDO $pdo, int $updated): void
{
    price_sheet_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'UPDATE price_sheet_meta SET last_published_at = ?, last_published_count = ? WHERE id = 1'
    );
    $stmt->execute([date('c'), max(0, $updated)]);
}

/**
 * @return list<array{id:int,name:string,row_count:int}>
 */
function price_sheet_list_categories(PDO $pdo): array
{
    price_sheet_ensure_schema($pdo);
    cms_ensure_product_categories_schema($pdo);

    $stmt = $pdo->query(
        'SELECT c.id, c.name, COUNT(r.id) AS row_count
         FROM categories c
         LEFT JOIN price_sheet_rows r ON r.category_id = c.id
         GROUP BY c.id, c.name
         ORDER BY c.name ASC'
    );

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'row_count' => (int) ($row['row_count'] ?? 0),
        ];
    }

    return $items;
}

/**
 * @return list<array<string,mixed>>
 */
function price_sheet_list_rows(PDO $pdo, int $categoryId): array
{
    price_sheet_ensure_schema($pdo);
    if ($categoryId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT id, category_id, visual_id, name, price_text, pack_size, sort_order, updated_at
         FROM price_sheet_rows
         WHERE category_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$categoryId]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $packSize = $row['pack_size'];
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'category_id' => (int) ($row['category_id'] ?? 0),
            'visual_id' => (string) ($row['visual_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'price_text' => (string) ($row['price_text'] ?? ''),
            'pack_size' => $packSize !== null ? (int) $packSize : null,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * @param list<array{id?:int,visual_id?:string,name?:string,price_text?:string,pack_size?:int|string|null,delete?:bool}> $postedRows
 * @return array{saved:int,deleted:int}
 */
function price_sheet_apply_posted_rows(
    PDO $pdo,
    int $categoryId,
    array $postedRows,
    PDOStatement $deleteStmt,
    PDOStatement $updateStmt,
    PDOStatement $insertStmt
): array {
    $saved = 0;
    $deleted = 0;
    $sortOrder = 0;

    $visualIds = [];
    foreach ($postedRows as $input) {
        if (!is_array($input) || !empty($input['delete'])) {
            continue;
        }
        $visualId = price_import_normalize_visual_id((string) ($input['visual_id'] ?? ''));
        if ($visualId !== '') {
            $visualIds[$visualId] = true;
        }
    }
    $catalogByVisual = price_sheet_load_catalog_meta_by_visual_ids($pdo, array_keys($visualIds));

    foreach ($postedRows as $input) {
        if (!is_array($input)) {
            continue;
        }

        $rowId = (int) ($input['id'] ?? 0);
        if (!empty($input['delete'])) {
            if ($rowId > 0) {
                $deleteStmt->execute([$rowId, $categoryId]);
                if ($deleteStmt->rowCount() > 0) {
                    $deleted++;
                }
            }
            continue;
        }

        $fields = price_sheet_resolve_row_fields($input);
        $visualId = $fields['visual_id'];
        $name = $fields['name'];
        $catalogMeta = $catalogByVisual[$visualId] ?? null;
        $modelName = price_sheet_clean_cell_value((string) ($catalogMeta['model_name'] ?? ''));
        $warrantyText = $fields['warranty_text'];
        $priceText = $fields['price_text'];
        $packSize = $fields['pack_size'];
        $stockQty = $fields['stock_qty'];

        if ($visualId === '' && $priceText === '' && $name === '') {
            continue;
        }
        if ($visualId === '') {
            throw new RuntimeException('کد کالا نمی‌تواند خالی باشد');
        }
        if ($priceText === '') {
            throw new RuntimeException('قیمت برای کد ' . $visualId . ' خالی است');
        }

        $targetCategoryId = price_sheet_resolve_catalog_category_id($pdo, $visualId) ?? $categoryId;

        price_sheet_persist_draft_row(
            $pdo,
            $categoryId,
            $rowId,
            $targetCategoryId,
            [
                'visual_id' => $visualId,
                'name' => $name,
                'model_name' => $modelName,
                'warranty_text' => $warrantyText,
                'price_text' => $priceText,
                'pack_size' => $packSize,
                'stock_qty' => $stockQty,
            ],
            $sortOrder
        );

        price_sheet_sync_row_to_product($pdo, $visualId, [
            'name' => $name,
            'warranty_text' => $warrantyText,
            'stock_qty' => $stockQty,
        ]);
        price_sheet_sync_row_to_series($pdo, $visualId, [
            'name' => $name,
            'warranty_text' => $warrantyText,
        ]);
        $saved++;
        $sortOrder++;
    }

    return ['saved' => $saved, 'deleted' => $deleted];
}

/**
 * @param list<array{id?:int,visual_id?:string,name?:string,price_text?:string,pack_size?:int|string|null,delete?:bool}> $postedRows
 * @return array{saved:int,deleted:int}
 */
function price_sheet_save_rows(PDO $pdo, int $categoryId, array $postedRows): array
{
    price_sheet_ensure_schema($pdo);
    if ($categoryId <= 0) {
        throw new RuntimeException('دسته انتخاب نشده است');
    }

    $catStmt = $pdo->prepare('SELECT id FROM categories WHERE id = ? LIMIT 1');
    $catStmt->execute([$categoryId]);
    if (!$catStmt->fetch()) {
        throw new RuntimeException('دسته یافت نشد');
    }

    $pdo->beginTransaction();
    try {
        $deleteStmt = $pdo->prepare('DELETE FROM price_sheet_rows WHERE id = ? AND category_id = ?');
        $updateStmt = $pdo->prepare(
            'UPDATE price_sheet_rows
             SET visual_id = ?, name = ?, model_name = ?, warranty_text = ?, price_text = ?, pack_size = ?, stock_qty = ?, sort_order = ?
             WHERE id = ? AND category_id = ?'
        );
        $insertStmt = $pdo->prepare(
            'INSERT INTO price_sheet_rows (category_id, visual_id, name, model_name, warranty_text, price_text, pack_size, stock_qty, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $result = price_sheet_apply_posted_rows(
            $pdo,
            $categoryId,
            $postedRows,
            $deleteStmt,
            $updateStmt,
            $insertStmt
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($result['saved'] > 0 || $result['deleted'] > 0) {
        price_sheet_touch_draft_updated($pdo);
    }

    return $result;
}

/**
 * @param array<int|string, list<array{id?:int,visual_id?:string,name?:string,price_text?:string,pack_size?:int|string|null,delete?:bool}>> $postedFrames
 * @return array{saved:int,deleted:int}
 */
function price_sheet_save_frames(PDO $pdo, array $postedFrames): array
{
    price_sheet_ensure_schema($pdo);
    cms_ensure_product_categories_schema($pdo);

    if ($postedFrames === []) {
        throw new RuntimeException('ردیفی برای ذخیره ارسال نشد');
    }

    $savedTotal = 0;
    $deletedTotal = 0;

    $pdo->beginTransaction();
    try {
        $deleteStmt = $pdo->prepare('DELETE FROM price_sheet_rows WHERE id = ? AND category_id = ?');
        $updateStmt = $pdo->prepare(
            'UPDATE price_sheet_rows
             SET visual_id = ?, name = ?, model_name = ?, warranty_text = ?, price_text = ?, pack_size = ?, stock_qty = ?, sort_order = ?
             WHERE id = ? AND category_id = ?'
        );
        $insertStmt = $pdo->prepare(
            'INSERT INTO price_sheet_rows (category_id, visual_id, name, model_name, warranty_text, price_text, pack_size, stock_qty, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $catStmt = $pdo->prepare('SELECT id FROM categories WHERE id = ? LIMIT 1');

        foreach ($postedFrames as $categoryIdRaw => $postedRows) {
            $categoryId = (int) $categoryIdRaw;
            if ($categoryId <= 0 || !is_array($postedRows)) {
                continue;
            }

            $catStmt->execute([$categoryId]);
            if (!$catStmt->fetch()) {
                throw new RuntimeException('دسته یافت نشد');
            }

            $result = price_sheet_apply_posted_rows(
                $pdo,
                $categoryId,
                $postedRows,
                $deleteStmt,
                $updateStmt,
                $insertStmt
            );
            $savedTotal += $result['saved'];
            $deletedTotal += $result['deleted'];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($savedTotal > 0 || $deletedTotal > 0) {
        price_sheet_touch_draft_updated($pdo);
    }

    return ['saved' => $savedTotal, 'deleted' => $deletedTotal];
}

/**
 * @return list<array{
 *   category_id:int,
 *   category_name:string,
 *   sort_order:int,
 *   rows:list<array<string,mixed>>
 * }>
 */
function price_sheet_list_frames(PDO $pdo): array
{
    price_sheet_ensure_schema($pdo);
    cms_ensure_product_categories_schema($pdo);

    $categoryStmt = $pdo->query(
        'SELECT id, name, COALESCE(sort_order, 9999) AS sort_order
         FROM categories
         ORDER BY sort_order ASC, name ASC'
    );
    $categories = $categoryStmt ? $categoryStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $stmt = $pdo->query(
        'SELECT r.id, r.category_id, r.visual_id, r.name, r.model_name, r.warranty_text,
                r.price_text, r.pack_size, r.stock_qty, r.sort_order
         FROM price_sheet_rows r
         ORDER BY r.category_id ASC, r.sort_order ASC, r.id ASC'
    );

    /** @var array<int, list<array<string,mixed>>> $rowsByCategory */
    $rowsByCategory = [];
    $visualIds = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $categoryId = (int) ($row['category_id'] ?? 0);
        if ($categoryId <= 0) {
            continue;
        }

        $visualId = price_import_normalize_visual_id((string) ($row['visual_id'] ?? ''));
        if ($visualId !== '') {
            $visualIds[$visualId] = true;
        }

        $packSize = $row['pack_size'];
        $stockQty = $row['stock_qty'];
        $rowsByCategory[$categoryId][] = [
            'id' => (int) ($row['id'] ?? 0),
            'category_id' => $categoryId,
            'visual_id' => $visualId,
            'name' => (string) ($row['name'] ?? ''),
            'model_name' => price_sheet_clean_cell_value((string) ($row['model_name'] ?? '')),
            'warranty_text' => price_sheet_clean_cell_value((string) ($row['warranty_text'] ?? '')),
            'price_text' => (string) ($row['price_text'] ?? ''),
            'pack_size' => $packSize !== null ? (int) $packSize : null,
            'stock_qty' => $stockQty !== null ? (int) $stockQty : null,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'pack_price_text' => '—',
        ];
    }

    /** @var array<string, array{model_name:string,warranty_text:string,stock_qty:?int,is_series:bool}> $catalogByVisual */
    $catalogByVisual = price_sheet_load_catalog_meta_by_visual_ids($pdo, array_keys($visualIds));

    $frames = [];
    foreach ($categories as $category) {
        $categoryId = (int) ($category['id'] ?? 0);
        if ($categoryId <= 0) {
            continue;
        }

        $frameRows = $rowsByCategory[$categoryId] ?? [];
        if ($frameRows === []) {
            continue;
        }

        foreach ($frameRows as &$sheetRow) {
            $visualId = (string) ($sheetRow['visual_id'] ?? '');
            price_sheet_apply_catalog_meta_to_row($sheetRow, $catalogByVisual[$visualId] ?? null);

            $sheetRow['pack_price_text'] = price_sheet_pack_price_text(
                (string) ($sheetRow['price_text'] ?? ''),
                $sheetRow['pack_size'] ?? null
            );
        }
        unset($sheetRow);

        $frames[] = [
            'category_id' => $categoryId,
            'category_name' => (string) ($category['name'] ?? ''),
            'sort_order' => (int) ($category['sort_order'] ?? 9999),
            'rows' => $frameRows,
        ];
    }

    return $frames;
}

function price_sheet_delete_row(PDO $pdo, int $rowId, int $categoryId): bool
{
    price_sheet_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM price_sheet_rows WHERE id = ? AND category_id = ?');
    $stmt->execute([$rowId, $categoryId]);
    if ($stmt->rowCount() > 0) {
        price_sheet_touch_draft_updated($pdo);
        return true;
    }

    return false;
}

/**
 * @param list<array<string,mixed>> $parsedRows
 * @return array{imported:int,skipped:int}
 */
function price_sheet_upsert_parsed_rows(PDO $pdo, int $categoryId, array $parsedRows, int $sortStart = 0): array
{
    if ($categoryId <= 0) {
        throw new RuntimeException('دسته انتخاب نشده است');
    }

    $imported = 0;
    $skipped = 0;

    $upsertStmt = $pdo->prepare(
        'INSERT INTO price_sheet_rows (category_id, visual_id, name, model_name, warranty_text, price_text, pack_size, stock_qty, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            model_name = VALUES(model_name),
            warranty_text = VALUES(warranty_text),
            price_text = VALUES(price_text),
            pack_size = VALUES(pack_size),
            stock_qty = COALESCE(VALUES(stock_qty), stock_qty),
            sort_order = VALUES(sort_order)'
    );
    $maxSortStmt = $pdo->prepare(
        'SELECT COALESCE(MAX(sort_order), -1) FROM price_sheet_rows WHERE category_id = ?'
    );

    /** @var array<int, int> $sortByCategory */
    $sortByCategory = [];

    foreach ($parsedRows as $row) {
        $visualId = price_import_normalize_visual_id((string) ($row['visual_id'] ?? ''));
        $priceText = trim((string) ($row['price_text'] ?? ''));
        if ($visualId === '' || $priceText === '') {
            $skipped++;
            continue;
        }

        $rowCategoryId = price_sheet_resolve_catalog_category_id($pdo, $visualId) ?? $categoryId;
        if (!isset($sortByCategory[$rowCategoryId])) {
            $maxSortStmt->execute([$rowCategoryId]);
            $sortByCategory[$rowCategoryId] = (int) $maxSortStmt->fetchColumn() + 1;
        }
        $sortOrder = $sortByCategory[$rowCategoryId];

        $name = trim((string) ($row['name'] ?? ''));
        $modelName = price_sheet_clean_cell_value((string) ($row['cars_raw'] ?? ''));
        $warrantyText = price_sheet_clean_cell_value((string) ($row['warranty'] ?? ''));
        $packSize = price_import_row_pack_size($row);
        $stockQty = null;

        $upsertStmt->execute([
            $rowCategoryId,
            $visualId,
            $name,
            $modelName,
            $warrantyText,
            $priceText,
            $packSize,
            $stockQty,
            $sortOrder,
        ]);
        $imported++;
        $sortByCategory[$rowCategoryId]++;
    }

    return ['imported' => $imported, 'skipped' => $skipped];
}

/**
 * @param array<string,mixed> $row
 * @param list<array<string,mixed>> $categories
 */
function price_sheet_resolve_row_category_id(PDO $pdo, array $row, array $categories): ?int
{
    $visualId = price_import_normalize_visual_id((string) ($row['visual_id'] ?? ''));
    if ($visualId !== '') {
        $catalogCategoryId = price_sheet_resolve_catalog_category_id($pdo, $visualId);
        if ($catalogCategoryId !== null && $catalogCategoryId > 0) {
            return $catalogCategoryId;
        }
    }

    $hints = [
        (string) ($row['section_hint'] ?? ''),
        (string) ($row['name_base'] ?? ''),
        (string) ($row['name'] ?? ''),
    ];
    foreach ($hints as $hint) {
        $categoryId = price_import_suggest_category_id($hint, $categories);
        if ($categoryId !== null && $categoryId > 0) {
            return $categoryId;
        }
    }

    return null;
}

/**
 * @return array{
 *   total_rows:int,
 *   imported:int,
 *   skipped_empty:int,
 *   skipped_unmapped:int,
 *   by_category:list<array{category_id:int,category_name:string,imported:int}>,
 *   unmapped_samples:list<array{visual_id:string,name:string,section_hint:string}>,
 *   message:string
 * }
 */
function price_sheet_import_from_google_sheet(PDO $pdo, string $sheetUrl): array
{
    price_sheet_ensure_schema($pdo);
    cms_ensure_product_categories_schema($pdo);

    $sheetUrl = trim($sheetUrl);
    if ($sheetUrl === '') {
        throw new RuntimeException('آدرس Google Sheet خالی است');
    }

    price_import_parse_google_sheet_url($sheetUrl);

    $download = price_import_fetch_google_sheet_file($sheetUrl);
    $stored = $download['path'];
    $downloadExt = $download['ext'];

    try {
        $parsed = price_import_parse_file($stored, $downloadExt);
        if ($parsed === []) {
            throw new RuntimeException('هیچ ردیف محصولی در Google Sheet یافت نشد');
        }

        $categories = price_import_load_categories($pdo);
        if ($categories === []) {
            throw new RuntimeException('هیچ دسته‌بندی در CMS تعریف نشده است');
        }

        $categoryNames = [];
        foreach ($categories as $category) {
            $categoryNames[(int) $category['id']] = (string) ($category['name'] ?? '');
        }

        /** @var array<int, list<array<string,mixed>>> $rowsByCategory */
        $rowsByCategory = [];
        $skippedEmpty = 0;
        $skippedUnmapped = 0;
        $unmappedSamples = [];

        foreach ($parsed as $row) {
            $visualId = price_import_normalize_visual_id((string) ($row['visual_id'] ?? ''));
            $priceText = trim((string) ($row['price_text'] ?? ''));
            if ($visualId === '' || $priceText === '') {
                $skippedEmpty++;
                continue;
            }

            $categoryId = price_sheet_resolve_row_category_id($pdo, $row, $categories);
            if ($categoryId === null || $categoryId <= 0) {
                $skippedUnmapped++;
                if (count($unmappedSamples) < 12) {
                    $unmappedSamples[] = [
                        'visual_id' => $visualId,
                        'name' => trim((string) ($row['name'] ?? '')),
                        'section_hint' => trim((string) ($row['section_hint'] ?? '')),
                    ];
                }
                continue;
            }

            $rowsByCategory[$categoryId][] = $row;
        }

        $importedTotal = 0;
        $byCategory = [];

        $pdo->beginTransaction();
        try {
            foreach ($rowsByCategory as $categoryId => $rows) {
                $result = price_sheet_upsert_parsed_rows($pdo, (int) $categoryId, $rows);
                $importedTotal += $result['imported'];
                $byCategory[] = [
                    'category_id' => (int) $categoryId,
                    'category_name' => $categoryNames[(int) $categoryId] ?? '',
                    'imported' => $result['imported'],
                ];
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($importedTotal > 0) {
            price_sheet_touch_draft_updated($pdo);
        }

        cms_setting_set(PRICE_SHEET_SETTING_GOOGLE_URL, $sheetUrl);
        cms_setting_set(PRICE_SHEET_SETTING_GOOGLE_IMPORTED_AT, date('c'));
        cms_setting_set(PRICE_SHEET_SETTING_GOOGLE_IMPORTED_COUNT, (string) $importedTotal);

        usort($byCategory, static fn(array $a, array $b): int => strcmp($a['category_name'], $b['category_name']));

        $message = sprintf(
            '%d ردیف از Google Sheet خوانده شد — %d ردیف در پیش‌نویس ذخیره شد — %d بدون دسته — %d نامعتبر',
            count($parsed),
            $importedTotal,
            $skippedUnmapped,
            $skippedEmpty
        );

        $actor = function_exists('cms_current_username') ? trim(cms_current_username()) : '';
        cms_admin_audit($pdo, 'price_sheet.google_import', [
            'entity_type' => 'price_sheet',
            'entity_label' => 'Google Sheet',
            'summary' => ($actor !== '' ? $actor . ' — ' : '') . $message,
            'detail' => [
                'sheet_url' => $sheetUrl,
                'imported' => $importedTotal,
                'skipped_unmapped' => $skippedUnmapped,
                'skipped_empty' => $skippedEmpty,
                'by_category' => $byCategory,
            ],
        ]);

        return [
            'total_rows' => count($parsed),
            'imported' => $importedTotal,
            'skipped_empty' => $skippedEmpty,
            'skipped_unmapped' => $skippedUnmapped,
            'by_category' => $byCategory,
            'unmapped_samples' => $unmappedSamples,
            'message' => $message,
        ];
    } finally {
        if (is_file($stored)) {
            @unlink($stored);
        }
    }
}

/**
 * @return array{sheet_url:string,imported_at:string,imported_at_display:string,imported_count:int}
 */
function price_sheet_get_google_import_info(): array
{
    $sheetUrl = trim(cms_setting_get(PRICE_SHEET_SETTING_GOOGLE_URL, ''));
    $importedAt = trim(cms_setting_get(PRICE_SHEET_SETTING_GOOGLE_IMPORTED_AT, ''));
    $importedCount = (int) cms_setting_get(PRICE_SHEET_SETTING_GOOGLE_IMPORTED_COUNT, '0');

    return [
        'sheet_url' => $sheetUrl,
        'imported_at' => $importedAt,
        'imported_at_display' => price_import_format_sync_display($importedAt),
        'imported_count' => $importedCount,
    ];
}

/**
 * @return array{imported:int,skipped:int}
 */
function price_sheet_import_file(PDO $pdo, int $categoryId, string $path, string $ext): array
{
    price_sheet_ensure_schema($pdo);
    if ($categoryId <= 0) {
        throw new RuntimeException('دسته انتخاب نشده است');
    }

    $parsed = price_import_parse_file($path, $ext);
    if ($parsed === []) {
        throw new RuntimeException('هیچ ردیف محصولی در فایل یافت نشد');
    }

    $pdo->beginTransaction();
    try {
        $result = price_sheet_upsert_parsed_rows($pdo, $categoryId, $parsed);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($result['imported'] > 0) {
        price_sheet_touch_draft_updated($pdo);
    }

    return $result;
}

/**
 * @return list<array<string,mixed>>
 */
function price_sheet_rows_for_publish(PDO $pdo, ?int $categoryId = null): array
{
    price_sheet_ensure_schema($pdo);
    if ($categoryId !== null && $categoryId > 0) {
        $stmt = $pdo->prepare(
            'SELECT r.*, c.name AS category_name
             FROM price_sheet_rows r
             INNER JOIN categories c ON c.id = r.category_id
             WHERE r.category_id = ?
             ORDER BY r.sort_order ASC, r.id ASC'
        );
        $stmt->execute([$categoryId]);
    } else {
        $stmt = $pdo->query(
            'SELECT r.*, c.name AS category_name
             FROM price_sheet_rows r
             INNER JOIN categories c ON c.id = r.category_id
             ORDER BY r.category_id ASC, r.sort_order ASC, r.id ASC'
        );
    }

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $packSize = $row['pack_size'];
        $stockQty = $row['stock_qty'];
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'category_id' => (int) ($row['category_id'] ?? 0),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'visual_id' => (string) ($row['visual_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'model_name' => price_sheet_clean_cell_value((string) ($row['model_name'] ?? '')),
            'warranty_text' => price_sheet_clean_cell_value((string) ($row['warranty_text'] ?? '')),
            'price_text' => (string) ($row['price_text'] ?? ''),
            'pack_size' => $packSize !== null ? (int) $packSize : null,
            'stock_qty' => $stockQty !== null ? (int) $stockQty : null,
        ];
    }

    return $rows;
}

/**
 * @param list<array<string,mixed>> $rows
 */
function price_sheet_validate_publish_conflicts(PDO $pdo, array $rows, ?int $scopeCategoryId = null): void
{
    if ($rows === []) {
        return;
    }

    $visualIds = [];
    foreach ($rows as $row) {
        $visualId = price_import_normalize_visual_id((string) ($row['visual_id'] ?? ''));
        if ($visualId !== '') {
            $visualIds[$visualId] = true;
        }
    }
    if ($visualIds === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($visualIds), '?'));
    $params = array_keys($visualIds);

    if ($scopeCategoryId !== null && $scopeCategoryId > 0) {
        $sql = "SELECT r.visual_id, r.price_text, r.pack_size, r.category_id, c.name AS category_name
                FROM price_sheet_rows r
                INNER JOIN categories c ON c.id = r.category_id
                WHERE r.visual_id IN ({$placeholders})
                ORDER BY r.visual_id ASC, r.category_id ASC";
    } else {
        $sql = "SELECT r.visual_id, r.price_text, r.pack_size, r.category_id, c.name AS category_name
                FROM price_sheet_rows r
                INNER JOIN categories c ON c.id = r.category_id
                WHERE r.visual_id IN ({$placeholders})
                ORDER BY r.visual_id ASC, r.category_id ASC";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    /** @var array<string, list<array<string,mixed>>> $byVisual */
    $byVisual = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $dbRow) {
        $visualId = price_import_normalize_visual_id((string) ($dbRow['visual_id'] ?? ''));
        if ($visualId === '') {
            continue;
        }
        $byVisual[$visualId][] = [
            'category_id' => (int) ($dbRow['category_id'] ?? 0),
            'category_name' => (string) ($dbRow['category_name'] ?? ''),
            'price_text' => trim((string) ($dbRow['price_text'] ?? '')),
            'pack_size' => $dbRow['pack_size'] !== null ? (int) $dbRow['pack_size'] : null,
        ];
    }

    $conflicts = [];
    foreach ($byVisual as $visualId => $entries) {
        if (count($entries) < 2) {
            continue;
        }

        if ($scopeCategoryId !== null && $scopeCategoryId > 0) {
            $inScope = false;
            foreach ($entries as $entry) {
                if ((int) $entry['category_id'] === $scopeCategoryId) {
                    $inScope = true;
                    break;
                }
            }
            if (!$inScope) {
                continue;
            }
        }

        $firstPrice = $entries[0]['price_text'];
        $firstPack = $entries[0]['pack_size'];
        $hasConflict = false;
        foreach ($entries as $entry) {
            if ($entry['price_text'] !== $firstPrice || $entry['pack_size'] !== $firstPack) {
                $hasConflict = true;
                break;
            }
        }
        if (!$hasConflict) {
            continue;
        }

        $parts = [];
        foreach ($entries as $entry) {
            $packLabel = $entry['pack_size'] !== null ? (string) $entry['pack_size'] : '—';
            $parts[] = sprintf(
                '%s (%s / بسته %s)',
                $entry['category_name'],
                $entry['price_text'],
                $packLabel
            );
        }
        $conflicts[] = 'کد ' . $visualId . ': ' . implode(' — ', $parts);
    }

    if ($conflicts !== []) {
        $message = 'قبل از انتشار، تضاد قیمت بین دسته‌ها را برطرف کنید:' . "\n" . implode("\n", $conflicts);
        throw new RuntimeException($message);
    }
}

/**
 * @return array{
 *   total_rows:int,
 *   updated:int,
 *   skipped:list<array{visual_id:string,name:string,excel_row:int,reason:string,entity?:string}>,
 *   updated_rows:list<array{visual_id:string,name:string,excel_row:int,entity:string,pack_size:?int}>,
 *   message:string,
 *   last_published_at:string,
 *   last_published_at_display:string,
 *   category_id:?int,
 *   category_name:string
 * }
 */
function price_sheet_publish(PDO $pdo, ?int $categoryId = null): array
{
    price_sheet_ensure_schema($pdo);
    cms_ensure_product_categories_schema($pdo);

    $categoryName = '';
    if ($categoryId !== null && $categoryId > 0) {
        $catStmt = $pdo->prepare('SELECT id, name FROM categories WHERE id = ? LIMIT 1');
        $catStmt->execute([$categoryId]);
        $cat = $catStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cat) {
            throw new RuntimeException('دسته یافت نشد');
        }
        $categoryName = (string) ($cat['name'] ?? '');
    }

    $sheetRows = price_sheet_rows_for_publish($pdo, $categoryId);
    if ($sheetRows === []) {
        throw new RuntimeException('ردیفی برای انتشار وجود ندارد');
    }

    price_sheet_validate_publish_conflicts($pdo, $sheetRows, $categoryId);

    $result = price_sheet_apply_catalog_from_rows($pdo, $sheetRows);
    price_sheet_record_publish_meta($pdo, (int) $result['updated']);

    $meta = price_sheet_load_meta($pdo);
    $scopeLabel = $categoryName !== '' ? 'دسته «' . $categoryName . '»' : 'همه دسته‌ها';
    $message = sprintf(
        'انتشار %s — %d ردیف — %d مورد به‌روز شد — %d ردیف رد شد',
        $scopeLabel,
        $result['total_rows'],
        $result['updated'],
        count($result['skipped'])
    );

    $actor = function_exists('cms_current_username') ? trim(cms_current_username()) : '';
    if ($actor === '' && function_exists('admin_audit_current_user')) {
        $auditUser = admin_audit_current_user($pdo);
        $actor = is_array($auditUser) ? trim((string) ($auditUser['username'] ?? '')) : '';
    }

    cms_admin_audit($pdo, 'price_sheet.publish', [
        'entity_type' => 'price_sheet',
        'entity_label' => $scopeLabel,
        'summary' => ($actor !== '' ? $actor . ' — ' : '') . $message,
        'detail' => [
            'category_id' => $categoryId,
            'updated' => $result['updated'],
            'total_rows' => $result['total_rows'],
            'skipped_count' => count($result['skipped']),
        ],
    ]);

    return array_merge($result, [
        'message' => $message,
        'last_published_at' => (string) ($meta['last_published_at'] ?? ''),
        'last_published_at_display' => price_import_format_sync_display($meta['last_published_at'] ?? ''),
        'category_id' => $categoryId,
        'category_name' => $categoryName,
    ]);
}

/**
 * @return array{
 *   draft_rows:int,
 *   last_published_at:string,
 *   last_published_at_display:string,
 *   last_published_count:int,
 *   draft_updated_at:string,
 *   draft_updated_at_display:string,
 *   categories:list<array{id:int,name:string,row_count:int}>
 * }
 */
function price_sheet_get_status(PDO $pdo): array
{
    price_sheet_ensure_schema($pdo);
    $meta = price_sheet_load_meta($pdo);
    $draftRows = (int) $pdo->query('SELECT COUNT(*) FROM price_sheet_rows')->fetchColumn();

    return [
        'draft_rows' => $draftRows,
        'last_published_at' => (string) ($meta['last_published_at'] ?? ''),
        'last_published_at_display' => price_import_format_sync_display($meta['last_published_at'] ?? ''),
        'last_published_count' => (int) ($meta['last_published_count'] ?? 0),
        'draft_updated_at' => (string) ($meta['draft_updated_at'] ?? ''),
        'draft_updated_at_display' => price_import_format_sync_display($meta['draft_updated_at'] ?? ''),
        'categories' => price_sheet_list_categories($pdo),
        'google_import' => price_sheet_get_google_import_info(),
    ];
}

function price_sheet_export_filename(bool $fromWarehouse = false): string
{
    require_once __DIR__ . '/jalali.php';
    $jalaliDay = cms_jalali_format_from_timestamp(date('Y-m-d H:i:s'));
    $jalaliDay = str_replace('/', '-', $jalaliDay);
    $jalaliDay = cms_to_persian_digits($jalaliDay);
    $suffix = $fromWarehouse ? 'لیست-قیمت-انبار' : 'لیست-قیمت';

    return $jalaliDay . '-' . $suffix . '.xlsx';
}

function price_sheet_export_include_stock_default(bool $fromWarehouse = false): bool
{
    return $fromWarehouse;
}

/**
 * @param array<string,mixed> $query
 */
function price_sheet_export_include_stock_from_query(array $query, bool $fromWarehouse = false): bool
{
    if ((string) ($query['export'] ?? '') === '1') {
        return (string) ($query['include_stock'] ?? '') === '1';
    }

    return price_sheet_export_include_stock_default($fromWarehouse);
}

/**
 * @return list<array{name:string,rows:list<list<string>>}>
 */
function price_sheet_build_export_sheets(PDO $pdo, bool $includeStock = false): array
{
    $frames = price_sheet_list_frames($pdo);
    $header = [
        'کد کالا',
        'نام',
        'خودرو',
        'گارانتی',
        'تعداد در کارتن',
        'قیمت واحد',
        'قیمت بسته',
    ];
    if ($includeStock) {
        $header[] = 'موجودی انبار';
    }

    $sheets = [];
    foreach ($frames as $frame) {
        $frameRows = is_array($frame['rows'] ?? null) ? $frame['rows'] : [];
        if ($frameRows === []) {
            continue;
        }

        $rows = [$header];
        foreach ($frameRows as $row) {
            $packPrice = (string) ($row['pack_price_text'] ?? '');
            if ($packPrice === '—') {
                $packPrice = '';
            }

            $line = [
                (string) ($row['visual_id'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['model_name'] ?? ''),
                (string) ($row['warranty_text'] ?? ''),
                $row['pack_size'] !== null ? (string) $row['pack_size'] : '',
                (string) ($row['price_text'] ?? ''),
                $packPrice,
            ];
            if ($includeStock) {
                $line[] = $row['stock_qty'] !== null ? (string) $row['stock_qty'] : '';
            }

            $rows[] = $line;
        }

        $sheets[] = [
            'name' => (string) ($frame['category_name'] ?? 'دسته'),
            'rows' => $rows,
        ];
    }

    return $sheets;
}

function price_sheet_export_page_url(bool $fromWarehouse = false, bool $includeStock = false): string
{
    $base = price_sheet_page_url($fromWarehouse);
    $query = 'export=1';
    if ($includeStock) {
        $query .= '&include_stock=1';
    }

    return $base . (str_contains($base, '?') ? '&' : '?') . $query;
}

function price_sheet_send_xlsx_export(PDO $pdo, bool $fromWarehouse = false, bool $includeStock = false): void
{
    require_once __DIR__ . '/price-export-xlsx.php';

    $sheets = price_sheet_build_export_sheets($pdo, $includeStock);
    if ($sheets === []) {
        throw new RuntimeException('پیش‌نویس خالی است — چیزی برای خروجی Excel نیست');
    }

    $filename = price_sheet_export_filename($fromWarehouse);
    $tmp = price_import_temp_dir() . DIRECTORY_SEPARATOR . 'export-' . bin2hex(random_bytes(8)) . '.xlsx';

    try {
        price_export_xlsx_write($tmp, $sheets);
        if (!is_file($tmp) || filesize($tmp) <= 0) {
            throw new RuntimeException('فایل Excel ساخته نشد');
        }
        cms_admin_audit($pdo, 'price_sheet.export', [
            'entity_type' => 'price_sheet',
            'summary' => cms_current_username() . ' — خروجی Excel لیست قیمت',
            'detail' => [
                'filename' => $filename,
                'sheet_count' => count($sheets),
                'from_warehouse' => $fromWarehouse,
                'include_stock' => $includeStock,
            ],
        ]);
        price_export_xlsx_send_download($filename, $tmp);
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }

    exit;
}
