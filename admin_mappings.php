<?php
   ini_set('display_errors', 1);
   error_reporting(E_ALL);

/**
 * admin_mappings.php
 * ───────────────────
 * Simple password-protected admin page to map osTicket Help Topics → Kanboard Projects.
 * Access at: https://bridge.yourdomain.com/admin_mappings.php
 *
 * Protect this page with HTTP Basic Auth via .htaccess or change ADMIN_PASSWORD below.
 */

define('ADMIN_PASSWORD', 'AdminPwd');

// Simple password check
session_start();
if ($_POST['password'] ?? '' === ADMIN_PASSWORD) {
    $_SESSION['bridge_admin'] = true;
}
if (!($_SESSION['bridge_admin'] ?? false)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $error = 'Wrong password.';
    }
    ?><!DOCTYPE html>
<html><head><title>Bridge Admin Login</title>
<style>body{font-family:sans-serif;max-width:400px;margin:80px auto;padding:0 20px}
input{width:100%;padding:8px;margin:8px 0;box-sizing:border-box}
button{padding:8px 20px;background:#2d6a4f;color:#fff;border:none;cursor:pointer}
.err{color:red}</style></head><body>
<h2>Bridge Admin</h2>
<?php if (!empty($error)) echo "<p class='err'>$error</p>"; ?>
<form method="POST">
<input type="password" name="password" placeholder="Password" autofocus>
<button type="submit">Login</button>
</form></body></html><?php
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/osticket_api.php';
require_once __DIR__ . '/kanboard_api.php';

$message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_mapping') {
        $topicId     = (int)$_POST['topic_id'];
        $topicName   = $_POST['topic_name'] ?? '';
        $projectId   = (int)$_POST['project_id'];
        $projectName = $_POST['project_name'] ?? '';

        if ($topicId && $projectId) {
            topic_set_project($topicId, $topicName, $projectId, $projectName);
            $message = "✅ Mapping saved: \"$topicName\" → \"$projectName\"";
        }
    }
    if ($_POST['action'] === 'delete_mapping') {
        $topicId = (int)$_POST['topic_id'];
        bridge_db()->prepare('DELETE FROM help_topic_project_map WHERE ost_topic_id = ?')->execute([$topicId]);
        $message = "🗑️ Mapping deleted.";
    }
    if ($_POST['action'] === 'logout') {
        session_destroy();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Load data
$helpTopics  = ost_get_help_topics();
$kbProjects  = kb_get_projects();
$currentMaps = bridge_db()->query('SELECT * FROM help_topic_project_map ORDER BY ost_topic_name')->fetchAll(PDO::FETCH_ASSOC);
$syncLog     = bridge_db()->query('SELECT * FROM sync_log ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
$bridgeMaps  = bridge_db()->query('SELECT * FROM ticket_task_map ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);

// Index KB projects by ID
$kbProjectIndex = [];
foreach ($kbProjects as $p) {
    $kbProjectIndex[(int)$p['id']] = $p['name'];
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>osTicket ↔ Kanboard Bridge Admin</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f6f8; margin: 0; padding: 20px; color: #333; }
  .wrap { max-width: 1100px; margin: 0 auto; }
  h1 { color: #1a1a2e; border-bottom: 3px solid #2d6a4f; padding-bottom: 10px; }
  h2 { color: #2d6a4f; margin-top: 30px; }
  .card { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
  .msg { background: #d4edda; border: 1px solid #c3e6cb; padding: 10px 16px; border-radius: 6px; margin-bottom: 16px; }
  select, input[type=text] { padding: 7px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
  button, .btn { padding: 8px 18px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
  .btn-green  { background: #2d6a4f; color: #fff; }
  .btn-red    { background: #c0392b; color: #fff; }
  .btn-gray   { background: #999; color: #fff; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { background: #2d6a4f; color: #fff; padding: 8px 12px; text-align: left; }
  td { padding: 7px 12px; border-bottom: 1px solid #eee; }
  tr:hover td { background: #f9f9f9; }
  .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; }
  .badge-green { background:#d4edda; color:#155724; }
  .badge-blue  { background:#d1ecf1; color:#0c5460; }
  .badge-red   { background:#f8d7da; color:#721c24; }
  .form-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
  .form-group { display:flex; flex-direction:column; gap:4px; }
  label { font-size:13px; font-weight:600; color:#555; }
</style>
</head>
<body>
<div class="wrap">
  <h1>🔗 osTicket ↔ Kanboard Bridge Admin</h1>

  <?php if ($message): ?>
  <div class="msg"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <!-- Help Topic → Project Mapping -->
  <div class="card">
    <h2>Help Topic → Kanboard Project Mapping</h2>
    <p style="color:#666;font-size:14px">Map each osTicket Help Topic to a Kanboard project. Tickets from that topic will be created in the matching project. Unmapped topics use the default project (ID <?= KB_DEFAULT_PROJECT_ID ?>).</p>

    <form method="POST">
      <input type="hidden" name="action" value="save_mapping">
      <div class="form-row">
        <div class="form-group">
          <label>osTicket Help Topic</label>
          <select name="topic_id" id="topicSelect" onchange="fillTopicName()" required>
            <option value="">— Select topic —</option>
            <?php foreach ($helpTopics as $ht): ?>
            <option value="<?= $ht['topic_id'] ?>" data-name="<?= htmlspecialchars($ht['topic']) ?>">
              <?= htmlspecialchars($ht['topic']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="topic_name" id="topicNameHidden">
        </div>
        <div class="form-group">
          <label>Kanboard Project</label>
          <select name="project_id" id="projectSelect" onchange="fillProjectName()" required>
            <option value="">— Select project —</option>
            <?php foreach ($kbProjects as $proj): ?>
            <option value="<?= $proj['id'] ?>" data-name="<?= htmlspecialchars($proj['name']) ?>">
              #<?= $proj['id'] ?> — <?= htmlspecialchars($proj['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="project_name" id="projectNameHidden">
        </div>
        <div class="form-group">
          <label>&nbsp;</label>
          <button type="submit" class="btn btn-green">Save Mapping</button>
        </div>
      </div>
    </form>

    <?php if ($currentMaps): ?>
    <table style="margin-top:20px">
      <tr><th>Help Topic</th><th>Kanboard Project</th><th>Action</th></tr>
      <?php foreach ($currentMaps as $m): ?>
      <tr>
        <td><?= htmlspecialchars($m['ost_topic_name']) ?></td>
        <td>#<?= $m['kb_project_id'] ?> — <?= htmlspecialchars($m['kb_project_name']) ?></td>
        <td>
          <form method="POST" style="display:inline" onsubmit="return confirm('Delete this mapping?')">
            <input type="hidden" name="action" value="delete_mapping">
            <input type="hidden" name="topic_id" value="<?= $m['ost_topic_id'] ?>">
            <button type="submit" class="btn btn-red" style="padding:4px 10px;font-size:12px">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p style="color:#999;font-style:italic;margin-top:16px">No mappings yet. All tickets will use default project #<?= KB_DEFAULT_PROJECT_ID ?>.</p>
    <?php endif; ?>
  </div>

  <!-- Bridged Tickets -->
  <div class="card">
    <h2>Bridged Tickets (last 30)</h2>
    <?php if ($bridgeMaps): ?>
    <table>
      <tr><th>osTicket #</th><th>Kanboard Task</th><th>Project</th><th>OST Status</th><th>KB Column</th><th>Last Synced</th></tr>
      <?php foreach ($bridgeMaps as $bm):
        $ostUrl = OST_BASE_URL . '/scp/tickets.php?id=' . $bm['ost_ticket_id'];
        $kbUrl  = KB_BASE_URL  . '/?controller=TaskViewController&action=show&task_id=' . $bm['kb_task_id'] . '&project_id=' . $bm['kb_project_id'];
      ?>
      <tr>
        <td><a href="<?= $ostUrl ?>" target="_blank">#<?= htmlspecialchars($bm['ost_ticket_number']) ?></a></td>
        <td><a href="<?= $kbUrl ?>" target="_blank">Task #<?= $bm['kb_task_id'] ?></a></td>
        <td><?= htmlspecialchars($kbProjectIndex[$bm['kb_project_id']] ?? '#' . $bm['kb_project_id']) ?></td>
        <td><span class="badge badge-blue"><?= htmlspecialchars($bm['last_ost_status']) ?></span></td>
        <td><?= htmlspecialchars($bm['last_kb_column']) ?></td>
        <td><?= $bm['last_synced_at'] ? date('Y-m-d H:i', $bm['last_synced_at']) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p style="color:#999;font-style:italic">No bridged tickets yet.</p>
    <?php endif; ?>
  </div>

  <!-- Sync Log -->
  <div class="card">
    <h2>Sync Log (last 50 events)</h2>
    <?php if ($syncLog): ?>
    <table>
      <tr><th>Time</th><th>Direction</th><th>Event</th><th>Detail</th></tr>
      <?php foreach ($syncLog as $log):
        $badge = str_contains($log['event'], 'error') ? 'badge-red' : (str_contains($log['direction'], 'ost_to_kb') ? 'badge-green' : 'badge-blue');
      ?>
      <tr>
        <td style="white-space:nowrap"><?= date('Y-m-d H:i:s', $log['created_at']) ?></td>
        <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($log['direction']) ?></span></td>
        <td><?= htmlspecialchars($log['event']) ?></td>
        <td style="font-size:12px;color:#555"><?= htmlspecialchars($log['detail']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p style="color:#999;font-style:italic">No log entries yet.</p>
    <?php endif; ?>
  </div>

  <form method="POST" style="text-align:right">
    <input type="hidden" name="action" value="logout">
    <button type="submit" class="btn btn-gray">Logout</button>
  </form>
</div>

<script>
function fillTopicName() {
  const sel = document.getElementById('topicSelect');
  document.getElementById('topicNameHidden').value = sel.options[sel.selectedIndex]?.dataset.name || '';
}
function fillProjectName() {
  const sel = document.getElementById('projectSelect');
  document.getElementById('projectNameHidden').value = sel.options[sel.selectedIndex]?.dataset.name || '';
}
</script>
</body>
</html>