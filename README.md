# osTicket ↔ Kanboard Sync Service

Production-grade bidirectional synchronization between osTicket and
Kanboard using plain PHP, designed for shared hosting environments.

------------------------------------------------------------------------

## ✨ Features

-   Bidirectional sync (ticket ↔ task)
-   Loop prevention
-   Webhook-driven processing
-   Queue with retries
-   Structured logging
-   Health monitoring
-   Multi-project ready
-   Lightweight (shared hosting friendly)
-   SQLite storage

------------------------------------------------------------------------

## 🏗 System Architecture

    osTicket → webhook → sync service → queue → worker → Kanboard API
    Kanboard → webhook → sync service → queue → worker → osTicket API

Webhook endpoints enqueue events quickly. A cron worker processes them
safely.

------------------------------------------------------------------------

## ⚡ Quick Start

1.  Upload project files to hosting
2.  Configure `app/config.php`
3.  Run database migration
4.  Configure webhooks
5.  Setup cron worker
6.  Test health endpoint

------------------------------------------------------------------------

## 📁 Repository Structure

    integration-sync/
    ├── public/
    ├── app/
    ├── worker.php
    ├── migrate.php
    ├── data/
    ├── logs/
    └── README.md

------------------------------------------------------------------------

## ⚙️ Requirements

-   PHP 8+
-   cURL extension
-   SQLite enabled
-   Cron access
-   HTTPS recommended

------------------------------------------------------------------------

## 🚀 Installation

### Clone or upload

Upload files to your hosting environment.

### Configure

Edit:

    app/config.php

Set API URLs, tokens, webhook secrets, and default project.

### Initialize database

    php migrate.php

### Permissions

Ensure writable:

-   data/
-   logs/

------------------------------------------------------------------------

## 🔗 Webhook Setup

### osTicket events

-   Ticket created
-   Ticket updated
-   Ticket closed
-   New reply

### Kanboard events

-   Task created
-   Task updated
-   Task moved
-   Comment added
-   Task closed

------------------------------------------------------------------------

## 🔁 Loop Prevention

Sync engine tracks changes and ignores updates originating from the
other system.

------------------------------------------------------------------------

## 📊 Status Mapping

  Ticket        Task
  ------------- ---------
  Open          Backlog
  In Progress   Doing
  Resolved      Done
  Closed        Closed

------------------------------------------------------------------------

## 🩺 Health Endpoint

    GET /public/health.php

Returns service status and queue depth.

------------------------------------------------------------------------

## 🧾 Logging

Logs stored in:

    logs/app.log

Includes events, retries, and errors.

------------------------------------------------------------------------

## 🔐 Security

-   Webhook secret validation
-   HTTPS recommended
-   Minimal public surface

------------------------------------------------------------------------

## 🧪 Testing Checklist

-   Create ticket → task created
-   Update ticket → task updates
-   Comment sync works
-   Close task → ticket updates
-   Health endpoint OK

------------------------------------------------------------------------

## 📈 Scaling Path

-   SQLite → MySQL
-   Add admin UI
-   Add metrics
-   Introduce Redis queue

------------------------------------------------------------------------

## 💾 Backup

Backup:

-   data/
-   logs/
-   config.php

------------------------------------------------------------------------

## 🗺 Roadmap

-   Admin dashboard
-   Replay failed events
-   Email alerts
-   Attachment sync
-   Metrics endpoint

------------------------------------------------------------------------

## 🛠 Troubleshooting Flow

1.  Check health endpoint
2.  Check logs
3.  Verify worker running
4.  Confirm webhook delivery
5.  Validate API credentials

------------------------------------------------------------------------

## 📜 License

MIT License

------------------------------------------------------------------------

## 🧠 Design Principles

-   Reliability first
-   Simple operations
-   Observable behavior
-   Easy recovery
