<?php
/**
 * Bridge Database (SQLite)
 * Handles the mapping table between osTicket tickets and Kanboard tasks.
 */

require_once __DIR__ . '/config.php';

function bridge_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . BRIDGE_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        bridge_init_schema($pdo);
    }
    return $pdo;
}

function bridge_init_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ticket_task_map (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            ost_ticket_id       INTEGER NOT NULL UNIQUE,  -- osTicket ost_ticket.ticket_id
            ost_ticket_number   TEXT    NOT NULL,          -- Human-readable number e.g. '123456'
            kb_task_id          INTEGER NOT NULL UNIQUE,
            kb_project_id       INTEGER NOT NULL,
            last_ost_status     TEXT    DEFAULT '',
            last_kb_column      TEXT    DEFAULT '',
            last_synced_at      INTEGER DEFAULT 0,         -- Unix timestamp
            created_at          INTEGER DEFAULT (strftime('%s','now'))
        );

        CREATE TABLE IF NOT EXISTS help_topic_project_map (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            ost_topic_id    INTEGER NOT NULL UNIQUE,
            ost_topic_name  TEXT    NOT NULL,
            kb_project_id   INTEGER NOT NULL,
            kb_project_name TEXT    NOT NULL
        );

        CREATE TABLE IF NOT EXISTS sync_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            direction   TEXT    NOT NULL,  -- 'ost_to_kb' or 'kb_to_ost'
            event       TEXT    NOT NULL,
            detail      TEXT,
            created_at  INTEGER DEFAULT (strftime('%s','now'))
        );
    ");
}

// ─── Mapping helpers ──────────────────────────────────────────────────────────

function map_get_by_ost(int $ostTicketId): ?array {
    $st = bridge_db()->prepare('SELECT * FROM ticket_task_map WHERE ost_ticket_id = ?');
    $st->execute([$ostTicketId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function map_get_by_kb(int $kbTaskId): ?array {
    $st = bridge_db()->prepare('SELECT * FROM ticket_task_map WHERE kb_task_id = ?');
    $st->execute([$kbTaskId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function map_insert(int $ostTicketId, string $ostNumber, int $kbTaskId, int $kbProjectId): void {
    $st = bridge_db()->prepare('
        INSERT OR IGNORE INTO ticket_task_map
            (ost_ticket_id, ost_ticket_number, kb_task_id, kb_project_id, last_synced_at)
        VALUES (?, ?, ?, ?, strftime(\'%s\',\'now\'))
    ');
    $st->execute([$ostTicketId, $ostNumber, $kbTaskId, $kbProjectId]);
}

function map_update_status(int $ostTicketId, string $ostStatus, string $kbColumn): void {
    $st = bridge_db()->prepare('
        UPDATE ticket_task_map
        SET last_ost_status = ?, last_kb_column = ?, last_synced_at = strftime(\'%s\',\'now\')
        WHERE ost_ticket_id = ?
    ');
    $st->execute([$ostStatus, $kbColumn, $ostTicketId]);
}

function topic_get_project(int $topicId): ?int {
    $st = bridge_db()->prepare('SELECT kb_project_id FROM help_topic_project_map WHERE ost_topic_id = ?');
    $st->execute([$topicId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['kb_project_id'] : null;
}

function topic_set_project(int $topicId, string $topicName, int $projectId, string $projectName): void {
    $st = bridge_db()->prepare('
        INSERT OR REPLACE INTO help_topic_project_map
            (ost_topic_id, ost_topic_name, kb_project_id, kb_project_name)
        VALUES (?, ?, ?, ?)
    ');
    $st->execute([$topicId, $topicName, $projectId, $projectName]);
}

// ─── Logging ──────────────────────────────────────────────────────────────────

function sync_log(string $direction, string $event, string $detail = ''): void {
    if (DEBUG_LOG) {
        $line = date('Y-m-d H:i:s') . " [$direction] $event" . ($detail ? ": $detail" : '') . PHP_EOL;
        file_put_contents(dirname(BRIDGE_DB_PATH) . '/bridge.log', $line, FILE_APPEND);
    }
    $st = bridge_db()->prepare('INSERT INTO sync_log (direction, event, detail) VALUES (?, ?, ?)');
    $st->execute([$direction, $event, $detail]);
}