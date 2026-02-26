<?php
/**
 * webhook_kanboard.php
 * ─────────────────────
 * Kanboard POSTs to this URL when a task is moved or updated.
 *
 * In Kanboard: Settings → Integrations → Webhook URL
 * Set URL to: https://bridge.yourdomain.com/webhook_kanboard.php?token=YOUR_SECRET
 *
 * This script provides real-time sync of:
 *  - Column changes (task.move.column) → osTicket status update
 *  - Task updates (task.update)        → osTicket status update if column changed
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/osticket_api.php';
require_once __DIR__ . '/kanboard_api.php';

// ─── Security: validate the secret token ─────────────────────────────────────
$token = $_GET['token'] ?? '';
if (!hash_equals(WEBHOOK_SECRET, $token)) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Parse the incoming payload ───────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['event_name'])) {
    http_response_code(400);
    exit('Bad Request');
}

$eventName = $data['event_name'];
$eventData = $data['event_data'] ?? [];

sync_log('kb_to_ost', 'webhook_received', $eventName);

// ─── Handle task.move.column event ───────────────────────────────────────────
if ($eventName === 'task.move.column') {
    $kbTaskId      = (int)($eventData['task_id'] ?? 0);
    $kbProjectId   = (int)($eventData['project_id'] ?? 0);
    $newColumnName = $eventData['column_name'] ?? '';

    if (!$kbTaskId || !$newColumnName) {
        http_response_code(200);
        exit('OK - no task id or column');
    }

    process_column_change($kbTaskId, $kbProjectId, $newColumnName);
}

// ─── Handle task.update event ────────────────────────────────────────────────
elseif ($eventName === 'task.update') {
    $kbTaskId    = (int)($eventData['task_id'] ?? 0);
    $kbProjectId = (int)($eventData['project_id'] ?? 0);

    if (!$kbTaskId) {
        http_response_code(200);
        exit('OK - no task id');
    }

    // Fetch current task state to get column
    try {
        $task = kb_get_task($kbTaskId);
        if ($task) {
            $colName = kb_column_name_by_id($kbProjectId, (int)$task['column_id']);
            process_column_change($kbTaskId, $kbProjectId, $colName);
        }
    } catch (Throwable $e) {
        sync_log('kb_to_ost', 'webhook_error', $e->getMessage());
    }
}

http_response_code(200);
echo 'OK';

// ─── Helper ───────────────────────────────────────────────────────────────────

function process_column_change(int $kbTaskId, int $kbProjectId, string $newColumnName): void {
    $mapping = map_get_by_kb($kbTaskId);
    if (!$mapping) {
        // This task isn't in our bridge — ignore
        return;
    }

    $ostTicketId   = (int)$mapping['ost_ticket_id'];
    $ticketNumber  = $mapping['ost_ticket_number'];
    $lastKbColumn  = $mapping['last_kb_column'];
    $lastOstStatus = $mapping['last_ost_status'];

    // Skip if column hasn't changed
    if ($newColumnName === $lastKbColumn) {
        return;
    }

    $newOstStatus = kb_column_to_ost_status($newColumnName);

    // Skip if OST status wouldn't change
    if (strtolower($newOstStatus) === strtolower($lastOstStatus)) {
        map_update_status($ostTicketId, $lastOstStatus, $newColumnName);
        return;
    }

    // Update osTicket
    $success = ost_set_task_status((int)$mapping['ost_ticket_id'], $newOstStatus);

    if ($success) {
        map_update_status($ostTicketId, $newOstStatus, $newColumnName);
        sync_log('kb_to_ost', 'webhook_status_sync',
            "KB task #$kbTaskId → '$newColumnName' → OST #$ticketNumber '$newOstStatus'");

        // Internal note in osTicket
        $kbTaskUrl = KB_BASE_URL . '/?controller=TaskViewController&action=show&task_id=' . $kbTaskId . '&project_id=' . $kbProjectId;
        ost_add_ticket_note((int)$mapping['ost_ticket_id'], sprintf(
            "🔄 Kanboard task #%d moved to \"%s\" → ticket status set to \"%s\"\n%s",
            $kbTaskId,
            $newColumnName,
            $newOstStatus,
            $kbTaskUrl
        ));
    } else {
        sync_log('kb_to_ost', 'webhook_error',
            "Failed to update OST #$ticketNumber to '$newOstStatus'");
    }
}