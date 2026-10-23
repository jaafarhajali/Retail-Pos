# The Windows setup

One file, `dist\CodexyERP-Setup-<version>.exe`, installs the whole program on a shop's PC: the web server, the
database, PHP and the program, as three Windows services that start with the PC:

| Service | What |
|---|---|
| `CodexyERPWeb` | Apache 2.4, serving `app\public` on port 80 (or the next free of 8080, 8081, 8090, 8800) |
| `CodexyERPDB` | MariaDB 10.4 on port 3306 (or the next free of 3307, 3308, 3309, 3316), data in `data\` |
| `CodexyERPBackup` | `php bin\backup-service.php`: the daily backup at the time set on the Backups page, checked every 30 minutes |

The setup asks for the folder (`C:\CodexyERP`, no spaces), the shop name and the administrator password, then opens
the browser. Everything it did is in `<folder>\install.log`; the program's own check is Settings → Check this installation.

**Update:** run the newer setup on the same folder. It keeps `data\`, `app\config\app.ini`, `app\storage\` and the
uploads, replaces the program, applies the migrations and restarts the services.

**Uninstall:** Windows → Apps → Codexy ERP. The services are removed; it asks whether to delete the data and backups.

**If the PC was off at the backup time:** the backup service makes the backup as soon as the PC is on again.

## Building it (on the development PC)

Needs Inno Setup (`C:\Tools\InnoSetup\ISCC.exe`), `C:\Tools\downloads\WinSW-x64.exe`, XAMPP in `C:\xampp`, git and tar.
The program files come from **git HEAD**, so commit first.

```
powershell -ExecutionPolicy Bypass -File tools\installer\build.ps1
```

For Retail Production, in that repository:

```
powershell -ExecutionPolicy Bypass -File tools\installer\build.ps1 -Product CodexyERPProduction -DisplayName "Codexy ERP Production" -DefaultDir C:\CodexyERPProduction -DbName retail_production
```

The version is the `VERSION` file at the root of the repository. Bump it before a release.

## Testing it

On a PC that never had XAMPP: run the setup, sign in as `admin`, make a sale, press Back up now, pull the USB stick
out and in, check Settings → Check this installation is all green. Then run the setup again to prove an update keeps
the data. On the development PC **start XAMPP first**: the setup takes the first free ports, so with XAMPP stopped it takes 80 and 3306 and XAMPP cannot start afterwards. With XAMPP running it lands on 8080 and the next free database port.
