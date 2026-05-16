# dbtools

A web-based admin panel for backing up, comparing, and replicating databases. It handles both **MySQL** and **InterBase / Firebird** databases across multiple environments (dev / test / production) from one interface — so you're not juggling `mysqldump` flags and `gbak` arguments by hand.

## Features

- **MySQL backups** — one-click `mysqldump` backups of any configured database, across multiple MySQL versions (8.x / 9.x) and environments.
- **Database comparison** — compare schema objects (tables, views, procedures, functions, triggers, events) between two environments, generate a sync plan, and apply it object-by-object or all at once.
- **Replication** — copy a database from one environment to another.
- **InterBase / Firebird** — backup, restore, and prod-to-test refresh via `gbak` / `gfix`, driven by a simple JSON config.
- **Scheduled backups** — run backups unattended through the bundled scripts and Windows Task Scheduler.
- **Log viewer** — review backup, replication, and SQL-execution logs from the browser.

## Tech stack

- **Backend:** PHP
- **Databases:** MySQL 8.x / 9.x, InterBase / Firebird
- **Frontend:** Bootstrap + vanilla JavaScript (AJAX)
- **Platform:** Windows (uses `mysqldump.exe`, `gbak.exe`, and PowerShell scripts)

## Setup

1. **Configuration** — copy the example config files and fill in your own values:
   ```
   copy config.php.example config.php
   copy interbase\ib-config.json.example interbase\ib-config.json
   ```
   - `config.php` — MySQL hosts, credentials, environments, the list of databases to back up, and the paths to `mysqldump.exe` / `mysql.exe`.
   - `interbase/ib-config.json` — only needed for InterBase/Firebird work: `gbak` / `gfix` paths, environments, and the database list.
2. **Serve it** — point a PHP-capable web server (IIS or Apache) at the `dbtools/` directory and open `index.php`.
3. **(Optional) scheduled backups** — point Windows Task Scheduler at `scheduled-backup.php`, or at the InterBase PowerShell scripts under `interbase/`.

## Authentication

dbtools doesn't include its own login, so you'll need to provide your own auth system — wire the auth check in `common/` to your login, or put dbtools behind whatever access control you prefer.

## A note

This is a personal admin tool, shared because the MySQL + InterBase backup/compare workflow may be useful to someone else. Expect rough edges.
