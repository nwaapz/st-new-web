<?php
declare(strict_types=1);

/**
 * Static service/specialty catalog for the mechanic CRM (StarTech Customer Club).
 * Kept as a PHP constant (not a DB table) for the MVP — see spec sections 3-5.
 * `reminder_basis`: km | time | both.
 * `is_visit_reminder`: true when the service has no fixed lifespan and the
 * reminder should say "come in for an inspection" rather than "replace this".
 * `default_km` / `default_months`: placeholder intervals used to suggest the
 * next-due date when a service record is created; mechanics can override them.
 */

/**
 * Persian labels for mechanic service categories.
 *
 * @return array<string, string>
 */
function mechanic_catalog_category_labels(): array
{
    return [
        'quick_service' => 'سرویس سریع',
        'general' => 'عمومی',
        'belt_engine' => 'تسمه و موتور',
        'brakes' => 'ترمز',
        'suspension' => 'جلوبندی',
        'electrical' => 'برق و انژکتور',
    ];
}

/**
 * Built-in defaults. CMS overrides live in site_settings (mechanic_catalog_intervals).
 *
 * @return array<string, array{
 *   label: string,
 *   category: string,
 *   reminder_basis: string,
 *   is_visit_reminder: bool,
 *   default_km: ?int,
 *   default_months: ?int
 * }>
 */
