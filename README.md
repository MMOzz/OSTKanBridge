# osTicket ↔ Kanboard Bridge

Bidirectional sync between osTicket Tasks and Kanboard Tasks using a lightweight PHP bridge + SQLite mapping database.

---

## How It Works

```
osTicket (MySQL)  ←──────────────────────────────────→  Kanboard (MySQL/SQLite)
        │                                                         │
        │         PHP Bridge (bridge.yourdomain.com)              │
        │   ┌─────────────────────────────────────────┐           │
        └──→│  sync_ost_to_kb.php  (cron, 1 min)      │──────────→│
            │  sync_kb_to_ost.php  (cron, 1 min)      │←──────────│
            │  webhook_kanboard.php (real-time)       │           │
            │  admin_mappings.php  (UI)               │           │
            │  bridge.sqlite       (mapping DB)       │           │
            └─────────────────────────────────────────┘
```

**Sync flow:**
1. An agent creates an osTicket **Task** and sets the custom field **"Kanboard Sync" = Yes**
2. The cron script (`sync_ost_to_kb.php`) detects it within 1 minute and creates a Kanboard task:
   - Title: `[OST T1234567] Task Subject`
   - Description includes links back to the osTicket task and parent ticket
   - Task is placed in the **Backlog** column
   - The Kanboard task ID is written to the osTicket task's **"Kanboard Task"** custom field
   - An internal note with the Kanboard link is added to the osTicket task thread
   - A comment with the osTicket link is added to the Kanboard task
3. When a Kanboard task is moved to **Done** → osTicket task status is set to **Closed**
4. Moving to any other column (Backlog, Ready, Work in progress) → osTicket task stays **Open**
5. Help Topics in osTicket can be mapped to specific Kanboard projects via the admin UI

---

## Files

| File | Purpose |
|------|---------|
| `config.php` | All settings — credentials, URLs, status mappings |
| `db.php` | SQLite bridge database, mapping helpers, logging |
| `osticket_api.php` | Reads/writes osTicket DB directly |
| `kanboard_api.php` | Kanboard JSON-RPC API client |
| `sync_ost_to_kb.php` | Cron: OST tasks → KB tasks |
| `sync_kb_to_ost.php` | Cron: KB column changes → OST task status |
| `webhook_kanboard.php` | Webhook: real-time KB → OST sync |
| `admin_mappings.php` | Web UI: map Help Topics → Kanboard Projects, view logs |
| `test.php` | Manual testing tool (delete after setup) |
| `pingtest.php` | Basic connectivity test (delete after setup) |
| `bridge.sqlite` | Auto-created SQLite mapping database |
| `.htaccess` | Blocks direct web access to sensitive files |

---

## Setup

### 1. Deploy Files

Upload all bridge files to a web-accessible directory on your server, e.g.:
```
https://yourdomain.com/bridge/
```

Make sure the directory is writable by the webserver for the SQLite file:
```bash
chmod 755 /path/to/bridge/
```

### 2. Configure osTicket

#### Create the "Kanboard Sync" list field

1. **Admin Panel → Manage → Lists → Add New List**
   - Name: `Kanboard Sync`
   - Add items: `Yes` and `No`
   - Save

2. **Admin Panel → Manage → Forms → Task Details → Add Field**
   - Type: **Selection List** → choose "Kanboard Sync"
   - Label: `Kanboard Sync`
   - Variable: `kanboard_sync`
   - Save

3. **Admin Panel → Manage → Forms → Task Details → Add Field** (optional but recommended)
   - Type: **Short Answer**
   - Label: `Kanboard Task`
   - Variable: `kanboard_task_id`
   - Save

#### Create an API Key (for future use)

1. **Admin Panel → Manage → API Keys → Add New API Key**
2. Copy the key into `OST_API_KEY` in `config.php`

### 3. Configure Kanboard

#### Get the API Token

1. **Settings → Application Settings → API**
2. Copy the **API token** into `KB_API_TOKEN` in `config.php`

#### Set Up the Webhook (for real-time sync)

1. **Settings → Integrations → Webhook URL**
2. Set to:
   ```
   https://yourdomain.com/bridge/webhook_kanboard.php?token=YOUR_WEBHOOK_SECRET
   ```

### 4. Edit config.php

Fill in all values:

```php
// osTicket database
define('OST_DB_DSN',     'mysql:host=localhost;dbname=your_db;charset=utf8mb4');
define('OST_DB_USER',    'your_db_user');
define('OST_DB_PASS',    'your_db_password');
define('OST_DB_PREFIX',  'ost_');

// URLs
define('OST_BASE_URL',   'https://support.yourdomain.com');
define('KB_BASE_URL',    'https://kanboard.yourdomain.com');

// API credentials
define('OST_API_KEY',    'your_osticket_api_key');
define('KB_API_TOKEN',   'your_kanboard_api_token');

// Webhook + admin security
define('WEBHOOK_SECRET', 'your_random_secret');
define('ADMIN_PASSWORD', 'your_admin_password');  // in admin_mappings.php

// Default Kanboard project (used when no Help Topic mapping exists)
define('KB_DEFAULT_PROJECT_ID', 2);  // adjust to your project ID
```

