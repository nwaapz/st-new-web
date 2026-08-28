<?php
declare(strict_types=1);

require_once __DIR__ . '/price-import-xlsx.php';
require_once __DIR__ . '/search-text.php';
require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/product-car-models.php';
require_once __DIR__ . '/product-categories.php';
require_once __DIR__ . '/car-model-factories.php';

const PRICE_IMPORT_COL_ROW = 0;
const PRICE_IMPORT_COL_CODE = 1;
const PRICE_IMPORT_COL_NAME = 2;
const PRICE_IMPORT_COL_SPEC = 3;
/** Legacy RTL layout; LTR sheets use col 3 (4th from left = نوع خودرو). */
const PRICE_IMPORT_COL_CARS = 4;
const PRICE_IMPORT_COL_PRICE = 5;
const PRICE_IMPORT_COL_WARRANTY = 6;
const PRICE_IMPORT_COL_PACK = 7;

function price_import_header_matches(string $cell, string $needle): bool
{
    $text = search_normalize(price_import_cell_string($cell));
    $norm = search_normalize($needle);
    if ($text === '' || $norm === '') {
        return false;
    }
    return $text === $norm || mb_strpos($text, $norm) !== false;
}

/**
 * @return array{row:int,code:int,name:int,spec:int,cars:int,price:int,warranty:int,pack:int}
 */
function price_import_rtl_column_map(): array
{
    return [
        'row' => PRICE_IMPORT_COL_ROW,
        'code' => PRICE_IMPORT_COL_CODE,
        'name' => PRICE_IMPORT_COL_NAME,
        'spec' => PRICE_IMPORT_COL_SPEC,
        'cars' => PRICE_IMPORT_COL_CARS,
        'price' => PRICE_IMPORT_COL_PRICE,
        'warranty' => PRICE_IMPORT_COL_WARRANTY,
        'pack' => PRICE_IMPORT_COL_PACK,
    ];
}

/**
 * Standard export: pack/warranty/price/cars on the left, code/row on the right.
 *
 * @return array{row:int,code:int,name:int,spec:int,cars:int,price:int,warranty:int,pack:int}
 */
function price_import_ltr_column_map(): array
{
    return [
        'pack' => 0,
        'warranty' => 1,
        'price' => 2,
        'cars' => 3,
        'spec' => 4,
        'name' => 5,
        'code' => 6,
        'row' => 7,
    ];
}

function price_import_looks_like_part_spec(string $text): bool
{
    if ($text === '') {
        return false;
    }
    return preg_match('/\d\s*PK[-\s]/i', $text) === 1
        || preg_match('/CR\+PLUS/i', $text) === 1;
}

function price_import_looks_like_belt_type(string $text): bool
{
    return $text !== '' && mb_strpos($text, 'تسمه') !== false;
}

/**
 * @return array{row:int,code:int,name:int,spec:int,cars:int,price:int,warranty:int,pack:int}
 */
function price_import_detect_column_map(array $rawRows): array
{
    $headers = [];
    foreach (array_slice($rawRows, 0, 15) as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $idx => $cell) {
            $text = price_import_cell_string($cell);
            if ($text === '') {
                continue;
            }
            if (price_import_header_matches($text, 'ردیف')) {
                $headers['row'] = (int) $idx;
            }
            if (price_import_header_matches($text, 'کد کالا')) {
                $headers['code'] = (int) $idx;
            }
            if (price_import_header_matches($text, 'نوع خودرو')) {
                $headers['cars'] = (int) $idx;
            }
            if (price_import_header_matches($text, 'قیمت')) {
                $headers['price'] = (int) $idx;
            }
            if (price_import_header_matches($text, 'گارانتی')) {
                $headers['warranty'] = (int) $idx;
            }
            if (price_import_header_matches($text, 'تعداد') || price_import_header_matches($text, 'کارتن')) {
                $headers['pack'] = (int) $idx;
            }
        }
    }

    $isLtr = isset($headers['pack'], $headers['row']) && $headers['pack'] < $headers['row'];
    $isRtl = isset($headers['row'], $headers['code']) && $headers['row'] < $headers['code'];
    if (!$isLtr && !$isRtl) {
        foreach (array_slice($rawRows, 0, 3) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $first = price_import_cell_string($row[0] ?? null);
            if ($first === 'ردیف') {
                $isRtl = true;
                break;
            }
            if (price_import_header_matches($first, 'تعداد') || price_import_header_matches($first, 'کارتن')) {
                $isLtr = true;
                break;
            }
        }
    }

    $cols = $isLtr ? price_import_ltr_column_map() : price_import_rtl_column_map();
    foreach ($headers as $key => $idx) {
        if (array_key_exists($key, $cols)) {
            $cols[$key] = $idx;
        }
    }

    return price_import_refine_spec_name_columns($rawRows, $cols);
}