function mechanic_catalog_builtin_services(): array
{
    static $builtin = null;
    if ($builtin !== null) {
        return $builtin;
    }

    $builtin = [
        'oil_engine' => ['label' => 'تعویض روغن موتور', 'category' => 'quick_service', 'reminder_basis' => 'both', 'is_visit_reminder' => false, 'default_km' => 7000, 'default_months' => 6],
        'oil_filter' => ['label' => 'تعویض فیلتر روغن', 'category' => 'quick_service', 'reminder_basis' => 'km', 'is_visit_reminder' => false, 'default_km' => 7000, 'default_months' => null],
        'air_filter' => ['label' => 'تعویض فیلتر هوا', 'category' => 'quick_service', 'reminder_basis' => 'km', 'is_visit_reminder' => false, 'default_km' => 15000, 'default_months' => null],
        'cabin_filter' => ['label' => 'تعویض فیلتر کابین', 'category' => 'quick_service', 'reminder_basis' => 'both', 'is_visit_reminder' => false, 'default_km' => 15000, 'default_months' => 12],
        'fuel_filter' => ['label' => 'تعویض فیلتر بنزین', 'category' => 'quick_service', 'reminder_basis' => 'km', 'is_visit_reminder' => false, 'default_km' => 30000, 'default_months' => null],
        'engine_repair' => ['label' => 'تعمیر موتور', 'category' => 'general', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => null, 'default_months' => null],
        'timing_belt' => ['label' => 'تسمه تایم', 'category' => 'belt_engine', 'reminder_basis' => 'both', 'is_visit_reminder' => false, 'default_km' => 60000, 'default_months' => 60],
        'timing_belt_kit' => ['label' => 'کیت تسمه تایم', 'category' => 'belt_engine', 'reminder_basis' => 'both', 'is_visit_reminder' => false, 'default_km' => 60000, 'default_months' => 60],
        'alternator_belt' => ['label' => 'تسمه دینام', 'category' => 'belt_engine', 'reminder_basis' => 'km', 'is_visit_reminder' => false, 'default_km' => 40000, 'default_months' => null],
        'ac_belt' => ['label' => 'تسمه کولر', 'category' => 'belt_engine', 'reminder_basis' => 'km', 'is_visit_reminder' => false, 'default_km' => 40000, 'default_months' => null],
        'hydraulic_belt' => ['label' => 'تسمه هیدرولیک', 'category' => 'belt_engine', 'reminder_basis' => 'km', 'is_visit_reminder' => false, 'default_km' => 40000, 'default_months' => null],
        'side_belts' => ['label' => 'تسمه‌های جانبی', 'category' => 'belt_engine', 'reminder_basis' => 'km', 'is_visit_reminder' => false, 'default_km' => 40000, 'default_months' => null],
        'idler_pulley' => ['label' => 'هرزگرد', 'category' => 'belt_engine', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 60000, 'default_months' => null],
        'tensioner' => ['label' => 'سفت‌کن', 'category' => 'belt_engine', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 60000, 'default_months' => null],
        'water_pump' => ['label' => 'واترپمپ', 'category' => 'belt_engine', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 60000, 'default_months' => null],
        'spark_plug' => ['label' => 'شمع و کوئل', 'category' => 'electrical', 'reminder_basis' => 'km', 'is_visit_reminder' => false, 'default_km' => 30000, 'default_months' => null],
        'cooling_system' => ['label' => 'سیستم خنک‌کننده', 'category' => 'general', 'reminder_basis' => 'both', 'is_visit_reminder' => false, 'default_km' => 40000, 'default_months' => 24],
        'coolant' => ['label' => 'مایع خنک‌کننده', 'category' => 'quick_service', 'reminder_basis' => 'both', 'is_visit_reminder' => false, 'default_km' => 40000, 'default_months' => 24],
        'brake_pad' => ['label' => 'لنت ترمز', 'category' => 'brakes', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 10000, 'default_months' => null],
        'brake_disc' => ['label' => 'دیسک ترمز', 'category' => 'brakes', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 20000, 'default_months' => null],
        'brake_caliper' => ['label' => 'کالیپر', 'category' => 'brakes', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 20000, 'default_months' => null],
        'brake_hose' => ['label' => 'شیلنگ ترمز', 'category' => 'brakes', 'reminder_basis' => 'time', 'is_visit_reminder' => true, 'default_km' => null, 'default_months' => 24],
        'brake_fluid' => ['label' => 'روغن ترمز', 'category' => 'quick_service', 'reminder_basis' => 'time', 'is_visit_reminder' => false, 'default_km' => null, 'default_months' => 24],
        'clutch' => ['label' => 'کلاچ', 'category' => 'general', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => 60000, 'default_months' => null],
        'gearbox' => ['label' => 'گیربکس', 'category' => 'general', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => null, 'default_months' => null],
        'gearbox_oil' => ['label' => 'روغن گیربکس', 'category' => 'quick_service', 'reminder_basis' => 'both', 'is_visit_reminder' => false, 'default_km' => 60000, 'default_months' => 24],
        'front_suspension' => ['label' => 'جلوبندی', 'category' => 'suspension', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => 20000, 'default_months' => null],
        'ball_joint' => ['label' => 'سیبک', 'category' => 'suspension', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 20000, 'default_months' => null],
        'control_arm' => ['label' => 'طبق', 'category' => 'suspension', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 20000, 'default_months' => null],
        'control_arm_bushing' => ['label' => 'بوش طبق', 'category' => 'suspension', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 20000, 'default_months' => null],
        'stabilizer_link' => ['label' => 'میل موج‌گیر', 'category' => 'suspension', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 20000, 'default_months' => null],
        'stabilizer_bushing' => ['label' => 'بوش میل موج‌گیر', 'category' => 'suspension', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 20000, 'default_months' => null],
        'shock_absorber' => ['label' => 'کمک‌فنر', 'category' => 'suspension', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => 40000, 'default_months' => null],
        'wheel_bearing' => ['label' => 'بلبرینگ چرخ', 'category' => 'suspension', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 40000, 'default_months' => null],
        'suspension_system' => ['label' => 'سیستم تعلیق', 'category' => 'suspension', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => 20000, 'default_months' => null],
        'wheel_alignment' => ['label' => 'میزان فرمان', 'category' => 'suspension', 'reminder_basis' => 'km', 'is_visit_reminder' => true, 'default_km' => 15000, 'default_months' => null],
        'battery' => ['label' => 'باتری', 'category' => 'electrical', 'reminder_basis' => 'time', 'is_visit_reminder' => true, 'default_km' => null, 'default_months' => 24],
        'alternator' => ['label' => 'دینام', 'category' => 'electrical', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => null, 'default_months' => null],
        'starter' => ['label' => 'استارت', 'category' => 'electrical', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => null, 'default_months' => null],
        'sensors' => ['label' => 'سنسورها', 'category' => 'electrical', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => null, 'default_months' => null],
        'diagnostics' => ['label' => 'دیاگ', 'category' => 'electrical', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => null, 'default_months' => null],
        'injector' => ['label' => 'انژکتور', 'category' => 'electrical', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => 40000, 'default_months' => null],
        'electrical_system' => ['label' => 'سیستم برق خودرو', 'category' => 'electrical', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => null, 'default_months' => null],
        'tire' => ['label' => 'لاستیک', 'category' => 'suspension', 'reminder_basis' => 'time', 'is_visit_reminder' => true, 'default_km' => null, 'default_months' => 12],
        'ac_system' => ['label' => 'سیستم تهویه', 'category' => 'quick_service', 'reminder_basis' => 'time', 'is_visit_reminder' => false, 'default_km' => null, 'default_months' => 6],
        'periodic_inspection' => ['label' => 'بازدید دوره‌ای خودرو', 'category' => 'quick_service', 'reminder_basis' => 'both', 'is_visit_reminder' => true, 'default_km' => 10000, 'default_months' => 6],
    ];

    return $builtin;
}

