# post-install.ps1 - run by the setup after the files are copied (and by the uninstaller with -Uninstall).
# Writes the configs from the templates, creates the database, installs and starts the three Windows services
# (<Product>Web, <Product>DB, <Product>Backup), makes the first administrator, and puts a shortcut on the desktop.
# Run again by the setup on an update: the services are stopped, the migrations applied, the services started.
# Everything it does is logged to <Root>\install.log; the setup shows that path when this script exits with 1.
param(
    [Parameter(Mandatory = $true)][string]$Root,
    [string]$Product = 'CodexyERP',
    [string]$DisplayName = 'Codexy ERP',
    [string]$DbName = 'retail_pos',
    [string]$ShopName = '',
    [string]$AdminPassword = '',
    [switch]$Uninstall
)
$ErrorActionPreference = 'Stop'
$Root = $Root.TrimEnd('\')
$log = "$Root\install.log"
function Log([string]$m) { "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $m" | Add-Content -Path $log -Encoding UTF8; Write-Host $m }
function Fail([string]$m) { Log "FAILED: $m"; exit 1 }
# Runs a program and returns its exit code and output. Never through PowerShell's own redirection: with
# ErrorActionPreference = Stop, one line a program prints on stderr (Apache does) would end this script silently.
function Native([string]$exe, [string[]]$arguments) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $exe
    $psi.Arguments = ($arguments | ForEach-Object { if ($_ -match '[\s"]') { '"' + $_.Replace('"', '\"') + '"' } else { $_ } }) -join ' '
    $psi.UseShellExecute = $false; $psi.CreateNoWindow = $true
    $psi.RedirectStandardOutput = $true; $psi.RedirectStandardError = $true
    $p = [System.Diagnostics.Process]::Start($psi)
    $out = $p.StandardOutput.ReadToEnd(); $err = $p.StandardError.ReadToEnd()
    $p.WaitForExit()
    return @{ Code = $p.ExitCode; Out = ($out + "`n" + $err).Trim() }
}
$svcWeb = "$($Product)Web"; $svcDb = "$($Product)DB"; $svcBak = "$($Product)Backup"
function Svc([string]$n) { Get-Service -Name $n -ErrorAction SilentlyContinue }
function StopSvc([string]$n) {
    $s = Svc $n
    if ($s -and $s.Status -ne 'Stopped') { Log "stopping $n"; Stop-Service -Name $n -Force -ErrorAction SilentlyContinue; (Svc $n).WaitForStatus('Stopped', '00:01:30') }
}
function StartSvc([string]$n) { Log "starting $n"; Start-Service -Name $n; (Svc $n).WaitForStatus('Running', '00:01:00') }
function PortFree([int]$p) {
    # a bind test lies on Windows (a second socket may bind a port another process listens on): ask netstat who listens
    $r = Native 'netstat.exe' @('-ano', '-p', 'TCP')
    return -not ($r.Out -match ('(?m)^\s*TCP\s+\S+:' + $p + '\s+\S+\s+LISTENING'))
}
function FirstFree([int[]]$ports) { foreach ($p in $ports) { if (PortFree $p) { return $p } }; Fail "No free port among $($ports -join ', ')." }
function WaitPort([int]$p, [int]$seconds) {
    for ($i = 0; $i -lt $seconds; $i++) {
        try { $c = New-Object System.Net.Sockets.TcpClient; $c.Connect('127.0.0.1', $p); $c.Close(); return $true } catch { Start-Sleep -Seconds 1 }
    }
    return $false
}
function Fill([string]$tpl, [string]$out, [hashtable]$vars) {
    $s = Get-Content -Path $tpl -Raw
    foreach ($k in $vars.Keys) { $s = $s.Replace('{{' + $k + '}}', [string]$vars[$k]) }
    [System.IO.File]::WriteAllText($out, $s, (New-Object System.Text.UTF8Encoding($false)))
}

