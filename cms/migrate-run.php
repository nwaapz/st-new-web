<?php
declare(strict_types=1);

/**
 * One-click schema upgrade for existing installs.
 * Open once while logged in as admin, then delete this file.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();
$pdo = cms_pdo();

header('Content-Type: text/html; charset=utf-8');

$log = [];
$ok = true;

try {
    $cols = $pdo->query('SHOW COLUMNS FROM categories LIKE \'car_model_id\'')->fetchAll();
    $prodCols = $pdo->query('SHOW COLUMNS FROM products LIKE \'car_model_id\'')->fetchAll();

    if (count($cols) === 0 && count($prodCols) > 0) {
        $log[] = 'Migration already applied (categories have no car_model_id; products have car_model_id).';
    } else {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        if (count($prodCols) === 0) {
            $pdo->exec('ALTER TABLE products ADD COLUMN car_model_id INT UNSIGNED NULL AFTER category_id');
            $log[] = 'Added products.car_model_id';
        }

        // Backfill if categories still have car_model_id
        if (count($cols) > 0) {
            $pdo->exec(
                'UPDATE products p
                 INNER JOIN categories c ON c.id = p.category_id
                 SET p.car_model_id = c.car_model_id
                 WHERE p.car_model_id IS NULL'
            );
            $log[] = 'Backfilled products.car_model_id from categories';
        }

        $pdo->exec('DELETE FROM products WHERE car_model_id IS NULL');
        $log[] = 'Removed products without a car model';

        // Ensure NOT NULL + index/FK (ignore if already there)
        try {
            $pdo->exec('ALTER TABLE products MODIFY COLUMN car_model_id INT UNSIGNED NOT NULL');
        } catch (Throwable $e) {
            $log[] = 'Note: ' . $e->getMessage();
        }
        try {
            $pdo->exec('ALTER TABLE products ADD KEY idx_prod_model (car_model_id)');
        } catch (Throwable $e) {
            /* exists */
        }
        try {
            $pdo->exec(
                'ALTER TABLE products ADD CONSTRAINT fk_prod_model FOREIGN KEY (car_model_id) REFERENCES car_models (id) ON DELETE CASCADE'
            );
        } catch (Throwable $e) {
            /* exists */
        }

        if (count($cols) > 0) {
            try {
                $pdo->exec('ALTER TABLE categories DROP FOREIGN KEY fk_cat_model');
            } catch (Throwable $e) {
                $log[] = 'Drop FK: ' . $e->getMessage();
            }
            try {
                $pdo->exec('ALTER TABLE categories DROP INDEX uq_cat_model_slug');
            } catch (Throwable $e) {
                /* */
            }
            try {
                $pdo->exec('ALTER TABLE categories DROP INDEX idx_cat_model');
            } catch (Throwable $e) {
                /* */
            }
            $pdo->exec('ALTER TABLE categories DROP COLUMN car_model_id');
            $log[] = 'Detached categories from car models';
            try {
                $pdo->exec('ALTER TABLE categories ADD UNIQUE KEY uq_category_slug (slug)');
            } catch (Throwable $e) {
                /* */
            }
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $log[] = 'Migration finished.';
    }

    // Product series tables (homepage groups)
    $seriesExists = $pdo->query("SHOW TABLES LIKE 'product_series'")->fetchAll();
    if (count($seriesExists) === 0) {
        $pdo->exec(
            'CREATE TABLE product_series (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              name VARCHAR(191) NOT NULL,
              slug VARCHAR(191) NOT NULL,
              description TEXT NULL,
              image VARCHAR(512) NULL,
              sort_order INT NOT NULL DEFAULT 0,
              published TINYINT(1) NOT NULL DEFAULT 1,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_series_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created product_series table';
    } else {
        $log[] = 'product_series already exists';
    }

    $seriesItemsExists = $pdo->query("SHOW TABLES LIKE 'product_series_items'")->fetchAll();
    if (count($seriesItemsExists) === 0) {
        $pdo->exec(
            'CREATE TABLE product_series_items (
              series_id INT UNSIGNED NOT NULL,
              product_id INT UNSIGNED NOT NULL,
              sort_order INT NOT NULL DEFAULT 0,
              PRIMARY KEY (series_id, product_id),
              KEY idx_series_item_product (product_id),
              CONSTRAINT fk_series_item_series FOREIGN KEY (series_id) REFERENCES product_series (id) ON DELETE CASCADE,
              CONSTRAINT fk_series_item_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created product_series_items table';
    } else {
        $log[] = 'product_series_items already exists';
    }

    // Shop / site settings
    $settingsExists = $pdo->query("SHOW TABLES LIKE 'site_settings'")->fetchAll();
    if (count($settingsExists) === 0) {
        $pdo->exec(
            'CREATE TABLE site_settings (
              setting_key VARCHAR(64) NOT NULL,
              setting_value TEXT NOT NULL,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created site_settings table';
    } else {
        $log[] = 'site_settings already exists';
    }

    $rewardsExists = $pdo->query("SHOW TABLES LIKE 'rewards'")->fetchAll();
    if (count($rewardsExists) === 0) {
        $pdo->exec(
            'CREATE TABLE rewards (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              title VARCHAR(191) NOT NULL,
              description TEXT NULL,
              image VARCHAR(512) NULL,
              sort_order INT NOT NULL DEFAULT 0,
              published TINYINT(1) NOT NULL DEFAULT 1,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created rewards table';
    } else {
        $log[] = 'rewards already exists';
    }

    $framesExists = $pdo->query("SHOW TABLES LIKE 'danestani_media_frames'")->fetchAll();
    if (count($framesExists) === 0) {
        $pdo->exec(
            'CREATE TABLE danestani_media_frames (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              title VARCHAR(191) NOT NULL,
              subtitle VARCHAR(255) NULL,
              badge VARCHAR(128) NULL,
              explanation TEXT NULL,
              sort_order INT NOT NULL DEFAULT 0,
              published TINYINT(1) NOT NULL DEFAULT 1,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created danestani_media_frames table';
    } else {
        $log[] = 'danestani_media_frames already exists';
    }

    $slidesExists = $pdo->query("SHOW TABLES LIKE 'danestani_media_slides'")->fetchAll();
    if (count($slidesExists) === 0) {
        $pdo->exec(
            'CREATE TABLE danestani_media_slides (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              frame_id INT UNSIGNED NOT NULL,
              image VARCHAR(512) NOT NULL,
              alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
              caption VARCHAR(512) NULL,
              sort_order INT NOT NULL DEFAULT 0,
              PRIMARY KEY (id),
              KEY idx_danestani_slides_frame (frame_id),
              CONSTRAINT fk_danestani_slide_frame
                FOREIGN KEY (frame_id) REFERENCES danestani_media_frames(id)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created danestani_media_slides table';
    } else {
        $log[] = 'danestani_media_slides already exists';
    }

    // Product detail: dimensions
    foreach (['dim_length', 'dim_width', 'dim_height', 'dim_weight'] as $dimCol) {
        $dimExists = $pdo->query("SHOW COLUMNS FROM products LIKE " . $pdo->quote($dimCol))->fetchAll();
        if (count($dimExists) === 0) {
            $pdo->exec("ALTER TABLE products ADD COLUMN {$dimCol} VARCHAR(64) NULL");
            $log[] = "Added products.{$dimCol}";
        } else {
            $log[] = "products.{$dimCol} already exists";
        }
    }

    // Global unique product slug (resolve duplicates first)
    $dupSlugs = $pdo->query(
        'SELECT slug, COUNT(*) AS c FROM products GROUP BY slug HAVING c > 1'
    )->fetchAll();
    if (count($dupSlugs) > 0) {
        $fixStmt = $pdo->prepare('UPDATE products SET slug = ? WHERE id = ?');
        foreach ($dupSlugs as $dup) {
            $slug = (string) $dup['slug'];
            $rows = $pdo->prepare('SELECT id FROM products WHERE slug = ? ORDER BY id ASC');
            $rows->execute([$slug]);
            $ids = $rows->fetchAll(PDO::FETCH_COLUMN);
            $first = true;
            foreach ($ids as $pid) {
                if ($first) {
                    $first = false;
                    continue;
                }
                $fixStmt->execute([$slug . '-' . (int) $pid, (int) $pid]);
            }
        }
        $log[] = 'Resolved duplicate product slugs';
    } else {
        $log[] = 'No duplicate product slugs';
    }

    try {
        $pdo->exec('ALTER TABLE products DROP INDEX uq_prod_cat_slug');
        $log[] = 'Dropped uq_prod_cat_slug';
    } catch (Throwable $e) {
        /* may not exist */
    }
    try {
        $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_prod_slug (slug)');
        $log[] = 'Added uq_prod_slug';
    } catch (Throwable $e) {
        $log[] = 'uq_prod_slug already exists or skipped: ' . $e->getMessage();
    }

    $productImagesExists = $pdo->query("SHOW TABLES LIKE 'product_images'")->fetchAll();
    if (count($productImagesExists) === 0) {
        $pdo->exec(
            'CREATE TABLE product_images (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              product_id INT UNSIGNED NOT NULL,
              image VARCHAR(512) NOT NULL,
              alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
              sort_order INT NOT NULL DEFAULT 0,
              PRIMARY KEY (id),
              KEY idx_product_images_product (product_id),
              CONSTRAINT fk_product_image_product
                FOREIGN KEY (product_id) REFERENCES products (id)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created product_images table';
    } else {
        $log[] = 'product_images already exists';
    }

    $productReviewsExists = $pdo->query("SHOW TABLES LIKE 'product_reviews'")->fetchAll();
    if (count($productReviewsExists) === 0) {
        $pdo->exec(
            'CREATE TABLE product_reviews (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              product_id INT UNSIGNED NOT NULL,
              author_name VARCHAR(128) NOT NULL,
              rating TINYINT UNSIGNED NOT NULL,
              body TEXT NOT NULL,
              status ENUM(\'pending\',\'approved\',\'rejected\') NOT NULL DEFAULT \'pending\',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_product_reviews_product_status (product_id, status),
              CONSTRAINT fk_product_review_product
                FOREIGN KEY (product_id) REFERENCES products (id)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created product_reviews table';
    } else {
        $log[] = 'product_reviews already exists';
    }

    $siteUsersExists = $pdo->query("SHOW TABLES LIKE 'site_users'")->fetchAll();
    if (count($siteUsersExists) === 0) {
        $pdo->exec(
            'CREATE TABLE site_users (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              phone VARCHAR(20) NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_site_users_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created site_users table';
    } else {
        $log[] = 'site_users already exists';
    }

    $siteOtpExists = $pdo->query("SHOW TABLES LIKE 'site_otp_codes'")->fetchAll();
    if (count($siteOtpExists) === 0) {
        $pdo->exec(
            'CREATE TABLE site_otp_codes (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              phone VARCHAR(20) NOT NULL,
              code_hash VARCHAR(255) NOT NULL,
              expires_at DATETIME NOT NULL,
              attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_site_otp_phone (phone),
              KEY idx_site_otp_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created site_otp_codes table';
    } else {
        $log[] = 'site_otp_codes already exists';
    }

    $siteDeviceExists = $pdo->query("SHOW TABLES LIKE 'site_device_tokens'")->fetchAll();
    if (count($siteDeviceExists) === 0) {
        $pdo->exec(
            'CREATE TABLE site_device_tokens (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id INT UNSIGNED NOT NULL,
              phone VARCHAR(20) NOT NULL,
              token_hash CHAR(64) NOT NULL,
              expires_at DATETIME NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              last_used_at TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_site_device_token_hash (token_hash),
              KEY idx_site_device_phone (phone),
              KEY idx_site_device_expires (expires_at),
              KEY idx_site_device_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created site_device_tokens table';
    } else {
        $log[] = 'site_device_tokens already exists';
    }

    $ordersExists = $pdo->query("SHOW TABLES LIKE 'orders'")->fetchAll();
    if (count($ordersExists) === 0) {
        $pdo->exec(
            'CREATE TABLE orders (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              public_code VARCHAR(32) NOT NULL,
              user_id INT UNSIGNED NOT NULL,
              phone VARCHAR(20) NOT NULL,
              status ENUM(\'submitted\',\'accepted\',\'rejected\',\'payment_proof_sent\',\'paid\',\'shipped\',\'not_received\',\'returned_to_origin\',\'lost\',\'received\') NOT NULL DEFAULT \'submitted\',
              payment_note TEXT NULL,
              payment_file VARCHAR(512) NULL,
              payment_files TEXT NULL,
              payment_warning TEXT NULL,
              payment_warning_state VARCHAR(16) NULL,
              payment_submitted_at TIMESTAMP NULL DEFAULT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_orders_public_code (public_code),
              KEY idx_orders_user (user_id),
              KEY idx_orders_status (status),
              KEY idx_orders_created (created_at),
              CONSTRAINT fk_orders_user
                FOREIGN KEY (user_id) REFERENCES site_users (id)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created orders table';
    } else {
        $log[] = 'orders already exists';
        try {
            $pdo->exec(
                "ALTER TABLE orders
                 MODIFY COLUMN status ENUM(
                   'submitted','accepted','rejected','payment_proof_sent','paid','shipped',
                   'not_received','returned_to_origin','lost','received'
                 )
                 NOT NULL DEFAULT 'submitted'"
            );
            $log[] = 'Updated orders.status ENUM (parcel tracking + received)';
        } catch (Throwable $e) {
            $log[] = 'orders.status ENUM: ' . $e->getMessage();
        }
        $orderCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM orders')->fetchAll() ?: [] as $col) {
            $orderCols[(string) ($col['Field'] ?? '')] = true;
        }
        if (!isset($orderCols['payment_note'])) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_note TEXT NULL');
            $log[] = 'Added orders.payment_note';
        }
        if (!isset($orderCols['payment_file'])) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_file VARCHAR(512) NULL');
            $log[] = 'Added orders.payment_file';
        }
        if (!isset($orderCols['payment_files'])) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_files TEXT NULL');
            $log[] = 'Added orders.payment_files';
        }
        if (!isset($orderCols['payment_warning'])) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_warning TEXT NULL');
            $log[] = 'Added orders.payment_warning';
        }
        if (!isset($orderCols['payment_warning_state'])) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_warning_state VARCHAR(16) NULL');
            $log[] = 'Added orders.payment_warning_state';
        }
        if (!isset($orderCols['payment_submitted_at'])) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_submitted_at TIMESTAMP NULL DEFAULT NULL');
            $log[] = 'Added orders.payment_submitted_at';
        }
    }

    $orderItemsExists = $pdo->query("SHOW TABLES LIKE 'order_items'")->fetchAll();
    if (count($orderItemsExists) === 0) {
        $pdo->exec(
            'CREATE TABLE order_items (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              order_id INT UNSIGNED NOT NULL,
              product_id INT UNSIGNED NULL,
              name VARCHAR(191) NOT NULL,
              slug VARCHAR(191) NOT NULL DEFAULT \'\',
              price_text VARCHAR(128) NULL,
              image VARCHAR(512) NULL,
              quantity INT UNSIGNED NOT NULL DEFAULT 1,
              factory_name VARCHAR(191) NULL,
              model_name VARCHAR(191) NULL,
              category_name VARCHAR(191) NULL,
              PRIMARY KEY (id),
              KEY idx_order_items_order (order_id),
              CONSTRAINT fk_order_items_order
                FOREIGN KEY (order_id) REFERENCES orders (id)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created order_items table';
    } else {
        $log[] = 'order_items already exists';
    }

    $orderEventsExists = $pdo->query("SHOW TABLES LIKE 'order_events'")->fetchAll();
    if (count($orderEventsExists) === 0) {
        $pdo->exec(
            'CREATE TABLE order_events (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              order_id INT UNSIGNED NOT NULL,
              from_status VARCHAR(32) NULL,
              to_status VARCHAR(32) NOT NULL,
              message TEXT NULL,
              actor ENUM(\'client\',\'admin\') NOT NULL DEFAULT \'client\',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_order_events_order (order_id),
              CONSTRAINT fk_order_events_order
                FOREIGN KEY (order_id) REFERENCES orders (id)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created order_events table';
    } else {
        $log[] = 'order_events already exists';
    }

    require_once __DIR__ . '/lib/messages.php';
    messages_ensure_schema($pdo);
    $log[] = 'Ensured site_messages + site_user_reads + branches';

    require_once __DIR__ . '/lib/branches.php';
    branches_ensure_schema($pdo);
    $log[] = 'Ensured branches + tickets + site_users.branch_id';

    require_once __DIR__ . '/lib/orders.php';
    orders_ensure_schema($pdo);
    $log[] = 'Ensured orders branch snapshot columns';

    // Explicit pack / invoice columns (also covered by orders_ensure_schema)
    try {
        $prodCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM products')->fetchAll() ?: [] as $col) {
            $prodCols[(string) ($col['Field'] ?? '')] = true;
        }
        if (!isset($prodCols['pack_size'])) {
            $pdo->exec('ALTER TABLE products ADD COLUMN pack_size INT UNSIGNED NULL AFTER price_text');
            $log[] = 'Added products.pack_size';
        } else {
            $log[] = 'products.pack_size already exists';
        }
    } catch (Throwable $e) {
        $log[] = 'products.pack_size: ' . $e->getMessage();
    }

    try {
        $itemCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM order_items')->fetchAll() ?: [] as $col) {
            $itemCols[(string) ($col['Field'] ?? '')] = true;
        }
        if (!isset($itemCols['unit_type'])) {
            $pdo->exec(
                "ALTER TABLE order_items
                 ADD COLUMN unit_type ENUM('piece','pack') NOT NULL DEFAULT 'piece' AFTER quantity"
            );
            $log[] = 'Added order_items.unit_type';
        } else {
            $log[] = 'order_items.unit_type already exists';
        }
        if (!isset($itemCols['pack_size'])) {
            $pdo->exec('ALTER TABLE order_items ADD COLUMN pack_size INT UNSIGNED NULL AFTER unit_type');
            $log[] = 'Added order_items.pack_size';
        } else {
            $log[] = 'order_items.pack_size already exists';
        }
    } catch (Throwable $e) {
        $log[] = 'order_items unit/pack: ' . $e->getMessage();
    }

    try {
        $orderCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM orders')->fetchAll() ?: [] as $col) {
            $orderCols[(string) ($col['Field'] ?? '')] = true;
        }
        foreach (
            [
                'pre_invoice_file' => 'VARCHAR(512) NULL',
                'pre_invoice_created_at' => 'TIMESTAMP NULL DEFAULT NULL',
                'pre_invoice_due_at' => 'DATE NULL',
                'final_invoice_file' => 'VARCHAR(512) NULL',
                'final_invoice_created_at' => 'TIMESTAMP NULL DEFAULT NULL',
            ] as $col => $def
        ) {
            if (!isset($orderCols[$col])) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN {$col} {$def}");
                $log[] = "Added orders.{$col}";
            } else {
                $log[] = "orders.{$col} already exists";
            }
        }
    } catch (Throwable $e) {
        $log[] = 'orders invoice cols: ' . $e->getMessage();
    }

    $branchesExists = $pdo->query("SHOW TABLES LIKE 'branches'")->fetchAll();
    if (count($branchesExists) === 0) {
        $pdo->exec(
            "CREATE TABLE branches (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              name VARCHAR(191) NOT NULL,
              province_code VARCHAR(64) NOT NULL,
              province_name VARCHAR(191) NOT NULL,
              city VARCHAR(191) NOT NULL,
              phone VARCHAR(20) NULL,
              address TEXT NULL,
              sort_order INT NOT NULL DEFAULT 0,
              published TINYINT(1) NOT NULL DEFAULT 1,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_branches_province (province_code),
              KEY idx_branches_published (published),
              UNIQUE KEY uq_branches_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $log[] = 'Created branches table';
    } else {
        $log[] = 'branches already exists';
    }

    $aboutExists = $pdo->query("SHOW TABLES LIKE 'about_exhibitions'")->fetchAll();
    if (count($aboutExists) === 0) {
        $pdo->exec(
            'CREATE TABLE about_exhibitions (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              title VARCHAR(191) NOT NULL,
              year VARCHAR(32) NULL,
              location VARCHAR(191) NULL,
              cover_image VARCHAR(512) NULL,
              video_path VARCHAR(512) NULL,
              explanation TEXT NULL,
              sort_order INT NOT NULL DEFAULT 0,
              published TINYINT(1) NOT NULL DEFAULT 1,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created about_exhibitions table';
    } else {
        $log[] = 'about_exhibitions already exists';
    }

    $aboutSlidesExists = $pdo->query("SHOW TABLES LIKE 'about_exhibition_slides'")->fetchAll();
    if (count($aboutSlidesExists) === 0) {
        $pdo->exec(
            'CREATE TABLE about_exhibition_slides (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              exhibition_id INT UNSIGNED NOT NULL,
              image VARCHAR(512) NOT NULL,
              alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
              caption VARCHAR(512) NULL,
              sort_order INT NOT NULL DEFAULT 0,
              PRIMARY KEY (id),
              KEY idx_about_slides_exhibition (exhibition_id),
              CONSTRAINT fk_about_slide_exhibition
                FOREIGN KEY (exhibition_id) REFERENCES about_exhibitions(id)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'Created about_exhibition_slides table';
    } else {
        $log[] = 'about_exhibition_slides already exists';
    }

    require_once __DIR__ . '/lib/car-model-factories.php';
    $cmfExists = $pdo->query("SHOW TABLES LIKE 'car_model_factories'")->fetchAll();
    $legacyFactoryCol = $pdo->query('SHOW COLUMNS FROM car_models LIKE \'factory_id\'')->fetchAll();
    if (count($cmfExists) === 0 || count($legacyFactoryCol) > 0) {
        cms_ensure_car_model_factories_schema($pdo);
        $log[] = 'Migrated car models to multi-factory (car_model_factories junction table)';
    } else {
        $log[] = 'car_model_factories already migrated';
    }

    foreach (
        [
            'video_path' => 'VARCHAR(512) NULL AFTER image',
            'video_poster' => 'VARCHAR(512) NULL AFTER video_path',
            'detail_lead_image' => 'VARCHAR(512) NULL AFTER video_poster',
            'shop_display_image' => 'VARCHAR(512) NULL AFTER detail_lead_image',
            'video_path_low' => 'VARCHAR(512) NULL AFTER video_poster',
        ] as $col => $definition
    ) {
        $exists = $pdo->query('SHOW COLUMNS FROM products LIKE ' . $pdo->quote($col))->fetchAll();
        if (count($exists) === 0) {
            $pdo->exec("ALTER TABLE products ADD COLUMN {$col} {$definition}");
            $log[] = "Added products.{$col}";
        }
    }

    $aboutLowExists = $pdo->query('SHOW COLUMNS FROM about_exhibitions LIKE ' . $pdo->quote('video_path_low'))->fetchAll();
    if (count($aboutLowExists) === 0) {
        $pdo->exec('ALTER TABLE about_exhibitions ADD COLUMN video_path_low VARCHAR(512) NULL AFTER video_path');
        $log[] = 'Added about_exhibitions.video_path_low';
    }

    require_once __DIR__ . '/lib/uploads.php';
    $legacyMove = cms_migrate_legacy_cms_uploads();
    if ($legacyMove['moved'] > 0) {
        $log[] = 'Moved ' . $legacyMove['moved'] . ' legacy file(s) from cms/uploads/ to uploads/';
    }
    if ($legacyMove['skipped'] > 0) {
        $log[] = 'Skipped ' . $legacyMove['skipped'] . ' legacy file(s) already present in uploads/';
    }
    foreach ($legacyMove['errors'] as $moveError) {
        $log[] = 'Legacy upload move: ' . $moveError;
    }

    require_once __DIR__ . '/lib/product-car-models.php';
    $pcmExists = $pdo->query("SHOW TABLES LIKE 'product_car_models'")->fetchAll();
    $legacyCarModelCol = $pdo->query('SHOW COLUMNS FROM products LIKE \'car_model_id\'')->fetchAll();
    if (count($pcmExists) === 0 || count($legacyCarModelCol) > 0) {
        cms_ensure_product_car_models_schema($pdo);
        $log[] = 'Migrated products to multi car-model (product_car_models junction table)';
    } else {
        $log[] = 'product_car_models already migrated';
    }

    $visualCol = $pdo->query("SHOW COLUMNS FROM products LIKE 'visual_id'")->fetchAll();
    if (count($visualCol) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN visual_id VARCHAR(64) NULL AFTER slug');
        $log[] = 'Added products.visual_id column';
    } else {
        $log[] = 'products.visual_id already exists';
    }
    $visualIdx = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'uq_prod_visual_id'")->fetchAll();
    if (count($visualIdx) === 0) {
        $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_prod_visual_id (visual_id)');
        $log[] = 'Added unique index uq_prod_visual_id';
    } else {
        $log[] = 'uq_prod_visual_id index already exists';
    }
    $orderVisualCol = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'visual_id'")->fetchAll();
    if (count($orderVisualCol) === 0) {
        $pdo->exec('ALTER TABLE order_items ADD COLUMN visual_id VARCHAR(64) NULL AFTER category_name');
        $log[] = 'Added order_items.visual_id column';
    } else {
        $log[] = 'order_items.visual_id already exists';
    }

    $skipFrameCol = $pdo->query("SHOW COLUMNS FROM products LIKE 'skip_image_auto_frame'")->fetchAll();
    if (count($skipFrameCol) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN skip_image_auto_frame TINYINT(1) NOT NULL DEFAULT 0 AFTER shop_display_image');
        $log[] = 'Added products.skip_image_auto_frame column';
    } else {
        $log[] = 'products.skip_image_auto_frame already exists';
    }

    foreach (
        [
            'video_path' => 'VARCHAR(512) NULL AFTER image',
            'video_path_low' => 'VARCHAR(512) NULL AFTER video_path',
        ] as $col => $definition
    ) {
        $exists = $pdo->query('SHOW COLUMNS FROM categories LIKE ' . $pdo->quote($col))->fetchAll();
        if (count($exists) === 0) {
            $pdo->exec("ALTER TABLE categories ADD COLUMN {$col} {$definition}");
            $log[] = "Added categories.{$col} column";
        } else {
            $log[] = "categories.{$col} already exists";
        }
    }

    $catSkipFrameCol = $pdo->query("SHOW COLUMNS FROM categories LIKE 'skip_image_auto_frame'")->fetchAll();
    if (count($catSkipFrameCol) === 0) {
        $pdo->exec('ALTER TABLE categories ADD COLUMN skip_image_auto_frame TINYINT(1) NOT NULL DEFAULT 0 AFTER video_path_low');
        $log[] = 'Added categories.skip_image_auto_frame column';
    } else {
        $log[] = 'categories.skip_image_auto_frame already exists';
    }

    require_once __DIR__ . '/lib/home-pattern.php';
    $hadPattern = cms_setting_get(HOME_PATTERN_SETTING_KEY, '') !== '';
    home_pattern_seed_if_missing();
    $log[] = $hadPattern
        ? 'home_pattern_config already exists'
        : 'Seeded default home_pattern_config';
} catch (Throwable $e) {
    $ok = false;
    $log[] = 'ERROR: ' . $e->getMessage();
}

cms_layout_start('Migration', cms_current_username(), 'shop');
?>
<h1 style="margin-top:0">آپدیت ساختار فروشگاه</h1>
<p class="cms-muted">دسته‌بندی محصول مستقل شد؛ هر محصول هم به مدل خودرو وصل می‌شود هم به دسته.</p>
<?php if ($ok): ?>
  <p class="cms-ok">موفق</p>
<?php else: ?>
  <p class="cms-error">خطا — لاگ را ببینید</p>
<?php endif; ?>
<ul class="cms-steps">
  <?php foreach ($log as $line): ?>
    <li><?= cms_h($line) ?></li>
  <?php endforeach; ?>
</ul>
<p class="cms-muted"><strong>این فایل migrate-run.php را بعد از موفقیت حذف کنید.</strong></p>
<p><a class="cms-btn" href="shop.php">بازگشت به فروشگاه</a></p>
<?php cms_layout_end(); ?>
