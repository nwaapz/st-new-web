<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

sales_web_config_emit();

function sales_web_config_emit(): void
{
    $firebase = null;
    $vapid = null;
    $candidates = [
        dirname(__DIR__) . '/deploy/firebase-web.json',
        dirname(__DIR__) . '/cms/firebase-web.json',
    ];
    foreach ($candidates as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $raw = file_get_contents($path);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json)) {
            continue;
        }
        $apiKey = trim((string) ($json['apiKey'] ?? $json['api_key'] ?? ''));
        $projectId = trim((string) ($json['projectId'] ?? $json['project_id'] ?? ''));
        $appId = trim((string) ($json['appId'] ?? $json['app_id'] ?? ''));
        $sender = trim((string) ($json['messagingSenderId'] ?? $json['messaging_sender_id'] ?? ''));
        if ($apiKey !== '' && $projectId !== '' && $appId !== '' && $sender !== '') {
            $firebase = [
                'apiKey' => $apiKey,
                'authDomain' => trim((string) ($json['authDomain'] ?? $json['auth_domain'] ?? ($projectId . '.firebaseapp.com'))),
                'projectId' => $projectId,
                'storageBucket' => trim((string) ($json['storageBucket'] ?? $json['storage_bucket'] ?? ($projectId . '.appspot.com'))),
                'messagingSenderId' => $sender,
                'appId' => $appId,
            ];
        }
        $vapid = trim((string) ($json['vapidKey'] ?? $json['vapid_key'] ?? '')) ?: null;
        break;
    }

    api_json([
        'ok' => true,
        'pwa_url' => '/test2/sales/',
        'firebase' => $firebase,
        'vapidKey' => $vapid,
    ]);
}
