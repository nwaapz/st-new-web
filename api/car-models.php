<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    $pdo = cms_pdo();
    $factoryId = isset($_GET['factory_id']) ? (int) $_GET['factory_id'] : 0;
    $factorySlug = trim((string) ($_GET['factory_slug'] ?? ''));

    if ($factoryId <= 0 && $factorySlug !== '') {
        $s = $pdo->prepare('SELECT id FROM factories WHERE slug = ? AND published = 1 LIMIT 1');
        $s->execute([$factorySlug]);
        $factoryId = (int) ($s->fetchColumn() ?: 0);
    }

    if ($factoryId <= 0) {
        api_error('factory_id or factory_slug required', 400);
    }

    $stmt = $pdo->prepare(
        'SELECT id, factory_id, name, slug, description, image, sort_order
         FROM car_models WHERE factory_id = ? AND published = 1
         ORDER BY sort_order ASC, name ASC'
    );
    $stmt->execute([$factoryId]);
    api_json(['items' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    api_error('Car models unavailable', 503);
}
