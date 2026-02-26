<?php
/**
 * osTicket Database Access
 * Reads directly from the osTicket MySQL database.
 * Also uses the osTicket REST API for write operations (status changes, notes).
 */

require_once __DIR__ . '/config.php';

function ost_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(OST_DB_DSN, OST_DB_USER, OST_DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

$p = OST_DB_PREFIX;

/**
 * Returns osTicket Tasks that have kanboard_sync = Yes
 * and are not yet in the bridge map.
 */
function ost_get_new_sync_tasks(): array {
    global $p;

    $fieldId = ost_get_custom_field_id(OST_CUSTOM_FIELD_VAR);
    if (!$fieldId) {
        return [];
    }

    $sql = "
        SELECT
            t.id            AS task_id,
            t.number        AS task_number,
            t.object_id     AS ticket_id,
            t.object_type   AS ticket_type,
            t.created       AS created_at,
            t.updated       AS updated_at,
            ts.name         AS status_name,
            te.title        AS task_title,
            tk.number       AS ticket_number,
            ht.topic        AS help_topic,
            ht.topic_id     AS topic_id,
            u.name          AS requester_name,
            ue.address      AS requester_email
        FROM {$p}task t
        LEFT JOIN {$p}thread thr         ON thr.object_id = t.id AND thr.object_type = 'A'
        LEFT JOIN {$p}thread_entry te    ON te.thread_id = thr.id AND te.type = 'M'
                                         AND te.id = (SELECT MIN(id) FROM {$p}thread_entry WHERE thread_id = thr.id AND type = 'M')
        LEFT JOIN {$p}ticket tk          ON tk.ticket_id = t.object_id AND t.object_type = 'T'
        LEFT JOIN {$p}ticket_status ts   ON ts.id = tk.status_id
        LEFT JOIN {$p}help_topic ht      ON ht.topic_id = tk.topic_id
        LEFT JOIN {$p}user u             ON u.id = tk.user_id
        LEFT JOIN {$p}user_email ue      ON ue.id = tk.user_email_id
        INNER JOIN {$p}form_entry fe     ON fe.object_id = t.id AND fe.object_type = 'A'
        INNER JOIN {$p}form_entry_values fev ON fev.entry_id = fe.id AND fev.field_id = :field_id
        WHERE fev.value LIKE :yes
        ORDER BY t.created DESC
        LIMIT 100
    ";

    $st = ost_db()->prepare($sql);
    $st->execute([':field_id' => $fieldId, ':yes' => OST_CUSTOM_FIELD_YES_VALUE]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns tasks that are already bridged and whose parent ticket status changed recently.
 */
function ost_get_changed_tasks(array $ostTaskIds): array {
    global $p;
    if (empty($ostTaskIds)) return [];

    $placeholders = implode(',', array_fill(0, count($ostTaskIds), '?'));
    $sql = "
        SELECT
            t.id         AS task_id,
            t.number     AS task_number,
            t.updated    AS updated_at,
            ts.name      AS status_name,
            tk.number    AS ticket_number
        FROM {$p}task t
        LEFT JOIN {$p}ticket tk        ON tk.ticket_id = t.object_id AND t.object_type = 'T'
        LEFT JOIN {$p}ticket_status ts ON ts.id = tk.status_id
        WHERE t.id IN ($placeholders)
          AND UNIX_TIMESTAMP(t.updated) > ?
    ";
    $st = ost_db()->prepare($sql);
    $st->execute(array_merge(array_values($ostTaskIds), [time() - SYNC_LOOKBACK_SECONDS]));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Find the numeric field ID for a custom field by its variable name.
 */
function ost_get_custom_field_id(string $varName): ?int {
    global $p;
    $st = ost_db()->prepare("SELECT id FROM {$p}form_field WHERE name = ? LIMIT 1");
    $st->execute([$varName]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

/**
 * Get the value of a custom field for a specific task.
 */
function ost_get_task_custom_field(int $taskId, string $varName): ?string {
    global $p;
    $fieldId = ost_get_custom_field_id($varName);
    if (!$fieldId) return null;

    $st = ost_db()->prepare("
        SELECT fev.value
        FROM {$p}form_entry fe
        INNER JOIN {$p}form_entry_values fev ON fev.entry_id = fe.id
        WHERE fe.object_id = ? AND fe.object_type = 'A' AND fev.field_id = ?
        LIMIT 1
    ");
    $st->execute([$taskId, $fieldId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['value'] : null;
}

/**
 * Write Kanboard task ID into the osTicket task custom field.
 */
function ost_set_kb_task_field(int $taskId, string $value): bool {
    global $p;
    $fieldId = ost_get_custom_field_id(OST_KB_TASK_FIELD_VAR);
    if (!$fieldId) return false;

    $st = ost_db()->prepare("SELECT id FROM {$p}form_entry WHERE object_id = ? AND object_type = 'A' LIMIT 1");
    $st->execute([$taskId]);
    $entry = $st->fetch(PDO::FETCH_ASSOC);
    if (!$entry) return false;

    $entryId = $entry['id'];
    $st = ost_db()->prepare("
        INSERT INTO {$p}form_entry_values (entry_id, field_id, value)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE value = ?
    ");
    return $st->execute([$entryId, $fieldId, $value, $value]);
}

/**
 * Add an internal note to an osTicket ticket via the osTicket REST API.
 */
function ost_add_internal_note(string $ticketNumber, string $message): bool {
    $url = OST_BASE_URL . '/api/tickets/' . $ticketNumber . '/notes';
    $payload = json_encode([
        'message' => $message,
        'type'    => 'N',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . OST_API_KEY,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 300;
}

/**
 * Change the status of an osTicket ticket via REST API.
 */
function ost_set_ticket_status(string $ticketNumber, string $statusName): bool {
    $statusId = ost_get_status_id($statusName);
    if (!$statusId) return false;

    $url = OST_BASE_URL . '/api/tickets/' . $ticketNumber;
    $payload = json_encode(['status_id' => $statusId]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_CUSTOMREQUEST   => 'PUT',
        CURLOPT_POSTFIELDS      => $payload,
        CURLOPT_HTTPHEADER      => [
            'Content-Type: application/json',
            'X-API-Key: ' . OST_API_KEY,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 300;
}

function ost_get_status_id(string $statusName): ?int {
    global $p;
    $st = ost_db()->prepare("SELECT id FROM {$p}ticket_status WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $st->execute([$statusName]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

/**
 * Get all help topics.
 */
function ost_get_help_topics(): array {
    global $p;
    $st = ost_db()->query("SELECT topic_id, topic FROM {$p}help_topic WHERE status_id = 0 ORDER BY topic");
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Add an internal note to an osTicket TASK directly via DB.
 */
function ost_add_task_note(int $taskId, string $message): bool {
    global $p;

    // Get the thread for this task
    $st = ost_db()->prepare("SELECT id FROM {$p}thread WHERE object_id = ? AND object_type = 'A' LIMIT 1");
    $st->execute([$taskId]);
    $thread = $st->fetch(PDO::FETCH_ASSOC);

    if (!$thread) {
        // Create thread if it doesn't exist
        $st = ost_db()->prepare("
            INSERT INTO {$p}thread (object_id, object_type, created)
            VALUES (?, 'A', NOW())
        ");
        $st->execute([$taskId]);
        $threadId = (int)ost_db()->lastInsertId();
    } else {
        $threadId = (int)$thread['id'];
    }

    // Insert the note entry (type 'N' = internal note)
    $st = ost_db()->prepare("
        INSERT INTO {$p}thread_entry
            (thread_id, staff_id, user_id, type, poster, title, body, format, source, created, updated)
        VALUES
            (?, 0, 0, 'N', 'SYSTEM', 'Kanboard Sync', ?, 'html', '', NOW(), NOW())
    ");
    return $st->execute([$threadId, $message]);
}

/**
 * Add an internal note to an osTicket TICKET directly via DB.
 */
function ost_add_ticket_note(int $ticketId, string $message): bool {
    global $p;

    $st = ost_db()->prepare("SELECT id FROM {$p}thread WHERE object_id = ? AND object_type = 'T' LIMIT 1");
    $st->execute([$ticketId]);
    $thread = $st->fetch(PDO::FETCH_ASSOC);
    if (!$thread) return false;

    $threadId = (int)$thread['id'];
    $st = ost_db()->prepare("
        INSERT INTO {$p}thread_entry
            (thread_id, staff_id, user_id, type, poster, title, body, format, source, created, updated)
        VALUES
            (?, 0, 0, 'N', 'SYSTEM', 'Kanboard Sync', ?, 'html', '', NOW(), NOW())
    ");
    return $st->execute([$threadId, $message]);
}

/**
 * Set osTicket Task status: 'open' or 'closed'
 * Tasks don't use status_id — they use the closed datetime column.
 */
function ost_set_task_status(int $taskId, string $status): bool {
    global $p;
    if (strtolower($status) === 'closed') {
        // flags=0 + closed timestamp = closed
        $st = ost_db()->prepare("UPDATE {$p}task SET flags = 0, closed = NOW(), updated = NOW() WHERE id = ?");
    } else {
        // flags=1 + no closed timestamp = open
        $st = ost_db()->prepare("UPDATE {$p}task SET flags = 1, closed = NULL, updated = NOW() WHERE id = ?");
    }
    $st->execute([$taskId]);
    return $st->rowCount() > 0;
}

/**
 * Get current task status: 'open' or 'closed'
 */
function ost_get_task_status(int $taskId): string {
    global $p;
    $st = ost_db()->prepare("SELECT flags, closed FROM {$p}task WHERE id = ? LIMIT 1");
    $st->execute([$taskId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return ($row && $row['flags'] == 0) ? 'closed' : 'open';
}