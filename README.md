# Retail POS

Point of sale and stock system for a retail shop (Argile / Hookah, Lebanon):
USD prices, USD + LBP payments, cash sessions with X/Z reports.
Design: `docs/specs/2026-09-24-core-design.md`. Plans: `docs/plans/`.

## Requirements

XAMPP with PHP 8.2 (with `extension=gd` enabled in `php.ini`, for product images) and MariaDB 10.4
(Apache + MySQL started). No internet needed.

## Install (first time, or after pulling new code)

```bash
cd "/c/xampp/htdocs/Retail POS"
/c/xampp/php/php.exe bin/install.php --admin-password='choose-a-strong-password'
```

It creates the `retail_pos` database, applies the migrations and creates the
`admin` user. Without `--admin-password` a random password is printed, and it
must be changed at first sign-in. Running it again only applies new migrations.

Open **http://localhost/Retail%20POS/**, or from another PC on the network
`http://<server-ip>/Retail%20POS/`. Check once that
`http://localhost/Retail%20POS/.git/HEAD` answers **403**: only `public/` may be served.

Settings for another machine (database password, timezone, production mode)
go in `config/app.ini` (never committed):

```ini
[app]
env = production
timezone = Asia/Beirut
[database]
host = 127.0.0.1
port = 3306
name = retail_pos
user = root
pass =
```

## Tests

```bash
/c/xampp/php/php.exe tests/run.php          # everything (about a minute)
/c/xampp/php/php.exe tests/run.php Auth     # files whose name contains "Auth"
```

The tests use a separate `retail_pos_test` database, rebuilt for every test,
and start their own web server on port 8190. Your real data is never touched.

## Where things are

| Folder | What |
|---|---|
| `public/` | the only web-reachable folder (front controller + assets) |
| `app/routes.php` | every URL, with the permission it requires |
| `app/Core/` | router, auth, permissions (Gate), audit, database |
| `app/Services/` | business rules |
| `app/Models/` | SQL |
| `database/migrations/` | numbered schema changes; add new files, never edit old ones |
| `public/uploads/products/` | product images (git-ignored; include it in backups) |
