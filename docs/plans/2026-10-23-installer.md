# Retail POS — Installer with Windows services (plan, waiting for the owner's approval)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** one setup file per product (`RetailPOS-Setup-1.0.exe`, `RetailProduction-Setup-1.0.exe`) that a shop runs once: it installs the web server, the database and PHP as **three Windows services** that start with the PC (web, database, **backup**), creates the database with a random password, makes the first administrator, opens the sign-in page, and can be run again later to update without touching the data. No XAMPP on the client, nothing to type.

**Why:** the owner sells the system to many shops (2026-10-10) and wants no support calls about Apache or MySQL; the automatic backup must be a service, not a scheduled task (2026-10-23).

**Architecture:** the same program, packaged. The installer is built with **Inno Setup** (free, the standard Windows installer builder) from a script in the repository (`tools/installer/`). It carries copies of the exact binaries this project is tested on, taken from this PC's XAMPP: Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32. Apache and MariaDB register themselves as services (`httpd -k install`, `mysqld --install`); PHP cannot, so the backup service is a small PHP loop (`bin/backup-service.php`, every 30 minutes the same decision as `bin/backup.php --auto`) wrapped by **WinSW** (free, one exe + one xml, the usual way to run a script as a Windows service). Everything lives under one folder **without spaces**, `C:\RetailPOS\` (`C:\RetailProduction\` for the factory edition):

```
C:\RetailPOS\
  app\        the project (what GitHub holds)
  php\        PHP 8.2 with php.ini (gd, zip, mysqli, pdo_mysql on)
  apache\     Apache 2.4 with one httpd.conf that serves app\public on port 80
  mariadb\    MariaDB 10.4 with my.ini, data in data\
  backup\     WinSW wrapper + xml for the backup service
  data\       the database files (survives updates and uninstall unless ticked)
```

**Tech Stack:** Inno Setup 6 (Pascal script, built on this PC with `iscc.exe`), WinSW 2.x, PowerShell for the build script, PHP for the install-time steps (`bin/install.php` already creates the database, migrations and the admin).

## Decisions to confirm before coding

1. **Install folder without spaces:** `C:\RetailPOS` and `C:\RetailProduction`. The path with a space (`Retail POS`) stays for development only.
2. **Our own Apache, PHP and MariaDB**, copied from this PC's XAMPP, never the client's XAMPP even if one exists.
3. **Database password:** random per shop, written to `config/app.ini` by the installer; the shop never sees MySQL.
4. **Ports:** 80 and 3306; when one is busy the installer takes 8080 / 3307 and writes them into the config and the shortcut.
5. **Backup service:** "RetailPOS Backup" checks every 30 minutes, makes the daily backup at the time set on the Backups page, retries a failed Google Drive upload. Runs as a service like the other two.
6. **Building needs two free tools on this PC:** Inno Setup and WinSW. I install them in `C:\Tools\` only after you say yes.
7. **Testing needs a clean Windows PC or a virtual machine** that never had XAMPP: your team runs the setup there and reports. I cannot do that step from here.

## Tasks

### Task 1 — The backup service script
- [ ] `bin/backup-service.php`: a loop that every 30 minutes calls `BackupService::autoKind()` and, when due, `create()`; otherwise `retryDrive()`; writes one line per run to `storage/logs/backup-service.log`; stops cleanly when the service stops (checks a stop file written by WinSW's pre-stop hook, or exits on SIGTERM where available). Test: `BackupServiceLoopTest` runs one iteration with a fake clock and asserts the decision and the log line.
- [ ] `bin/install-backup-tasks.php` stays for XAMPP installs; the Backups page says "service" when the service is installed (a setting written by the installer) and "task" otherwise.

### Task 2 — Production mode by config
- [ ] `config/app.ini.example` gains `[server] port`, `[database] host/port/user/password`; `config.php` reads them (host/port/user/password already read, add port of the web server for the shortcut only).
- [ ] A "Check this installation" page (`admin` → `settings/check`, permission `settings.manage`): PHP extensions on, writable `storage/` and `uploads/`, database reachable, backup folder, USB folder, disk free space, the three services' state (`sc query`), last backup. Test: `HttpInstallCheckTest`.

### Task 3 — The build script
- [ ] `tools/installer/build.ps1`: copies `apache\`, `php\`, `mysql\` from `C:\xampp` into `tools/installer/stage/` with trimmed folders (no docs, no test data), writes `httpd.conf`, `php.ini`, `my.ini` from templates with the ports and the install folder as placeholders, exports the project with `git archive` (never the working folder, never `config/app.ini`, backups or logs), and calls `iscc.exe` on `setup.iss`. Produces `dist/RetailPOS-Setup-<version>.exe`.
- [ ] `tools/installer/setup.iss`: pages for shop name, administrator password, folder; checks ports; copies files; installs the three services; runs `php bin/install.php --admin-password=...`; writes `config/app.ini` (env = production, random DB password, ports); creates the desktop shortcut to `http://localhost[:port]/`; opens the browser at the end.
- [ ] **Update mode:** when the folder exists, stops the services, replaces `app\`, `php\`, `apache\` (keeps `data\`, `app\config\app.ini`, `app\storage\`, `app\public\uploads\`), runs `bin/install.php` for the migrations, starts the services.
- [ ] **Uninstall:** removes the services; asks before deleting `data\` and the backups.

### Task 4 — Retail Production
- [ ] The same `tools/installer/` with the product name, folder and service names as parameters (`RetailProduction`), so both setups come from one script run in each repository.

### Task 5 — Documentation and the drill
- [ ] `docs/INSTALL.md`: for the team: build, install on a clean PC, update, restore from a USB zip on a new PC.
- [ ] The restore drill (`bin/restore-drill.php`) runs inside an installed copy, proving a backup made on XAMPP restores on an installed one.

**Effort:** 4 to 5 days of work plus the team's test on a clean PC. Tasks 1 and 2 first (they are plain code with tests), then 3, then 4 and 5.
