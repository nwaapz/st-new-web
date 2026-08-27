<?php
declare(strict_types=1);

require_once __DIR__ . '/price-import-xlsx.php';
require_once __DIR__ . '/search-text.php';
require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/product-car-models.php';
require_once __DIR__ . '/product-categories.php';

const PRICE_IMPORT_COL_ROW = 0;
const PRICE_IMPORT_COL_CODE = 1;
const PRICE_IMPORT_COL_NAME = 2;
const PRICE_IMPORT_COL_SPEC = 3;
const PRICE_IMPORT_COL_CARS = 4;
const PRICE_IMPORT_COL_PRICE = 5;
const PRICE_IMPORT_COL_WARRANTY = 6;
const PRICE_IMPORT_COL_PACK = 7;

function price_import_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS price_import_car_aliases (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          alias_norm VARCHAR(191) NOT NULL,
          car_model_id INT UNSIGNED NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_pica_alias_car (alias_norm, car_model_id),
          KEY idx_pica_car (car_model_id),
          CONSTRAINT fk_pica_car FOREIGN KEY (car_model_id) REFERENCES car_models (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function price_import_temp_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'startech-price-import';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function price_import_cell_string($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_float($value) && floor($value) === $value) {
        return (string) (int) $value;
    }
    return trim((string) $value);
}

function price_import_is_header_row(array $row): bool
{
    $first = price_import_cell_string($row[PRICE_IMPORT_COL_ROW] ?? null);
    return $first === 'ردیف';
}

function price_import_is_section_row(array $row): bool
{
    $a = price_import_cell_string($row[PRICE_IMPORT_COL_ROW] ?? null);
    $b = $row[PRICE_IMPORT_COL_CODE] ?? null;
    if ($a === '' || price_import_is_header_row($row)) {
        return false;
    }
    if ($b !== null && $b !== '' && is_numeric($b)) {
        return false;
    }
    return mb_strlen($a) >= 8;
}

function price_import_is_product_row(array $row): bool
{
    $code = $row[PRICE_IMPORT_COL_CODE] ?? null;
    return $code !== null && $code !== '' && is_numeric($code);
}

function price_import_build_name(array $row): string
{
    $base = price_import_cell_string($row[PRICE_IMPORT_COL_NAME] ?? null);
    $spec = price_import_cell_string($row[PRICE_IMPORT_COL_SPEC] ?? null);
    if ($base === '') {
        return $spec;
    }
    if ($spec === '') {
        return $base;
    }
    return $base . ' ' . $spec;
}

function price_import_rial_to_toman_text($rial): ?string
{
    if ($rial === null || $rial === '') {
        return null;
    }
    if (!is_numeric($rial)) {
        return null;
    }
    $toman = (int) round(((float) $rial) / 10);
    if ($toman <= 0) {
        return null;
    }
    return invoices_format_toman($toman);
}

function price_import_parse_pack_size($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $pack = (int) round((float) $value);
    return $pack > 0 ? $pack : null;
}

/**
 * @return list<list<mixed>>
 */
function price_import_read_csv_rows(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('خواندن CSV ناموفق بود');
    }
    if (!function_exists('str_starts_with')) {
        $content = substr($content, 0, 3) === "\xEF\xBB\xBF" ? substr($content, 3) : $content;
    } elseif (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }

    $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $rows[] = price_import_parse_csv_line($line);
    }
    return $rows;
}

/** @return list<mixed> */
function price_import_parse_csv_line(string $line): array
{
    $delimiter = substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    $fh = fopen('php://memory', 'r+');
    if ($fh === false) {
        return array_map('trim', explode($delimiter, $line));
    }
    fwrite($fh, $line);
    rewind($fh);
    $row = fgetcsv($fh, 0, $delimiter);
    fclose($fh);
    if (!is_array($row)) {
        return [];
    }

    $out = [];
    foreach ($row as $cell) {
        $cell = trim((string) $cell);
        if ($cell !== '' && is_numeric($cell)) {
            $out[] = strpos($cell, '.') !== false ? (float) $cell : (int) $cell;
        } else {
            $out[] = $cell;
        }
    }
    return $out;
}

