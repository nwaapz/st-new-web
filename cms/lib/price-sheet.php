<?php
declare(strict_types=1);

require_once __DIR__ . '/price-import.php';
require_once __DIR__ . '/admin-audit.php';

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

    $saved = 0;
    $deleted = 0;
    $sortOrder = 0;

    $pdo->beginTransaction();
    try {
        $deleteStmt = $pdo->prepare('DELETE FROM price_sheet_rows WHERE id = ? AND category_id = ?');
        $updateStmt = $pdo->prepare(
            'UPDATE price_sheet_rows
             SET visual_id = ?, name = ?, price_text = ?, pack_size = ?, sort_order = ?
             WHERE id = ? AND category_id = ?'
        );
        $insertStmt = $pdo->prepare(
            'INSERT INTO price_sheet_rows (category_id, visual_id, name, price_text, pack_size, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

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

            $visualId = price_import_normalize_visual_id((string) ($input['visual_id'] ?? ''));
            $name = trim((string) ($input['name'] ?? ''));
            $priceText = trim((string) ($input['price_text'] ?? ''));
            $packRaw = trim((string) ($input['pack_size'] ?? ''));
            $packSize = $packRaw === '' ? null : max(0, (int) $packRaw);
            if ($packSize === 0) {
                $packSize = null;
            }

            if ($visualId === '' && $priceText === '' && $name === '') {
                continue;
            }
            if ($visualId === '') {
                throw new RuntimeException('کد کالا نمی‌تواند خالی باشد');
            }
            if ($priceText === '') {
                throw new RuntimeException('قیمت برای کد ' . $visualId . ' خالی است');
            }

            if ($rowId > 0) {
                $updateStmt->execute([
                    $visualId,
                    $name,
                    $priceText,
                    $packSize,
                    $sortOrder,
                    $rowId,
                    $categoryId,
                ]);
            } else {
                $insertStmt->execute([
                    $categoryId,
                    $visualId,
                    $name,
                    $priceText,
                    $packSize,
                    $sortOrder,
                ]);
            }
            $saved++;
            $sortOrder++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($saved > 0 || $deleted > 0) {
        price_sheet_touch_draft_updated($pdo);
    }

    return ['saved' => $saved, 'deleted' => $deleted];
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
    $sortOrder = $sortStart;
    if ($sortOrder <= 0) {
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM price_sheet_rows WHERE category_id = ?');
        $maxStmt->execute([$categoryId]);
        $sortOrder = (int) $maxStmt->fetchColumn() + 1;
    }

    $upsertStmt = $pdo->prepare(
        'INSERT INTO price_sheet_rows (category_id, visual_id, name, price_text, pack_size, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            price_text = VALUES(price_text),
            pack_size = VALUES(pack_size),
            sort_order = VALUES(sort_order)'
    );

    foreach ($parsedRows as $row) {
        $visualId = price_import_normalize_visual_id((string) ($row['visual_id'] ?? ''));
        $priceText = trim((string) ($row['price_text'] ?? ''));
        if ($visualId === '' || $priceText === '') {
            $skipped++;
            continue;
        }

        $name = trim((string) ($row['name'] ?? ''));
        $packSize = price_import_row_pack_size($row);

        $upsertStmt->execute([
            $categoryId,
            $visualId,
            $name,
            $priceText,
            $packSize,
            $sortOrder,
        ]);
        $imported++;
        $sortOrder++;
    }

    return ['imported' => $imported, 'skipped' => $skipped];
}

/**
 * @param array<string,mixed> $row
 * @param list<array<string,mixed>> $categories
 */
function price_sheet_resolve_row_category_id(array $row, array $categories): ?int
{
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

            $categoryId = price_sheet_resolve_row_category_id($row, $categories);
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
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'category_id' => (int) ($row['category_id'] ?? 0),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'visual_id' => (string) ($row['visual_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'price_text' => (string) ($row['price_text'] ?? ''),
            'pack_size' => $packSize !== null ? (int) $packSize : null,
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

    $parsedRows = [];
    foreach ($sheetRows as $index => $row) {
        $parsedRows[] = [
            'visual_id' => (string) ($row['visual_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'price_text' => (string) ($row['price_text'] ?? ''),
            'pack_size' => $row['pack_size'] ?? null,
            'excel_row' => $index + 1,
        ];
    }

    $result = price_import_apply_prices_only($pdo, $parsedRows);
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
