<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    $pdo = cms_pdo();

    // Standalone product categories (optional filter unused; kept for future)
    $stmt = $pdo->query(
        'SELECT id, name, slug, description, image, sort_order
         FROM categories WHERE published = 1
         ORDER BY sort_order ASC, name ASC'
    );
    api_json(['items' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    api_error('Categories unavailable', 503);
}
