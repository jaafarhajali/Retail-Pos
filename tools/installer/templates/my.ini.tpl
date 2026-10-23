# MariaDB for {{DISPLAY}} — written by post-install.ps1 from my.ini.tpl.
[client]
port={{DBPORT}}
default-character-set=utf8mb4

[mysqld]
port={{DBPORT}}
bind-address=127.0.0.1
basedir="{{ROOT}}/mariadb"
datadir="{{ROOT}}/data"
tmpdir="{{ROOT}}/tmp"
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
innodb_buffer_pool_size=128M
innodb_flush_log_at_trx_commit=1
max_allowed_packet=64M
max_connections=50
skip-name-resolve
log-error="{{ROOT}}/data/mariadb-error.log"

[mysqldump]
max_allowed_packet=64M
