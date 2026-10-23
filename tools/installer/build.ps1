# build.ps1 — builds the Windows setup of this product from the committed code (git HEAD) and this PC's XAMPP binaries.
#   powershell -ExecutionPolicy Bypass -File tools\installer\build.ps1
#   … -Product RetailProduction -DisplayName "Retail Production" -DefaultDir C:\RetailProduction -DbName retail_production
# Needs: Inno Setup (ISCC.exe), WinSW-x64.exe, XAMPP (Apache 2.4, PHP 8.2, MariaDB 10.4), git, tar. Output: dist\<Product>-Setup-<version>.exe
param(
    [string]$Product = 'RetailPOS',
    [string]$DisplayName = 'Retail POS',
    [string]$DefaultDir = 'C:\RetailPOS',
    [string]$DbName = 'retail_pos',
    [string]$Xampp = 'C:\xampp',
    [string]$Iscc = 'C:\Tools\InnoSetup\ISCC.exe',
    [string]$WinSW = 'C:\Tools\downloads\WinSW-x64.exe'
)
$ErrorActionPreference = 'Stop'
$Repo = (Resolve-Path "$PSScriptRoot\..\..").Path.TrimEnd('\')
$Version = (Get-Content "$Repo\VERSION" -TotalCount 1).Trim()
$Stage = "$PSScriptRoot\stage"
$Dist = "$Repo\dist"
foreach ($need in @($Iscc, $WinSW, "$Xampp\apache\bin\httpd.exe", "$Xampp\php\php8apache2_4.dll", "$Xampp\mysql\bin\mysqld.exe", "$Xampp\mysql\bin\mysql_install_db.exe")) {
    if (-not (Test-Path $need)) { throw "Missing: $need" }
}
Write-Host "Building $DisplayName $Version from $Repo"
if (Test-Path $Stage) { Remove-Item -Recurse -Force $Stage }
foreach ($d in @('app', 'php', 'apache\conf', 'apache\logs', 'mariadb', 'backup')) { New-Item -ItemType Directory -Force -Path "$Stage\$d" | Out-Null }
New-Item -ItemType Directory -Force -Path $Dist | Out-Null

# 1. the program, from git HEAD: never the working folder, never config/app.ini, backups, logs, tests, docs or tools
$tar = "$env:TEMP\$Product-app.tar"
& git -C $Repo archive --format=tar --output="$tar" HEAD ':!tests' ':!docs' ':!tools' ':!.gitignore' ':!.editorconfig' ':!README.md'
if ($LASTEXITCODE -ne 0) { throw "git archive failed" }
& tar -xf $tar -C "$Stage\app"
Remove-Item $tar
Copy-Item "$Repo\VERSION" "$Stage\app\VERSION" -Force
$appFiles = (Get-ChildItem "$Stage\app" -Recurse -File | Measure-Object).Count
Write-Host "  app: $appFiles files"

# 2. PHP: the runtime only (no docs, dev headers, pear, tests); php.ini becomes a template with the install folder as placeholder
& robocopy "$Xampp\php" "$Stage\php" /E /XD docs dev pear tests extras include lib\php\doc /XF *.txt *.html *.md php.ini php.ini-development php.ini-production php-cgi.exe php8phpdbg.dll phpdbg.exe *.pdb /NFL /NDL /NJH /NJS /NP | Out-Null
$ini = Get-Content "$Xampp\php\php.ini" -Raw
$ini = $ini -replace [regex]::Escape('C:\xampp\php'), '{{ROOT}}\php' -replace [regex]::Escape('C:/xampp/php'), '{{ROOTFS}}/php'
$ini = $ini -replace [regex]::Escape('C:\xampp\tmp'), '{{ROOT}}\tmp' -replace [regex]::Escape('C:/xampp/tmp'), '{{ROOTFS}}/tmp'
$ini = $ini -replace [regex]::Escape('C:\xampp'), '{{ROOT}}' -replace [regex]::Escape('C:/xampp'), '{{ROOTFS}}'
$ini = $ini -replace '(?m)^display_errors\s*=.*$', 'display_errors=Off' -replace '(?m)^log_errors\s*=.*$', 'log_errors=On'
$ini = $ini -replace '(?m)^;?error_log\s*=.*$', 'error_log="{{ROOT}}\php\php-errors.log"'
$ini = $ini -replace '(?m)^upload_max_filesize\s*=.*$', 'upload_max_filesize=64M' -replace '(?m)^post_max_size\s*=.*$', 'post_max_size=64M'
$ini = $ini -replace '(?m)^;?date\.timezone\s*=.*$', 'date.timezone=Asia/Beirut'
$ini = $ini -replace '(?m)^browscap\s*=.*$', ';browscap='   # the extras folder is not shipped
foreach ($ext in @('gd', 'zip', 'pdo_mysql', 'mbstring', 'curl', 'fileinfo', 'openssl')) {
    if ($ini -notmatch "(?m)^extension=$ext\s*$" -and $ini -notmatch "(?m)^extension=php_$ext\.dll\s*$") { $ini += "`r`nextension=$ext" }
}
$ini = $ini -replace '(?m)^;extension=(gd|zip|pdo_mysql|mbstring|curl|fileinfo)\s*$', 'extension=$1'
[System.IO.File]::WriteAllText("$Stage\php\php.ini.tpl", $ini, (New-Object System.Text.UTF8Encoding($false)))
Write-Host "  php: $((Get-ChildItem "$Stage\php" -Recurse -File | Measure-Object).Count) files"

# 3. Apache: binaries and modules; our own httpd.conf template; mime.types
& robocopy "$Xampp\apache\bin" "$Stage\apache\bin" /E /XF *.pdb /NFL /NDL /NJH /NJS /NP | Out-Null
& robocopy "$Xampp\apache\modules" "$Stage\apache\modules" /E /XF *.pdb /NFL /NDL /NJH /NJS /NP | Out-Null
Copy-Item "$Xampp\apache\conf\mime.types" "$Stage\apache\conf\mime.types"
Copy-Item "$PSScriptRoot\templates\httpd.conf.tpl" "$Stage\apache\conf\httpd.conf.tpl"
Write-Host "  apache: $((Get-ChildItem "$Stage\apache" -Recurse -File | Measure-Object).Count) files"

# 4. MariaDB: the server, the clients, the error messages
& robocopy "$Xampp\mysql\bin" "$Stage\mariadb\bin" /E /XF *.pdb *.pl *.map mysqld-debug.exe /NFL /NDL /NJH /NJS /NP | Out-Null
& robocopy "$Xampp\mysql\share" "$Stage\mariadb\share" /E /NFL /NDL /NJH /NJS /NP | Out-Null
Copy-Item "$PSScriptRoot\templates\my.ini.tpl" "$Stage\mariadb\my.ini.tpl"
Write-Host "  mariadb: $((Get-ChildItem "$Stage\mariadb" -Recurse -File | Measure-Object).Count) files"

# 5. the backup service wrapper and the install script
Copy-Item $WinSW "$Stage\backup\$($Product)Backup.exe"
Copy-Item "$PSScriptRoot\templates\backup-service.xml.tpl" "$Stage\backup\backup-service.xml.tpl"
Copy-Item "$PSScriptRoot\post-install.ps1" "$Stage\post-install.ps1"

# 6. the setup
$size = [math]::Round(((Get-ChildItem $Stage -Recurse -File | Measure-Object -Property Length -Sum).Sum / 1MB))
Write-Host "  staged: $size MB; compiling…"
& $Iscc "/DProduct=$Product" "/DDisplayName=$DisplayName" "/DVersion=$Version" "/DDefaultDir=$DefaultDir" "/DDbName=$DbName" "/DStage=$Stage" "/DOut=$Dist" /Q "$PSScriptRoot\setup.iss"
if ($LASTEXITCODE -ne 0) { throw "Inno Setup failed" }
$exe = Get-Item "$Dist\$Product-Setup-$Version.exe"
Write-Host ("Built {0} ({1} MB)" -f $exe.FullName, [math]::Round($exe.Length / 1MB))
