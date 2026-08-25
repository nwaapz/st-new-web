<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';
require_once dirname(__DIR__) . '/cms/lib/messages.php';
require_once dirname(__DIR__) . '/cms/lib/branches.php';
require_once dirname(__DIR__) . '/cms/lib/car-model-factories.php';
require_once dirname(__DIR__) . '/cms/lib/product-car-models.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    orders_ensure_schema($pdo);
    messages_ensure_schema($pdo);
    cms_ensure_car_model_factories_schema($pdo);
    cms_ensure_product_car_models_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $user = site_auth_current_user($pdo);
    if ($user === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    if ($method === 'GET') {
        $orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        $userId = (int) $user['id'];

        if ($orderId > 0) {
            $stmt = $pdo->prepare(
                'SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1'
            );
            $stmt->execute([$orderId, $userId]);
            $order = $stmt->fetch();
            if (!$order) {
                api_error('سفارش یافت نشد', 404);
            }
            $serialized = orders_serialize(
                $order,
                orders_fetch_items($pdo, (int) $order['id']),
                orders_fetch_events($pdo, (int) $order['id'])
            );
            $serialized['unread_admin_events'] = messages_unread_admin_events_for_order(
                $pdo,
                $userId,
                (int) $order['id']
            );
            api_json([
                'ok' => true,
                'order' => $serialized,
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll() ?: [];
        $orderIds = [];
        foreach ($rows as $row) {
            $orderIds[] = (int) $row['id'];
        }
        $unreadByOrder = messages_unread_admin_events_by_order($pdo, $userId, $orderIds);
        $orders = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $serialized = orders_serialize(
                $row,
                orders_fetch_items($pdo, $id),
                orders_fetch_events($pdo, $id)
            );
            $serialized['unread_admin_events'] = $unreadByOrder[$id] ?? 0;
            $orders[] = $serialized;
        }
        api_json(['ok' => true, 'orders' => $orders]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = site_auth_request_json();
    $rawItems = $body['items'] ?? null;
    if (!is_array($rawItems) || $rawItems === []) {
        api_error('سبد خرید خالی است', 400);
    }

    $normalized = [];
    foreach ($rawItems as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $productId = isset($raw['id']) ? (int) $raw['id'] : 0;
        $name = isset($raw['name']) ? trim((string) $raw['name']) : '';
        $quantity = isset($raw['quantity']) ? (int) $raw['quantity'] : 0;
        if ($productId <= 0 || $name === '' || $quantity < 1) {
            continue;
        }
        $quantity = min(99, $quantity);
        $unitType = isset($raw['unit_type']) && (string) $raw['unit_type'] === 'pack'
            ? 'pack'
            : 'piece';
        $clientPack = isset($raw['pack_size']) ? (int) $raw['pack_size'] : 0;

        $snapshot = [
            'product_id' => $productId,
            'name' => mb_substr($name, 0, 191),
            'slug' => isset($raw['slug']) ? mb_substr(trim((string) $raw['slug']), 0, 191) : '',
            'price_text' => null,
            'image' => null,
            'quantity' => $quantity,
            'unit_type' => $unitType,
            'pack_size' => $clientPack > 0 ? $clientPack : null,
            'factory_name' => null,
            'model_name' => null,
            'category_name' => null,
            'visual_id' => null,
        ];

        if (isset($raw['price_text']) && is_string($raw['price_text']) && trim($raw['price_text']) !== '') {
            $snapshot['price_text'] = mb_substr(trim($raw['price_text']), 0, 128);
        }
        if (isset($raw['image']) && is_string($raw['image']) && trim($raw['image']) !== '') {
            $snapshot['image'] = mb_substr(trim($raw['image']), 0, 512);
        }
        foreach (['factory_name', 'model_name', 'category_name'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
                $snapshot[$key] = mb_substr(trim($raw[$key]), 0, 191);
            }
        }
        if (isset($raw['visual_id']) && is_string($raw['visual_id']) && trim($raw['visual_id']) !== '') {
            $snapshot['visual_id'] = mb_substr(trim($raw['visual_id']), 0, 64);
        }

        // Prefer live catalog fields when the product still exists.
        $factoryNamesSql = cms_product_factory_names_sql('p');
        $modelNamesSql = cms_product_model_names_sql('p');
        $prodStmt = $pdo->prepare(
            'SELECT p.id, p.name, p.slug, p.visual_id, p.price_text, p.image, p.pack_size, p.shop_display_image,
                    COALESCE(NULLIF(p.shop_display_image, \'\'), NULLIF(p.image, \'\'), NULLIF(c.image, \'\')) AS display_image,
                    ' . $factoryNamesSql . ' AS factory_name,
                    ' . $modelNamesSql . ' AS model_name,
                    c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = ?
             LIMIT 1'
        );
        $prodStmt->execute([$productId]);
        $prod = $prodStmt->fetch();
        if ($prod) {
            $snapshot['name'] = (string) $prod['name'];
            $snapshot['slug'] = (string) ($prod['slug'] ?? '');
            $snapshot['price_text'] = $prod['price_text'] !== null ? (string) $prod['price_text'] : $snapshot['price_text'];
            $snapshot['image'] = $prod['display_image'] !== null && (string) $prod['display_image'] !== ''
                ? (string) $prod['display_image']
                : ($prod['image'] !== null ? (string) $prod['image'] : $snapshot['image']);
            $livePack = isset($prod['pack_size']) && $prod['pack_size'] !== null
                ? (int) $prod['pack_size']
                : 0;
            if ($livePack > 0) {
                $snapshot['pack_size'] = $livePack;
            } else {
                $snapshot['pack_size'] = null;
                $snapshot['unit_type'] = 'piece';
            }
            if ($snapshot['unit_type'] === 'pack' && (!$snapshot['pack_size'] || (int) $snapshot['pack_size'] <= 0)) {
                $snapshot['unit_type'] = 'piece';
            }
            $snapshot['factory_name'] = $prod['factory_name'] !== null
                ? (string) $prod['factory_name']
                : $snapshot['factory_name'];
            $snapshot['model_name'] = $prod['model_name'] !== null
                ? (string) $prod['model_name']
                : $snapshot['model_name'];
            $snapshot['category_name'] = $prod['category_name'] !== null
                ? (string) $prod['category_name']
                : $snapshot['category_name'];
            if ($prod['visual_id'] !== null && trim((string) $prod['visual_id']) !== '') {
                $snapshot['visual_id'] = (string) $prod['visual_id'];
            }
        } elseif ($unitType === 'pack' && $clientPack <= 0) {
            $snapshot['unit_type'] = 'piece';
            $snapshot['pack_size'] = null;
        }

        $mergeKey = $productId . ':' . $snapshot['unit_type'];
        if (isset($normalized[$mergeKey])) {
            $normalized[$mergeKey]['quantity'] = min(
                99,
                (int) $normalized[$mergeKey]['quantity'] + $quantity
            );
        } else {
            $normalized[$mergeKey] = $snapshot;
        }
    }

    if ($normalized === []) {
        api_error('اقلام سفارش نامعتبر است', 400);
    }

    $branchId = isset($user['branch_id']) && $user['branch_id'] !== null
        ? (int) $user['branch_id']
        : 0;
    $branchSnap = [
        'branch_id' => null,
        'branch_name' => null,
        'branch_city' => null,
        'branch_province_name' => null,
        'branch_phone' => null,
    ];
    if ($branchId > 0) {
        branches_ensure_schema($pdo);
        $bStmt = $pdo->prepare(
            'SELECT id, name, city, province_name, phone FROM branches WHERE id = ? LIMIT 1'
        );
        $bStmt->execute([$branchId]);
        $bRow = $bStmt->fetch();
        if ($bRow) {
            $branchSnap = [
                'branch_id' => (int) $bRow['id'],
                'branch_name' => (string) $bRow['name'],
                'branch_city' => (string) ($bRow['city'] ?? ''),
                'branch_province_name' => (string) ($bRow['province_name'] ?? ''),
                'branch_phone' => (string) ($bRow['phone'] ?? $user['phone']),
            ];
        }
    }

    $pdo->beginTransaction();
    try {
        $publicCode = orders_generate_public_code($pdo);
        $ins = $pdo->prepare(
            'INSERT INTO orders (
               public_code, user_id, phone, status,
               branch_id, branch_name, branch_city, branch_province_name, branch_phone
             ) VALUES (?, ?, ?, \'submitted\', ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $publicCode,
            $user['id'],
            $user['phone'],
            $branchSnap['branch_id'],
            $branchSnap['branch_name'],
            $branchSnap['branch_city'],
            $branchSnap['branch_province_name'],
            $branchSnap['branch_phone'],
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemIns = $pdo->prepare(
            'INSERT INTO order_items
              (order_id, product_id, name, slug, price_text, image, quantity,
               unit_type, pack_size, factory_name, model_name, category_name, visual_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($normalized as $item) {
            $itemIns->execute([
                $orderId,
                $item['product_id'],
                $item['name'],
                $item['slug'],
                $item['price_text'],
                $item['image'],
                $item['quantity'],
                $item['unit_type'],
                $item['pack_size'],
                $item['factory_name'],
                $item['model_name'],
                $item['category_name'],
                $item['visual_id'] ?? null,
            ]);
        }

        $submitNote = $branchSnap['branch_id']
            ? 'سفارش از سمت نماینده ثبت شد'
            : 'سفارش از سمت مشتری ثبت شد';
        orders_add_event($pdo, $orderId, null, 'submitted', 'client', $submitNote);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        api_error('خطا در ایجاد سفارش', 500);
    }

    api_json([
        'ok' => true,
        'order' => orders_serialize(
            $order,
            orders_fetch_items($pdo, $orderId),
            orders_fetch_events($pdo, $orderId)
        ),
    ], 201);
} catch (Throwable $e) {
    error_log('[orders] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
