<?php
/**
 * Kanboard JSON-RPC API Client
 */

require_once __DIR__ . '/config.php';

function kb_call(string $method, array $params = []): mixed {
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'method'  => $method,
        'id'      => time(),
        'params'  => $params,
    ]);

    $ch = curl_init(KB_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => KB_API_USER . ':' . KB_API_TOKEN,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("Kanboard API curl error");
    }
    if ($httpCode !== 200) {
        throw new RuntimeException("Kanboard API HTTP $httpCode");
    }

    $data = json_decode($response, true);
    if (isset($data['error'])) {
        throw new RuntimeException("Kanboard API error: " . $data['error']['message']);
    }

    return $data['result'] ?? null;
}

// ─── Task helpers ─────────────────────────────────────────────────────────────

function kb_create_task(int $projectId, string $title, string $description, ?int $columnId = null): int {
    $params = [
        'project_id'  => $projectId,
        'title'       => $title,
        'description' => $description,
    ];
    if ($columnId) {
        $params['column_id'] = $columnId;
    }
    $taskId = kb_call('createTask', $params);
    if (!$taskId) {
        throw new RuntimeException("Kanboard createTask returned empty result");
    }
    return (int)$taskId;
}

function kb_get_task(int $taskId): ?array {
    return kb_call('getTask', ['task_id' => $taskId]) ?: null;
}

function kb_update_task(int $taskId, array $fields): bool {
    $fields['id'] = $taskId;
    return (bool)kb_call('updateTask', $fields);
}

function kb_move_task_to_column(int $taskId, int $projectId, int $columnId): bool {
    return (bool)kb_call('moveTaskToColumn', [
        'task_id'    => $taskId,
        'project_id' => $projectId,
        'column_id'  => $columnId,
    ]);
}

function kb_add_comment(int $taskId, string $content): bool {
    // Get the first available user ID for the comment author
    $users = kb_call('getAllUsers') ?? [];
    $userId = !empty($users) ? (int)$users[0]['id'] : 1;
    return (bool)kb_call('createComment', [
        'task_id'  => $taskId,
        'user_id'  => $userId,
        'content'  => $content,
    ]);
}

// ─── Column helpers ───────────────────────────────────────────────────────────

function kb_get_columns(int $projectId): array {
    return kb_call('getColumns', ['project_id' => $projectId]) ?? [];
}

function kb_column_id_by_name(int $projectId, string $columnName): ?int {
    foreach (kb_get_columns($projectId) as $col) {
        if (strcasecmp($col['title'], $columnName) === 0) {
            return (int)$col['id'];
        }
    }
    return null;
}

function kb_column_name_by_id(int $projectId, int $columnId): string {
    foreach (kb_get_columns($projectId) as $col) {
        if ((int)$col['id'] === $columnId) {
            return $col['title'];
        }
    }
    return '';
}

// ─── Project helpers ──────────────────────────────────────────────────────────

function kb_get_projects(): array {
    return kb_call('getAllProjects') ?? [];
}

function kb_get_project(int $projectId): ?array {
    return kb_call('getProjectById', ['project_id' => $projectId]) ?: null;
}

// ─── User helpers ─────────────────────────────────────────────────────────────

function kb_get_user_id(string $username): int {
    $user = kb_call('getUserByUsername', ['username' => $username]);
    return $user ? (int)$user['id'] : 1;
}

// ─── Status / column mapping ─────────────────────────────────────────────────

function ost_status_to_kb_column(string $ostStatus): string {
    $map = unserialize(STATUS_OST_TO_KB);
    return $map[strtolower($ostStatus)] ?? 'Backlog';
}

function kb_column_to_ost_status(string $kbColumn): string {
    $map = unserialize(STATUS_KB_TO_OST);
    return $map[$kbColumn] ?? 'open';
}