<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/osticket_api.php';
require_once __DIR__ . '/kanboard_api.php';

if (($_GET['token'] ?? '') !== WEBHOOK_SECRET) {
    http_response_code(403);
    die('Forbidden: add ?token=YOUR_SECRET');
}

$action = $_GET['action'] ?? 'menu';
$t = urlencode(WEBHOOK_SECRET);

function u($a, $p = '') { global $t; return "?token=$t&action=$a$p"; }

function run(string $label, callable $fn): void {
    echo "<h3>$label</h3><pre style='background:#111;color:#0f0;padding:10px;border-radius:4px;overflow:auto'>";
    try {
        $r = $fn();
        if ($r === true)       echo "OK (true)";
        elseif ($r === false)  echo "FAILED (false)";
        elseif ($r === null)   echo "NULL";
        else                   echo htmlspecialchars(print_r($r, true));
    } catch (Throwable $e) {
        echo "ERROR: " . htmlspecialchars($e->getMessage()) . "\n" . basename($e->getFile()) . ":" . $e->getLine();
    }
    echo "</pre>";
}

?><!DOCTYPE html><html><head><meta charset="UTF-8"><title>Bridge Test</title>
<style>body{font-family:sans-serif;padding:20px;background:#f4f4f4} a{margin:4px;display:inline-block;padding:6px 12px;background:#0066cc;color:#fff;border-radius:4px;text-decoration:none} a:hover{background:#0052a3} h2{margin-top:24px;border-bottom:2px solid #ccc;padding-bottom:4px}</style>
</head><body>
<h1>Bridge Tester</h1>
<p><a href="<?= u('menu') ?>">&#8592; Menu</a></p>

<?php if ($action === 'menu'): ?>

<h2>osTicket</h2>
<a href="<?= u('ost_topics') ?>">Help Topics</a>
<a href="<?= u('ost_field') ?>">Custom Field IDs</a>
<a href="<?= u('ost_tickets') ?>">Sync-eligible Tickets</a>
<a href="<?= u('ost_note') ?>">Send Internal Note</a>
<a href="<?= u('ost_status') ?>">Change Ticket Status</a>

<h2>Kanboard</h2>
<a href="<?= u('kb_projects') ?>">List Projects</a>
<a href="<?= u('kb_columns') ?>">List Columns</a>
<a href="<?= u('kb_task') ?>">Get Task</a>
<a href="<?= u('kb_create') ?>">Create Test Task</a>

<h2>Bridge</h2>
<a href="<?= u('bridge_db') ?>">Bridge DB Info</a>
<a href="<?= u('bridge_maps') ?>">All Mappings</a>
<a href="<?= u('sync_log') ?>">Sync Log</a>
<a href="<?= u('run_ost_kb') ?>" style="background:#007700">&#9654; Run OST&#8594;KB sync</a>
<a href="<?= u('run_kb_ost') ?>" style="background:#007700">&#9654; Run KB&#8594;OST sync</a>

<?php elseif ($action === 'ost_topics'): ?>
<?php run('osTicket Help Topics', fn() => ost_get_help_topics()); ?>

<?php elseif ($action === 'ost_field'): ?>
<?php run('Custom field: ' . OST_CUSTOM_FIELD_VAR, fn() => ost_get_custom_field_id(OST_CUSTOM_FIELD_VAR)); ?>
<?php run('Custom field: ' . OST_KB_TASK_FIELD_VAR, fn() => ost_get_custom_field_id(OST_KB_TASK_FIELD_VAR)); ?>

<?php elseif ($action === 'ost_tickets'): ?>
<?php run('Sync-eligible tasks (kanboard_sync=Yes)', fn() => ost_get_new_sync_tasks()); ?>

<?php elseif ($action === 'ost_note'): ?>
<h2>Send Internal Note</h2>
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<?php
$taskId = (int)$_POST['num'];
$msg = trim($_POST['msg']);
echo "<h3>Debug: Send note to task #$taskId</h3><pre style='background:#111;color:#0f0;padding:10px;border-radius:4px'>";
try {
    global $p;
    // Step 1: find thread
    $st = ost_db()->prepare("SELECT id FROM ost_thread WHERE object_id = ? AND object_type = 'A' LIMIT 1");
    $st->execute([$taskId]);
    $thread = $st->fetch(PDO::FETCH_ASSOC);
    echo "Thread lookup for task $taskId: " . ($thread ? "Found ID=" . $thread['id'] : "NOT FOUND") . "\n";

    if ($thread) {
        $threadId = (int)$thread['id'];
        $st = ost_db()->prepare("INSERT INTO ost_thread_entry (thread_id, staff_id, user_id, type, poster, title, body, format, source, created, updated) VALUES (?, 0, 0, 'N', 'SYSTEM', 'Kanboard Sync', ?, 'html', '', NOW(), NOW())");
        $result = $st->execute([$threadId, nl2br(htmlspecialchars($msg))]);
        echo "Insert result: " . ($result ? "OK, new id=" . ost_db()->lastInsertId() : "FAILED") . "\n";
        echo "Row count: " . $st->rowCount() . "\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . htmlspecialchars($e->getMessage()) . "\n" . $e->getFile() . ":" . $e->getLine();
}
echo "</pre>";
?>
<?php else: ?>
<form method="POST">
<input type="hidden" name="action" value="ost_note">
<p>Task ID (numeric, e.g. 6): <input type="text" name="num" placeholder="6"></p>
<p>Message: <textarea name="msg" rows="3" cols="50">Test note from bridge</textarea></p>
<p><input type="submit" value="Send Note"></p>
</form>
<?php endif; ?>

<?php elseif ($action === 'ost_status'): ?>
<h2>Change Ticket Status</h2>
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<?php run('Set task #' . $_POST['num'] . ' to ' . $_POST['status'], fn() => ost_set_task_status((int)$_POST['num'], trim($_POST['status']))); ?>
<?php else: ?>
<form method="POST">
<input type="hidden" name="action" value="ost_status">
<p>Task ID (numeric, e.g. 6): <input type="text" name="num" placeholder="6"></p>
<p>Status: <select name="status"><option>open</option><option>closed</option></select></p>
<p><input type="submit" value="Change Status"></p>
</form>
<?php endif; ?>

<?php elseif ($action === 'kb_projects'): ?>
<?php run('Kanboard Projects', fn() => kb_get_projects()); ?>

<?php elseif ($action === 'kb_columns'): ?>
<h2>Project Columns</h2>
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<?php run('Columns for project #' . $_POST['pid'], fn() => kb_get_columns((int)$_POST['pid'])); ?>
<?php else: ?>
<form method="POST">
<input type="hidden" name="action" value="kb_columns">
<p>Project ID: <input type="number" name="pid" value="<?= KB_DEFAULT_PROJECT_ID ?>"></p>
<p><input type="submit" value="Get Columns"></p>
</form>
<?php endif; ?>

<?php elseif ($action === 'kb_task'): ?>
<h2>Get Task</h2>
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<?php run('Task #' . $_POST['tid'], fn() => kb_get_task((int)$_POST['tid'])); ?>
<?php else: ?>
<form method="POST">
<input type="hidden" name="action" value="kb_task">
<p>Task ID: <input type="number" name="tid" value="1"></p>
<p><input type="submit" value="Get Task"></p>
</form>
<?php endif; ?>

<?php elseif ($action === 'kb_create'): ?>
<h2>Create Test Task</h2>
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<?php run('Create task in project #' . $_POST['pid'], fn() => kb_create_task((int)$_POST['pid'], trim($_POST['title']), trim($_POST['desc']))); ?>
<?php else: ?>
<form method="POST">
<input type="hidden" name="action" value="kb_create">
<p>Project ID: <input type="number" name="pid" value="<?= KB_DEFAULT_PROJECT_ID ?>"></p>
<p>Title: <input type="text" name="title" value="[TEST] Bridge test task" size="40"></p>
<p>Description: <textarea name="desc" rows="3" cols="50">Created by bridge tester. Safe to delete.</textarea></p>
<p><input type="submit" value="Create Task"></p>
</form>
<?php endif; ?>

<?php elseif ($action === 'bridge_db'): ?>
<?php run('Bridge DB', function() {
    $db = bridge_db();
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    return ['path' => BRIDGE_DB_PATH, 'tables' => $tables];
}); ?>

<?php elseif ($action === 'bridge_maps'): ?>
<?php run('Ticket-Task Mappings', fn() => bridge_db()->query('SELECT * FROM ticket_task_map ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: '(none)'); ?>
<?php run('Topic-Project Mappings', fn() => bridge_db()->query('SELECT * FROM help_topic_project_map')->fetchAll(PDO::FETCH_ASSOC) ?: '(none)'); ?>

<?php elseif ($action === 'sync_log'): ?>
<?php run('Sync Log (last 50)', fn() => bridge_db()->query('SELECT * FROM sync_log ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC) ?: '(empty)'); ?>

<?php elseif ($action === 'run_ost_kb'): ?>
<h2>Running sync_ost_to_kb.php</h2>
<pre style="background:#111;color:#0f0;padding:10px;border-radius:4px"><?php
ob_start();
try {
    require __DIR__ . '/sync_ost_to_kb.php';
    echo htmlspecialchars(ob_get_clean());
} catch(Throwable $e) {
    ob_get_clean();
    echo "ERROR: " . htmlspecialchars($e->getMessage()) . "\n" . basename($e->getFile()) . ":" . $e->getLine();
}
?></pre>

<?php elseif ($action === 'run_kb_ost'): ?>
<h2>Running sync_kb_to_ost.php</h2>
<pre style="background:#111;color:#0f0;padding:10px;border-radius:4px"><?php
ob_start();
try {
    require __DIR__ . '/sync_kb_to_ost.php';
    echo htmlspecialchars(ob_get_clean());
} catch(Throwable $e) {
    ob_get_clean();
    echo "ERROR: " . htmlspecialchars($e->getMessage()) . "\n" . basename($e->getFile()) . ":" . $e->getLine();
}
?></pre>

<?php endif; ?>

<p style="margin-top:40px;color:#999;font-size:12px">Delete test.php when done.</p>
</body></html>