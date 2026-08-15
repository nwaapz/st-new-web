<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/mechanic-catalog.php';

try {
    $services = [];
    foreach (mechanic_catalog_services() as $key => $service) {
        $services[] = [
            'key' => $key,
            'label' => $service['label'],
            'category' => $service['category'],
            'reminder_basis' => $service['reminder_basis'],
            'is_visit_reminder' => $service['is_visit_reminder'],
        ];
    }

    $presets = [];
    foreach (mechanic_catalog_presets() as $key => $preset) {
        $presets[] = [
            'key' => $key,
            'label' => $preset['label'],
            'services' => $preset['services'],
        ];
    }

    api_json(['ok' => true, 'services' => $services, 'presets' => $presets]);
} catch (Throwable $e) {
    error_log('[mechanic-catalog] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
