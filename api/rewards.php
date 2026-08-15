<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    $pdo = cms_pdo();
    $stmt = $pdo->query(
        'SELECT id, title, description, image, sort_order
         FROM rewards
         WHERE published = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $rows = $stmt->fetchAll();
    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'image' => $row['image'] !== null && $row['image'] !== ''
                ? (string) $row['image']
                : null,
            'sort_order' => (int) $row['sort_order'],
        ];
    }
    api_json(['items' => $items]);
} catch (Throwable $e) {
    api_error('Rewards unavailable', 503);
}
