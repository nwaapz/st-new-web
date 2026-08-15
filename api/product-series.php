<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    $pdo = cms_pdo();

    $rows = $pdo->query(
        'SELECT id, name, slug, description, image, sort_order
         FROM product_series
         WHERE published = 1
         ORDER BY sort_order ASC, name ASC'
    )->fetchAll();

    $itemStmt = $pdo->prepare(
        'SELECT product_id
         FROM product_series_items
         WHERE series_id = ?
         ORDER BY sort_order ASC, product_id ASC'
    );

    $items = [];
    foreach ($rows as $row) {
        $itemStmt->execute([(int) $row['id']]);
        $productIds = array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN));
        $items[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'description' => $row['description'],
            'image' => $row['image'],
            'sort_order' => (int) $row['sort_order'],
            'product_ids' => $productIds,
        ];
    }

    api_json(['items' => $items]);
} catch (Throwable $e) {
    api_error('Product series unavailable', 503);
}