/**
 * @return array<string, array{default_km:?int, default_months:?int, reminder_basis?:string}>
 */
function mechanic_catalog_interval_overrides(): array
{
    if (!function_exists('cms_setting_get')) {
        return [];
    }
    $raw = cms_setting_get('mechanic_catalog_intervals', '');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mechanic_catalog_services(): array
{
    static $services = null;
    if ($services !== null) {
        return $services;
    }

    $services = mechanic_catalog_builtin_services();
    $overrides = mechanic_catalog_interval_overrides();
    foreach ($services as $key => &$service) {
        if (!isset($overrides[$key]) || !is_array($overrides[$key])) {
            continue;
        }
        $row = $overrides[$key];
        if (array_key_exists('default_km', $row)) {
            $km = $row['default_km'];
            $service['default_km'] = $km === null || $km === '' ? null : max(0, (int) $km);
            if ($service['default_km'] === 0) {
                $service['default_km'] = null;
            }
        }
        if (array_key_exists('default_months', $row)) {
            $months = $row['default_months'];
            $service['default_months'] = $months === null || $months === '' ? null : max(0, (int) $months);
            if ($service['default_months'] === 0) {
                $service['default_months'] = null;
            }
        }
        if (isset($row['reminder_basis']) && in_array($row['reminder_basis'], ['km', 'time', 'both'], true)) {
            $service['reminder_basis'] = $row['reminder_basis'];
        }
    }
    unset($service);

    return $services;
}

/**
 * @return array<string, array{label: string, services: string[]}>
 */
function mechanic_catalog_presets(): array
{
    return [
        'general' => [
            'label' => 'مکانیکی عمومی',
            'services' => ['oil_engine', 'oil_filter', 'air_filter', 'cabin_filter', 'fuel_filter', 'engine_repair', 'timing_belt', 'side_belts', 'spark_plug', 'cooling_system', 'brake_pad', 'clutch', 'gearbox', 'front_suspension'],
        ],
        'belt_engine' => [
            'label' => 'تعمیرکار تخصصی تسمه و موتور',
            'services' => ['timing_belt', 'timing_belt_kit', 'alternator_belt', 'ac_belt', 'hydraulic_belt', 'side_belts', 'idler_pulley', 'tensioner', 'water_pump', 'engine_repair'],
        ],
        'suspension' => [
            'label' => 'جلوبندی‌ساز',
            'services' => ['ball_joint', 'control_arm', 'control_arm_bushing', 'stabilizer_link', 'stabilizer_bushing', 'shock_absorber', 'wheel_bearing', 'suspension_system', 'wheel_alignment'],
        ],
        'brakes' => [
            'label' => 'تعمیرکار ترمز و زیربندی',
            'services' => ['brake_pad', 'brake_disc', 'brake_caliper', 'brake_hose', 'brake_fluid', 'wheel_bearing'],
        ],
        'electrical' => [
            'label' => 'برق خودرو و انژکتور',
            'services' => ['battery', 'alternator', 'starter', 'spark_plug', 'sensors', 'diagnostics', 'injector', 'electrical_system'],
        ],
        'quick_service' => [
            'label' => 'تعویض روغن و سرویس سریع',
            'services' => ['oil_engine', 'oil_filter', 'air_filter', 'cabin_filter', 'fuel_filter', 'gearbox_oil', 'brake_fluid', 'coolant', 'periodic_inspection'],
        ],
    ];
}

function mechanic_catalog_service(string $key): ?array
{
    $services = mechanic_catalog_services();
    return $services[$key] ?? null;
}

function mechanic_is_custom_service_key(string $key): bool
{
    return $key === 'custom' || strncmp($key, 'custom:', 7) === 0;
}

function mechanic_custom_service_key(string $label): string
{
    $norm = trim($label);
    if (function_exists('mb_strtolower')) {
        $norm = mb_strtolower($norm, 'UTF-8');
    }
    return 'custom:' . substr(hash('sha256', $norm), 0, 20);
}

/**
 * @return array{next_due_at: ?string, next_due_km: ?int}
 */
function mechanic_custom_suggest_next_due(string $performedAt, ?int $km, ?int $intervalKm): array
{
    $nextDueAt = null;
    $nextDueKm = null;

    if ($intervalKm !== null && $intervalKm > 0 && $km !== null) {
        $nextDueKm = $km + $intervalKm;
    }

    if ($nextDueKm === null) {
        try {
            $date = new DateTimeImmutable($performedAt !== '' ? $performedAt : 'today');
            $nextDueAt = $date->modify('+6 months')->format('Y-m-d');
        } catch (Throwable $e) {
            $nextDueAt = null;
        }
    }

    return ['next_due_at' => $nextDueAt, 'next_due_km' => $nextDueKm];
}

/**
 * @return array{next_due_at: ?string, next_due_km: ?int}
 */
function mechanic_catalog_suggest_next_due(string $serviceKey, string $performedAt, ?int $km): array
{
    $service = mechanic_catalog_service($serviceKey);
    if ($service === null) {
        return ['next_due_at' => null, 'next_due_km' => null];
    }

    $nextDueAt = null;
    $nextDueKm = null;

    if (($service['reminder_basis'] === 'time' || $service['reminder_basis'] === 'both') && $service['default_months']) {
        try {
            $date = new DateTimeImmutable($performedAt !== '' ? $performedAt : 'today');
            $nextDueAt = $date->modify('+' . (int) $service['default_months'] . ' months')->format('Y-m-d');
        } catch (Throwable $e) {
            $nextDueAt = null;
        }
    }

    if (($service['reminder_basis'] === 'km' || $service['reminder_basis'] === 'both') && $service['default_km'] && $km !== null) {
        $nextDueKm = $km + (int) $service['default_km'];
    }

    if ($nextDueAt === null && $nextDueKm === null) {
        try {
            $date = new DateTimeImmutable($performedAt !== '' ? $performedAt : 'today');
            $nextDueAt = $date->modify('+6 months')->format('Y-m-d');
        } catch (Throwable $e) {
            $nextDueAt = null;
        }
    }

    return ['next_due_at' => $nextDueAt, 'next_due_km' => $nextDueKm];
}

/**
 * Classify a reminder's urgency per spec section 9 (green/yellow/red).
 * Combines km-based and time-based status; the more urgent one wins.
 * When avg km/day is known, predicted due can pull yellow/red earlier.
 *
 * @return array{status: string, km_remaining: ?int, days_remaining: ?int, predicted_due_at: ?string}
 */
function mechanic_reminder_status(
    ?int $nextDueKm,
    ?int $currentKm,
    ?string $nextDueAt,
    ?float $avgKmPerDay = null
): array {
    $statuses = [];
    $kmRemaining = null;
    $daysRemaining = null;
    $predictedDueAt = null;

    if ($nextDueKm !== null && $currentKm !== null) {
        $kmRemaining = $nextDueKm - $currentKm;
        if ($kmRemaining <= 0) {
            $statuses[] = 'red';
        } elseif ($kmRemaining <= 2000) {
            $statuses[] = 'yellow';
        } else {
            $statuses[] = 'green';
        }
    } elseif ($nextDueKm !== null) {
        $statuses[] = 'green';
    }

    if ($nextDueAt !== null && $nextDueAt !== '') {
        try {
            $due = new DateTimeImmutable($nextDueAt);
            $today = new DateTimeImmutable('today');
            $daysRemaining = (int) $today->diff($due)->format('%r%a');
            if ($daysRemaining <= 0) {
                $statuses[] = 'red';
            } elseif ($daysRemaining <= 30) {
                $statuses[] = 'yellow';
            } else {
                $statuses[] = 'green';
            }
        } catch (Throwable $e) {
            // ignore invalid date
        }
    }

    if ($avgKmPerDay !== null && $avgKmPerDay > 0 && $nextDueKm !== null && $currentKm !== null) {
        try {
            $today = new DateTimeImmutable('today');
            if ($kmRemaining === null) {
                $kmRemaining = $nextDueKm - $currentKm;
            }
            if ($kmRemaining <= 0) {
                $predictedDays = $kmRemaining;
                $predictedDueAt = $today->format('Y-m-d');
            } else {
                $predictedDays = (int) ceil($kmRemaining / $avgKmPerDay);
                $predictedDueAt = $today->modify('+' . $predictedDays . ' days')->format('Y-m-d');
            }
            if ($daysRemaining === null) {
                $daysRemaining = $predictedDays;
            } else {
                $daysRemaining = min($daysRemaining, $predictedDays);
            }
            if ($predictedDays <= 0) {
                $statuses[] = 'red';
            } elseif ($predictedDays <= 30) {
                $statuses[] = 'yellow';
            } else {
                $statuses[] = 'green';
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (in_array('red', $statuses, true)) {
        $status = 'red';
    } elseif (in_array('yellow', $statuses, true)) {
        $status = 'yellow';
    } elseif (in_array('green', $statuses, true)) {
        $status = 'green';
    } else {
        $status = 'none';
    }

    return [
        'status' => $status,
        'km_remaining' => $kmRemaining,
        'days_remaining' => $daysRemaining,
        'predicted_due_at' => $predictedDueAt,
    ];
}