/**
 * @param list<list<mixed>> $rawRows
 * @return list<array<string, mixed>>
 */
function price_import_parse_rows(array $rawRows): array
{
    $parsed = [];
    $sectionHint = '';

    foreach ($rawRows as $excelRowIndex => $row) {
        if (price_import_is_section_row($row)) {
            $sectionHint = trim(price_import_cell_string($row[PRICE_IMPORT_COL_ROW] ?? null));
            continue;
        }
        if (!price_import_is_product_row($row)) {
            continue;
        }

        $visualId = price_import_cell_string($row[PRICE_IMPORT_COL_CODE] ?? null);
        $parsed[] = [
            'excel_row' => $excelRowIndex + 1,
            'visual_id' => $visualId,
            'name_base' => price_import_cell_string($row[PRICE_IMPORT_COL_NAME] ?? null),
            'spec' => price_import_cell_string($row[PRICE_IMPORT_COL_SPEC] ?? null),
            'name' => price_import_build_name($row),
            'cars_raw' => price_import_cell_string($row[PRICE_IMPORT_COL_CARS] ?? null),
            'price_rial' => $row[PRICE_IMPORT_COL_PRICE] ?? null,
            'price_text' => price_import_rial_to_toman_text($row[PRICE_IMPORT_COL_PRICE] ?? null),
            'warranty' => price_import_cell_string($row[PRICE_IMPORT_COL_WARRANTY] ?? null),
            'pack_size' => price_import_parse_pack_size($row[PRICE_IMPORT_COL_PACK] ?? null),
            'section_hint' => $sectionHint,
        ];
    }

    return $parsed;
}

/**
 * @return list<array<string, mixed>>
 */
function price_import_parse_file(string $path, string $ext): array
{
    $ext = strtolower($ext);
    if ($ext === 'csv') {
        return price_import_parse_rows(price_import_read_csv_rows($path));
    }
    if ($ext === 'xlsx') {
        return price_import_parse_rows(price_import_xlsx_read_rows($path));
    }
    throw new RuntimeException('فرمت فایل پشتیبانی نمی‌شود — .xlsx یا .csv');
}

/**
 * @return list<array<string, mixed>>
 */
function price_import_parse_xlsx(string $path): array
{
    return price_import_parse_file($path, 'xlsx');
}

/** @return list<string> */
function price_import_expand_car_tokens(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $chunks = preg_split('/[,،\/·]+/u', $raw) ?: [];
    $tokens = [];

    foreach ($chunks as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }

        if (preg_match('/تیپ\s*(\d+)\s*و\s*(\d+)/u', $chunk, $m)) {
            $prefix = trim(preg_replace('/تیپ\s*\d+\s*و\s*\d+.*/u', '', $chunk) ?? $chunk);
            if ($prefix === '') {
                $parts = preg_split('/\s*-\s*/u', $chunk) ?: [];
                $prefix = trim((string) ($parts[0] ?? ''));
            }
            $tokens[] = trim($prefix . ' تیپ ' . $m[1]);
            $tokens[] = trim($prefix . ' تیپ ' . $m[2]);
            continue;
        }

        $parts = preg_split('/\s*-\s*/u', $chunk) ?: [];
        $base = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if ($base !== '' && preg_match('/^(تیپ|tu5|glx|ef7|موتور)/ui', $part)) {
                $tokens[] = trim($base . ' ' . $part);
            } else {
                $tokens[] = $part;
                if (!preg_match('/^(تیپ|tu5|glx|ef7|موتور)/ui', $part)) {
                    $base = $part;
                }
            }
        }
    }

    $unique = [];
    foreach ($tokens as $token) {
        $token = trim(preg_replace('/\s+/u', ' ', $token) ?? $token);
        if ($token !== '' && !in_array($token, $unique, true)) {
            $unique[] = $token;
        }
    }

    if ($unique === [] && $raw !== '') {
        $unique[] = $raw;
    }

    return $unique;
}

