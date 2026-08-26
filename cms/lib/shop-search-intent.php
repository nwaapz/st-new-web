<?php
declare(strict_types=1);

require_once __DIR__ . '/search-text.php';
require_once __DIR__ . '/car-model-factories.php';

/** @return list<array{triggers: list<string>, patterns: list<string>}> */
function shop_search_synonym_groups(): array
{
    return [
        [
            'triggers' => ['تسمه تایم', 'تسمه تایمینگ', 'تایمینگ', 'تایمینگ بند'],
            'patterns' => ['تسمه', 'تایم', 'موتور'],
        ],
        [
            'triggers' => ['تسمه'],
            'patterns' => ['تسمه'],
        ],
        [
            'triggers' => ['موتور', 'موتوری'],
            'patterns' => ['موتور'],
        ],
    ];
}

function shop_search_strip_once(string $text, string $needle): string
{
    $needle = search_normalize($needle);
    if ($needle === '') {
        return $text;
    }
    $pos = mb_strpos($text, $needle);
    if ($pos === false) {
        return $text;
    }
    $before = mb_substr($text, 0, $pos);
    $after = mb_substr($text, $pos + mb_strlen($needle));
    $merged = trim($before . ' ' . $after);
    return trim(preg_replace('/\s+/u', ' ', $merged) ?? $merged);
}

/**
 * @param list<int> $ids
 */
function shop_search_add_category_id(array &$ids, int $id): void
{
    if ($id <= 0 || in_array($id, $ids, true)) {
        return;
    }
    $ids[] = $id;
}

/**
 * Parse a shop search query into car / factory / category filters + remainder text.
 *
 * @param array{skip_car?: bool, skip_factory?: bool, skip_categories?: bool} $options
 * @return array{
 *   car_model_id: ?int,
 *   factory_id: ?int,
 *   category_ids: list<int>,
 *   remainder: string,
 *   matched: list<array{kind: string, id: int, label: string}>
 * }
 */
function shop_search_parse_intent(PDO $pdo, string $raw, array $options = []): array
{
    $skipCar = !empty($options['skip_car']);
    $skipFactory = !empty($options['skip_factory']);
    $skipCategories = !empty($options['skip_categories']);

    $text = search_normalize($raw);
    $result = [
        'car_model_id' => null,
        'factory_id' => null,
        'category_ids' => [],
        'remainder' => '',
        'matched' => [],
    ];

    if ($text === '') {
        return $result;
    }

    cms_ensure_car_model_factories_schema($pdo);
    $primaryFactorySql = cms_car_model_primary_factory_id_sql('m');
    $factoryNamesSql = cms_car_model_factory_names_sql('m');

    if (!$skipCar) {
        $carStmt = $pdo->query(
            "SELECT m.id, m.name, {$primaryFactorySql} AS factory_id
             FROM car_models m
             WHERE m.published = 1
             ORDER BY CHAR_LENGTH(m.name) DESC, m.sort_order ASC, m.name ASC"
        );
        foreach ($carStmt->fetchAll() ?: [] as $row) {
            $name = search_normalize((string) $row['name']);
            if ($name === '' || mb_strlen($name) < 2) {
                continue;
            }
            if (mb_strpos($text, $name) === false) {
                continue;
            }
            $result['car_model_id'] = (int) $row['id'];
            $factoryId = (int) ($row['factory_id'] ?? 0);
            if ($factoryId > 0) {
                $result['factory_id'] = $factoryId;
            }
            $text = shop_search_strip_once($text, $name);
            $result['matched'][] = [
                'kind' => 'car',
                'id' => (int) $row['id'],
                'label' => (string) $row['name'],
            ];
            break;
        }
    }

    if (!$skipFactory && $result['car_model_id'] === null) {
        $factoryStmt = $pdo->query(
            'SELECT id, name FROM factories
             WHERE published = 1
             ORDER BY CHAR_LENGTH(name) DESC, sort_order ASC, name ASC'
        );
        foreach ($factoryStmt->fetchAll() ?: [] as $row) {
            $name = search_normalize((string) $row['name']);
            if ($name === '' || mb_strlen($name) < 2) {
                continue;
            }
            if (mb_strpos($text, $name) === false) {
                continue;
            }
            $result['factory_id'] = (int) $row['id'];
            $text = shop_search_strip_once($text, $name);
            $result['matched'][] = [
                'kind' => 'factory',
                'id' => (int) $row['id'],
                'label' => (string) $row['name'],
            ];
            break;
        }
    }

    $categories = [];
    if (!$skipCategories) {
        $catStmt = $pdo->query(
            'SELECT id, name FROM categories
             WHERE published = 1
             ORDER BY CHAR_LENGTH(name) DESC, sort_order ASC, name ASC'
        );
        $categories = $catStmt->fetchAll() ?: [];

        foreach ($categories as $row) {
            $name = search_normalize((string) $row['name']);
            if ($name === '' || mb_strlen($name) < 2) {
                continue;
            }
            if (mb_strpos($text, $name) === false) {
                continue;
            }
            shop_search_add_category_id($result['category_ids'], (int) $row['id']);
            $text = shop_search_strip_once($text, $name);
            $result['matched'][] = [
                'kind' => 'category',
                'id' => (int) $row['id'],
                'label' => (string) $row['name'],
            ];
            break;
        }

        foreach (shop_search_synonym_groups() as $group) {
            $triggerMatched = false;
            foreach ($group['triggers'] as $trigger) {
                $normTrigger = search_normalize($trigger);
                if ($normTrigger === '' || mb_strpos($text, $normTrigger) === false) {
                    continue;
                }
                $text = shop_search_strip_once($text, $normTrigger);
                $triggerMatched = true;
                break;
            }
            if (!$triggerMatched) {
                continue;
            }
            foreach ($categories as $row) {
                $catName = search_normalize((string) $row['name']);
                if ($catName === '') {
                    continue;
                }
                foreach ($group['patterns'] as $pattern) {
                    $normPattern = search_normalize($pattern);
                    if ($normPattern === '') {
                        continue;
                    }
                    if (mb_strpos($catName, $normPattern) !== false) {
                        shop_search_add_category_id($result['category_ids'], (int) $row['id']);
                        break;
                    }
                }
            }
        }
    }

    $result['category_ids'] = array_slice(array_values(array_unique($result['category_ids'])), 0, 5);

    foreach ($result['category_ids'] as $catId) {
        $already = false;
        foreach ($result['matched'] as $match) {
            if ($match['kind'] === 'category' && $match['id'] === $catId) {
                $already = true;
                break;
            }
        }
        if ($already) {
            continue;
        }
        foreach ($categories as $row) {
            if ((int) $row['id'] !== $catId) {
                continue;
            }
            $result['matched'][] = [
                'kind' => 'category',
                'id' => $catId,
                'label' => (string) $row['name'],
            ];
            break;
        }
    }

    $result['remainder'] = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

    return $result;
}

/**
 * @return array{car_model_id: ?int, factory_id: ?int, category_ids: list<int>, remainder: string}
 */
function shop_search_intent_for_response(array $intent): array
{
    return [
        'car_model_id' => $intent['car_model_id'] ?? null,
        'factory_id' => $intent['factory_id'] ?? null,
        'category_ids' => $intent['category_ids'] ?? [],
        'remainder' => (string) ($intent['remainder'] ?? ''),
    ];
}
