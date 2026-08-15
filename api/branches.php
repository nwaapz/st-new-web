<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/messages.php';
require_once dirname(__DIR__) . '/cms/lib/iran-provinces.php';
require_once dirname(__DIR__) . '/cms/lib/page-intros.php';

try {
    $pdo = cms_pdo();
    messages_ensure_schema($pdo);

    $province = isset($_GET['province']) ? trim((string) $_GET['province']) : '';
    $params = [];
    $where = 'published = 1';
    if ($province !== '') {
        $where .= ' AND province_code = ?';
        $params[] = $province;
    }

    $sql = "SELECT id, name, province_code, province_name, city, sort_order
            FROM branches
            WHERE {$where}
            ORDER BY province_name ASC, city ASC, sort_order ASC, name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'province_code' => (string) $row['province_code'],
            'province_name' => (string) $row['province_name'],
            'city' => (string) $row['city'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    $countStmt = $pdo->query(
        'SELECT province_code, COUNT(*) AS branch_count
         FROM branches
         WHERE published = 1
         GROUP BY province_code'
    );
    $counts = [];
    foreach ($countStmt->fetchAll() ?: [] as $row) {
        $counts[(string) $row['province_code']] = (int) $row['branch_count'];
    }

    $provinces = [];
    foreach (iran_provinces() as $p) {
        $code = $p['code'];
        $provinces[] = [
            'code' => $code,
            'name' => $p['name'],
            'branch_count' => $counts[$code] ?? 0,
        ];
    }

    $headerImage = trim(cms_setting_get('branch_portal_header_image', ''));
    $intro = cms_page_intro_public('branch_portal');

    api_json([
        'items' => $items,
        'provinces' => $provinces,
        'counts' => $counts,
        'header_image' => $headerImage !== '' ? $headerImage : null,
        'title' => $intro['title'],
        'explanation' => $intro['explanation'],
    ]);
} catch (Throwable $e) {
    error_log('[branches] ' . $e->getMessage());
    api_error('Branches unavailable', 503);
}
