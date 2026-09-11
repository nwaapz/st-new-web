<?php
declare(strict_types=1);

function sales_users_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sales_users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          username VARCHAR(64) NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          display_name VARCHAR(128) NOT NULL DEFAULT \'\',
          branch_id INT UNSIGNED NULL,
          published TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_sales_users_username (username),
          KEY idx_sales_users_branch (branch_id),
          KEY idx_sales_users_published (published)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function sales_users_normalize_username(string $raw): string
{
    return strtolower(trim($raw));
}

function sales_users_is_valid_username(string $username): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/i', $username);
}

/**
 * @return array{id: int, username: string, display_name: string, branch_id: ?int}|null
 */
function sales_users_public_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'display_name' => (string) ($row['display_name'] ?? ''),
        'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== null
            ? (int) $row['branch_id']
            : null,
    ];
}