/**
 * @param array{row:int,code:int,name:int,spec:int,cars:int,price:int,warranty:int,pack:int} $cols
 * @return array{row:int,code:int,name:int,spec:int,cars:int,price:int,warranty:int,pack:int}
 */
function price_import_refine_spec_name_columns(array $rawRows, array $cols): array
{
    foreach ($rawRows as $row) {
        if (!is_array($row) || !price_import_is_product_row($row, $cols)) {
            continue;
        }

        $min = min($cols['cars'], $cols['code']);
        $max = max($cols['cars'], $cols['code']);
        for ($idx = $min + 1; $idx < $max; $idx++) {
            $text = price_import_cell_string($row[$idx] ?? null);
            if ($text === '') {
                continue;
            }
            if (price_import_looks_like_part_spec($text)) {
                $cols['spec'] = $idx;
            } elseif (price_import_looks_like_belt_type($text)) {
                $cols['name'] = $idx;
            }
        }
        break;
    }

    return $cols;
}

/**
 * @param array{row:int,code:int,name:int,spec:int,cars:int,price:int,warranty:int,pack:int} $cols
 */
function price_import_is_header_row(array $row, array $cols): bool
{
    foreach ($row as $cell) {
        if (price_import_header_matches((string) $cell, 'ردیف')) {
            return true;
        }
    }
    return price_import_cell_string($row[$cols['row']] ?? null) === 'ردیف';
}

/**
 * @param array{row:int,code:int,name:int,spec:int,cars:int,price:int,warranty:int,pack:int} $cols
 */
function price_import_is_section_row(array $row, array $cols): bool
{
    if (price_import_is_header_row($row, $cols)) {
        return false;
    }
    if (price_import_is_product_row($row, $cols)) {
        return false;
    }

    foreach ($row as $cell) {
        $text = price_import_cell_string($cell);
        if ($text === '' || is_numeric($text)) {
            continue;
        }
        if (
            mb_strlen($text) >= 8
            && (
                mb_strpos($text, 'تسمه') !== false
                || stripos($text, 'MOLD') !== false
                || stripos($text, 'EPDM') !== false
            )
        ) {
            return true;
        }
    }

    return false;
}

/**
 * @param array{row:int,code:int,name:int,spec:int,cars:int,price:int,warranty:int,pack:int} $cols
 */
function price_import_is_product_row(array $row, array $cols): bool
{
    $code = $row[$cols['code']] ?? null;
    return $code !== null && $code !== '' && is_numeric($code);
}

function price_import_section_hint_from_row(array $row): string
{
    $best = '';
    foreach ($row as $cell) {
        $text = trim(price_import_cell_string($cell));
        if ($text === '' || is_numeric($text)) {
            continue;
        }
        if (mb_strlen($text) > mb_strlen($best)) {
            $best = $text;
        }
    }
    return $best;
}