/** @return array<string, list<int>> alias_norm => car_model_ids */
function price_import_load_alias_map(PDO $pdo): array
{
    price_import_ensure_schema($pdo);
    $map = [];
    $stmt = $pdo->query(
        'SELECT alias_norm, car_model_id FROM price_import_car_aliases ORDER BY alias_norm ASC, car_model_id ASC'
    );
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $key = (string) $row['alias_norm'];
        if (!isset($map[$key])) {
            $map[$key] = [];
        }
        $map[$key][] = (int) $row['car_model_id'];
    }
    return $map;
}

/**
 * @param list<array{id:int,name:string}> $carModels
 * @param array<string, list<int>> $aliasMap
 * @return array{
 *   token: string,
 *   confidence: string,
 *   car_model_id: ?int,
 *   car_model_name: ?string,
 *   candidates: list<array{id:int,name:string,score:int}>
 * }
 */
function price_import_match_car_token(string $token, array $carModels, array $aliasMap): array
{
    $norm = search_normalize($token);
    $result = [
        'token' => $token,
        'confidence' => 'unmatched',
        'car_model_id' => null,
        'car_model_name' => null,
        'candidates' => [],
    ];

    if ($norm === '') {
        return $result;
    }

    if (isset($aliasMap[$norm])) {
        $id = (int) $aliasMap[$norm][0];
        foreach ($carModels as $model) {
            if ((int) $model['id'] === $id) {
                return [
                    'token' => $token,
                    'confidence' => 'certain',
                    'car_model_id' => $id,
                    'car_model_name' => (string) $model['name'],
                    'candidates' => [],
                ];
            }
        }
    }

    foreach ($carModels as $model) {
        $modelNorm = search_normalize((string) $model['name']);
        if ($modelNorm !== '' && $modelNorm === $norm) {
            return [
                'token' => $token,
                'confidence' => 'certain',
                'car_model_id' => (int) $model['id'],
                'car_model_name' => (string) $model['name'],
                'candidates' => [],
            ];
        }
    }

    $scored = [];
    foreach ($carModels as $model) {
        $modelNorm = search_normalize((string) $model['name']);
        if ($modelNorm === '' || mb_strlen($modelNorm) < 2) {
            continue;
        }
        $score = 0;
        if (mb_strpos($norm, $modelNorm) !== false || mb_strpos($modelNorm, $norm) !== false) {
            $score = mb_strlen($modelNorm);
        }
        if ($score > 0) {
            $scored[] = [
                'id' => (int) $model['id'],
                'name' => (string) $model['name'],
                'score' => $score,
            ];
        }
    }

    usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));

    if ($scored === []) {
        return $result;
    }

    $top = $scored[0];
    $secondScore = isset($scored[1]) ? (int) $scored[1]['score'] : 0;
    $result['candidates'] = array_slice($scored, 0, 8);

    if ($secondScore > 0 && $secondScore >= (int) floor($top['score'] * 0.85)) {
        return [
            'token' => $token,
            'confidence' => 'uncertain',
            'car_model_id' => null,
            'car_model_name' => null,
            'candidates' => $result['candidates'],
        ];
    }

    return [
        'token' => $token,
        'confidence' => 'likely',
        'car_model_id' => (int) $top['id'],
        'car_model_name' => (string) $top['name'],
        'candidates' => $result['candidates'],
    ];
}

/**
 * @param list<array{id:int,name:string}> $carModels
 * @param array<string, list<int>> $aliasMap
 * @return list<array<string, mixed>>
 */
function price_import_parse_car_string(string $raw, array $carModels, array $aliasMap): array
{
    $tokens = price_import_expand_car_tokens($raw);
    $matches = [];
    foreach ($tokens as $token) {
        $matches[] = price_import_match_car_token($token, $carModels, $aliasMap);
    }
    return $matches;
}

/** @return list<array{id:int,name:string}> */
function price_import_load_car_models(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, name FROM car_models WHERE published = 1 ORDER BY sort_order ASC, name ASC'
    )->fetchAll() ?: [];
}

/** @return list<array{id:int,name:string}> */
function price_import_load_categories(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, name FROM categories WHERE published = 1 ORDER BY sort_order ASC, name ASC'
    )->fetchAll() ?: [];
}

