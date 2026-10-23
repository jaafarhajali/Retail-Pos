# Apache for {{DISPLAY}} — written by post-install.ps1 from httpd.conf.tpl. Serves only app/public.
ServerRoot "{{ROOT}}/apache"
Listen {{PORT}}
ServerName localhost:{{PORT}}
ServerAdmin admin@localhost
ServerTokens Prod
ServerSignature Off

LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule access_compat_module modules/mod_access_compat.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule alias_module modules/mod_alias.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule headers_module modules/mod_headers.so
LoadModule env_module modules/mod_env.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule deflate_module modules/mod_deflate.so

{{LOADFILES}}
LoadModule php_module "{{ROOT}}/php/php8apache2_4.dll"
PHPIniDir "{{ROOT}}/php"
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>

TypesConfig conf/mime.types
DirectoryIndex index.php index.html
ErrorLog "logs/error.log"
LogLevel warn
LogFormat "%h %l %u %t \"%r\" %>s %b" common
CustomLog "logs/access.log" common
LimitRequestBody 67108864
AddDefaultCharset utf-8

<Directory />
    AllowOverride None
    Require all denied
</Directory>

DocumentRoot "{{ROOT}}/app/public"
<Directory "{{ROOT}}/app/public">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