/** Product title is the part code (4PK-930 CR+PLUS); belt type stays in name_base for category. */
function price_import_build_name(string $spec, string $nameBase = ''): string
{
    if ($spec !== '') {
        return $spec;
    }
    return $nameBase;
}

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
    $cols = price_import_detect_column_map($rawRows);

    foreach ($rawRows as $excelRowIndex => $row) {
        if (!is_array($row) || price_import_is_header_row($row, $cols)) {
            continue;
        }
        if (price_import_is_section_row($row, $cols)) {
            $sectionHint = price_import_section_hint_from_row($row);
            continue;
        }
        if (!price_import_is_product_row($row, $cols)) {
            continue;
        }

        $nameBase = price_import_cell_string($row[$cols['name']] ?? null);
        $spec = price_import_cell_string($row[$cols['spec']] ?? null);
        $visualId = price_import_cell_string($row[$cols['code']] ?? null);
        $parsed[] = [
            'excel_row' => $excelRowIndex + 1,
            'visual_id' => $visualId,
            'name_base' => $nameBase,
            'spec' => $spec,
            'name' => price_import_build_name($spec, $nameBase),
            'cars_raw' => price_import_cell_string($row[$cols['cars']] ?? null),
            'price_rial' => $row[$cols['price']] ?? null,
            'price_text' => price_import_rial_to_toman_text($row[$cols['price']] ?? null),
            'warranty' => price_import_cell_string($row[$cols['warranty']] ?? null),
            'pack_size' => price_import_parse_pack_size($row[$cols['pack']] ?? null),
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

/** @return list<array{id:int,name:string,factory_name?:string}> */
function price_import_load_car_models(PDO $pdo): array
{
    $factoryNamesSql = cms_car_model_factory_names_sql('m');
    return $pdo->query(
        "SELECT m.id, m.name, {$factoryNamesSql} AS factory_name
         FROM car_models m
         ORDER BY m.sort_order ASC, m.name ASC"
    )->fetchAll() ?: [];
}

/** @return list<array{id:int,name:string}> */
function price_import_load_categories(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, name FROM categories ORDER BY sort_order ASC, name ASC'
    )->fetchAll() ?: [];
}

function price_import_car_model_label(array $model): string
{
    $factory = trim((string) ($model['factory_name'] ?? ''));
    $name = trim((string) ($model['name'] ?? ''));
    return $factory !== '' ? $factory . ' / ' . $name : $name;
}

/**
 * @param array<string, mixed> $input
 * @return list<array{car_id:int,category_id:int}>
 */
function price_import_parse_extra_cars(array $input): array
{
    $extra = [];
    if (!isset($input['extra_cars']) || !is_array($input['extra_cars'])) {
        return $extra;
    }
    foreach ($input['extra_cars'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $carId = (int) ($row['car_id'] ?? 0);
        if ($carId <= 0) {
            continue;
        }
        $extra[] = [
            'car_id' => $carId,
            'category_id' => max(0, (int) ($row['category_id'] ?? 0)),
        ];
    }
    return $extra;
}

/**
 * @param array<string, int> $carCategoryPick token_norm => category_id
 * @param list<array{car_id:int,category_id:int}> $extraCars
 * @return array<int, int> car_model_id => category_id
 */
function price_import_build_car_category_map(
    array $carMatches,
    array $overrides,
    array $carCategoryPick,
    array $extraCars
): array {
    $map = [];
    foreach ($carMatches as $match) {
        $token = (string) ($match['token'] ?? '');
        $norm = search_normalize($token);
        $carId = 0;
        if (isset($overrides[$norm]) && (int) $overrides[$norm] > 0) {
            $carId = (int) $overrides[$norm];
        } elseif (($match['confidence'] ?? '') === 'certain' || ($match['confidence'] ?? '') === 'likely') {
            $carId = (int) ($match['car_model_id'] ?? 0);
        }
        if ($carId > 0 && isset($carCategoryPick[$norm])) {
            $categoryId = (int) $carCategoryPick[$norm];
            if ($categoryId > 0) {
                $map[$carId] = $categoryId;
            }
        }
    }
    foreach ($extraCars as $extra) {
        $carId = (int) ($extra['car_id'] ?? 0);
        $categoryId = (int) ($extra['category_id'] ?? 0);
        if ($carId > 0 && $categoryId > 0) {
            $map[$carId] = $categoryId;
        }
    }
    return $map;
}

/**
 * Up to two product-level categories: main pick plus distinct per-car overrides.
 *
 * @param array<int, int> $carCategoryMap car_model_id => category_id
 * @return list<int>
 */
function price_import_collect_product_category_ids(int $mainCategoryId, array $carCategoryMap): array
{
    $ids = [];
    if ($mainCategoryId > 0) {
        $ids[] = $mainCategoryId;
    }
    foreach ($carCategoryMap as $categoryId) {
        $categoryId = (int) $categoryId;
        if ($categoryId > 0 && !in_array($categoryId, $ids, true)) {
            $ids[] = $categoryId;
        }
    }

    return array_slice($ids, 0, 2);
}

/**
 * Merge import categories into product_categories (preserves existing, caps at 2).
 *
 * @param array<int, int> $carCategoryMap
 */
function price_import_sync_product_categories(
    PDO $pdo,
    int $productId,
    int $mainCategoryId,
    array $carCategoryMap
): void {
    cms_product_sync_categories_from_assignment(
        $pdo,
        $productId,
        $mainCategoryId > 0 ? [$mainCategoryId] : [],
        $carCategoryMap,
        true
    );
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

/**
 * @param list<array<string, mixed>> $carMatches
 * @param list<array{car_id:int,category_id:int}> $extraCars
 */
function price_import_confirmed_car_ids(array $carMatches, array $overrides = [], array $extraCars = []): array
{
    $ids = [];
    foreach ($carMatches as $match) {
        $token = (string) ($match['token'] ?? '');
        $norm = search_normalize($token);
        if (isset($overrides[$norm]) && (int) $overrides[$norm] > 0) {
            $ids[] = (int) $overrides[$norm];
        }
    }
    foreach ($extraCars as $extra) {
        $carId = (int) ($extra['car_id'] ?? 0);
        if ($carId > 0) {
            $ids[] = $carId;
        }
    }
    return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
}

/**
 * Parsed car tokens still shown in the import row (removed tokens are excluded on save).
 *
 * @return list<string>|null null = treat all parsed tokens as active (legacy)
 */
function price_import_parse_car_active_norms(array $input): ?array
{
    if (!isset($input['car_active']) || !is_array($input['car_active'])) {
        return null;
    }

    $norms = [];
    foreach ($input['car_active'] as $norm => $flag) {
        if ((string) $flag !== '' && (string) $flag !== '0') {
            $norms[] = (string) $norm;
        }
    }

    return $norms;
}

/**
 * @param list<array<string, mixed>> $carMatches
 * @param list<string>|null $activeNorms
 * @return list<array<string, mixed>>
 */
function price_import_filter_car_matches_by_active(array $carMatches, ?array $activeNorms): array
{
    if ($activeNorms === null) {
        return $carMatches;
    }

    $activeSet = array_flip($activeNorms);
    $filtered = [];
    foreach ($carMatches as $match) {
        $norm = search_normalize((string) ($match['token'] ?? ''));
        if ($norm !== '' && isset($activeSet[$norm])) {
            $filtered[] = $match;
        }
    }

    return $filtered;
}

/**
 * @param array<string, mixed> $row
 * @param list<array<string, mixed>> $carMatches
 * @return array{ready:bool,issues:list<string>}
 */
function price_import_row_issues(
    array $row,
    array $carMatches,
    array $overrides = [],
    array $extraCars = []
): array {
    $issues = [];
    $action = (string) ($row['action'] ?? 'create');

    if (trim((string) ($row['name'] ?? '')) === '' && ($row['action'] ?? '') === 'create') {
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

        if (empty($row['skip_cars'])) {
            foreach ($carMatches as $match) {
                $token = (string) ($match['token'] ?? '');
                $norm = search_normalize($token);
                if (isset($overrides[$norm]) && (int) $overrides[$norm] > 0) {
                    continue;
                }
                $issues[] = 'خودرو نیاز به انتخاب دارد: ' . $token;
            }

            foreach ($extraCars as $extra) {
                if ((int) ($extra['car_id'] ?? 0) <= 0) {
                    $issues[] = 'خودرو اضافه‌شده باید انتخاب شود';
                    break;
                }
            }

            if (price_import_confirmed_car_ids($carMatches, $overrides, $extraCars) === []) {
                $issues[] = 'حداقل یک خودرو باید انتخاب شود';
            }
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

function price_import_car_names_for_ids(array $carModelIds, array $carModels): string
{
    $byId = [];
    foreach ($carModels as $model) {
        $byId[(int) $model['id']] = price_import_car_model_label($model);
    }
    $names = [];
    foreach ($carModelIds as $carModelId) {
        $carModelId = (int) $carModelId;
        if ($carModelId > 0 && isset($byId[$carModelId])) {
            $names[] = $byId[$carModelId];
        }
    }
    return implode(' · ', $names);
}

/** @param list<array<string, mixed>> $rows */
function price_import_refresh_session_rows(PDO $pdo, array $rows): array
{
    if ($rows === []) {
        return $rows;
    }

    cms_ensure_product_car_models_schema($pdo);
    $carModels = price_import_load_car_models($pdo);
    $productIds = [];

    foreach ($rows as &$row) {
        $productId = (int) ($row['existing_product_id'] ?? 0);
        if ($productId <= 0 && trim((string) ($row['visual_id'] ?? '')) !== '') {
            $existing = price_import_find_product_by_visual_id($pdo, (string) $row['visual_id']);
            if ($existing) {
                $productId = (int) $existing['id'];
                $row['existing_product_id'] = $productId;
                $row['action'] = 'update';
                $row['existing_name'] = (string) $existing['name'];
                $row['existing_price_text'] = (string) ($existing['price_text'] ?? '');
                $row['existing_pack_size'] = $existing['pack_size'] !== null ? (int) $existing['pack_size'] : null;
                $row['category_id'] = null;
            }
        }
        if ($productId > 0) {
            $productIds[] = $productId;
        }
    }
    unset($row);

    $carIdsMap = cms_product_load_car_model_ids_map($pdo, array_values(array_unique($productIds)));
    $refreshed = [];

    foreach ($rows as $row) {
        $productId = (int) ($row['existing_product_id'] ?? 0);
        $existingCarIds = $productId > 0 ? ($carIdsMap[$productId] ?? []) : [];
        $skipCars = $productId > 0 && $existingCarIds !== [];

        $row['skip_cars'] = $skipCars;
        $row['needs_car_setup'] = !$skipCars;
        if ($skipCars) {
            $row['existing_car_ids'] = $existingCarIds;
            $row['existing_car_names'] = price_import_car_names_for_ids($existingCarIds, $carModels);
            $row['car_matches'] = [];
            $row['car_overrides'] = [];
            $row['extra_cars'] = [];
            $row['car_category_map'] = [];
        }

        $carMatches = is_array($row['car_matches'] ?? null) ? $row['car_matches'] : [];
        $overrides = is_array($row['car_overrides'] ?? null) ? $row['car_overrides'] : [];
        $extraCars = is_array($row['extra_cars'] ?? null) ? $row['extra_cars'] : [];
        $issues = price_import_row_issues($row, $carMatches, $overrides, $extraCars);
        $row['ready'] = $issues['ready'];
        $row['issues'] = $issues['issues'];
        $refreshed[] = $row;
    }

    return $refreshed;
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

    $existingProductIds = [];
    $existingByVisualId = [];
    foreach ($parsedRows as $row) {
        $visualId = (string) ($row['visual_id'] ?? '');
        $existing = price_import_find_product_by_visual_id($pdo, $visualId);
        if ($existing) {
            $productId = (int) $existing['id'];
            $existingProductIds[] = $productId;
            $existingByVisualId[$visualId] = $existing;
        }
    }
    $existingCarIdsMap = cms_product_load_car_model_ids_map($pdo, $existingProductIds);

    foreach ($parsedRows as $index => $row) {
        $visualId = (string) ($row['visual_id'] ?? '');
        $existing = $existingByVisualId[$visualId] ?? null;
        $existingProductId = $existing ? (int) $existing['id'] : null;
        $existingCarIds = $existingProductId !== null ? ($existingCarIdsMap[$existingProductId] ?? []) : [];
        $skipCars = $existing !== null && $existingCarIds !== [];
        $carMatches = $skipCars
            ? []
            : price_import_parse_car_string((string) ($row['cars_raw'] ?? ''), $carModels, $aliasMap);
        $suggestedCategoryId = price_import_suggest_category_id((string) ($row['name_base'] ?? ''), $categories)
            ?? price_import_suggest_category_id((string) ($row['section_hint'] ?? ''), $categories);

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
            'existing_product_id' => $existingProductId,
            'existing_name' => $existing ? (string) $existing['name'] : null,
            'existing_price_text' => $existing ? (string) ($existing['price_text'] ?? '') : null,
            'existing_pack_size' => $existing ? ($existing['pack_size'] !== null ? (int) $existing['pack_size'] : null) : null,
            'category_id' => $existing ? null : $suggestedCategoryId,
            'category_suggested_id' => $suggestedCategoryId,
            'skip_cars' => $skipCars,
            'needs_car_setup' => !$skipCars,
            'existing_car_ids' => $skipCars ? $existingCarIds : [],
            'existing_car_names' => $skipCars ? price_import_car_names_for_ids($existingCarIds, $carModels) : '',
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

function price_import_merge_car_models(
    PDO $pdo,
    int $productId,
    array $newCarModelIds,
    array $newCategoryOverrides = []
): void {
    $existing = cms_product_load_car_model_ids($pdo, $productId);
    $categoryMap = cms_product_load_car_model_categories($pdo, $productId);
    foreach ($newCategoryOverrides as $carId => $categoryId) {
        $carId = (int) $carId;
        $categoryId = (int) $categoryId;
        if ($carId > 0 && $categoryId > 0) {
            $categoryMap[$carId] = $categoryId;
        }
    }
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

/** @param array<string, mixed> $rowInput */
function price_import_apply_row(PDO $pdo, array $rowInput, bool $saveAliases = false): array
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

    $skipCars = !empty($rowInput['skip_cars']);
    $carMatches = is_array($rowInput['car_matches'] ?? null) ? $rowInput['car_matches'] : [];
    $carOverrides = is_array($rowInput['car_overrides'] ?? null) ? $rowInput['car_overrides'] : [];
    $extraCars = is_array($rowInput['extra_cars'] ?? null) ? $rowInput['extra_cars'] : [];
    $carCategoryMap = is_array($rowInput['car_category_map'] ?? null) ? $rowInput['car_category_map'] : [];
    $carIds = $skipCars ? [] : price_import_confirmed_car_ids($carMatches, $carOverrides, $extraCars);

    $action = (string) ($rowInput['action'] ?? 'create');
    $existing = price_import_find_product_by_visual_id($pdo, $visualId);

    if ($existing) {
        $productId = (int) $existing['id'];
        $stmt = $pdo->prepare('UPDATE products SET price_text = ?, pack_size = ?, published = 1 WHERE id = ?');
        $stmt->execute([
            $priceText !== '' ? $priceText : null,
            $packSize,
            $productId,
        ]);
        if (!$skipCars && $carIds !== []) {
            cms_product_save_car_model_ids($pdo, $productId, $carIds, $carCategoryMap);
            price_import_sync_product_categories(
                $pdo,
                $productId,
                (int) ($rowInput['category_id'] ?? 0),
                $carCategoryMap
            );
        }
        if ($saveAliases && !$skipCars) {
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
         VALUES (?, ?, ?, ?, ?, ?, \'none\', 1, 0)'
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

    cms_product_save_category_ids(
        $pdo,
        $productId,
        price_import_collect_product_category_ids($categoryId, $carCategoryMap)
    );
    cms_product_save_car_model_ids($pdo, $productId, $carIds, $carCategoryMap);

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
 * @param list<int> $indicesToRemove
 * @return array<string, mixed>
 */
function price_import_session_remove_rows(array $session, array $indicesToRemove): array
{
    $remove = array_flip(array_map('intval', $indicesToRemove));
    $parsed = is_array($session['parsed'] ?? null) ? $session['parsed'] : [];
    $rows = is_array($session['preview']['rows'] ?? null) ? $session['preview']['rows'] : [];

    $newRows = [];
    foreach ($rows as $row) {
        $index = (int) ($row['index'] ?? -1);
        if (!isset($remove[$index])) {
            $newRows[] = $row;
        }
    }

    $newParsed = [];
    foreach ($parsed as $parsedIndex => $parsedRow) {
        if (!isset($remove[(int) $parsedIndex])) {
            $newParsed[$parsedIndex] = $parsedRow;
        }
    }

    $session['preview']['rows'] = $newRows;
    $session['parsed'] = $newParsed;

    return $session;
}

/**
 * @param callable(array<string, mixed>, array<string, mixed>): array<string, mixed> $mergeFormRow
 * @return array<string, mixed>
 */
function price_import_session_merge_posted_rows(array $session, array $postedRows, callable $mergeFormRow): array
{
    $rows = is_array($session['preview']['rows'] ?? null) ? $session['preview']['rows'] : [];
    $mergedRows = [];
    foreach ($rows as $row) {
        $index = (int) ($row['index'] ?? -1);
        $input = is_array($postedRows[$index] ?? null) ? $postedRows[$index] : [];
        $mergedRows[] = $mergeFormRow($row, $input);
    }
    $session['preview']['rows'] = $mergedRows;

    return $session;
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<int, array<string, mixed>> $formRows keyed by index
 * @param list<int>|null $onlyIndices
 * @return array{created:int,updated:int,skipped:int,errors:list<string>,applied_indices:list<int>}
 */
function price_import_apply_batch(
    PDO $pdo,
    array $rows,
    array $formRows,
    bool $saveAliases = false,
    ?array $onlyIndices = null
): array {
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $appliedIndices = [];
    $onlySet = $onlyIndices !== null ? array_flip(array_map('intval', $onlyIndices)) : null;

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $index = (int) ($row['index'] ?? -1);
            if ($onlySet !== null && !isset($onlySet[$index])) {
                continue;
            }

            $form = $formRows[$index] ?? null;
            if ($form === null || empty($form['include'])) {
                $skipped++;
                continue;
            }

            $merged = array_merge($row, $form);
            $issues = price_import_row_issues(
                $merged,
                is_array($merged['car_matches'] ?? null) ? $merged['car_matches'] : [],
                is_array($merged['car_overrides'] ?? null) ? $merged['car_overrides'] : [],
                is_array($merged['extra_cars'] ?? null) ? $merged['extra_cars'] : []
            );
            if (!$issues['ready']) {
                $errors[] = 'ردیف ' . ($merged['visual_id'] ?? '?') . ': ' . implode('؛ ', $issues['issues']);
                $skipped++;
                continue;
            }

            try {
                $result = price_import_apply_row($pdo, $merged, $saveAliases);
                if ($result['action'] === 'create') {
                    $created++;
                } else {
                    $updated++;
                }
                $appliedIndices[] = $index;
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
        'applied_indices' => $appliedIndices,
    ];
}
