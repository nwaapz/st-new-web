<?php
declare(strict_types=1);

/**
 * Unread inbox + order-queue counts for the CMS dashboard and root-tab badge.
 *
 * @return array{
 *   new_messages: int,
 *   support_unread: int,
 *   branch_msg_unread: int,
 *   ticket_unread: int,
 *   new_orders: int,
 *   client_order_activity: int,
 *   total: int
 * }
 */
function cms_admin_attention_counts(?PDO $pdo = null): array
{
    $counts = [
        'new_messages' => 0,
        'support_unread' => 0,
        'branch_msg_unread' => 0,
        'ticket_unread' => 0,
        'new_orders' => 0,
        'client_order_activity' => 0,
        'total' => 0,
    ];

    try {
        $pdo = $pdo ?? cms_pdo();
    } catch (Throwable $e) {
        return $counts;
    }

    try {
        $counts['support_unread'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM site_messages
             WHERE channel = 'support' AND actor = 'client' AND admin_read_at IS NULL"
        )->fetchColumn();
    } catch (Throwable $e) {
        /* table may not exist yet */
    }

    try {
        $counts['branch_msg_unread'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM site_messages
             WHERE channel = 'branch' AND actor = 'client' AND admin_read_at IS NULL"
        )->fetchColumn();
    } catch (Throwable $e) {
        /* table may not exist yet */
    }

    try {
        $counts['ticket_unread'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM branch_ticket_messages
             WHERE actor = 'branch' AND admin_read_at IS NULL"
        )->fetchColumn();
    } catch (Throwable $e) {
        /* table may not exist yet */
    }

    try {
        $counts['new_orders'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM orders WHERE status = 'submitted'"
        )->fetchColumn();
    } catch (Throwable $e) {
        /* table may not exist yet */
    }

    try {
        $counts['client_order_activity'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM orders
             WHERE status = 'payment_proof_sent'
                OR payment_warning_state = 'answered'"
        )->fetchColumn();
    } catch (Throwable $e) {
        /* table/column may not exist yet */
    }

    $counts['new_messages'] = $counts['support_unread']
        + $counts['branch_msg_unread']
        + $counts['ticket_unread'];
    $counts['total'] = $counts['new_messages']
        + $counts['new_orders']
        + $counts['client_order_activity'];

    return $counts;
}