#### Status/Column Mapping

Adjust these to match your exact Kanboard column names (case-sensitive):

```php
// osTicket task status → Kanboard column (when creating/updating task)
define('STATUS_OST_TO_KB', serialize([
    'open'   => 'Backlog',   // new tasks land here
    'closed' => 'Done',
]));

// Kanboard column → osTicket task status
define('STATUS_KB_TO_OST', serialize([
    'Backlog'          => 'open',
    'Ready'            => 'open',
    'Work in progress' => 'open',
    'Done'             => 'closed',
]));
```

### 5. Set Up Cron Jobs

Add two cron jobs running every minute. Replace the path with your actual server path:

```cron
* * * * * php /www/htdocs/youruser/yourdomain.com/bridge/sync_ost_to_kb.php
* * * * * php /www/htdocs/youruser/yourdomain.com/bridge/sync_kb_to_ost.php
```

In your hosting control panel, look for **Cron Jobs** or **Scheduled Tasks**.

### 6. Map Help Topics to Kanboard Projects (optional)

Open `https://yourdomain.com/bridge/admin_mappings.php` and log in with `ADMIN_PASSWORD`.

Use the dropdowns to map each osTicket Help Topic to a Kanboard project. Tasks from that help topic's parent ticket will be created in the matching project. Unmapped topics use `KB_DEFAULT_PROJECT_ID`.

---

## Agent Workflow

1. A support ticket comes in to osTicket
2. Agent creates a **Task** on the ticket
3. Agent sets the **"Kanboard Sync"** field on the task to **"Yes"**
4. Within 1 minute a Kanboard task appears automatically in **Backlog**
5. The osTicket task gets an internal note with a link to the Kanboard task
6. The **"Kanboard Task"** field on the osTicket task shows the Kanboard task number

**Status stays in sync automatically:**
- Move Kanboard task to **Done** → osTicket task closes within 1 minute (or immediately via webhook)
- Move Kanboard task to any other column → osTicket task stays open
- An internal note is added to the osTicket task whenever the status changes via Kanboard

---

## Database Schema (bridge.sqlite)

**ticket_task_map** — links osTicket tasks to Kanboard tasks:
| Column | Description |
|--------|-------------|
| ost_ticket_id | osTicket task numeric ID |
| ost_ticket_number | osTicket task number e.g. T1234567 |
| kb_task_id | Kanboard task ID |
| kb_project_id | Kanboard project ID |
| last_ost_status | Last known osTicket status (open/closed) |
| last_kb_column | Last known Kanboard column name |
| last_synced_at | Unix timestamp of last sync |

**help_topic_project_map** — maps osTicket Help Topics to Kanboard projects.

**sync_log** — log of all sync events, viewable in the admin UI.

---

## Security

- `config.php`, `db.php`, `osticket_api.php`, `kanboard_api.php`, `*.sqlite`, `*.log` are all blocked from direct web access via `.htaccess`
- `webhook_kanboard.php` is protected by `?token=WEBHOOK_SECRET`
- `admin_mappings.php` is protected by a session password
- Use HTTPS for all endpoints
- Delete `test.php` and `pingtest.php` after setup

---

## Troubleshooting

**Sync-eligible tasks returns empty**
→ Make sure the task has "Kanboard Sync" set to **Yes** (capital Y) and the field variable name matches `OST_CUSTOM_FIELD_VAR` in config.php.

**Tasks created but status not syncing back**
→ Check that column names in `STATUS_KB_TO_OST` exactly match your Kanboard column titles. Use **"List Columns"** in test.php to verify.

**Webhook not firing**
→ Verify the webhook URL in Kanboard Settings → Integrations. The cron fallback syncs every minute regardless.

**500 errors on bridge pages**
→ Check your hosting error log. Common causes: wrong DB credentials, missing PHP extensions (PDO, SQLite, cURL), or `.htaccess` directives not supported by your host.

**Check the log**
→ Open `admin_mappings.php` → Sync Log, or query `SELECT * FROM sync_log ORDER BY id DESC LIMIT 50` in the SQLite file.

**osTicket task not closing**
→ The bridge sets the "Task Progress Status" custom field to "Closed". Make sure field ID 36 exists in your osTicket installation (check with `SELECT id, label FROM ost_form_field WHERE name = 'taskprogress'`).