<?php
declare(strict_types=1);

require_once __DIR__ . '/contact.php';

const BALE_API_BASE = 'https://tapi.bale.ai/bot';

function bale_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS bale_chats (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          chat_id BIGINT NOT NULL,
          role ENUM('user','assistant') NOT NULL,
          body TEXT NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_bale_chats_chat (chat_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ready = true;
}

/**
 * @param array<int, string> $headers
 * @param array<string, mixed> $payload
 * @return array{ok:bool, status:int, json:array, raw:string, error:string}
 */
function bale_http_json(string $url, array $payload, array $headers = [], int $timeout = 20): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return ['ok' => false, 'status' => 0, 'json' => [], 'raw' => '', 'error' => 'json_encode failed'];
    }
    $headerLines = array_merge(['Content-Type: application/json; charset=utf-8'], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'json' => [], 'raw' => '', 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw)) {
            return ['ok' => false, 'status' => $status, 'json' => [], 'raw' => '', 'error' => $err !== '' ? $err : 'empty response'];
        }
        $json = json_decode($raw, true);
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'json' => is_array($json) ? $json : [],
            'raw' => $raw,
            'error' => $err,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    if (!is_string($raw)) {
        return ['ok' => false, 'status' => $status, 'json' => [], 'raw' => '', 'error' => 'http request failed'];
    }
    $json = json_decode($raw, true);
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'json' => is_array($json) ? $json : [],
        'raw' => $raw,
        'error' => '',
    ];
}

function bale_bot_token(): string
{
    return trim(cms_setting_get('contact_bale_bot_token', ''));
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok:bool, status:int, json:array, raw:string, error:string}
 */
function bale_api(string $method, array $payload, int $timeout = 20): array
{
    $token = bale_bot_token();
    if ($token === '') {
        return ['ok' => false, 'status' => 0, 'json' => [], 'raw' => '', 'error' => 'توکن ربات بله تنظیم نشده'];
    }
    return bale_http_json(BALE_API_BASE . $token . '/' . ltrim($method, '/'), $payload, [], $timeout);
}

function bale_send_message($chatId, string $text): array
{
    return bale_api('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
    ]);
}

function bale_set_webhook(string $url): array
{
    return bale_api('setWebhook', ['url' => $url]);
}

function bale_wants_human(string $text): bool
{
    $needles = ['اپراتور', 'انسان', 'پشتیبان', 'کارشناس', 'آدم', 'مسئول'];
    foreach ($needles as $needle) {
        if (function_exists('mb_strpos')) {
            if (mb_strpos($text, $needle) !== false) {
                return true;
            }
        } elseif (strpos($text, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function bale_canned_reply(bool $human): string
{
    if ($human) {
        return 'پیام شما برای پشتیبانی انسانی ثبت می‌شود. لطفاً از صفحه «تماس با ما» در سایت پیام بگذارید یا با شماره‌های تماس استارتک ارتباط بگیرید.';
    }
    return 'سلام، پیام شما دریافت شد. ربات هوشمند هنوز فعال نشده. از صفحه «تماس با ما» در سایت گفتگو کنید یا با تلفن / واتساپ / بله پیام بدهید.';
}

/**
 * @return list<array{role:string, body:string}>
 */
function bale_recent_turns(PDO $pdo, $chatId, int $limit = 8): array
{
    $stmt = $pdo->prepare(
        'SELECT role, body FROM bale_chats WHERE chat_id = ? ORDER BY id DESC LIMIT ' . (int) $limit
    );
    $stmt->execute([(string) $chatId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    $rows = array_reverse($rows);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'role' => (string) ($row['role'] ?? 'user'),
            'body' => (string) ($row['body'] ?? ''),
        ];
    }
    return $out;
}

function bale_store_turn(PDO $pdo, $chatId, string $role, string $body): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO bale_chats (chat_id, role, body) VALUES (?, ?, ?)'
    );
    $stmt->execute([(string) $chatId, $role, $body]);
}

function bale_llm_enabled(): bool
{
    return trim(cms_setting_get('contact_llm_api_key', '')) !== ''
        && trim(cms_setting_get('contact_llm_base_url', '')) !== '';
}

function bale_llm_reply(PDO $pdo, $chatId, string $userText): string
{
    $base = rtrim(trim(cms_setting_get('contact_llm_base_url', '')), '/');
    $key = trim(cms_setting_get('contact_llm_api_key', ''));
    $model = trim(cms_setting_get('contact_llm_model', ''));
    if ($base === '' || $key === '') {
        return '';
    }
    if (substr($base, -18) !== '/chat/completions') {
        $base .= '/chat/completions';
    }
    $prompt = trim(cms_setting_get('contact_llm_prompt', ''));
    if ($prompt === '') {
        $prompt = contact_default_llm_prompt();
    }
    if ($model === '') {
        $model = 'gpt-4o-mini';
    }

    $messages = [
        ['role' => 'system', 'content' => $prompt],
    ];
    foreach (bale_recent_turns($pdo, $chatId, 8) as $turn) {
        $role = $turn['role'] === 'assistant' ? 'assistant' : 'user';
        $messages[] = ['role' => $role, 'content' => $turn['body']];
    }
    $messages[] = ['role' => 'user', 'content' => $userText];

    $result = bale_http_json(
        $base,
        [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.4,
            'max_tokens' => 400,
        ],
        ['Authorization: Bearer ' . $key],
        25
    );
    if (!$result['ok']) {
        return '';
    }
    $choices = $result['json']['choices'] ?? null;
    $content = '';
    if (is_array($choices) && isset($choices[0]['message']['content'])) {
        $content = $choices[0]['message']['content'];
    }
    return is_string($content) ? trim($content) : '';
}

/**
 * @param array<string, mixed> $update
 */
function bale_handle_update(PDO $pdo, array $update): void
{
    bale_ensure_schema($pdo);
    $message = $update['message'] ?? null;
    if (!is_array($message)) {
        return;
    }
    $chat = $message['chat'] ?? null;
    $chatId = is_array($chat) ? ($chat['id'] ?? null) : null;
    if ($chatId === null || $chatId === '') {
        return;
    }
    $text = trim((string) ($message['text'] ?? ''));
    if ($text === '') {
        bale_send_message($chatId, 'فعلاً فقط پیام متنی پشتیبانی می‌شود.');
        return;
    }

    $human = bale_wants_human($text);
    $reply = '';
    if (!$human && bale_llm_enabled()) {
        try {
            $reply = bale_llm_reply($pdo, $chatId, $text);
        } catch (Throwable $e) {
            $reply = '';
        }
    }
    if ($reply === '') {
        $reply = bale_canned_reply($human);
    }

    try {
        bale_store_turn($pdo, $chatId, 'user', $text);
        bale_store_turn($pdo, $chatId, 'assistant', $reply);
    } catch (Throwable $e) {
        /* keep answering even if history write fails */
    }
    bale_send_message($chatId, $reply);
}
