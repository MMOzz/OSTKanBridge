<?php
/**
 * osTicket <-> Kanboard Bridge Configuration
 * ============================================
 * Edit all values in this file before deploying.
 */

// ─── SQLite Bridge Database ───────────────────────────────────────────────────
// Path to the SQLite file. Must be writable by your webserver user.
// Place it OUTSIDE your webroot for security, e.g. /var/www/bridge/bridge.sqlite
define('BRIDGE_DB_PATH', __DIR__ . '/bridge.sqlite');

// ─── osTicket Settings ────────────────────────────────────────────────────────
define('OST_BASE_URL',   'https://support.yourdomain.com');   // No trailing slash
define('OST_API_KEY',    'YOUR_OSTICKET_API_KEY');             // Admin → API Keys
define('OST_DB_DSN',     'mysql:host=localhost;dbname=osticket;charset=utf8mb4');
define('OST_DB_USER',    'osticket_db_user');
define('OST_DB_PASS',    'osticket_db_password');
define('OST_DB_PREFIX',  'ost_');    // Default osTicket table prefix

// Name of the custom list field you created in osTicket.
// Admin → Manage → Lists → create "Kanboard Sync" list with values YES / NO
// Admin → Manage → Forms → add that list field to the ticket form
// The variable name here must match the field's variable name in osTicket.
define('OST_CUSTOM_FIELD_VAR', 'kanboard_sync'); // adjust to match your field's variable name
define('OST_CUSTOM_FIELD_YES_VALUE', '%"Yes"%');

// The custom text field that will store the Kanboard task ID/link (internal note fallback)
// Create another Text field in the ticket form, variable name as below.
define('OST_KB_TASK_FIELD_VAR', 'kanboard_task_id'); // optional — used if you add a field for it

// ─── Kanboard Settings ────────────────────────────────────────────────────────
define('KB_BASE_URL',    'https://kanboard.yourdomain.com');  // No trailing slash
define('KB_API_URL',     KB_BASE_URL . '/jsonrpc.php');
define('KB_API_USER',    'jsonrpc');          // Kanboard API user (always 'jsonrpc' for token auth)
define('KB_API_TOKEN',   'YOUR_KANBOARD_API_TOKEN'); // Settings → API → API Token

// Default project ID to use when no Help Topic mapping exists
define('KB_DEFAULT_PROJECT_ID', 2);

// Which column name in Kanboard represents each osTicket status.
// Keys = osTicket status names (lowercase), Values = Kanboard column names.
// Adjust column names to match your actual Kanboard board columns.
// Map osTicket task progress status → Kanboard column name
define('STATUS_OST_TO_KB', serialize([
    'open'   => 'Backlog',
    'closed' => 'Done',
]));

// Reverse mapping: Kanboard column name → osTicket task status ('open' or 'closed')
// osTicket task progress values: open, closed
define('STATUS_KB_TO_OST', serialize([
    'Backlog'          => 'open',
    'Ready'            => 'open',
    'Work in progress' => 'open',
    'Done'             => 'closed',
]));

// ─── Webhook Security ─────────────────────────────────────────────────────────
// A secret token appended to your webhook URL to prevent unauthorized calls.
// Set this in Kanboard: Settings → Integrations → Webhook URL → add ?token=YOUR_SECRET
define('WEBHOOK_SECRET', 'change_this_to_a_random_secret_string');

// ─── Sync Settings ────────────────────────────────────────────────────────────
// How far back (in seconds) the cron scripts look for changes on each run.
// Set slightly longer than your cron interval to avoid gaps. (90s for 1min cron)
define('SYNC_LOOKBACK_SECONDS', 90);

// Enable debug logging to bridge.log next to bridge.sqlite
define('DEBUG_LOG', true);