<?php
/**
 * sync_kb_to_ost.php
 * ───────────────────
 * Run via cron every 1-2 minutes (complement to the webhook for reliability):
 *   * /1 * * * * php /path/to/bridge/sync_kb_to_ost.php >> /var/log/ost_kb_bridge.log 2>&1
 *
 * What it does:
 *  - Polls Kanboard tasks that are in the bridge map
 *  - If a task's column changed, updates the corresponding osTicket status
 *
 * The webhook (webhook_kanboard.php) handles real-time updates.
 * This cron is a fallback in case webhooks are missed.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/osticket_api.php';
require_once __DIR__ . '/kanboard_api.php';

// Load all mapped tasks from the bridge DB
$st = bridge_db()->query('SELECT ost_ticket_id, ost_ticket_number, kb_task_id, kb_project_id, last_kb_column, last_ost_status FROM ticket_task_map');
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $kbTaskId     = (int)$row['kb_task_id'];
    $ostTicketId  = (int)$row['ost_ticket_id'];
    $ticketNumber = $row['ost_ticket_number'];
    $projectId    = (int)$row['kb_project_id'];

    try {
        $task = kb_get_task($kbTaskId);
        if (!$task) continue;

        $currentColumnId   = (int)$task['column_id'];
        $currentColumnName = kb_column_name_by_id($projectId, $currentColumnId);

        // Skip if column hasn't changed since last sync
        if ($currentColumnName === $row['last_kb_column']) {
            continue;
        }

        // Map column to osTicket status
        $newOstStatus = kb_column_to_ost_status($currentColumnName);

        // Skip if that's already the osTicket status
        if (strtolower($newOstStatus) === strtolower($row['last_ost_status'])) {
            map_update_status($ostTicketId, $row['last_ost_status'], $currentColumnName);
            continue;
        }

        // Update osTicket status
        $success = ost_set_task_status($ostTicketId, $newOstStatus);

        if ($success) {
            map_update_status($ostTicketId, $newOstStatus, $currentColumnName);
            sync_log('kb_to_ost', 'status_sync',
                "KB task #$kbTaskId col '$currentColumnName' → OST #$ticketNumber status '$newOstStatus'");

            // Add an internal note in osTicket about the change
            $kbTaskUrl = KB_BASE_URL . '/?controller=TaskViewController&action=show&task_id=' . $kbTaskId . '&project_id=' . $projectId;
            ost_add_task_note($ostTicketId, sprintf(
                "🔄 Status updated via Kanboard: Task #%d moved to column \"%s\"\n%s",
                $kbTaskId,
                $currentColumnName,
                $kbTaskUrl
            ));
        } else {
            sync_log('kb_to_ost', 'error_status',
                "Failed to set OST #$ticketNumber to '$newOstStatus'");
        }

    } catch (Throwable $e) {
        sync_log('kb_to_ost', 'error', "KB task #$kbTaskId: " . $e->getMessage());
    }
}

echo date('Y-m-d H:i:s') . " sync_kb_to_ost completed\n";