function price_import_suggest_category_id(string $sectionHint, array $categories): ?int
{
    $hint = search_normalize($sectionHint);
    if ($hint === '') {
        return null;
    }

    $bestId = null;
    $bestScore = 0;
    foreach ($categories as $category) {
        $name = search_normalize((string) $category['name']);
        if ($name === '' || mb_strlen($name) < 2) {
            continue;
        }
        $score = 0;
        if ($hint === $name) {
            $score = 1000 + mb_strlen($name);
        } elseif (mb_strpos($hint, $name) !== false || mb_strpos($name, $hint) !== false) {
            $score = mb_strlen($name);
        } elseif (
            (mb_strpos($hint, 'تسمه') !== false && mb_strpos($name, 'تسمه') !== false && mb_strpos($hint, 'تایم') !== false && mb_strpos($name, 'تایم') !== false)
            || (mb_strpos($hint, 'v-belt') !== false && mb_strpos($name, 'v') !== false)
            || (mb_strpos($hint, 'سفت') !== false && mb_strpos($name, 'سفت') !== false)
        ) {
            $score = 5;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = (int) $category['id'];
        }
    }

    return $bestScore >= 5 ? $bestId : null;
}

/** @param list<array<string, mixed>> $carMatches */
function price_import_confirmed_car_ids(array $carMatches, array $overrides = []): array
{
    $ids = [];
    foreach ($carMatches as $match) {
        $token = (string) ($match['token'] ?? '');
        $norm = search_normalize($token);
        if (isset($overrides[$norm]) && (int) $overrides[$norm] > 0) {
            $ids[] = (int) $overrides[$norm];
            continue;
        }
        if (($match['confidence'] ?? '') === 'certain' || ($match['confidence'] ?? '') === 'likely') {
            $id = (int) ($match['car_model_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }
    return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
}

/**
 * @param array<string, mixed> $row
 * @param list<array<string, mixed>> $carMatches
 * @return array{ready:bool,issues:list<string>}
 */
function price_import_row_issues(array $row, array $carMatches, array $overrides = []): array
{
    $issues = [];
    $action = (string) ($row['action'] ?? 'create');

    if (trim((string) ($row['name'] ?? '')) === '') {
        $issues[] = 'نام محصول خالی است';
    }
    if (trim((string) ($row['price_text'] ?? '')) === '') {
        $issues[] = 'قیمت نامعتبر است';
    }

    if ($action === 'create') {
        if ((int) ($row['category_id'] ?? 0) <= 0) {
            $issues[] = 'دسته محصول انتخاب نشده';
        }
        if ((int) ($row['pack_size'] ?? 0) <= 0) {
            $issues[] = 'تعداد در کارتن نامعتبر است';
        }

        foreach ($carMatches as $match) {
            $token = (string) ($match['token'] ?? '');
            $norm = search_normalize($token);
            $confidence = (string) ($match['confidence'] ?? 'unmatched');
            if (isset($overrides[$norm]) && (int) $overrides[$norm] > 0) {
                continue;
            }
            if ($confidence === 'uncertain' || $confidence === 'unmatched') {
                $issues[] = 'خودرو نیاز به انتخاب دارد: ' . $token;
            }
        }

        if (price_import_confirmed_car_ids($carMatches, $overrides) === []) {
            $issues[] = 'حداقل یک خودرو باید انتخاب شود';
        }
    }

    return [
        'ready' => $issues === [],
        'issues' => $issues,
    ];
}

/** @param list<array<string, mixed>> $rows */
function price_import_find_product_by_visual_id(PDO $pdo, string $visualId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, visual_id, price_text, pack_size, description FROM products WHERE visual_id = ? LIMIT 1'
    );
    $stmt->execute([$visualId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @param list<array<string, mixed>> $parsedRows */
function price_import_build_preview(PDO $pdo, array $parsedRows): array
{
    cms_ensure_product_car_models_schema($pdo);
    cms_ensure_product_categories_schema($pdo);
    price_import_ensure_schema($pdo);

    $carModels = price_import_load_car_models($pdo);
    $categories = price_import_load_categories($pdo);
    $aliasMap = price_import_load_alias_map($pdo);
    $previewRows = [];

    foreach ($parsedRows as $index => $row) {
        $visualId = (string) ($row['visual_id'] ?? '');
        $existing = price_import_find_product_by_visual_id($pdo, $visualId);
        $carMatches = price_import_parse_car_string((string) ($row['cars_raw'] ?? ''), $carModels, $aliasMap);
        $suggestedCategoryId = price_import_suggest_category_id((string) ($row['section_hint'] ?? ''), $categories);

        $preview = [
            'index' => $index,
            'excel_row' => (int) ($row['excel_row'] ?? 0),
            'visual_id' => $visualId,
            'name' => (string) ($row['name'] ?? ''),
            'name_base' => (string) ($row['name_base'] ?? ''),
            'spec' => (string) ($row['spec'] ?? ''),
            'cars_raw' => (string) ($row['cars_raw'] ?? ''),
            'price_text' => (string) ($row['price_text'] ?? ''),
            'price_rial' => $row['price_rial'] ?? null,
            'pack_size' => $row['pack_size'] ?? null,
            'warranty' => (string) ($row['warranty'] ?? ''),
            'section_hint' => (string) ($row['section_hint'] ?? ''),
            'action' => $existing ? 'update' : 'create',
            'existing_product_id' => $existing ? (int) $existing['id'] : null,
            'existing_name' => $existing ? (string) $existing['name'] : null,
            'existing_price_text' => $existing ? (string) ($existing['price_text'] ?? '') : null,
            'existing_pack_size' => $existing ? ($existing['pack_size'] !== null ? (int) $existing['pack_size'] : null) : null,
            'category_id' => $existing ? null : $suggestedCategoryId,
            'category_suggested_id' => $suggestedCategoryId,
            'car_matches' => $carMatches,
            'include' => true,
        ];

        $issues = price_import_row_issues($preview, $carMatches);
        $preview['ready'] = $issues['ready'];
        $preview['issues'] = $issues['issues'];
        $previewRows[] = $preview;
    }

    return [
        'rows' => $previewRows,
        'car_models' => $carModels,
        'categories' => $categories,
    ];
}

function price_import_unique_slug(PDO $pdo, string $name, int $excludeId = 0): string
{
    $base = cms_slugify($name);
    $slug = $base;
    $n = 2;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE slug = ? AND id <> ? LIMIT 1');
        $stmt->execute([$slug, $excludeId]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $n;
        $n++;
    }
}

function price_import_merge_car_models(PDO $pdo, int $productId, array $newCarModelIds): void
{
    $existing = cms_product_load_car_model_ids($pdo, $productId);
    $categoryMap = cms_product_load_car_model_categories($pdo, $productId);
    $merged = array_values(array_unique(array_merge($existing, $newCarModelIds)));
    cms_product_save_car_model_ids($pdo, $productId, $merged, $categoryMap);
}

function price_import_save_alias(PDO $pdo, string $token, int $carModelId): void
{
    price_import_ensure_schema($pdo);
    $norm = search_normalize($token);
    if ($norm === '' || $carModelId <= 0) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO price_import_car_aliases (alias_norm, car_model_id) VALUES (?, ?)'
    );
    $stmt->execute([$norm, $carModelId]);
}

/**
 * @param array<string, mixed> $rowInput
 * @param array<string, int> $carOverrides token_norm => car_model_id
 */
function price_import_apply_row(PDO $pdo, array $rowInput, array $carOverrides = [], bool $saveAliases = false): array
{
    $visualId = trim((string) ($rowInput['visual_id'] ?? ''));
    if ($visualId === '') {
        throw new RuntimeException('کد کالا خالی است');
    }

    $name = trim((string) ($rowInput['name'] ?? ''));
    $priceText = trim((string) ($rowInput['price_text'] ?? ''));
    $packSize = isset($rowInput['pack_size']) && $rowInput['pack_size'] !== '' && $rowInput['pack_size'] !== null
        ? max(0, (int) $rowInput['pack_size'])
        : null;
    if ($packSize === 0) {
        $packSize = null;
    }

    $carMatches = is_array($rowInput['car_matches'] ?? null) ? $rowInput['car_matches'] : [];
    $carIds = price_import_confirmed_car_ids($carMatches, $carOverrides);

    $action = (string) ($rowInput['action'] ?? 'create');
    $existing = price_import_find_product_by_visual_id($pdo, $visualId);

    if ($existing) {
        $productId = (int) $existing['id'];
        $stmt = $pdo->prepare('UPDATE products SET price_text = ?, pack_size = ? WHERE id = ?');
        $stmt->execute([
            $priceText !== '' ? $priceText : null,
            $packSize,
            $productId,
        ]);
        if ($carIds !== []) {
            price_import_merge_car_models($pdo, $productId, $carIds);
        }
        if ($saveAliases) {
            foreach ($carMatches as $match) {
                $token = (string) ($match['token'] ?? '');
                $norm = search_normalize($token);
                $picked = (int) ($carOverrides[$norm] ?? ($match['car_model_id'] ?? 0));
                if ($picked > 0) {
                    price_import_save_alias($pdo, $token, $picked);
                }
            }
        }
        return ['action' => 'update', 'product_id' => $productId];
    }

    if ($action !== 'create') {
        throw new RuntimeException('محصول یافت نشد: ' . $visualId);
    }

    $categoryId = (int) ($rowInput['category_id'] ?? 0);
    if ($categoryId <= 0) {
        throw new RuntimeException('دسته محصول برای ایجاد الزامی است');
    }
    if ($name === '') {
        throw new RuntimeException('نام محصول الزامی است');
    }
    if ($priceText === '') {
        throw new RuntimeException('قیمت محصول الزامی است');
    }
    if ($carIds === []) {
        throw new RuntimeException('حداقل یک خودرو الزامی است');
    }

    $warranty = trim((string) ($rowInput['warranty'] ?? ''));
    $description = $warranty !== '' ? 'گارانتی: ' . $warranty : null;
    $slug = price_import_unique_slug($pdo, $name);

    $stmt = $pdo->prepare(
        'INSERT INTO products (name, slug, visual_id, description, price_text, pack_size, banner, published, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, \'none\', 0, 0)'
    );
    $stmt->execute([
        $name,
        $slug,
        $visualId,
        $description,
        $priceText,
        $packSize,
    ]);
    $productId = (int) $pdo->lastInsertId();

    cms_product_save_category_ids($pdo, $productId, [$categoryId]);
    cms_product_save_car_model_ids($pdo, $productId, $carIds, []);

    if ($saveAliases) {
        foreach ($carMatches as $match) {
            $token = (string) ($match['token'] ?? '');
            $norm = search_normalize($token);
            $picked = (int) ($carOverrides[$norm] ?? ($match['car_model_id'] ?? 0));
            if ($picked > 0) {
                price_import_save_alias($pdo, $token, $picked);
            }
        }
    }

    return ['action' => 'create', 'product_id' => $productId];
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<int, array<string, mixed>> $formRows keyed by index
 */
function price_import_apply_batch(PDO $pdo, array $rows, array $formRows, bool $saveAliases = false): array
{
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $index = (int) ($row['index'] ?? -1);
            $form = $formRows[$index] ?? null;
            if ($form === null || empty($form['include'])) {
                $skipped++;
                continue;
            }

            $merged = array_merge($row, $form);
            $carOverrides = is_array($form['car_overrides'] ?? null) ? $form['car_overrides'] : [];
            $issues = price_import_row_issues(
                $merged,
                is_array($merged['car_matches'] ?? null) ? $merged['car_matches'] : [],
                $carOverrides
            );
            if (!$issues['ready']) {
                $errors[] = 'ردیف ' . ($merged['visual_id'] ?? '?') . ': ' . implode('؛ ', $issues['issues']);
                $skipped++;
                continue;
            }

            try {
                $result = price_import_apply_row($pdo, $merged, $carOverrides, $saveAliases);
                if ($result['action'] === 'create') {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (Throwable $rowError) {
                $errors[] = 'ردیف ' . ($merged['visual_id'] ?? '?') . ': ' . $rowError->getMessage();
                $skipped++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}
