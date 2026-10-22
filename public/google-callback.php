<?php
/**
 * Where Google sends the browser back after "Connect Google Drive" on the Backups page (2026-10-22). Google wants a
 * plain path as the redirect URI, so this file hands the code to the application: index.php?r=backup/google-callback.
 */
declare(strict_types=1);

$query = http_build_query(array_intersect_key($_GET, array_flip(['code', 'state', 'error'])));
header('Location: index.php?r=backup/google-callback' . ($query === '' ? '' : '&' . $query));
exit;
