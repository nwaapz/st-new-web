<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

function product_reviews_api_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_reviews (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          product_id INT UNSIGNED NOT NULL,
          author_name VARCHAR(128) NOT NULL,
          rating TINYINT UNSIGNED NOT NULL,
          body TEXT NOT NULL,
          status ENUM(\'pending\',\'approved\',\'rejected\') NOT NULL DEFAULT \'pending\',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_product_reviews_product_status (product_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

try {
    $pdo = cms_pdo();
    product_reviews_api_ensure_schema($pdo);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        $productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
        if ($productId <= 0) {
            api_error('product_id required', 400);
        }

        $check = $pdo->prepare('SELECT id FROM products WHERE id = ? AND published = 1');
        $check->execute([$productId]);
        if (!$check->fetch()) {
            api_error('Product not found', 404);
        }

        $stmt = $pdo->prepare(
            'SELECT id, author_name, rating, body, created_at
             FROM product_reviews
             WHERE product_id = ? AND status = \'approved\'
             ORDER BY created_at DESC, id DESC
             LIMIT 100'
        );
        $stmt->execute([$productId]);
        $items = $stmt->fetchAll();

        $agg = $pdo->prepare(
            'SELECT AVG(rating) AS rating_avg, COUNT(*) AS rating_count
             FROM product_reviews
             WHERE product_id = ? AND status = \'approved\''
        );
        $agg->execute([$productId]);
        $stats = $agg->fetch() ?: ['rating_avg' => null, 'rating_count' => 0];

        api_json([
            'items' => $items,
            'rating_avg' => $stats['rating_avg'] !== null
                ? round((float) $stats['rating_avg'], 1)
                : null,
            'rating_count' => (int) ($stats['rating_count'] ?? 0),
        ]);
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $data = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        if ($data === []) {
            $data = $_POST;
        }

        // Honeypot — bots fill this; humans leave empty
        $honeypot = trim((string) ($data['website'] ?? ''));
        if ($honeypot !== '') {
            api_json(['ok' => true, 'message' => 'submitted']);
        }

        $productId = (int) ($data['product_id'] ?? 0);
        $authorName = trim((string) ($data['author_name'] ?? ''));
        $rating = (int) ($data['rating'] ?? 0);
        $body = trim((string) ($data['body'] ?? ''));

        if ($productId <= 0) {
            api_error('product_id required', 400);
        }
        if ($authorName === '' || mb_strlen($authorName) > 128) {
            api_error('نام الزامی است (حداکثر ۱۲۸ کاراکتر)', 400);
        }
        if ($rating < 1 || $rating > 5) {
            api_error('امتیاز باید بین ۱ تا ۵ باشد', 400);
        }
        if ($body === '' || mb_strlen($body) < 5) {
            api_error('متن نظر حداقل ۵ کاراکتر باشد', 400);
        }
        if (mb_strlen($body) > 2000) {
            api_error('متن نظر حداکثر ۲۰۰۰ کاراکتر است', 400);
        }

        $check = $pdo->prepare('SELECT id FROM products WHERE id = ? AND published = 1');
        $check->execute([$productId]);
        if (!$check->fetch()) {
            api_error('Product not found', 404);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO product_reviews (product_id, author_name, rating, body, status)
             VALUES (?, ?, ?, ?, \'pending\')'
        );
        $stmt->execute([$productId, $authorName, $rating, $body]);

        api_json([
            'ok' => true,
            'message' => 'نظر شما ثبت شد و پس از تأیید نمایش داده می‌شود',
        ], 201);
    }

    api_error('Method not allowed', 405);
} catch (Throwable $e) {
    api_error('Reviews unavailable', 503);
}
