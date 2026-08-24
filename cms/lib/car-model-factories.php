<?php
declare(strict_types=1);

/**
 * Car models can be linked to up to two factories via car_model_factories.
 */

function cms_car_model_factory_names_sql(string $modelAlias = 'm'): string
{
    return '(SELECT GROUP_CONCAT(f2.name ORDER BY cmf.sort_order ASC, f2.sort_order ASC, f2.name ASC SEPARATOR \' · \')
             FROM car_model_factories cmf
             JOIN factories f2 ON f2.id = cmf.factory_id
             WHERE cmf.car_model_id = ' . $modelAlias . '.id)';
}

function cms_car_model_primary_factory_id_sql(string $modelAlias = 'm'): string
{
    return '(SELECT cmf.factory_id
             FROM car_model_factories cmf
             WHERE cmf.car_model_id = ' . $modelAlias . '.id
             ORDER BY cmf.sort_order ASC, cmf.factory_id ASC
             LIMIT 1)';
}

function cms_car_model_factory_filter_sql(string $modelAlias = 'm'): string
{
    return 'EXISTS (
        SELECT 1 FROM car_model_factories cmf_filter
        WHERE cmf_filter.car_model_id = ' . $modelAlias . '.id
          AND cmf_filter.factory_id = ?
    )';
}

/** @return list<int> */
function cms_car_model_load_factory_ids(PDO $pdo, int $carModelId): array
{
    $stmt = $pdo->prepare(
        'SELECT factory_id FROM car_model_factories
         WHERE car_model_id = ?
         ORDER BY sort_order ASC, factory_id ASC'
    );
    $stmt->execute([$carModelId]);
    $ids = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $ids[] = (int) $row['factory_id'];
    }
    return $ids;
}

function cms_car_model_save_factory_ids(PDO $pdo, int $carModelId, array $factoryIds): void
{
    $unique = [];
    foreach ($factoryIds as $factoryId) {
        $factoryId = (int) $factoryId;
        if ($factoryId > 0 && !in_array($factoryId, $unique, true)) {
            $unique[] = $factoryId;
        }
    }
    if ($unique === []) {
        throw new RuntimeException('حداقل یک کارخانه الزامی است');
    }
    if (count($unique) > 2) {
        throw new RuntimeException('هر مدل حداکثر دو کارخانه می‌تواند داشته باشد');
    }

    $pdo->prepare('DELETE FROM car_model_factories WHERE car_model_id = ?')->execute([$carModelId]);
    $stmt = $pdo->prepare(
        'INSERT INTO car_model_factories (car_model_id, factory_id, sort_order) VALUES (?, ?, ?)'
    );
    foreach ($unique as $sortOrder => $factoryId) {
        $stmt->execute([$carModelId, $factoryId, $sortOrder]);
    }
}

function cms_ensure_car_model_factories_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS car_model_factories (
          car_model_id INT UNSIGNED NOT NULL,
          factory_id INT UNSIGNED NOT NULL,
          sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (car_model_id, factory_id),
          KEY idx_cmf_factory (factory_id),
          CONSTRAINT fk_cmf_model FOREIGN KEY (car_model_id) REFERENCES car_models (id) ON DELETE CASCADE,
          CONSTRAINT fk_cmf_factory FOREIGN KEY (factory_id) REFERENCES factories (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $factoryCol = $pdo->query('SHOW COLUMNS FROM car_models LIKE \'factory_id\'')->fetchAll();
    if (count($factoryCol) > 0) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $pdo->exec(
            'INSERT IGNORE INTO car_model_factories (car_model_id, factory_id, sort_order)
             SELECT id, factory_id, 0 FROM car_models WHERE factory_id IS NOT NULL AND factory_id > 0'
        );

        $dupes = $pdo->query(
            'SELECT slug FROM car_models GROUP BY slug HAVING COUNT(*) > 1'
        )->fetchAll();
        foreach ($dupes as $dupe) {
            $slug = (string) $dupe['slug'];
            $rows = $pdo->prepare('SELECT id, slug FROM car_models WHERE slug = ? ORDER BY id ASC');
            $rows->execute([$slug]);
            $all = $rows->fetchAll() ?: [];
            foreach (array_slice($all, 1) as $index => $row) {
                $newSlug = $slug . '-' . ((int) $row['id']);
                $upd = $pdo->prepare('UPDATE car_models SET slug = ? WHERE id = ?');
                $upd->execute([$newSlug, (int) $row['id']]);
            }
        }

        try {
            $pdo->exec('ALTER TABLE car_models DROP FOREIGN KEY fk_model_factory');
        } catch (Throwable $e) {
            /* already dropped */
        }
        try {
            $pdo->exec('ALTER TABLE car_models DROP INDEX uq_model_factory_slug');
        } catch (Throwable $e) {
            /* */
        }
        try {
            $pdo->exec('ALTER TABLE car_models DROP INDEX idx_model_factory');
        } catch (Throwable $e) {
            /* */
        }
        $pdo->exec('ALTER TABLE car_models DROP COLUMN factory_id');

        try {
            $pdo->exec('ALTER TABLE car_models ADD UNIQUE KEY uq_model_slug (slug)');
        } catch (Throwable $e) {
            /* exists */
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } else {
        try {
            $pdo->exec('ALTER TABLE car_models ADD UNIQUE KEY uq_model_slug (slug)');
        } catch (Throwable $e) {
            /* exists */
        }
    }

    $ready = true;
}