try {
    if ($Uninstall) {
        Log "uninstall: removing the services"
        foreach ($n in @($svcBak, $svcWeb, $svcDb)) {
            StopSvc $n
            if (Svc $n) { $r = Native 'sc.exe' @('delete', $n); Log "deleted $n ($($r.Code))" }
        }
        exit 0
    }

    $ini = "$Root\app\config\app.ini"
    $update = Test-Path $ini
    Log ("=== " + $(if ($update) { 'update' } else { 'fresh install' }) + " of $DisplayName in $Root")

    # Apache, PHP and MariaDB are built with Visual Studio: a PC that never had XAMPP lacks the Visual C++ runtime, and
    # httpd.exe then cannot even start (no output, no service). The setup ships Microsoft's redistributable.
    $sys = "$env:SystemRoot\System32"
    $missing = @('vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll') | Where-Object { -not (Test-Path "$sys\$_") }
    if ($missing.Count -gt 0) {
        $redist = "$Root\redist\vc_redist.x64.exe"
        if (-not (Test-Path $redist)) { Fail "the Visual C++ runtime is missing ($($missing -join ', ')) and the setup has no $redist." }
        Log "installing the Visual C++ runtime (missing: $($missing -join ', '))"
        $res = Native $redist @('/install', '/quiet', '/norestart')
        if ($res.Code -ne 0 -and $res.Code -ne 3010 -and $res.Code -ne 1638) { Fail "vc_redist.x64.exe failed with code $($res.Code): $($res.Out)" }
        Log "Visual C++ runtime installed (code $($res.Code))"
    }
    $res = Native "$Root\apache\bin\httpd.exe" @('-v')
    if ($res.Code -ne 0) { Fail "Apache cannot start on this PC (code $($res.Code)): $($res.Out)" }
    Log ("apache: " + ($res.Out -split "`n")[0])
    foreach ($d in @("$Root\tmp", "$Root\data", "$Root\apache\logs", "$Root\app\storage\logs", "$Root\app\storage\sessions", "$Root\app\storage\backups", "$Root\app\public\uploads\products")) {
        New-Item -ItemType Directory -Force -Path $d | Out-Null
    }

    if ($update) {
        $iniText = Get-Content -Path $ini -Raw
        $webPort = [int][regex]::Match($iniText, '(?m)^web_port\s*=\s*(\d+)').Groups[1].Value
        $dbPort = [int][regex]::Match($iniText, '(?m)^port\s*=\s*(\d+)').Groups[1].Value
        $dbPass = [regex]::Match($iniText, '(?m)^pass\s*=\s*"?([^"\r\n]*)"?').Groups[1].Value
        if ($webPort -eq 0 -or $dbPort -eq 0) { Fail "config\app.ini has no ports; cannot update." }
        foreach ($n in @($svcBak, $svcWeb)) { StopSvc $n }
    } else {
        $webPort = FirstFree @(80, 8080, 8081, 8090, 8800)
        $dbPort = FirstFree @(3306, 3307, 3308, 3309, 3316)
        $chars = ([char[]]'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789')
        $dbPass = -join (1..24 | ForEach-Object { $chars | Get-Random })
    }
    Log "web port $webPort, database port $dbPort"

    # configs from the templates
    $r = $Root.Replace('\', '/')
    $loadFiles = @('php8ts.dll', 'libpq.dll', 'libsqlite3.dll') | Where-Object { Test-Path "$Root\php\$_" } | ForEach-Object { "LoadFile `"$r/php/$_`"" }
    Fill "$Root\apache\conf\httpd.conf.tpl" "$Root\apache\conf\httpd.conf" @{ ROOT = $r; PORT = $webPort; DISPLAY = $DisplayName; LOADFILES = ($loadFiles -join "`n") }
    Fill "$Root\php\php.ini.tpl" "$Root\php\php.ini" @{ ROOT = $Root; ROOTFS = $r }
    Fill "$Root\mariadb\my.ini.tpl" "$Root\mariadb\my.ini" @{ ROOT = $r; DBPORT = $dbPort; DISPLAY = $DisplayName }
    Fill "$Root\backup\backup-service.xml.tpl" "$Root\backup\$($Product)Backup.xml" @{ ROOT = $Root; PRODUCT = $Product; DISPLAY = $DisplayName }
    Log "configs written"

    # the database
    if (-not $update) {
        if (Test-Path "$Root\data\mysql") {
            # a previous attempt (or an uninstall that kept the data) left database files without a config: keep them aside, never delete
            $aside = "$Root\data-old-" + (Get-Date -Format 'yyyyMMdd-HHmmss')
            Move-Item "$Root\data" $aside
            New-Item -ItemType Directory -Force -Path "$Root\data" | Out-Null
            Log "found database files without config\app.ini: moved to $aside"
        }
        Log "creating the database files"
        $res = Native "$Root\mariadb\bin\mysql_install_db.exe" @("--datadir=$Root\data", "--password=$dbPass", "--port=$dbPort")
        if ($res.Code -ne 0) { Fail "mysql_install_db failed: $($res.Out)" }
        Remove-Item "$Root\data\my.ini" -ErrorAction SilentlyContinue   # mysql_install_db writes its own; ours is mariadb\my.ini
    }
    if (-not (Svc $svcDb)) {
        $res = Native "$Root\mariadb\bin\mysqld.exe" @('--install', $svcDb, "--defaults-file=$Root\mariadb\my.ini")
        if (-not (Svc $svcDb)) { Fail "could not install the database service: $($res.Out)" }
        Log "service $svcDb installed"
    }
    StopSvc $svcDb
    StartSvc $svcDb
    if (-not (WaitPort $dbPort 60)) { Fail "the database did not answer on port $dbPort. See $Root\data\mariadb-error.log." }

    # the web server
    if (-not (Svc $svcWeb)) {
        $res = Native "$Root\apache\bin\httpd.exe" @('-k', 'install', '-n', $svcWeb, '-f', "$Root\apache\conf\httpd.conf")
        Log "httpd (code $($res.Code)): $($res.Out)"
        if (-not (Svc $svcWeb)) { Fail "could not install the web service (httpd exit code $($res.Code))." }
    }
    $res = Native "$Root\apache\bin\httpd.exe" @('-t', '-f', "$Root\apache\conf\httpd.conf")
    if ($res.Code -ne 0) { Fail "the web server refuses its configuration: $($res.Out)" }

    # the program's own config, then its installer (database, migrations, first administrator, settings)
    if (-not $update) {
        @(
            "; $DisplayName - written by the setup. Never share this file: it holds the database password.",
            "[app]", "env = production", "debug = 0", "timezone = Asia/Beirut", "",
            "[database]", "host = 127.0.0.1", "port = $dbPort", "name = $DbName", "user = root", "pass = `"$dbPass`"", "",
            "[install]", "product = $Product", "web_port = $webPort", "root = `"$Root`""
        ) | Set-Content -Path $ini -Encoding UTF8
        Log "config\app.ini written"
    }
    $phpArgs = @("$Root\app\bin\install.php", "--runner=service", "--services=$svcWeb,$svcDb,$svcBak")
    if (-not $update) { $phpArgs += "--admin-password=$AdminPassword"; if ($ShopName -ne '') { $phpArgs += "--shop-name=$ShopName" } }
    $res = Native "$Root\php\php.exe" $phpArgs
    $res.Out -split "`n" | ForEach-Object { if ($_ -ne '') { Log "install.php: $_" } }
    if ($res.Code -ne 0) { Fail "the program's installer failed. See the lines above." }

    StartSvc $svcWeb
    if (-not (WaitPort $webPort 30)) { Fail "the web server did not answer on port $webPort. See $Root\apache\logs\error.log." }

    # the backup service (WinSW wrapper around php bin/backup-service.php)
    if (-not (Svc $svcBak)) {
        $res = Native "$Root\backup\$($Product)Backup.exe" @('install')
        Log "winsw: $($res.Out)"
        if (-not (Svc $svcBak)) { Fail "could not install the backup service." }
    }
    StartSvc $svcBak

    # a way in
    $url = "http://localhost" + $(if ($webPort -eq 80) { '' } else { ":$webPort" }) + "/"
    "@echo off`r`nstart `"`" $url" | Set-Content -Path "$Root\open.cmd" -Encoding ASCII
    "[InternetShortcut]`r`nURL=$url" | Set-Content -Path "$env:PUBLIC\Desktop\$DisplayName.url" -Encoding ASCII
    Log "done: $url"
    exit 0
} catch {
    Log ("FAILED: " + $_.Exception.Message + " (line " + $_.InvocationInfo.ScriptLineNumber + ")")
    exit 1
}
