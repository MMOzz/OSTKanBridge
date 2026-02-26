<?php
/**
 * sync_ost_to_kb.php
 * Run via cron every 1-2 minutes:
 *   * /1 * * * * php /path/to/bridge/sync_ost_to_kb.php
 *
 * 1. Finds osTicket tasks with kanboard_sync = Yes not yet in bridge → creates Kanboard tasks
 * 2. For bridged tasks whose parent ticket status changed → moves Kanboard task column
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/osticket_api.php';
require_once __DIR__ . '/kanboard_api.php';

// ─── Step 1: Create new Kanboard tasks ───────────────────────────────────────

$tasks = ost_get_new_sync_tasks();
foreach ($tasks as $task) {
    $ostTaskId     = (int)$task['task_id'];
    $ostTaskNumber = $task['task_number'];
    $ticketNumber  = $task['ticket_number'] ?? null;
    $ticketId      = (int)($task['ticket_id'] ?? 0);

    // Skip if already mapped
    if (map_get_by_ost($ostTaskId)) {
        continue;
    }

    try {
        // Determine Kanboard project via help topic mapping or default
        $topicId   = (int)($task['topic_id'] ?? 0);
        $projectId = ($topicId ? topic_get_project($topicId) : null) ?? KB_DEFAULT_PROJECT_ID;

        // Build title and description
        $title = sprintf('[OST %s] %s', $ostTaskNumber, $task['task_title'] ?? 'No Subject');

        $ostTaskUrl   = OST_BASE_URL . '/scp/tasks.php?id=' . $ostTaskId;
        $ostTicketUrl = $ticketId ? OST_BASE_URL . '/scp/tickets.php?id=' . $ticketId : null;

        $descLines = ['## osTicket Task Details'];
        $descLines[] = sprintf('**Task #:** [%s](%s)', $ostTaskNumber, $ostTaskUrl);
        if ($ostTicketUrl && $ticketNumber) {
            $descLines[] = sprintf('**Linked Ticket #:** [%s](%s)', $ticketNumber, $ostTicketUrl);
        }
        if (!empty($task['requester_name'])) {
            $descLines[] = sprintf('**Requester:** %s (%s)', $task['requester_name'], $task['requester_email'] ?? '');
        }
        if (!empty($task['help_topic'])) {
            $descLines[] = sprintf('**Help Topic:** %s', $task['help_topic']);
        }
        if (!empty($task['status_name'])) {
            $descLines[] = sprintf('**Ticket Status:** %s', $task['status_name']);
        }
        $descLines[] = sprintf('**Created:** %s', $task['created_at']);

        $description = implode("\n\n", $descLines);

        // Determine target column before creating task
        $targetColumn = ost_status_to_kb_column($task['status_name'] ?? 'open');
        $columnId     = kb_column_id_by_name($projectId, $targetColumn);

        // Create Kanboard task directly in the right column
        $kbTaskId = kb_create_task($projectId, $title, $description, $columnId ?: null);

        // Store mapping
        map_insert($ostTaskId, $ostTaskNumber, $kbTaskId, $projectId);
        map_update_status($ostTaskId, ost_get_task_status($ostTaskId), $targetColumn);

        // Write KB task ID back to osTicket task custom field
        ost_set_kb_task_field($ostTaskId, (string)$kbTaskId);

        // Add internal note to the linked ticket and task
        $kbTaskUrl = KB_BASE_URL . '/?controller=TaskViewController&action=show&task_id=' . $kbTaskId . '&project_id=' . $projectId;
        $noteText = sprintf("Kanboard task created: Task #%d\n%s", $kbTaskId, $kbTaskUrl);
        // Note on the osTicket task
        ost_add_task_note($ostTaskId, $noteText);
        // Also note on the linked ticket if available
        if ($ticketId) {
            ost_add_ticket_note($ticketId, sprintf(
                "Kanboard task created for OST task %s: Task #%d\n%s",
                $ostTaskNumber, $kbTaskId, $kbTaskUrl
            ));
        }

        // Add comment in Kanboard with OST links (best effort)
        try {
            $comment = sprintf("Linked to osTicket task %s: %s", $ostTaskNumber, $ostTaskUrl);
            if ($ostTicketUrl && $ticketNumber) {
                $comment .= sprintf("\nLinked ticket #%s: %s", $ticketNumber, $ostTicketUrl);
            }
            kb_add_comment($kbTaskId, $comment);
        } catch (Throwable $ce) {
            sync_log('ost_to_kb', 'warning_comment', "Could not add KB comment: " . $ce->getMessage());
        }

        sync_log('ost_to_kb', 'task_created', "OST task $ostTaskNumber → KB task #$kbTaskId");

    } catch (Throwable $e) {
        sync_log('ost_to_kb', 'error_create', "OST task $ostTaskNumber: " . $e->getMessage());
    }
}

// ─── Step 2: Sync status changes for bridged tasks ────────────────────────────

$st = bridge_db()->query('SELECT ost_ticket_id, ost_ticket_number, kb_task_id, kb_project_id, last_ost_status FROM ticket_task_map');
$bridgedTasks = $st->fetchAll(PDO::FETCH_ASSOC);

if (!empty($bridgedTasks)) {
    $ostIds  = array_column($bridgedTasks, 'ost_ticket_id');
    $changed = ost_get_changed_tasks($ostIds);

    $bridgeIndex = [];
    foreach ($bridgedTasks as $bt) {
        $bridgeIndex[(int)$bt['ost_ticket_id']] = $bt;
    }

    foreach ($changed as $task) {
        $taskId    = (int)$task['task_id'];
        $newStatus = $task['status_name'] ?? 'open';
        $bt        = $bridgeIndex[$taskId] ?? null;

        if (!$bt) continue;
        if (strtolower($newStatus) === strtolower($bt['last_ost_status'])) continue;

        try {
            $kbTaskId  = (int)$bt['kb_task_id'];
            $projectId = (int)$bt['kb_project_id'];
            $targetCol = ost_status_to_kb_column($newStatus);
            $columnId  = kb_column_id_by_name($projectId, $targetCol);

            if ($columnId) {
                kb_move_task_to_column($kbTaskId, $projectId, $columnId);
                map_update_status($taskId, $newStatus, $targetCol);
                sync_log('ost_to_kb', 'status_sync', "OST task " . $task['task_number'] . " status '$newStatus' → KB column '$targetCol'");
            }
        } catch (Throwable $e) {
            sync_log('ost_to_kb', 'error_status', "OST task " . $task['task_number'] . ": " . $e->getMessage());
        }
    }
}

echo date('Y-m-d H:i:s') . " sync_ost_to_kb completed\n";